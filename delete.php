<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Only POST requests are allowed.', [], 405);
}

if (
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id']) ||
    (int) $_SESSION['user_id'] <= 0
) {
    jsonResponse(false, 'Authentication required.', [], 401);
}

$userId = (int) $_SESSION['user_id'];

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    jsonResponse(false, 'Invalid JSON request.', [], 400);
}

$messageIdRaw = $input['message_id'] ?? null;
$action = $input['action'] ?? null;

if (
    !is_scalar($messageIdRaw) ||
    !preg_match('/^[1-9][0-9]*$/', (string) $messageIdRaw)
) {
    jsonResponse(false, 'Valid message ID is required.', [], 422);
}

$messageId = (int) $messageIdRaw;

if ($messageId <= 0) {
    jsonResponse(false, 'Valid message ID is required.', [], 422);
}

if (
    !is_string($action) ||
    !in_array($action, ['delete_for_me', 'delete_for_everyone'], true)
) {
    jsonResponse(
        false,
        'A valid deletion action is required.',
        ['allowed_actions' => ['delete_for_me', 'delete_for_everyone']],
        422
    );
}

try {
    $database = new Database();
    $pdo = $database->connect();

    /*
     * Load the message and its conversation.
     */
    $messageStmt = $pdo->prepare(
        'SELECT id, conversation_id, sender_id, is_deleted
         FROM messages
         WHERE id = :message_id
         LIMIT 1'
    );

    $messageStmt->execute([
        ':message_id' => $messageId
    ]);

    $message = $messageStmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        jsonResponse(false, 'Message not found.', [], 404);
    }

    $conversationId = (int) $message['conversation_id'];
    $senderId = (int) $message['sender_id'];

    /*
     * Verify conversation membership for both actions.
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

    if (!$participantStmt->fetch(PDO::FETCH_ASSOC)) {
        jsonResponse(
            false,
            'You are not a participant in this conversation.',
            [],
            403
        );
    }

    /*
     * Delete for me:
     * hide this message only for the current user.
     */
    if ($action === 'delete_for_me') {
        $hideStmt = $pdo->prepare(
            'INSERT INTO message_user_deletions
                (user_id, message_id)
             VALUES
                (:user_id, :message_id)
             ON DUPLICATE KEY UPDATE
                deleted_at = CURRENT_TIMESTAMP'
        );

        $hideStmt->execute([
            ':user_id' => $userId,
            ':message_id' => $messageId
        ]);

        jsonResponse(
            true,
            'Message hidden for you.',
            [
                'action' => 'delete_for_me',
                'message_id' => $messageId,
                'conversation_id' => $conversationId
            ]
        );
    }

    /*
     * Delete for everyone:
     * only the original sender may do this.
     */
    if ($senderId !== $userId) {
        jsonResponse(
            false,
            'You can only delete your own messages for everyone.',
            [],
            403
        );
    }

    if ((int) $message['is_deleted'] === 1) {
        jsonResponse(
            true,
            'Message is already deleted for everyone.',
            [
                'action' => 'delete_for_everyone',
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
                'is_deleted' => 1
            ]
        );
    }

    $pdo->beginTransaction();

    $deleteStmt = $pdo->prepare(
        'UPDATE messages
         SET message = \'\',
             is_deleted = 1,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :message_id
           AND sender_id = :sender_id
           AND is_deleted = 0'
    );

    $deleteStmt->execute([
        ':message_id' => $messageId,
        ':sender_id' => $userId
    ]);

    if ($deleteStmt->rowCount() !== 1) {
        $pdo->rollBack();

        jsonResponse(
            false,
            'The message could not be deleted. Refresh and try again.',
            [],
            409
        );
    }

    $pdo->commit();

    jsonResponse(
        true,
        'Message deleted for everyone.',
        [
            'action' => 'delete_for_everyone',
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'is_deleted' => 1
        ]
    );

} catch (PDOException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Doopa message deletion database error: ' . $e->getMessage());

    jsonResponse(false, 'Unable to delete message.', [], 500);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Doopa message deletion error: ' . $e->getMessage());

    jsonResponse(false, 'An unexpected error occurred.', [], 500);
}
