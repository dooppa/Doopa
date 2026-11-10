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

if ($userId <= 0) {
    jsonResponse(
        false,
        'Authentication required.',
        [],
        401
    );
}

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
| Basic message data
|--------------------------------------------------------------------------
*/

$conversationId = (int) ($input['conversation_id'] ?? 0);
$message = trim((string) ($input['message'] ?? ''));
$messageType = trim((string) ($input['message_type'] ?? 'text'));

/*
|--------------------------------------------------------------------------
| Optional reply message
|--------------------------------------------------------------------------
*/

$replyToMessageId = null;

if (array_key_exists('reply_to_message_id', $input)) {

    /*
     * Reject null/empty reply IDs when the field is explicitly supplied.
     */

    if (
        $input['reply_to_message_id'] === null ||
        $input['reply_to_message_id'] === ''
    ) {
        jsonResponse(
            false,
            'Reply message ID must be a valid positive integer.',
            [],
            422
        );
    }

    /*
     * Prevent values such as:
     * 1.5
     * true
     * false
     * abc
     */

    if (
        is_bool($input['reply_to_message_id']) ||
        is_float($input['reply_to_message_id']) ||
        is_array($input['reply_to_message_id'])
    ) {
        jsonResponse(
            false,
            'Reply message ID must be a valid positive integer.',
            [],
            422
        );
    }

    $replyToMessageId = filter_var(
        $input['reply_to_message_id'],
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    if ($replyToMessageId === false) {
        jsonResponse(
            false,
            'Reply message ID must be a valid positive integer.',
            [],
            422
        );
    }

    $replyToMessageId = (int) $replyToMessageId;
}

/*
|--------------------------------------------------------------------------
| Validate conversation
|--------------------------------------------------------------------------
*/

if ($conversationId <= 0) {
    jsonResponse(
        false,
        'Valid conversation ID is required.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate message
|--------------------------------------------------------------------------
*/

if ($message === '') {
    jsonResponse(
        false,
        'Message cannot be empty.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate message type
|--------------------------------------------------------------------------
*/

$allowedTypes = [
    'text',
    'image',
    'video',
    'file',
    'audio'
];

if (!in_array($messageType, $allowedTypes, true)) {
    jsonResponse(
        false,
        'Invalid message type.',
        [],
        422
    );
}

try {

    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Verify logged-in user belongs to conversation
    |--------------------------------------------------------------------------
    */

    $participantStmt = $pdo->prepare(
        'SELECT id
         FROM conversation_participants
         WHERE conversation_id = :conversation_id
           AND user_id = :user_id
         LIMIT 1'
    );

    $participantStmt->execute([
        ':conversation_id' => $conversationId,
        ':user_id' => $userId
    ]);

    if (!$participantStmt->fetch()) {
        jsonResponse(
            false,
            'You are not a participant in this conversation.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validate reply message
    |--------------------------------------------------------------------------
    |
    | A reply must point to a message in the SAME conversation.
    |
    */

    $replyToMessage = null;

    if ($replyToMessageId !== null) {

        $replyStmt = $pdo->prepare(
            'SELECT
                id,
                conversation_id,
                sender_id,
                message,
                message_type,
                is_deleted,
                created_at,
                updated_at
             FROM messages
             WHERE id = :message_id
               AND conversation_id = :conversation_id
             LIMIT 1'
        );

        $replyStmt->execute([
            ':message_id' => $replyToMessageId,
            ':conversation_id' => $conversationId
        ]);

        $replyToMessage = $replyStmt->fetch(PDO::FETCH_ASSOC);

        if (!$replyToMessage) {
            jsonResponse(
                false,
                'The message you are replying to was not found in this conversation.',
                [],
                404
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Get recipient
    |--------------------------------------------------------------------------
    |
    | Current messaging system uses direct conversations.
    | Recipient is the other participant.
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
        ':conversation_id' => $conversationId,
        ':sender_id' => $userId
    ]);

    $recipient = $recipientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$recipient) {
        jsonResponse(
            false,
            'Message recipient not found.',
            [],
            404
        );
    }

    $recipientId = (int) $recipient['id'];

    /*
    |--------------------------------------------------------------------------
    | Detect @username mentions
    |--------------------------------------------------------------------------
    |
    | Examples:
    |
    | Hello @john
    | @john please check this
    | Thanks @john_doe
    |
    | Allowed username characters:
    | letters
    | numbers
    | underscore
    | dot
    | hyphen
    |
    */

    $mentionedUsernames = [];

    if (preg_match_all(
        '/(?<![A-Za-z0-9_.-])@([A-Za-z0-9_.-]{1,100})/',
        $message,
        $mentionMatches
    )) {

        foreach ($mentionMatches[1] as $username) {

            $username = trim((string) $username);

            if ($username === '') {
                continue;
            }

            /*
             * Username matching is case-insensitive.
             * Store the username only once.
             */

            $usernameKey = strtolower($username);

            $mentionedUsernames[$usernameKey] = $username;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Begin transaction
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Insert message
    |--------------------------------------------------------------------------
    */

    $messageStmt = $pdo->prepare(
        'INSERT INTO messages
            (
                conversation_id,
                sender_id,
                reply_to_message_id,
                message,
                message_type
            )
         VALUES
            (
                :conversation_id,
                :sender_id,
                :reply_to_message_id,
                :message,
                :message_type
            )'
    );

    $messageStmt->execute([
        ':conversation_id' => $conversationId,
        ':sender_id' => $userId,
        ':reply_to_message_id' => $replyToMessageId,
        ':message' => $message,
        ':message_type' => $messageType
    ]);

    $messageId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | Update conversation activity
    |--------------------------------------------------------------------------
    */

    $updateConversationStmt = $pdo->prepare(
        'UPDATE conversations
         SET updated_at = CURRENT_TIMESTAMP
         WHERE id = :conversation_id'
    );

    $updateConversationStmt->execute([
        ':conversation_id' => $conversationId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Process Mentions
    |--------------------------------------------------------------------------
    */

    $mentions = [];

    if (!empty($mentionedUsernames)) {

        /*
         * Get all users matching detected usernames.
         *
         * The participant JOIN is important:
         * a user can only be mentioned if they belong
         * to the same conversation.
         */

        $mentionUsernames = array_values($mentionedUsernames);

        $usernamePlaceholders = implode(
            ',',
            array_fill(0, count($mentionUsernames), '?')
        );

        $mentionUserStmt = $pdo->prepare(
            "SELECT
                u.id,
                u.full_name,
                u.username,
                u.avatar_url
             FROM users u
             INNER JOIN conversation_participants cp
                 ON cp.user_id = u.id
                AND cp.conversation_id = ?
             WHERE LOWER(u.username) IN ($usernamePlaceholders)
             AND u.id != ?
             ORDER BY u.id ASC"
        );

        $mentionParams = [
            $conversationId
        ];

        foreach ($mentionUsernames as $username) {
            $mentionParams[] = strtolower($username);
        }

        $mentionParams[] = $userId;

        $mentionUserStmt->execute($mentionParams);

        $mentionUsers = $mentionUserStmt->fetchAll(PDO::FETCH_ASSOC);

        /*
         * Insert each valid mention.
         */

        $insertMentionStmt = $pdo->prepare(
            'INSERT INTO message_mentions
                (
                    message_id,
                    user_id,
                    created_at
                )
             VALUES
                (
                    :message_id,
                    :user_id,
                    CURRENT_TIMESTAMP
                )'
        );

        /*
         * Notification for mentions.
         */

        $mentionNotificationStmt = $pdo->prepare(
            'INSERT INTO notifications
                (
                    user_id,
                    actor_id,
                    type,
                    reference_id,
                    message,
                    is_read,
                    created_at
                )
             VALUES
                (
                    :user_id,
                    :actor_id,
                    :type,
                    :reference_id,
                    :message,
                    0,
                    CURRENT_TIMESTAMP
                )'
        );

        foreach ($mentionUsers as $mentionUser) {

            $mentionedUserId = (int) $mentionUser['id'];

            /*
             * Prevent duplicate database records.
             *
             * The unique key also protects against duplicates
             * at database level.
             */

            try {

                $insertMentionStmt->execute([
                    ':message_id' => $messageId,
                    ':user_id' => $mentionedUserId
                ]);

            } catch (PDOException $mentionException) {

                /*
                 * Ignore duplicate mention records.
                 * Re-throw all other database errors.
                 */

                if (
                    (int) $mentionException->errorInfo[1] !== 1062
                ) {
                    throw $mentionException;
                }
            }

            /*
             * Add mention to API response.
             */

            $mentions[] = [
                'id' => null,
                'message_id' => $messageId,
                'user_id' => $mentionedUserId,
                'user' => [
                    'id' => $mentionedUserId,
                    'full_name' => $mentionUser['full_name'],
                    'username' => $mentionUser['username'],
                    'avatar_url' => $mentionUser['avatar_url']
                ]
            ];

            /*
             * Create mention notification.
             *
             * Do not notify the sender if they somehow mention
             * themselves. Self-mentions are excluded above.
             */

            $mentionNotificationStmt->execute([
                ':user_id' => $mentionedUserId,
                ':actor_id' => $userId,
                ':type' => 'mention',
                ':reference_id' => $messageId,
                ':message' => 'mentioned you in a message'
            ]);
        }

        /*
         * Retrieve inserted mention IDs and timestamps.
         */

        if (!empty($mentions)) {

            $mentionStmt = $pdo->prepare(
                'SELECT
                    mm.id,
                    mm.message_id,
                    mm.user_id,
                    mm.created_at,
                    u.full_name,
                    u.username,
                    u.avatar_url
                 FROM message_mentions mm
                 INNER JOIN users u
                     ON u.id = mm.user_id
                 WHERE mm.message_id = :message_id
                 ORDER BY mm.id ASC'
            );

            $mentionStmt->execute([
                ':message_id' => $messageId
            ]);

            $storedMentions = $mentionStmt->fetchAll(PDO::FETCH_ASSOC);

            $mentions = [];

            foreach ($storedMentions as $storedMention) {

                $mentions[] = [
                    'id' => (int) $storedMention['id'],
                    'message_id' => (int) $storedMention['message_id'],
                    'user_id' => (int) $storedMention['user_id'],
                    'user' => [
                        'id' => (int) $storedMention['user_id'],
                        'full_name' => $storedMention['full_name'],
                        'username' => $storedMention['username'],
                        'avatar_url' => $storedMention['avatar_url']
                    ],
                    'created_at' => $storedMention['created_at']
                ];
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Create normal message notification
    |--------------------------------------------------------------------------
    */

    $notificationMessage = 'sent you a message';

    if ($replyToMessageId !== null) {
        $notificationMessage = 'replied to your message';
    }

    $notificationStmt = $pdo->prepare(
        'INSERT INTO notifications
            (
                user_id,
                actor_id,
                type,
                reference_id,
                message,
                is_read,
                created_at
            )
         VALUES
            (
                :user_id,
                :actor_id,
                :type,
                :reference_id,
                :message,
                0,
                CURRENT_TIMESTAMP
            )'
    );

    $notificationStmt->execute([
        ':user_id' => $recipientId,
        ':actor_id' => $userId,
        ':type' => 'message',
        ':reference_id' => $messageId,
        ':message' => $notificationMessage
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
    | Retrieve newly-created message
    |--------------------------------------------------------------------------
    */

    $messageResultStmt = $pdo->prepare(
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

    $messageResultStmt->execute([
        ':message_id' => $messageId
    ]);

    $newMessage = $messageResultStmt->fetch(PDO::FETCH_ASSOC);

    if (!$newMessage) {
        jsonResponse(
            false,
            'Message was created but could not be retrieved.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Prepare reply information
    |--------------------------------------------------------------------------
    */

    $replyData = null;

    if ($replyToMessage !== null) {

        $replyDisplayMessage = $replyToMessage['message'];

        if ((int) $replyToMessage['is_deleted'] === 1) {
            $replyDisplayMessage = 'This message was deleted.';
        }

        $replyData = [
            'id' => (int) $replyToMessage['id'],
            'conversation_id' => (int) $replyToMessage['conversation_id'],
            'sender_id' => (int) $replyToMessage['sender_id'],
            'message' => $replyDisplayMessage,
            'message_type' => $replyToMessage['message_type'],
            'is_deleted' => (int) $replyToMessage['is_deleted'],
            'created_at' => $replyToMessage['created_at'],
            'updated_at' => $replyToMessage['updated_at']
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Return successful response
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        $replyToMessageId !== null
            ? 'Reply sent successfully.'
            : 'Message sent successfully.',
        [
            'message' => [
                'id' => (int) $newMessage['id'],
                'conversation_id' => (int) $newMessage['conversation_id'],
                'sender_id' => (int) $newMessage['sender_id'],
                'message' => $newMessage['message'],
                'message_type' => $newMessage['message_type'],
                'reply_to_message_id' =>
                    $newMessage['reply_to_message_id'] !== null
                        ? (int) $newMessage['reply_to_message_id']
                        : null,
                'forwarded_from_message_id' =>
                    $newMessage['forwarded_from_message_id'] !== null
                        ? (int) $newMessage['forwarded_from_message_id']
                        : null,
                'is_deleted' => (int) $newMessage['is_deleted'],
                'created_at' => $newMessage['created_at'],
                'updated_at' => $newMessage['updated_at']
            ],

            'reply_to' => $replyData,

            'mentions' => $mentions,

            'mention_count' => count($mentions),

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

    error_log(
        'Doopa message send database error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to send message.',
        [],
        500
    );

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Doopa message send general error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'An unexpected error occurred.',
        [],
        500
    );
}