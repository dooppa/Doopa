<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(
        false,
        'Only POST requests are allowed.',
        [],
        405
    );
}

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

$conversationId = (int) ($input['conversation_id'] ?? 0);

if ($conversationId <= 0) {
    jsonResponse(
        false,
        'Valid conversation ID is required.',
        [],
        422
    );
}

try {

    $database = new Database();
    $pdo = $database->connect();

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

    $updateStmt = $pdo->prepare(
        'UPDATE conversation_participants
         SET last_read_at = CURRENT_TIMESTAMP
         WHERE conversation_id = :conversation_id
           AND user_id = :user_id'
    );

    $updateStmt->execute([
        ':conversation_id' => $conversationId,
        ':user_id' => $userId
    ]);

    $resultStmt = $pdo->prepare(
        'SELECT last_read_at
         FROM conversation_participants
         WHERE conversation_id = :conversation_id
           AND user_id = :user_id
         LIMIT 1'
    );

    $resultStmt->execute([
        ':conversation_id' => $conversationId,
        ':user_id' => $userId
    ]);

    $result = $resultStmt->fetch();

    jsonResponse(
        true,
        'Conversation marked as read successfully.',
        [
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'last_read_at' => $result['last_read_at']
        ]
    );

} catch (PDOException $e) {

    jsonResponse(
        false,
        'Unable to mark conversation as read.',
        [],
        500
    );
}