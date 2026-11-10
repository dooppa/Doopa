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
| Read JSON request
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
| Validate participant
|--------------------------------------------------------------------------
*/

$participantId = (int) ($input['user_id'] ?? 0);

if ($participantId <= 0) {
    jsonResponse(
        false,
        'Valid user ID is required.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Prevent conversation with yourself
|--------------------------------------------------------------------------
*/

if ($participantId === $userId) {
    jsonResponse(
        false,
        'You cannot create a conversation with yourself.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

try {

    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Check that the other user exists
    |--------------------------------------------------------------------------
    */

    $userStmt = $pdo->prepare(
        'SELECT id, full_name, username, avatar_url
         FROM users
         WHERE id = :user_id
         LIMIT 1'
    );

    $userStmt->execute([
        ':user_id' => $participantId
    ]);

    $participant = $userStmt->fetch();

    if (!$participant) {
        jsonResponse(
            false,
            'User not found.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Find existing direct conversation
    |--------------------------------------------------------------------------
    */

    $existingStmt = $pdo->prepare(
        'SELECT c.id
         FROM conversations c
         INNER JOIN conversation_participants cp1
             ON cp1.conversation_id = c.id
         INNER JOIN conversation_participants cp2
             ON cp2.conversation_id = c.id
         WHERE c.type = :type
           AND cp1.user_id = :user_id
           AND cp2.user_id = :participant_id
         LIMIT 1'
    );

    $existingStmt->execute([
        ':type' => 'direct',
        ':user_id' => $userId,
        ':participant_id' => $participantId
    ]);

    $existingConversation = $existingStmt->fetch();

    /*
    |--------------------------------------------------------------------------
    | Return existing conversation
    |--------------------------------------------------------------------------
    */

    if ($existingConversation) {

        jsonResponse(
            true,
            'Conversation already exists.',
            [
                'conversation_id' => (int) $existingConversation['id'],
                'type' => 'direct',
                'created' => false,
                'participant' => [
                    'id' => (int) $participant['id'],
                    'full_name' => $participant['full_name'],
                    'username' => $participant['username'],
                    'avatar_url' => $participant['avatar_url']
                ]
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Create conversation
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    $conversationStmt = $pdo->prepare(
        'INSERT INTO conversations
            (type, name, created_by)
         VALUES
            (:type, NULL, :created_by)'
    );

    $conversationStmt->execute([
        ':type' => 'direct',
        ':created_by' => $userId
    ]);

    $conversationId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | Add current user
    |--------------------------------------------------------------------------
    */

    $participantStmt = $pdo->prepare(
        'INSERT INTO conversation_participants
            (conversation_id, user_id)
         VALUES
            (:conversation_id, :user_id)'
    );

    $participantStmt->execute([
        ':conversation_id' => $conversationId,
        ':user_id' => $userId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Add other participant
    |--------------------------------------------------------------------------
    */

    $participantStmt->execute([
        ':conversation_id' => $conversationId,
        ':user_id' => $participantId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Commit
    |--------------------------------------------------------------------------
    */

    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | Return created conversation
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        'Conversation created successfully.',
        [
            'conversation_id' => $conversationId,
            'type' => 'direct',
            'created' => true,
            'participant' => [
                'id' => (int) $participant['id'],
                'full_name' => $participant['full_name'],
                'username' => $participant['username'],
                'avatar_url' => $participant['avatar_url']
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
        'Unable to create conversation.',
        [],
        500
    );
}

