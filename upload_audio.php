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
        'Audio file is required.',
        [],
        422
    );
}

$file = $_FILES['file'];

/*
|--------------------------------------------------------------------------
| Upload Error Handling
|--------------------------------------------------------------------------
*/

if ($file['error'] !== UPLOAD_ERR_OK) {

    $uploadErrors = [

        UPLOAD_ERR_INI_SIZE =>
            'The uploaded audio exceeds the server upload limit.',

        UPLOAD_ERR_FORM_SIZE =>
            'The uploaded audio exceeds the allowed form size.',

        UPLOAD_ERR_PARTIAL =>
            'The audio file was only partially uploaded.',

        UPLOAD_ERR_NO_FILE =>
            'No audio file was uploaded.',

        UPLOAD_ERR_NO_TMP_DIR =>
            'Temporary upload directory is missing.',

        UPLOAD_ERR_CANT_WRITE =>
            'Failed to write the uploaded audio file.',

        UPLOAD_ERR_EXTENSION =>
            'A server extension stopped the upload.'
    ];

    jsonResponse(
        false,
        $uploadErrors[$file['error']]
            ?? 'Unknown audio upload error.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| File Information
|--------------------------------------------------------------------------
*/

$originalName = basename(
    (string) $file['name']
);

$tmpName = (string) $file['tmp_name'];

$fileSize = (int) $file['size'];

if ($originalName === '') {
    jsonResponse(
        false,
        'Invalid audio filename.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Maximum Audio Size
|--------------------------------------------------------------------------
|
| Current limit: 20 MB
|
*/

$maxFileSize = 20 * 1024 * 1024;

if ($fileSize <= 0) {
    jsonResponse(
        false,
        'The uploaded audio file is empty.',
        [],
        422
    );
}

if ($fileSize > $maxFileSize) {
    jsonResponse(
        false,
        'Audio file is too large. Maximum allowed size is 20 MB.',
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

} catch (Throwable $e) {

    jsonResponse(
        false,
        'Database connection failed.',
        [],
        500
    );
}

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
        'Cannot attach audio to a deleted message.',
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
        'PHP Fileinfo extension is not available.',
        [],
        500
    );
}

$finfo = new finfo(FILEINFO_MIME_TYPE);

$mimeType = $finfo->file($tmpName);

if (
    $mimeType === false ||
    $mimeType === ''
) {
    jsonResponse(
        false,
        'Unable to determine audio file type.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Allowed Audio MIME Types
|--------------------------------------------------------------------------
*/

$allowedMimeTypes = [

    // MP3
    'audio/mpeg' =>
        'mp3',

    // WAV
    'audio/wav' =>
        'wav',

    'audio/x-wav' =>
        'wav',

    // M4A / MP4 audio
    'audio/mp4' =>
        'm4a',

    // AAC
    'audio/aac' =>
        'aac',

    // OGG
    'audio/ogg' =>
        'ogg',

    // WebM audio
    'audio/webm' =>
        'webm',

    // FLAC
    'audio/flac' =>
        'flac',

    'audio/x-flac' =>
        'flac'
];

/*
|--------------------------------------------------------------------------
| Validate MIME Type
|--------------------------------------------------------------------------
*/

if (!isset($allowedMimeTypes[$mimeType])) {
    jsonResponse(
        false,
        'Invalid audio format. Allowed formats are MP3, WAV, M4A, AAC, OGG, WebM and FLAC.',
        [],
        422
    );
}

$extension =
    $allowedMimeTypes[$mimeType];

/*
|--------------------------------------------------------------------------
| Upload Directory
|--------------------------------------------------------------------------
*/

$uploadDirectory =
    __DIR__ . '/../../uploads/messages/';

if (!is_dir($uploadDirectory)) {

    if (!mkdir(
        $uploadDirectory,
        0755,
        true
    )) {
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
| Check Directory Permissions
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

    $randomName =
        bin2hex(
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
    $randomName .
    '_' .
    time() .
    '.' .
    $extension;

$destination =
    $uploadDirectory .
    $fileName;

/*
|--------------------------------------------------------------------------
| Move Uploaded Audio
|--------------------------------------------------------------------------
*/

if (!move_uploaded_file(
    $tmpName,
    $destination
)) {
    jsonResponse(
        false,
        'Unable to save uploaded audio.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Relative Database Path
|--------------------------------------------------------------------------
*/

$relativePath =
    'uploads/messages/' .
    $fileName;

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
        VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            'audio'
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
    | Remove Physical File If Database Insert Fails
    |--------------------------------------------------------------------------
    */

    if (is_file($destination)) {
        unlink($destination);
    }

    jsonResponse(
        false,
        'Failed to save audio attachment.',
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
    'Audio uploaded successfully.',
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
                'audio'
        ]
    ],
    201
);