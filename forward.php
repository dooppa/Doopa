<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=UTF-8');

/*
|--------------------------------------------------------------------------
| Only allow POST requests
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(
        false,
        'Only POST requests are allowed.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/
if (
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id'])
) {
    jsonResponse(
        false,
        'Authentication required.',
        [],
        401
    );
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Read JSON input
|--------------------------------------------------------------------------
*/
$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    jsonResponse(
        false,
        'Invalid JSON request.',
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| Validate message ID
|--------------------------------------------------------------------------
*/
if (
    !array_key_exists('message_id', $input) ||
    $input['message_id'] === null ||
    $input['message_id'] === ''
) {
    jsonResponse(
        false,
        'Valid message ID is required.',
        [],
        422
    );
}

if (
    is_bool($input['message_id']) ||
    is_float($input['message_id'])
) {
    jsonResponse(
        false,
        'Message ID must be a valid positive integer.',
        [],
        422
    );
}

$messageId = filter_var(
    $input['message_id'],
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if ($messageId === false) {
    jsonResponse(
        false,
        'Message ID must be a valid positive integer.',
        [],
        422
    );
}

$messageId = (int) $messageId;

/*
|--------------------------------------------------------------------------
| Validate destination conversation ID
|--------------------------------------------------------------------------
*/
if (
    !array_key_exists('conversation_id', $input) ||
    $input['conversation_id'] === null ||
    $input['conversation_id'] === ''
) {
    jsonResponse(
        false,
        'Valid destination conversation ID is required.',
        [],
        422
    );
}

if (
    is_bool($input['conversation_id']) ||
    is_float($input['conversation_id'])
) {
    jsonResponse(
        false,
        'Conversation ID must be a valid positive integer.',
        [],
        422
    );
}

$destinationConversationId = filter_var(
    $input['conversation_id'],
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1
        ]
    ]
);

if ($destinationConversationId === false) {
    jsonResponse(
        false,
        'Conversation ID must be a valid positive integer.',
        [],
        422
    );
}

$destinationConversationId = (int) $destinationConversationId;

