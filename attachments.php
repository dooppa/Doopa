<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

/*
|--------------------------------------------------------------------------
| METHOD CHECK
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(
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
| VALIDATE MESSAGE ID
|--------------------------------------------------------------------------
*/

$messageId = filter_input(
    INPUT_GET,
    'message_id',
    FILTER_VALIDATE_INT
);

if ($messageId === false || $messageId === null || $messageId <= 0) {
    jsonResponse(
        false,
        'A valid message_id is required.',
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION AND ATTACHMENT RETRIEVAL
|--------------------------------------------------------------------------
*/

try {
    $database = new Database();
    $db = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | GET MESSAGE
    |--------------------------------------------------------------------------
    */

    $messageStmt = $db->prepare("
        SELECT
            m.id,
            m.conversation_id,
            m.sender_id,
            m.message_type,
            m.is_deleted
        FROM messages m
        WHERE m.id = :message_id
        LIMIT 1
    ");

    $messageStmt->execute([
        ':message_id' => $messageId
    ]);

    $message = $messageStmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        jsonResponse(
            false,
            'Message not found.',
            [],
            404
        );
    }

    $conversationId = (int) $message['conversation_id'];

    /*
    |--------------------------------------------------------------------------
    | VERIFY CONVERSATION PARTICIPANT
    |--------------------------------------------------------------------------
    */

    $participantStmt = $db->prepare("
        SELECT id
        FROM conversation_participants
        WHERE conversation_id = :conversation_id
          AND user_id = :user_id
        LIMIT 1
    ");

    $participantStmt->execute([
        ':conversation_id' => $conversationId,
        ':user_id' => $userId
    ]);

    $participant = $participantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$participant) {
        jsonResponse(
            false,
            'You are not a participant in this conversation.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GET ATTACHMENTS
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
            created_at,
            updated_at
        FROM message_attachments
        WHERE message_id = :message_id
        ORDER BY id ASC
    ");

    $attachmentStmt->execute([
        ':message_id' => $messageId
    ]);

    $attachments = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | FORMAT ATTACHMENTS
    |--------------------------------------------------------------------------
    */

    foreach ($attachments as &$attachment) {
        $attachment['id'] = (int) $attachment['id'];
        $attachment['message_id'] = (int) $attachment['message_id'];
        $attachment['file_size'] = (int) $attachment['file_size'];

        /*
        |--------------------------------------------------------------------------
        | FORMAT FILE SIZE
        |--------------------------------------------------------------------------
        */

        $bytes = $attachment['file_size'];

        if ($bytes < 1024) {
            $attachment['file_size_formatted'] = $bytes . ' B';
        } elseif ($bytes < 1024 * 1024) {
            $attachment['file_size_formatted'] =
                round($bytes / 1024, 2) . ' KB';
        } elseif ($bytes < 1024 * 1024 * 1024) {
            $attachment['file_size_formatted'] =
                round($bytes / (1024 * 1024), 2) . ' MB';
        } else {
            $attachment['file_size_formatted'] =
                round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        }

        /*
        |--------------------------------------------------------------------------
        | PROTECTED FILE URL
        |--------------------------------------------------------------------------
        */

        $attachment['file_url'] =
            '/login%20template/doopa/api/messages/attachment_file.php?id=' .
            rawurlencode((string) $attachment['id']);
    }

    unset($attachment);

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        'Message attachments retrieved successfully.',
        [
            'message' => [
                'id' => (int) $message['id'],
                'conversation_id' => $conversationId,
                'sender_id' => (int) $message['sender_id'],
                'message_type' => $message['message_type'],
                'is_deleted' => (int) $message['is_deleted']
            ],
            'count' => count($attachments),
            'attachments' => $attachments
        ]
    );

} catch (PDOException $e) {
    error_log(
        'Doopa attachment metadata database error: ' . $e->getMessage()
    );

    jsonResponse(
        false,
        'Database error while retrieving attachments.',
        [],
        500
    );

} catch (Throwable $e) {
    error_log(
        'Doopa attachment metadata error: ' . $e->getMessage()
    );

    jsonResponse(
        false,
        'An unexpected server error occurred.',
        [],
        500
    );
}
