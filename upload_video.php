<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function jsonResponse(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

/*
|--------------------------------------------------------------------------
| Request Method
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
| Input Validation
|--------------------------------------------------------------------------
*/

$conversationId = isset($_POST['conversation_id'])
    ? (int) $_POST['conversation_id']
    : 0;

$messageId = isset($_POST['message_id'])
    ? (int) $_POST['message_id']
    : 0;

if ($conversationId <= 0) {
    jsonResponse(
        false,
        'Valid conversation_id is required.',
        [],
        422
    );
}

if ($messageId <= 0) {
    jsonResponse(
        false,
        'Valid message_id is required.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| File Validation
|--------------------------------------------------------------------------
*/

if (!isset($_FILES['file'])) {
    jsonResponse(
        false,
        'Video file is required.',
        [],
        422
    );
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(
        false,
        'Video upload failed.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Maximum Video Size
|--------------------------------------------------------------------------
|
| Current limit: 50 MB
|
*/

$maxFileSize = 50 * 1024 * 1024;

if ((int) $file['size'] > $maxFileSize) {
    jsonResponse(
        false,
        'Video file is too large. Maximum allowed size is 50 MB.',
        [],
        422
    );
}

if ((int) $file['size'] <= 0) {
    jsonResponse(
        false,
        'The uploaded video is empty.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

try {
    $database = new Database();
    $db = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Verify Conversation Membership
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT id
        FROM conversation_participants
        WHERE conversation_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $conversationId,
        $userId
    ]);

    if (!$stmt->fetch()) {
        jsonResponse(
            false,
            'You are not a participant in this conversation.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Verify Message
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT id, conversation_id, is_deleted
        FROM messages
        WHERE id = ?
          AND conversation_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $messageId,
        $conversationId
    ]);

    $message = $stmt->fetch();

    if (!$message) {
        jsonResponse(
            false,
            'Message not found.',
            [],
            404
        );
    }

    if ((int) $message['is_deleted'] === 1) {
        jsonResponse(
            false,
            'Cannot attach a video to a deleted message.',
            [],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Detect Actual MIME Type
    |--------------------------------------------------------------------------
    */

    if (!class_exists('finfo')) {
        jsonResponse(
            false,
            'File information service is unavailable.',
            [],
            500
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mimeType = $finfo->file($file['tmp_name']);

    /*
    |--------------------------------------------------------------------------
    | Allowed Video Types
    |--------------------------------------------------------------------------
    */

    $allowedMimeTypes = [
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
        'video/quicktime' => 'mov',
        'video/x-msvideo' => 'avi',
        'video/x-matroska'=> 'mkv'
    ];

    if (!isset($allowedMimeTypes[$mimeType])) {
        jsonResponse(
            false,
            'Invalid video format. Allowed formats are MP4, WebM, MOV, AVI and MKV.',
            [],
            422
        );
    }

    $extension = $allowedMimeTypes[$mimeType];

    /*
    |--------------------------------------------------------------------------
    | Upload Directory
    |--------------------------------------------------------------------------
    */

    $uploadDirectory = __DIR__ . '/../../uploads/messages/';

    if (!is_dir($uploadDirectory)) {
        if (!mkdir($uploadDirectory, 0755, true)) {
            jsonResponse(
                false,
                'Unable to create upload directory.',
                [],
                500
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Secure Filename
    |--------------------------------------------------------------------------
    */

    try {
        $randomName = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        jsonResponse(
            false,
            'Unable to generate secure filename.',
            [],
            500
        );
    }

    $fileName = $randomName . '_' . time() . '.' . $extension;

    $destination = $uploadDirectory . $fileName;

    /*
    |--------------------------------------------------------------------------
    | Move Uploaded File
    |--------------------------------------------------------------------------
    */

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        jsonResponse(
            false,
            'Unable to save uploaded video.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Relative Database Path
    |--------------------------------------------------------------------------
    */

    $relativePath = 'uploads/messages/' . $fileName;

    /*
    |--------------------------------------------------------------------------
    | Save Attachment
    |--------------------------------------------------------------------------
    */

    try {
        $stmt = $db->prepare("
            INSERT INTO message_attachments (
                message_id,
                file_name,
                original_name,
                file_path,
                mime_type,
                file_size,
                attachment_type
            )
            VALUES (?, ?, ?, ?, ?, ?, 'video')
        ");

        $stmt->execute([
            $messageId,
            $fileName,
            basename($file['name']),
            $relativePath,
            $mimeType,
            (int) $file['size']
        ]);

        $attachmentId = (int) $db->lastInsertId();

    } catch (Throwable $e) {

        /*
        |--------------------------------------------------------------------------
        | Roll Back Physical File
        |--------------------------------------------------------------------------
        */

        if (is_file($destination)) {
            unlink($destination);
        }

        jsonResponse(
            false,
            'Failed to save video attachment.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        'Video uploaded successfully.',
        [
            'attachment' => [
                'id' => $attachmentId,
                'message_id' => $messageId,
                'file_name' => $fileName,
                'original_name' => basename($file['name']),
                'file_path' => $relativePath,
                'mime_type' => $mimeType,
                'file_size' => (int) $file['size'],
                'attachment_type' => 'video'
            ]
        ],
        201
    );
}