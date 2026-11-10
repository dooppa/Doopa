<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - CONVERSATION LIST
|--------------------------------------------------------------------------
| Batch 9A / Batch 8I
| Conversation List + Correct Unread Counts
| Respects per-user message deletion in conversation previews.
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);


/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/

function sendJson(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): void {
    http_response_code($statusCode);

    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data' => $data
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| START SESSION
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/*
|--------------------------------------------------------------------------
| LOAD CONFIGURATION
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';


/*
|--------------------------------------------------------------------------
| REQUEST METHOD
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(
        false,
        'Only GET requests are allowed.',
        [],
        405
    );
}


/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id'])
) {
    sendJson(
        false,
        'Authentication required.',
        [],
        401
    );
}

$userId = (int) $_SESSION['user_id'];

if ($userId <= 0) {
    sendJson(
        false,
        'Invalid authenticated user.',
        [],
        401
    );
}


/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

try {
    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        throw new RuntimeException(
            'Database connection was not established.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | GET CONVERSATIONS
    |--------------------------------------------------------------------------
    |
    | Retrieve only conversations in which the current user participates.
    | Obtain this user's last_read_at from conversation_participants.
    |
    */

    $conversationStmt = $db->prepare("
        SELECT
            c.id,
            c.type,
            c.name,
            c.created_by,
            c.created_at,
            c.updated_at,
            cp.last_read_at
        FROM conversations c
        INNER JOIN conversation_participants cp
            ON cp.conversation_id = c.id
        WHERE cp.user_id = :user_id
        ORDER BY c.updated_at DESC, c.id DESC
    ");

    $conversationStmt->execute([
        ':user_id' => $userId
    ]);

    $conversations = $conversationStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


    /*
    |--------------------------------------------------------------------------
    | RESULT VARIABLES
    |--------------------------------------------------------------------------
    */

    $result = [];
    $totalUnreadCount = 0;


    /*
    |--------------------------------------------------------------------------
    | PROCESS EACH CONVERSATION
    |--------------------------------------------------------------------------
    */

    foreach ($conversations as $conversation) {
        $conversationId = (int) $conversation['id'];
        $lastReadAt = $conversation['last_read_at'];


        /*
        |--------------------------------------------------------------------------
        | OTHER PARTICIPANT
        |--------------------------------------------------------------------------
        */

        $otherStmt = $db->prepare("
            SELECT
                u.id,
                u.full_name,
                u.username,
                u.avatar_url
            FROM conversation_participants cp
            INNER JOIN users u
                ON u.id = cp.user_id
            WHERE cp.conversation_id = :conversation_id
              AND cp.user_id != :user_id
            ORDER BY cp.id ASC
            LIMIT 1
        ");

        $otherStmt->execute([
            ':conversation_id' => $conversationId,
            ':user_id' => $userId
        ]);

        $otherUser = $otherStmt->fetch(
            PDO::FETCH_ASSOC
        );


        /*
        |--------------------------------------------------------------------------
        | MESSAGE COUNT
        |--------------------------------------------------------------------------
        |
        | This remains the total number of messages in the conversation.
        | Per-user hiding does not remove a message from the conversation.
        |
        */

        $messageCountStmt = $db->prepare("
            SELECT COUNT(*)
            FROM messages
            WHERE conversation_id = :conversation_id
        ");

        $messageCountStmt->execute([
            ':conversation_id' => $conversationId
        ]);

        $messageCount = (int) $messageCountStmt->fetchColumn();


        /*
        |--------------------------------------------------------------------------
        | UNREAD COUNT
        |--------------------------------------------------------------------------
        |
        | Count incoming messages that have not been globally deleted.
        | Preserve the existing last_read_at behaviour.
        |
        */

        if ($lastReadAt === null || $lastReadAt === '') {
            $unreadStmt = $db->prepare("
                SELECT COUNT(*)
                FROM messages
                WHERE conversation_id = :conversation_id
                  AND sender_id != :user_id
                  AND is_deleted = 0
            ");

            $unreadStmt->execute([
                ':conversation_id' => $conversationId,
                ':user_id' => $userId
            ]);

        } else {
            $unreadStmt = $db->prepare("
                SELECT COUNT(*)
                FROM messages
                WHERE conversation_id = :conversation_id
                  AND sender_id != :user_id
                  AND is_deleted = 0
                  AND created_at > :last_read_at
            ");

            $unreadStmt->execute([
                ':conversation_id' => $conversationId,
                ':user_id' => $userId,
                ':last_read_at' => $lastReadAt
            ]);
        }

        $unreadCount = (int) $unreadStmt->fetchColumn();
        $totalUnreadCount += $unreadCount;


        /*
        |--------------------------------------------------------------------------
        | LATEST MESSAGE VISIBLE TO THIS USER
        |--------------------------------------------------------------------------
        |
        | Batch 8I:
        | Skip messages hidden by the current user.
        | Do not skip messages hidden only by another participant.
        | Globally deleted messages remain eligible so the conversation
        | can display the "This message was deleted." notice.
        |
        */

        $latestStmt = $db->prepare("
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
            WHERE m.conversation_id = :conversation_id
              AND NOT EXISTS (
                  SELECT 1
                  FROM message_user_deletions mud
                  WHERE mud.message_id = m.id
                    AND mud.user_id = :user_id
              )
            ORDER BY m.id DESC
            LIMIT 1
        ");

        $latestStmt->execute([
            ':conversation_id' => $conversationId,
            ':user_id' => $userId
        ]);

        $latestMessage = $latestStmt->fetch(
            PDO::FETCH_ASSOC
        );


        /*
        |--------------------------------------------------------------------------
        | FORMAT LATEST MESSAGE
        |--------------------------------------------------------------------------
        */

        if ($latestMessage) {
            $latestMessage['id'] =
                (int) $latestMessage['id'];

            $latestMessage['conversation_id'] =
                (int) $latestMessage['conversation_id'];

            $latestMessage['sender_id'] =
                (int) $latestMessage['sender_id'];

            $latestMessage['is_deleted'] =
                (int) $latestMessage['is_deleted'];


            /*
            |--------------------------------------------------------------------------
            | DELETED MESSAGE DISPLAY
            |--------------------------------------------------------------------------
            */

            if ((int) $latestMessage['is_deleted'] === 1) {
                $latestMessage['message'] =
                    'This message was deleted.';
            }


            /*
            |--------------------------------------------------------------------------
            | LATEST ATTACHMENT
            |--------------------------------------------------------------------------
            */

            $attachmentStmt = $db->prepare("
                SELECT
                    id,
                    message_id,
                    file_name,
                    original_name,
                    file_path,
                    mime_type,
                    file_size,
                    attachment_type,
                    created_at
                FROM message_attachments
                WHERE message_id = :message_id
                ORDER BY id DESC
                LIMIT 1
            ");

            $attachmentStmt->execute([
                ':message_id' => (int) $latestMessage['id']
            ]);

            $attachment = $attachmentStmt->fetch(
                PDO::FETCH_ASSOC
            );


            /*
            |--------------------------------------------------------------------------
            | ATTACHMENT RESPONSE
            |--------------------------------------------------------------------------
            */

            if ($attachment) {
                $attachment['id'] =
                    (int) $attachment['id'];

                $attachment['message_id'] =
                    (int) $attachment['message_id'];

                $attachment['file_size'] =
                    (int) $attachment['file_size'];

                $attachment['file_url'] =
                    '/doopa/' .
                    ltrim(
                        (string) $attachment['file_path'],
                        '/'
                    );

            } else {
                $attachment = null;
            }

            $latestMessage['attachment'] = $attachment;

        } else {
            $latestMessage = null;
        }


        /*
        |--------------------------------------------------------------------------
        | CONVERSATION RESPONSE
        |--------------------------------------------------------------------------
        */

        $result[] = [
            'conversation_id' => $conversationId,

            'type' => $conversation['type'],

            'name' => $conversation['name'],

            'created_by' => (int) $conversation['created_by'],

            'participant' => $otherUser
                ? [
                    'id' => (int) $otherUser['id'],
                    'full_name' => $otherUser['full_name'],
                    'username' => $otherUser['username'],
                    'avatar_url' => $otherUser['avatar_url']
                ]
                : null,

            'last_message' => $latestMessage,

            'message_count' => $messageCount,

            'unread_count' => $unreadCount,

            'last_read_at' => $lastReadAt,

            'created_at' => $conversation['created_at'],

            'updated_at' => $conversation['updated_at']
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    sendJson(
        true,
        'Conversations retrieved successfully.',
        [
            'count' => count($result),
            'total_unread_count' => $totalUnreadCount,
            'conversations' => $result
        ]
    );

} catch (Throwable $e) {
    error_log(
        'DOOPA conversations/list ERROR: ' .
        $e->getMessage() .
        ' | File: ' .
        $e->getFile() .
        ' | Line: ' .
        $e->getLine()
    );

    sendJson(
        false,
        'Unable to retrieve conversations.',
        [],
        500
    );
}

