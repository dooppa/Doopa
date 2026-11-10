<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - SEND MESSAGE WITH OPTIONAL ATTACHMENT
|--------------------------------------------------------------------------
| Batch 8H - Message + Attachment Integration
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

/*
|--------------------------------------------------------------------------
| JSON RESPONSE FUNCTION
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
| PHP FATAL ERROR HANDLER
|--------------------------------------------------------------------------
*/

register_shutdown_function(function (): void {
    $error = error_get_last();

    if ($error === null) {
        return;
    }

    $fatalTypes = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR
    ];

    if (in_array($error['type'], $fatalTypes, true)) {
        error_log(
            'DOOPA send_with_attachment FATAL: ' .
            $error['message'] .
            ' | File: ' . $error['file'] .
            ' | Line: ' . $error['line']
        );

        /*
         * Do not expose internal PHP paths or messages.
         * A fatal error may occur after output has started.
         */
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');

            echo json_encode([
                'success' => false,
                'message' => 'A server error occurred.',
                'data' => []
            ]);
        }
    }
});

/*
|--------------------------------------------------------------------------
| PHP ERROR HANDLER
|--------------------------------------------------------------------------
*/

set_error_handler(
    function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        /*
         * Log the underlying error without exposing it to clients.
         */
        error_log(
            'DOOPA PHP ERROR: ' . $message .
            ' | File: ' . $file .
            ' | Line: ' . $line
        );

        /*
         * Let PHP handle notices and warnings normally.
         * They remain hidden because display_errors is disabled.
         */
        return false;
    }
);

