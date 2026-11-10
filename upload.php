<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=UTF-8');

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
| Validate Conversation
|--------------------------------------------------------------------------
*/

$conversationId = (int) ($_POST['conversation_id'] ?? 0);

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
| Validate Message
|--------------------------------------------------------------------------
*/

$messageId = (int) ($_POST['message_id'] ?? 0);

if ($messageId <= 0) {
    jsonResponse(
        false,
        'Valid message ID is required.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate Uploaded File
|--------------------------------------------------------------------------
*/

if (!isset($_FILES['file'])) {
    jsonResponse(
        false,
        'Image file is required.',
        [],
        422
    );
}

$file = $_FILES['file'];

if (!is_array($file)) {
    jsonResponse(
        false,
        'Invalid uploaded file.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Upload Error
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
            'The server could not write the uploaded file.',

        UPLOAD_ERR_EXTENSION =>
            'The upload was stopped by a server extension.'
    ];

    $message = $uploadErrors[$file['error']]
        ?? 'Unknown file upload error.';

    jsonResponse(
        false,
        $message,
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| File Size
|--------------------------------------------------------------------------
| Maximum image size: 5 MB
|--------------------------------------------------------------------------
*/

$maxFileSize = 5 * 1024 * 1024;

$fileSize = (int) $file['size'];

if ($fileSize <= 0) {
    jsonResponse(
        false,
        'The uploaded image is empty.',
        [],
        422
    );
}

if ($fileSize > $maxFileSize) {
    jsonResponse(
        false,
        'Image size cannot exceed 5 MB.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate MIME Type
|--------------------------------------------------------------------------
*/

$tmpFile = $file['tmp_name'];

if (!is_uploaded_file($tmpFile)) {
    jsonResponse(
        false,
        'Invalid uploaded file.',
        [],
        422
    );
}

$finfo = new finfo(FILEINFO_MIME_TYPE);

$mimeType = $finfo->file($tmpFile);

$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp'
];

if (!isset($allowedMimeTypes[$mimeType])) {
    jsonResponse(
        false,
        'Only JPG, PNG, GIF, and WEBP images are allowed.',
        [],
        422
    );
}

$extension = $allowedMimeTypes[$mimeType];

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
    | Verify Conversation Membership
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
    | Verify Message
    |--------------------------------------------------------------------------
    */

    $messageStmt = $pdo->prepare(
        'SELECT
            id,
            conversation_id,
            sender_id,
            is_deleted
         FROM messages
         WHERE id = :message_id
         LIMIT 1'
    );

    $messageStmt->execute([
        ':message_id' => $messageId
    ]);

    $messageRecord = $messageStmt->fetch();

    if (!$messageRecord) {
        jsonResponse(
            false,
            'Message not found.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Make Sure Message Belongs To Conversation
    |--------------------------------------------------------------------------
    */

    if ((int) $messageRecord['conversation_id'] !== $conversationId) {
        jsonResponse(
            false,
            'Message does not belong to this conversation.',
            [],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Prevent Attachment To Deleted Message
    |--------------------------------------------------------------------------
    */

    if ((int) $messageRecord['is_deleted'] === 1) {
        jsonResponse(
            false,
            'Cannot attach an image to a deleted message.',
            [],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Generate Secure Unique Filename
    |--------------------------------------------------------------------------
    */

    $uniqueName =
        bin2hex(random_bytes(16))
        . '_' .
        time()
        . '.' .
        $extension;

    /*
    |--------------------------------------------------------------------------
    | Upload Directory
    |--------------------------------------------------------------------------
    */

    $uploadDirectory =
        __DIR__ . '/../../uploads/messages/';

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
    | Final File Path
    |--------------------------------------------------------------------------
    */

    $destination =
        $uploadDirectory . $uniqueName;

    /*
    |--------------------------------------------------------------------------
    | Move Uploaded File
    |--------------------------------------------------------------------------
    */

    if (!move_uploaded_file($tmpFile, $destination)) {
        jsonResponse(
            false,
            'Unable to save uploaded image.',
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
        'uploads/messages/' . $uniqueName;

    /*
    |--------------------------------------------------------------------------
    | Save Attachment
    |--------------------------------------------------------------------------
    */

    $attachmentStmt = $pdo->prepare(
        'INSERT INTO message_attachments
            (
                message_id,
                file_name,
                original_name,
                file_path,
                mime_type,
                file_size,
                attachment_type
            )
         VALUES
            (
                :message_id,
                :file_name,
                :original_name,
                :file_path,
                :mime_type,
                :file_size,
                :attachment_type
            )'
    );

    $attachmentStmt->execute([
        ':message_id'      => $messageId,
        ':file_name'       => $uniqueName,
        ':original_name'   => (string) $file['name'],
        ':file_path'       => $relativePath,
        ':mime_type'       => $mimeType,
        ':file_size'       => $fileSize,
        ':attachment_type' => 'image'
    ]);

    $attachmentId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        'Image uploaded successfully.',
        [
            'attachment' => [
                'id' => $attachmentId,
                'message_id' => $messageId,
                'file_name' => $uniqueName,
                'original_name' => (string) $file['name'],
                'file_path' => $relativePath,
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'attachment_type' => 'image'
            ]
        ],
        201
    );

} catch (PDOException $e) {

    /*
    |--------------------------------------------------------------------------
    | Delete Physical File If Database Insert Fails
    |--------------------------------------------------------------------------
    */

    if (
        isset($destination) &&
        file_exists($destination)
    ) {
        unlink($destination);
    }

    jsonResponse(
        false,
        'Unable to save image attachment.',
        [],
        500
    );
}