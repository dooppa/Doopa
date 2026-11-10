<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

/*
|--------------------------------------------------------------------------
| JSON Response Helper
|--------------------------------------------------------------------------
*/

function jsonResponse(
    bool $success,
    string $message,
    array $data = [],
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
| Required Parameters
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
        'File is required.',
        [],
        422
    );
}

$file = $_FILES['file'];

/*
|--------------------------------------------------------------------------
| Upload Error Check
|--------------------------------------------------------------------------
*/

if ($file['error'] !== UPLOAD_ERR_OK) {

    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE =>
            'The uploaded file exceeds the server upload limit.',

        UPLOAD_ERR_FORM_SIZE =>
            'The uploaded file exceeds the allowed form size.',

        UPLOAD_ERR_PARTIAL =>
            'The file was only partially uploaded.',

        UPLOAD_ERR_NO_FILE =>
            'No file was uploaded.',

        UPLOAD_ERR_NO_TMP_DIR =>
            'Temporary upload directory is missing.',

        UPLOAD_ERR_CANT_WRITE =>
            'Failed to write the uploaded file.',

        UPLOAD_ERR_EXTENSION =>
            'A server extension stopped the upload.'
    ];

    $errorMessage = $uploadErrors[$file['error']]
        ?? 'Unknown file upload error.';

    jsonResponse(
        false,
        $errorMessage,
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Basic File Information
|--------------------------------------------------------------------------
*/

$originalName = basename((string) $file['name']);

$fileSize = (int) $file['size'];

$tmpName = (string) $file['tmp_name'];

/*
|--------------------------------------------------------------------------
| File Name Validation
|--------------------------------------------------------------------------
*/

if ($originalName === '') {
    jsonResponse(
        false,
        'Invalid file name.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Maximum File Size
|--------------------------------------------------------------------------
|
| Current limit: 20 MB
|
*/

$maxFileSize = 20 * 1024 * 1024;

if ($fileSize <= 0) {
    jsonResponse(
        false,
        'The uploaded file is empty.',
        [],
        422
    );
}

if ($fileSize > $maxFileSize) {
    jsonResponse(
        false,
        'File is too large. Maximum allowed size is 20 MB.',
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

    $participant = $stmt->fetch();

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
    | Verify Message
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            id,
            conversation_id,
            is_deleted
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

    /*
    |--------------------------------------------------------------------------
    | Prevent Attachment To Deleted Message
    |--------------------------------------------------------------------------
    */

    if ((int) $message['is_deleted'] === 1) {
        jsonResponse(
            false,
            'Cannot attach a file to a deleted message.',
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

    $mimeType = $finfo->file($tmpName);

    if ($mimeType === false || $mimeType === '') {
        jsonResponse(
            false,
            'Unable to determine file type.',
            [],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Allowed Document/File Types
    |--------------------------------------------------------------------------
    */

    $allowedMimeTypes = [

        // PDF
        'application/pdf' => 'pdf',

        // Microsoft Word
        'application/msword' =>
            'doc',

        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' =>
            'docx',

        // Microsoft Excel
        'application/vnd.ms-excel' =>
            'xls',

        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' =>
            'xlsx',

        // Microsoft PowerPoint
        'application/vnd.ms-powerpoint' =>
            'ppt',

        'application/vnd.openxmlformats-officedocument.presentationml.presentation' =>
            'pptx',

        // Text
        'text/plain' =>
            'txt',

        // CSV
        'text/csv' =>
            'csv',

        // ZIP
        'application/zip' =>
            'zip',

        // RAR
        'application/vnd.rar' =>
            'rar',

        // 7-Zip
        'application/x-7z-compressed' =>
            '7z'
    ];

    /*
    |--------------------------------------------------------------------------
    | Validate MIME Type
    |--------------------------------------------------------------------------
    */

    if (!isset($allowedMimeTypes[$mimeType])) {
        jsonResponse(
            false,
            'Invalid file format. Allowed formats are PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, TXT, CSV, ZIP, RAR and 7Z.',
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
    | Verify Upload Directory
    |--------------------------------------------------------------------------
    */

    if (!is_writable($uploadDirectory)) {
        jsonResponse(
            false,
            'Upload directory is not writable.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Generate Secure Filename
    |--------------------------------------------------------------------------
    */

    try {

        $randomName = bin2hex(
            random_bytes(16)
        );

    } catch (Throwable $e) {

        jsonResponse(
            false,
            'Unable to generate secure filename.',
            [],
            500
        );
    }

    $fileName =
        $randomName
        . '_'
        . time()
        . '.'
        . $extension;

    $destination =
        $uploadDirectory . $fileName;

    /*
    |--------------------------------------------------------------------------
    | Move Uploaded File
    |--------------------------------------------------------------------------
    */

    if (!move_uploaded_file(
        $tmpName,
        $destination
    )) {

        jsonResponse(
            false,
            'Unable to save uploaded file.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Relative File Path
    |--------------------------------------------------------------------------
    */

    $relativePath =
        'uploads/messages/' . $fileName;

    /*
    |--------------------------------------------------------------------------
    | Save Attachment To Database
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
            VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                'file'
            )
        ");

        $stmt->execute([
            $messageId,
            $fileName,
            $originalName,
            $relativePath,
            $mimeType,
            $fileSize
        ]);

        $attachmentId =
            (int) $db->lastInsertId();

    } catch (Throwable $e) {

        /*
        |--------------------------------------------------------------------------
        | Delete Physical File If Database Insert Fails
        |--------------------------------------------------------------------------
        */

        if (is_file($destination)) {
            unlink($destination);
        }

        jsonResponse(
            false,
            'Failed to save file attachment.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Success Response
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        'File uploaded successfully.',
        [
            'attachment' => [
                'id' =>
                    $attachmentId,

                'message_id' =>
                    $messageId,

                'file_name' =>
                    $fileName,

                'original_name' =>
                    $originalName,

                'file_path' =>
                    $relativePath,

                'mime_type' =>
                    $mimeType,

                'file_size' =>
                    $fileSize,

                'attachment_type' =>
                    'file'
            ]
        ],
        201
    );
}