try {

    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Retrieve original message
    |--------------------------------------------------------------------------
    |
    | The user must be a participant in the source conversation.
    |
    */
    $sourceMessageStmt = $pdo->prepare(
        'SELECT
            m.id,
            m.conversation_id,
            m.sender_id,
            m.message,
            m.message_type,
            m.is_deleted,
            m.created_at,
            m.updated_at
         FROM messages m
         INNER JOIN conversation_participants cp
             ON cp.conversation_id = m.conversation_id
            AND cp.user_id = :user_id
         WHERE m.id = :message_id
         LIMIT 1'
    );

    $sourceMessageStmt->execute([
        ':user_id' => $userId,
        ':message_id' => $messageId
    ]);

    $sourceMessage = $sourceMessageStmt->fetch();

    if (!$sourceMessage) {
        jsonResponse(
            false,
            'Message not found or you are not authorised to access it.',
            [],
            404
        );
    }

    $sourceConversationId = (int) $sourceMessage['conversation_id'];

    /*
    |--------------------------------------------------------------------------
    | Check destination conversation exists
    |--------------------------------------------------------------------------
    */
    $destinationConversationStmt = $pdo->prepare(
        'SELECT
            id,
            type,
            name
         FROM conversations
         WHERE id = :conversation_id
         LIMIT 1'
    );

    $destinationConversationStmt->execute([
        ':conversation_id' => $destinationConversationId
    ]);

    $destinationConversation = $destinationConversationStmt->fetch();

    if (!$destinationConversation) {
        jsonResponse(
            false,
            'Destination conversation not found.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Verify destination participation
    |--------------------------------------------------------------------------
    */
    $destinationParticipantStmt = $pdo->prepare(
        'SELECT id
         FROM conversation_participants
         WHERE conversation_id = :conversation_id
           AND user_id = :user_id
         LIMIT 1'
    );

    $destinationParticipantStmt->execute([
        ':conversation_id' => $destinationConversationId,
        ':user_id' => $userId
    ]);

    if (!$destinationParticipantStmt->fetch()) {
        jsonResponse(
            false,
            'You are not a participant in the destination conversation.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Prevent forwarding deleted messages
    |--------------------------------------------------------------------------
    */
    if ((int) $sourceMessage['is_deleted'] === 1) {
        jsonResponse(
            false,
            'Deleted messages cannot be forwarded.',
            [],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Get destination recipient
    |--------------------------------------------------------------------------
    |
    | Current system uses direct conversations.
    |
    */
    $recipientStmt = $pdo->prepare(
        'SELECT
            u.id,
            u.full_name,
            u.username
         FROM conversation_participants cp
         INNER JOIN users u
             ON u.id = cp.user_id
         WHERE cp.conversation_id = :conversation_id
           AND cp.user_id != :sender_id
         LIMIT 1'
    );

    $recipientStmt->execute([
        ':conversation_id' => $destinationConversationId,
        ':sender_id' => $userId
    ]);

    $recipient = $recipientStmt->fetch();

    if (!$recipient) {
        jsonResponse(
            false,
            'Destination recipient not found.',
            [],
            404
        );
    }

    $recipientId = (int) $recipient['id'];

    /*
    |--------------------------------------------------------------------------
    | Begin transaction
    |--------------------------------------------------------------------------
    */
    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Create forwarded message
    |--------------------------------------------------------------------------
    |
    | The original message content/type are preserved.
    |
    */
    $forwardStmt = $pdo->prepare(
        'INSERT INTO messages
            (
                conversation_id,
                sender_id,
                forwarded_from_message_id,
                message,
                message_type
            )
         VALUES
            (
                :conversation_id,
                :sender_id,
                :forwarded_from_message_id,
                :message,
                :message_type
            )'
    );

    $forwardStmt->execute([
        ':conversation_id' => $destinationConversationId,
        ':sender_id' => $userId,
        ':forwarded_from_message_id' => $messageId,
        ':message' => $sourceMessage['message'],
        ':message_type' => $sourceMessage['message_type']
    ]);

    $forwardedMessageId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | Update destination conversation activity
    |--------------------------------------------------------------------------
    */
    $updateConversationStmt = $pdo->prepare(
        'UPDATE conversations
         SET updated_at = CURRENT_TIMESTAMP
         WHERE id = :conversation_id'
    );

    $updateConversationStmt->execute([
        ':conversation_id' => $destinationConversationId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Create notification
    |--------------------------------------------------------------------------
    */
    $notificationStmt = $pdo->prepare(
        'INSERT INTO notifications
            (
                user_id,
                type,
                reference_id,
                message,
                is_read,
                created_at
            )
         VALUES
            (
                :user_id,
                :type,
                :reference_id,
                :message,
                0,
                CURRENT_TIMESTAMP
            )'
    );

    $notificationStmt->execute([
        ':user_id' => $recipientId,
        ':type' => 'message',
        ':reference_id' => $forwardedMessageId,
        ':message' => 'forwarded you a message'
    ]);

    $notificationId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | Commit transaction
    |--------------------------------------------------------------------------
    */
    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | Retrieve forwarded message
    |--------------------------------------------------------------------------
    */
    $resultStmt = $pdo->prepare(
        'SELECT
            id,
            conversation_id,
            sender_id,
            reply_to_message_id,
            forwarded_from_message_id,
            message,
            message_type,
            is_deleted,
            created_at,
            updated_at
         FROM messages
         WHERE id = :message_id
         LIMIT 1'
    );

    $resultStmt->execute([
        ':message_id' => $forwardedMessageId
    ]);

    $forwardedMessage = $resultStmt->fetch();

    /*
    |--------------------------------------------------------------------------
    | Success response
    |--------------------------------------------------------------------------
    */
    jsonResponse(
        true,
        'Message forwarded successfully.',
        [
            'message' => [
                'id' => (int) $forwardedMessage['id'],
                'conversation_id' => (int) $forwardedMessage['conversation_id'],
                'sender_id' => (int) $forwardedMessage['sender_id'],
                'reply_to_message_id' => $forwardedMessage['reply_to_message_id'] !== null
                    ? (int) $forwardedMessage['reply_to_message_id']
                    : null,
                'forwarded_from_message_id' => $forwardedMessage['forwarded_from_message_id'] !== null
                    ? (int) $forwardedMessage['forwarded_from_message_id']
                    : null,
                'message' => $forwardedMessage['message'],
                'message_type' => $forwardedMessage['message_type'],
                'is_deleted' => (int) $forwardedMessage['is_deleted'],
                'created_at' => $forwardedMessage['created_at'],
                'updated_at' => $forwardedMessage['updated_at']
            ],
            'forwarded_from' => [
                'id' => (int) $sourceMessage['id'],
                'conversation_id' => $sourceConversationId,
                'sender_id' => (int) $sourceMessage['sender_id'],
                'message' => $sourceMessage['message'],
                'message_type' => $sourceMessage['message_type'],
                'created_at' => $sourceMessage['created_at']
            ],
            'notification' => [
                'id' => $notificationId,
                'recipient_id' => $recipientId,
                'type' => 'message'
            ]
        ],
        201
    );

} catch (PDOException $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonResponse(
        false,
        'Unable to forward message.',
        [],
        500
    );
}