/*
|--------------------------------------------------------------------------
| LOAD CONFIGURATION
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/upload_security.php';

/*
|--------------------------------------------------------------------------
| REQUEST METHOD
|--------------------------------------------------------------------------
*/

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sendJson(
        false,
        'Only POST requests are allowed.',
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
| INPUT
|--------------------------------------------------------------------------
*/

$conversationId = filter_input(
    INPUT_POST,
    'conversation_id',
    FILTER_VALIDATE_INT
);

if (
    $conversationId === false ||
    $conversationId === null ||
    $conversationId <= 0
) {
    sendJson(
        false,
        'A valid conversation_id is required.',
        [],
        400
    );
}

$conversationId = (int) $conversationId;

$messageText = trim(
    (string) ($_POST['message'] ?? '')
);

/*
|--------------------------------------------------------------------------
| ALLOWED MIME TYPES
|--------------------------------------------------------------------------
*/

$allowedMimeTypes = [
    // Images
    'image/jpeg' => [
        'extension' => 'jpg',
        'type' => 'image'
    ],
    'image/png' => [
        'extension' => 'png',
        'type' => 'image'
    ],
    'image/gif' => [
        'extension' => 'gif',
        'type' => 'image'
    ],
    'image/webp' => [
        'extension' => 'webp',
        'type' => 'image'
    ],

    // Videos
    'video/mp4' => [
        'extension' => 'mp4',
        'type' => 'video'
    ],
    'video/webm' => [
        'extension' => 'webm',
        'type' => 'video'
    ],
    'video/quicktime' => [
        'extension' => 'mov',
        'type' => 'video'
    ],
    'video/x-msvideo' => [
        'extension' => 'avi',
        'type' => 'video'
    ],
    'video/x-matroska' => [
        'extension' => 'mkv',
        'type' => 'video'
    ],

    // Documents
    'application/pdf' => [
        'extension' => 'pdf',
        'type' => 'file'
    ],
    'application/msword' => [
        'extension' => 'doc',
        'type' => 'file'
    ],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => [
        'extension' => 'docx',
        'type' => 'file'
    ],
    'application/vnd.ms-excel' => [
        'extension' => 'xls',
        'type' => 'file'
    ],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => [
        'extension' => 'xlsx',
        'type' => 'file'
    ],
    'application/vnd.ms-powerpoint' => [
        'extension' => 'ppt',
        'type' => 'file'
    ],
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => [
        'extension' => 'pptx',
        'type' => 'file'
    ],
    'text/plain' => [
        'extension' => 'txt',
        'type' => 'file'
    ],
    'text/csv' => [
        'extension' => 'csv',
        'type' => 'file'
    ],
    'application/zip' => [
        'extension' => 'zip',
        'type' => 'file'
    ],
    'application/vnd.rar' => [
        'extension' => 'rar',
        'type' => 'file'
    ],
    'application/x-7z-compressed' => [
        'extension' => '7z',
        'type' => 'file'
    ],

    // Audio
    'audio/mpeg' => [
        'extension' => 'mp3',
        'type' => 'audio'
    ],
    'audio/wav' => [
        'extension' => 'wav',
        'type' => 'audio'
    ],
    'audio/x-wav' => [
        'extension' => 'wav',
        'type' => 'audio'
    ],
    'audio/mp4' => [
        'extension' => 'm4a',
        'type' => 'audio'
    ],
    'audio/aac' => [
        'extension' => 'aac',
        'type' => 'audio'
    ],
    'audio/ogg' => [
        'extension' => 'ogg',
        'type' => 'audio'
    ],
    'audio/webm' => [
        'extension' => 'webm',
        'type' => 'audio'
    ],
    'audio/flac' => [
        'extension' => 'flac',
        'type' => 'audio'
    ],
    'audio/x-flac' => [
        'extension' => 'flac',
        'type' => 'audio'
    ]
];

/*
|--------------------------------------------------------------------------
| MAXIMUM FILE SIZE: 50 MiB
|--------------------------------------------------------------------------
*/

$maxFileSize = 50 * 1024 * 1024;

/*
|--------------------------------------------------------------------------
| FILE DETECTION
|--------------------------------------------------------------------------
*/

$hasFile = false;

if (
    isset($_FILES['file']) &&
    is_array($_FILES['file']) &&
    isset($_FILES['file']['name']) &&
    trim((string) $_FILES['file']['name']) !== ''
) {
    $hasFile = true;
}

/*
|--------------------------------------------------------------------------
| MESSAGE OR ATTACHMENT REQUIRED
|--------------------------------------------------------------------------
*/

if ($messageText === '' && !$hasFile) {
    sendJson(
        false,
        'A message or attachment is required.',
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| WORKING VARIABLES
|--------------------------------------------------------------------------
*/

$db = null;
$physicalFilePath = null;
$messageId = 0;
$validatedFile = null;

/*
|--------------------------------------------------------------------------
| MAIN REQUEST
|--------------------------------------------------------------------------
*/

try {
    /*
    |--------------------------------------------------------------------------
    | VALIDATE UPLOADED FILE
    |--------------------------------------------------------------------------
    */

    if ($hasFile) {
        $uploadError = $_FILES['file']['error'] ?? null;

        if (
            !is_int($uploadError) ||
            $uploadError !== UPLOAD_ERR_OK
        ) {
            if (
                $uploadError === UPLOAD_ERR_INI_SIZE ||
                $uploadError === UPLOAD_ERR_FORM_SIZE
            ) {
                sendJson(
                    false,
                    'The uploaded file exceeds the allowed size limit.',
                    [],
                    413
                );
            }

            sendJson(
                false,
                'The file upload failed. Please try again.',
                [],
                400
            );
        }

        $uploadedSize = $_FILES['file']['size'] ?? null;

        if (
            !is_int($uploadedSize) ||
            $uploadedSize < 0
        ) {
            sendJson(
                false,
                'The uploaded file size could not be verified.',
                [],
                400
            );
        }

        if ($uploadedSize > $maxFileSize) {
            sendJson(
                false,
                'This file is larger than 50 MiB. Please choose a smaller file.',
                [],
                413
            );
        }

        if (!function_exists('validateUploadedFile')) {
            throw new RuntimeException(
                'Upload security function is not available.'
            );
        }

        $validation = validateUploadedFile(
            $_FILES['file'],
            $allowedMimeTypes,
            $maxFileSize
        );

        if (!is_array($validation)) {
            throw new RuntimeException(
                'Invalid response from upload validation.'
            );
        }

        if (
            !isset($validation['success']) ||
            $validation['success'] !== true
        ) {
            sendJson(
                false,
                (string) (
                    $validation['message'] ??
                    'File validation failed.'
                ),
                [],
                400
            );
        }

        if (
            !isset($validation['data']) ||
            !is_array($validation['data'])
        ) {
            throw new RuntimeException(
                'File validation returned invalid data.'
            );
        }

        $validatedFile = $validation['data'];
    }

    /*
    |--------------------------------------------------------------------------
    | DEFINE MESSAGE TYPE
    |--------------------------------------------------------------------------
    | This fixes the original Undefined variable $messageType error.
    |--------------------------------------------------------------------------
    */

    $messageType = 'text';

    if ($validatedFile !== null) {
        $validatedMimeType = (string) (
            $validatedFile['mime_type'] ?? ''
        );

        $validatedType = (string) (
            $validatedFile['type'] ?? ''
        );

        $allowedMessageTypes = [
            'image',
            'video',
            'audio',
            'file'
        ];

        if (in_array($validatedType, $allowedMessageTypes, true)) {
            $messageType = $validatedType;
        } elseif (
            isset($allowedMimeTypes[$validatedMimeType]['type'])
        ) {
            $messageType = $allowedMimeTypes[$validatedMimeType]['type'];
        } else {
            throw new RuntimeException(
                'Could not determine the attachment type.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DATABASE CONNECTION
    |--------------------------------------------------------------------------
    */

    $database = new Database();
    $db = $database->connect();

    if (!$db instanceof PDO) {
        throw new RuntimeException(
            'Database connection was not established.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY CONVERSATION
    |--------------------------------------------------------------------------
    */

    $conversationStmt = $db->prepare("
        SELECT id, type, name
        FROM conversations
        WHERE id = :conversation_id
        LIMIT 1
    ");

    $conversationStmt->execute([
        ':conversation_id' => $conversationId
    ]);

    $conversation = $conversationStmt->fetch(PDO::FETCH_ASSOC);

    if (!$conversation) {
        sendJson(
            false,
            'Conversation not found.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY CONVERSATION PARTICIPANT
    |--------------------------------------------------------------------------
    */

    $participantStmt = $db->prepare("
        SELECT id, conversation_id, user_id
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
        sendJson(
            false,
            'You are not a participant in this conversation.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIND OTHER PARTICIPANT
    |--------------------------------------------------------------------------
    */

    $otherStmt = $db->prepare("
        SELECT
            cp.user_id,
            u.full_name,
            u.username,
            u.avatar_url
        FROM conversation_participants cp
        INNER JOIN users u ON u.id = cp.user_id
        WHERE cp.conversation_id = :conversation_id
          AND cp.user_id != :user_id
        LIMIT 1
    ");

    $otherStmt->execute([
        ':conversation_id' => $conversationId,
        ':user_id' => $userId
    ]);

    $otherUser = $otherStmt->fetch(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | START DATABASE TRANSACTION
    |--------------------------------------------------------------------------
    */

    $db->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | CREATE MESSAGE
    |--------------------------------------------------------------------------
    */

    $messageStmt = $db->prepare("
        INSERT INTO messages (
            conversation_id,
            sender_id,
            message,
            message_type
        )
        VALUES (
            :conversation_id,
            :sender_id,
            :message,
            :message_type
        )
    ");

    $messageStmt->execute([
        ':conversation_id' => $conversationId,
        ':sender_id' => $userId,
        ':message' => $messageText,
        ':message_type' => $messageType
    ]);

    $messageId = (int) $db->lastInsertId();

    if ($messageId <= 0) {
        throw new RuntimeException(
            'Message was not created.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE ATTACHMENT
    |--------------------------------------------------------------------------
    */

    $attachment = null;

    if ($validatedFile !== null) {
        $uploadDirectory = __DIR__ . '/../../uploads/messages/';

        if (!is_dir($uploadDirectory)) {
            if (!mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                throw new RuntimeException(
                    'Unable to create upload directory.'
                );
            }
        }

        if (!is_writable($uploadDirectory)) {
            throw new RuntimeException(
                'Upload directory is not writable.'
            );
        }

        $secureFileName = (string) (
            $validatedFile['file_name'] ?? ''
        );

        if (
            $secureFileName === '' ||
            basename($secureFileName) !== $secureFileName ||
            !preg_match('/\A[A-Za-z0-9._-]+\z/', $secureFileName)
        ) {
            throw new RuntimeException(
                'Secure file name is invalid.'
            );
        }

        $physicalFilePath = $uploadDirectory . $secureFileName;

        if (
            !isset($_FILES['file']['tmp_name']) ||
            !is_string($_FILES['file']['tmp_name']) ||
            $_FILES['file']['tmp_name'] === ''
        ) {
            throw new RuntimeException(
                'Uploaded file temporary path is missing.'
            );
        }

        if (!is_uploaded_file($_FILES['file']['tmp_name'])) {
            throw new RuntimeException(
                'Uploaded file is invalid.'
            );
        }

        if (
            !move_uploaded_file(
                $_FILES['file']['tmp_name'],
                $physicalFilePath
            )
        ) {
            throw new RuntimeException(
                'Failed to save uploaded file.'
            );
        }

        $relativePath = 'uploads/messages/' . $secureFileName;

        /*
        |--------------------------------------------------------------------------
        | INSERT ATTACHMENT DATABASE RECORD
        |--------------------------------------------------------------------------
        */

        $attachmentStmt = $db->prepare("
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
                :message_id,
                :file_name,
                :original_name,
                :file_path,
                :mime_type,
                :file_size,
                :attachment_type
            )
        ");

        $attachmentStmt->execute([
            ':message_id' => $messageId,
            ':file_name' => $secureFileName,
            ':original_name' => (string) (
                $validatedFile['original_name'] ?? 'file'
            ),
            ':file_path' => $relativePath,
            ':mime_type' => (string) (
                $validatedFile['mime_type'] ?? 'application/octet-stream'
            ),
            ':file_size' => (int) (
                $validatedFile['file_size'] ?? 0
            ),
            ':attachment_type' => $messageType
        ]);

        $attachmentId = (int) $db->lastInsertId();

        if ($attachmentId <= 0) {
            throw new RuntimeException(
                'Attachment record was not created.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ATTACHMENT RESPONSE - PROTECTED URL
        |--------------------------------------------------------------------------
        */

        $attachment = [
            'id' => $attachmentId,
            'message_id' => $messageId,
            'file_name' => $secureFileName,
            'original_name' => (string) (
                $validatedFile['original_name'] ?? 'file'
            ),
            'file_path' => $relativePath,
            'mime_type' => (string) (
                $validatedFile['mime_type'] ?? 'application/octet-stream'
            ),
            'file_size' => (int) (
                $validatedFile['file_size'] ?? 0
            ),
            'attachment_type' => $messageType,
            'file_url' =>
                '/login%20template/doopa/api/messages/attachment_file.php?id=' .
                rawurlencode((string) $attachmentId)
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE CONVERSATION
    |--------------------------------------------------------------------------
    */

    $updateConversation = $db->prepare("
        UPDATE conversations
        SET updated_at = CURRENT_TIMESTAMP
        WHERE id = :conversation_id
    ");

    $updateConversation->execute([
        ':conversation_id' => $conversationId
    ]);

    /*
    |--------------------------------------------------------------------------
    | CREATE MESSAGE NOTIFICATION
    |--------------------------------------------------------------------------
    */

    $notification = null;

    if ($otherUser && isset($otherUser['user_id'])) {
        $notificationMessage = $validatedFile !== null
            ? 'sent you a ' . $messageType
            : 'sent you a message';

        $notificationStmt = $db->prepare("
            INSERT INTO notifications (
                user_id,
                actor_id,
                type,
                reference_id,
                message,
                is_read
            )
            VALUES (
                :user_id,
                :actor_id,
                :type,
                :reference_id,
                :message,
                :is_read
            )
        ");

        $notificationStmt->execute([
            ':user_id' => (int) $otherUser['user_id'],
            ':actor_id' => $userId,
            ':type' => 'message',
            ':reference_id' => $messageId,
            ':message' => $notificationMessage,
            ':is_read' => 0
        ]);

        $notificationId = (int) $db->lastInsertId();

        if ($notificationId <= 0) {
            throw new RuntimeException(
                'Notification was not created.'
            );
        }

        $notification = [
            'id' => $notificationId,
            'user_id' => (int) $otherUser['user_id'],
            'actor_id' => $userId,
            'type' => 'message',
            'reference_id' => $messageId,
            'message' => $notificationMessage,
            'is_read' => false
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | COMMIT TRANSACTION
    |--------------------------------------------------------------------------
    */

    $db->commit();

    /*
    |--------------------------------------------------------------------------
    | SUCCESS RESPONSE
    |--------------------------------------------------------------------------
    */

    sendJson(
        true,
        $validatedFile !== null
            ? 'Message and attachment sent successfully.'
            : 'Message sent successfully.',
        [
            'message' => [
                'id' => $messageId,
                'conversation_id' => $conversationId,
                'sender_id' => $userId,
                'message' => $messageText,
                'message_type' => $messageType,
                'is_deleted' => 0
            ],
            'attachment' => $attachment,
            'notification' => $notification
        ],
        201
    );

} catch (Throwable $e) {
    /*
    |--------------------------------------------------------------------------
    | ROLLBACK
    |--------------------------------------------------------------------------
    */

    if ($db instanceof PDO && $db->inTransaction()) {
        try {
            $db->rollBack();
        } catch (Throwable $rollbackError) {
            error_log(
                'DOOPA rollback ERROR: ' .
                $rollbackError->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | REMOVE PHYSICAL FILE IF REQUEST FAILED
    |--------------------------------------------------------------------------
    */

    if (
        $physicalFilePath !== null &&
        is_file($physicalFilePath)
    ) {
        @unlink($physicalFilePath);
    }

    /*
    |--------------------------------------------------------------------------
    | LOG INTERNAL ERROR
    |--------------------------------------------------------------------------
    */

    error_log(
        'DOOPA send_with_attachment ERROR: ' .
        $e->getMessage() .
        ' | File: ' . $e->getFile() .
        ' | Line: ' . $e->getLine()
    );

    /*
    |--------------------------------------------------------------------------
    | SAFE JSON ERROR RESPONSE
    |--------------------------------------------------------------------------
    */

    sendJson(
        false,
        'Unable to send message with attachment.',
        [],
        500
    );
}

