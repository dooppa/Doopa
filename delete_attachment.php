<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - DELETE MESSAGE ATTACHMENT
|--------------------------------------------------------------------------
| Batch 8I - Attachment Deletion
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

session_start();

/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/
function sendJson(
    bool $success,
    string $message,
    mixed $data = []
): never {
    http_response_code($success ? 200 : 400);

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| ERROR HANDLING
|--------------------------------------------------------------------------
*/
set_error_handler(
    function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        throw new ErrorException(
            $message,
            0,
            $severity,
            $file,
            $line
        );
    }
);

register_shutdown_function(function (): void {
    $error = error_get_last();

    if ($error !== null && in_array(
        $error['type'],
        [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE],
        true
    )) {
        http_response_code(500);

        echo json_encode([
            'success' => false,
            'message' => 'A server error occurred.',
            'data'    => []
        ]);
    }
});

/*
|--------------------------------------------------------------------------
| LOAD CONFIGURATION
|--------------------------------------------------------------------------
*/
try {
    require_once __DIR__ . '/../../config/config.php';
    require_once __DIR__ . '/../../config/database.php';

    /*
    |--------------------------------------------------------------------------
    | REQUEST METHOD
    |--------------------------------------------------------------------------
    */
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        sendJson(
            false,
            'Only DELETE requests are allowed.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AUTHENTICATION
    |--------------------------------------------------------------------------
    */
    if (
        empty($_SESSION['logged_in']) ||
        $_SESSION['logged_in'] !== true ||
        empty($_SESSION['user_id'])
    ) {
        sendJson(
            false,
            'Authentication required.'
        );
    }

    $currentUserId = (int) $_SESSION['user_id'];

    /*
    |--------------------------------------------------------------------------
    | ATTACHMENT ID
    |--------------------------------------------------------------------------
    |
    | Supports:
    | DELETE /delete_attachment.php?attachment_id=6
    |
    |--------------------------------------------------------------------------
    */
    $attachmentId = filter_input(
        INPUT_GET,
        'attachment_id',
        FILTER_VALIDATE_INT
    );

    if (!$attachmentId || $attachmentId <= 0) {
        sendJson(
            false,
            'A valid attachment_id is required.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DATABASE CONNECTION
    |--------------------------------------------------------------------------
    */
    $database = new Database();
    $pdo = $database->connect();

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    /*
    |--------------------------------------------------------------------------
    | GET ATTACHMENT + MESSAGE + OWNER
    |--------------------------------------------------------------------------
    |
    | The authenticated user must be the sender of the message.
    |
    |--------------------------------------------------------------------------
    */
    $sql = "
        SELECT
            ma.id,
            ma.message_id,
            ma.file_name,
            ma.original_name,
            ma.file_path,
            ma.mime_type,
            ma.file_size,
            ma.attachment_type,
            ma.created_at,
            m.conversation_id,
            m.sender_id,
            m.is_deleted
        FROM message_attachments ma
        INNER JOIN messages m
            ON m.id = ma.message_id
        WHERE ma.id = :attachment_id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':attachment_id' => $attachmentId
    ]);

    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | ATTACHMENT NOT FOUND
    |--------------------------------------------------------------------------
    */
    if (!$attachment) {
        sendJson(
            false,
            'Attachment not found.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AUTHORISATION
    |--------------------------------------------------------------------------
    |
    | Only the user who sent the message may delete its attachment.
    |
    |--------------------------------------------------------------------------
    */
    if ((int) $attachment['sender_id'] !== $currentUserId) {
        sendJson(
            false,
            'You are not authorised to delete this attachment.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TRANSACTION
    |--------------------------------------------------------------------------
    */
    $pdo->beginTransaction();

    try {
        /*
        |--------------------------------------------------------------------------
        | PHYSICAL FILE PATH
        |--------------------------------------------------------------------------
        |
        | file_path is stored relative to the DOOPA project root.
        |
        |--------------------------------------------------------------------------
        */
        $relativePath = ltrim(
            (string) $attachment['file_path'],
            '/\\'
        );

        $projectRoot = dirname(__DIR__, 2);

        $physicalPath = $projectRoot
            . DIRECTORY_SEPARATOR
            . str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                $relativePath
            );

        /*
        |--------------------------------------------------------------------------
        | DELETE DATABASE RECORD
        |--------------------------------------------------------------------------
        */
        $deleteStmt = $pdo->prepare("
            DELETE FROM message_attachments
            WHERE id = :attachment_id
            LIMIT 1
        ");

        $deleteStmt->execute([
            ':attachment_id' => $attachmentId
        ]);

        if ($deleteStmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Attachment database record could not be deleted.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | DELETE PHYSICAL FILE
        |--------------------------------------------------------------------------
        */
        $fileDeleted = false;
        $fileAlreadyMissing = false;

        if (is_file($physicalPath)) {
            if (!unlink($physicalPath)) {
                throw new RuntimeException(
                    'The attachment database record was found, but the physical file could not be deleted.'
                );
            }

            $fileDeleted = true;
        } else {
            /*
            |--------------------------------------------------------------------------
            | File is already missing.
            |--------------------------------------------------------------------------
            */
            $fileAlreadyMissing = true;
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE MESSAGE TYPE
        |--------------------------------------------------------------------------
        |
        | If the message no longer has attachments, convert an attachment
        | message back to text if appropriate.
        |
        |--------------------------------------------------------------------------
        */
        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM message_attachments
            WHERE message_id = :message_id
        ");

        $countStmt->execute([
            ':message_id' => (int) $attachment['message_id']
        ]);

        $remainingAttachments = (int) $countStmt->fetchColumn();

        /*
        |--------------------------------------------------------------------------
        | Commit
        |--------------------------------------------------------------------------
        */
        $pdo->commit();

        /*
        |--------------------------------------------------------------------------
        | SUCCESS RESPONSE
        |--------------------------------------------------------------------------
        */
        sendJson(
            true,
            'Attachment deleted successfully.',
            [
                'attachment_id'       => (int) $attachment['id'],
                'message_id'          => (int) $attachment['message_id'],
                'original_name'       => $attachment['original_name'],
                'attachment_type'     => $attachment['attachment_type'],
                'file_deleted'        => $fileDeleted,
                'file_already_missing'=> $fileAlreadyMissing,
                'remaining_attachments' => $remainingAttachments
            ]
        );

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

} catch (Throwable $e) {

    error_log(
        'DOOPA delete attachment error: ' .
        $e->getMessage()
    );

    sendJson(
        false,
        'Unable to delete attachment.',
        []
    );
}