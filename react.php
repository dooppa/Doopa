<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doopa - Message Reactions API
|--------------------------------------------------------------------------
| Endpoint:
|   POST /api/messages/react.php
|
| Actions:
|   add    - Add a reaction
|   remove - Remove a reaction
|
| Required JSON:
|   {
|       "message_id": 40,
|       "reaction": "👍",
|       "action": "add"
|   }
|
| Authentication:
|   Existing Doopa session authentication
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| JSON response helper
|--------------------------------------------------------------------------
*/
function sendJson(
    bool $success,
    string $message,
    mixed $data = [],
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data' => $data
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Method validation
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(
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
    !isset($_SESSION['logged_in'], $_SESSION['user_id']) ||
    $_SESSION['logged_in'] !== true ||
    !is_numeric($_SESSION['user_id']) ||
    (int) $_SESSION['user_id'] <= 0
) {
    sendJson(
        false,
        'Authentication required.',
        [],
        401
    );
}

$currentUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/
require_once __DIR__ . '/../../config/database.php';

try {
    $database = new Database();
    $pdo = $database->connect();

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    sendJson(
        false,
        'Database connection failed.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Read JSON body
|--------------------------------------------------------------------------
*/
$rawInput = file_get_contents('php://input');

if ($rawInput === false || trim($rawInput) === '') {
    sendJson(
        false,
        'Invalid JSON request.',
        [],
        422
    );
}

$input = json_decode($rawInput, true);

if (
    !is_array($input) ||
    json_last_error() !== JSON_ERROR_NONE
) {
    sendJson(
        false,
        'Invalid JSON request.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate message_id
|--------------------------------------------------------------------------
*/
if (
    !array_key_exists('message_id', $input) ||
    !is_int($input['message_id']) ||
    $input['message_id'] <= 0
) {
    sendJson(
        false,
        'message_id must be a positive integer.',
        [],
        422
    );
}

$messageId = (int) $input['message_id'];

/*
|--------------------------------------------------------------------------
| Validate action
|--------------------------------------------------------------------------
*/
$action = $input['action'] ?? null;

if (!is_string($action)) {
    sendJson(
        false,
        'action is required.',
        [],
        422
    );
}

$action = strtolower(trim($action));

if (!in_array($action, ['add', 'remove'], true)) {
    sendJson(
        false,
        'action must be either add or remove.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate reaction
|--------------------------------------------------------------------------
*/
$reaction = $input['reaction'] ?? null;

if (!is_string($reaction)) {
    sendJson(
        false,
        'reaction is required.',
        [],
        422
    );
}

$reaction = trim($reaction);

if ($reaction === '') {
    sendJson(
        false,
        'reaction cannot be empty.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Reaction length validation
|--------------------------------------------------------------------------
*/
if (mb_strlen($reaction, 'UTF-8') > 50) {
    sendJson(
        false,
        'reaction cannot exceed 50 characters.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Find message and verify conversation membership
|--------------------------------------------------------------------------
*/
$messageStmt = $pdo->prepare(
    "
    SELECT
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
    WHERE m.id = ?
      AND cp.user_id = ?
    LIMIT 1
    "
);

$messageStmt->execute([
    $messageId,
    $currentUserId
]);

$message = $messageStmt->fetch(PDO::FETCH_ASSOC);

if (!$message) {
    sendJson(
        false,
        'Message not found or you are not authorised to access it.',
        [],
        404
    );
}

/*
|--------------------------------------------------------------------------
| Deleted-message protection
|--------------------------------------------------------------------------
*/
if ((int) $message['is_deleted'] === 1) {
    sendJson(
        false,
        'Deleted messages cannot be reacted to.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| ADD REACTION
|--------------------------------------------------------------------------
*/
if ($action === 'add') {
    try {
        $pdo->beginTransaction();

        /*
        |----------------------------------------------------------------------
        | Check whether this exact reaction already exists
        |----------------------------------------------------------------------
        */
        $existingStmt = $pdo->prepare(
            "
            SELECT
                id,
                message_id,
                user_id,
                reaction,
                created_at,
                updated_at
            FROM message_reactions
            WHERE message_id = ?
              AND user_id = ?
              AND reaction = ?
            LIMIT 1
            "
        );

        $existingStmt->execute([
            $messageId,
            $currentUserId,
            $reaction
        ]);

        $existingReaction = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingReaction) {
            $pdo->rollBack();

            sendJson(
                false,
                'Reaction already exists.',
                [
                    'reaction' => [
                        'id' => (int) $existingReaction['id'],
                        'message_id' => (int) $existingReaction['message_id'],
                        'user_id' => (int) $existingReaction['user_id'],
                        'reaction' => $existingReaction['reaction'],
                        'created_at' => $existingReaction['created_at'],
                        'updated_at' => $existingReaction['updated_at']
                    ]
                ],
                409
            );
        }

        /*
        |----------------------------------------------------------------------
        | Insert reaction
        |----------------------------------------------------------------------
        */
        $insertStmt = $pdo->prepare(
            "
            INSERT INTO message_reactions
                (
                    message_id,
                    user_id,
                    reaction
                )
            VALUES
                (
                    ?,
                    ?,
                    ?
                )
            "
        );

        $insertStmt->execute([
            $messageId,
            $currentUserId,
            $reaction
        ]);

        $reactionId = (int) $pdo->lastInsertId();

        /*
        |----------------------------------------------------------------------
        | Retrieve inserted reaction
        |----------------------------------------------------------------------
        */
        $reactionStmt = $pdo->prepare(
            "
            SELECT
                id,
                message_id,
                user_id,
                reaction,
                created_at,
                updated_at
            FROM message_reactions
            WHERE id = ?
            LIMIT 1
            "
        );

        $reactionStmt->execute([
            $reactionId
        ]);

        $createdReaction = $reactionStmt->fetch(PDO::FETCH_ASSOC);

        $pdo->commit();

        sendJson(
            true,
            'Reaction added successfully.',
            [
                'action' => 'add',
                'reaction' => [
                    'id' => (int) $createdReaction['id'],
                    'message_id' => (int) $createdReaction['message_id'],
                    'user_id' => (int) $createdReaction['user_id'],
                    'reaction' => $createdReaction['reaction'],
                    'created_at' => $createdReaction['created_at'],
                    'updated_at' => $createdReaction['updated_at']
                ]
            ],
            201
        );
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        /*
        |----------------------------------------------------------------------
        | Handle duplicate-key race condition
        |----------------------------------------------------------------------
        */
        if ((int) $e->errorInfo[1] === 1062) {
            sendJson(
                false,
                'Reaction already exists.',
                [],
                409
            );
        }

        sendJson(
            false,
            'Unable to add reaction.',
            [],
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| REMOVE REACTION
|--------------------------------------------------------------------------
*/
try {
    $deleteStmt = $pdo->prepare(
        "
        DELETE FROM message_reactions
        WHERE message_id = ?
          AND user_id = ?
          AND reaction = ?
        "
    );

    $deleteStmt->execute([
        $messageId,
        $currentUserId,
        $reaction
    ]);

    if ($deleteStmt->rowCount() === 0) {
        sendJson(
            false,
            'Reaction not found.',
            [],
            404
        );
    }

    sendJson(
        true,
        'Reaction removed successfully.',
        [
            'action' => 'remove',
            'message_id' => $messageId,
            'user_id' => $currentUserId,
            'reaction' => $reaction
        ],
        200
    );
} catch (PDOException $e) {
    sendJson(
        false,
        'Unable to remove reaction.',
        [],
        500
    );
}