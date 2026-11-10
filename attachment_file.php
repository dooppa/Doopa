<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT) ||
    (int) $_SESSION['user_id'] <= 0
) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Authentication required.');
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| METHOD CHECK
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Only GET requests are allowed.');
}

/*
|--------------------------------------------------------------------------
| VALIDATE ATTACHMENT ID
|--------------------------------------------------------------------------
*/

$attachmentId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if ($attachmentId === false || $attachmentId === null || $attachmentId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('A valid attachment id is required.');
}

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

try {
    $database = new Database();
    $db = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | FIND ATTACHMENT AND VERIFY CONVERSATION MEMBERSHIP
    |--------------------------------------------------------------------------
    */

    $stmt = $db->prepare("
        SELECT
            a.id,
            a.message_id,
            a.file_name,
            a.original_name,
            a.file_path,
            a.mime_type,
            a.file_size,
            m.conversation_id
        FROM message_attachments a
        INNER JOIN messages m
            ON m.id = a.message_id
        INNER JOIN conversation_participants cp
            ON cp.conversation_id = m.conversation_id
           AND cp.user_id = :user_id
        WHERE a.id = :attachment_id
          AND m.is_deleted = 0
        LIMIT 1
    ");

    $stmt->execute([
        ':user_id' => $userId,
        ':attachment_id' => $attachmentId
    ]);

    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Attachment not found.');
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE STORED FILE PATH
    |--------------------------------------------------------------------------
    */

    $relativePath = str_replace(
        '\\',
        '/',
        (string) $attachment['file_path']
    );

    // Accept only the expected relative uploads/messages location.
    if (
        str_contains($relativePath, "\0") ||
        str_contains($relativePath, '..') ||
        str_starts_with($relativePath, '/') ||
        !preg_match(
            '#^uploads/messages/[A-Za-z0-9._-]+$#D',
            $relativePath
        )
    ) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Attachment not found.');
    }

    $uploadDirectory = realpath(
        __DIR__ . '/../../uploads/messages'
    );

    $filePath = realpath(
        __DIR__ . '/../../' . $relativePath
    );

    if (
        $uploadDirectory === false ||
        $filePath === false ||
        !is_file($filePath) ||
        !is_readable($filePath)
    ) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Attachment not found.');
    }

    $directoryPrefix = rtrim(
        $uploadDirectory,
        DIRECTORY_SEPARATOR
    ) . DIRECTORY_SEPARATOR;

    if (!str_starts_with($filePath, $directoryPrefix)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Attachment not found.');
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY FILE SIZE
    |--------------------------------------------------------------------------
    */

    $fileSize = filesize($filePath);

    if (
        $fileSize === false ||
        $fileSize < 0 ||
        $fileSize !== (int) $attachment['file_size']
    ) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Attachment not found.');
    }

    /*
    |--------------------------------------------------------------------------
    | SAFE RESPONSE HEADERS
    |--------------------------------------------------------------------------
    */

    $mimeType = strtolower(
        trim((string) $attachment['mime_type'])
    );

    // Never allow a stored database value to inject response headers.
   if (
    !preg_match(
        '~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~D',
        $mimeType
    )
) {
    $mimeType = 'application/octet-stream';
}

    $originalName = basename(
        str_replace(
            '\\',
            '/',
            (string) $attachment['original_name']
        )
    );

    $originalName = preg_replace(
        '/[\x00-\x1F\x7F"\\\\\/]/',
        '_',
        $originalName
    );

    if (!is_string($originalName) || $originalName === '') {
        $originalName = 'attachment';
    }

    $asciiName = preg_replace(
        '/[^A-Za-z0-9._-]/',
        '_',
        $originalName
    );

    if (!is_string($asciiName) || $asciiName === '') {
        $asciiName = 'attachment';
    }

    $disposition = in_array(
        $mimeType,
        [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'video/mp4',
            'audio/mpeg',
            'audio/mp3',
            'audio/ogg',
            'audio/wav',
            'audio/webm',
            'application/pdf'
        ],
        true
    ) ? 'inline' : 'attachment';

    header('Content-Type: ' . $mimeType);
    header(
        'Content-Disposition: ' . $disposition .
        '; filename="' . $asciiName . '"' .
        "; filename*=UTF-8''" .
        rawurlencode($originalName)
    );
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Accept-Ranges: bytes');

    /*
    |--------------------------------------------------------------------------
    | SUPPORT HTTP RANGE REQUESTS FOR AUDIO AND VIDEO
    |--------------------------------------------------------------------------
    */

    $start = 0;
    $end = $fileSize > 0 ? $fileSize - 1 : 0;
    $statusCode = 200;

    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';

    if ($rangeHeader !== '' && $fileSize > 0) {
        // Support one byte range. Multiple ranges are not supported.
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', trim($rangeHeader), $matches)) {
            header('Content-Range: bytes */' . $fileSize);
            http_response_code(416);
            exit;
        }

        $rangeStart = $matches[1];
        $rangeEnd = $matches[2];

        if ($rangeStart === '' && $rangeEnd === '') {
            header('Content-Range: bytes */' . $fileSize);
            http_response_code(416);
            exit;
        }

        if ($rangeStart === '') {
            $suffixLength = (int) $rangeEnd;

            if ($suffixLength <= 0) {
                header('Content-Range: bytes */' . $fileSize);
                http_response_code(416);
                exit;
            }

            $start = max(0, $fileSize - $suffixLength);
        } else {
            $start = (int) $rangeStart;

            if ($start >= $fileSize) {
                header('Content-Range: bytes */' . $fileSize);
                http_response_code(416);
                exit;
            }

            if ($rangeEnd !== '') {
                $end = min((int) $rangeEnd, $fileSize - 1);
            }
        }

        if ($end < $start) {
            header('Content-Range: bytes */' . $fileSize);
            http_response_code(416);
            exit;
        }

        $statusCode = 206;
    }

    $length = $fileSize > 0 ? $end - $start + 1 : 0;

    http_response_code($statusCode);
    header('Content-Length: ' . $length);

    if ($statusCode === 206) {
        header(
            'Content-Range: bytes ' .
            $start . '-' . $end . '/' . $fileSize
        );
    }

    /*
    |--------------------------------------------------------------------------
    | STREAM FILE
    |--------------------------------------------------------------------------
    */

    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        exit;
    }

    $handle = fopen($filePath, 'rb');

    if ($handle === false) {
        // Headers have already been sent; log the failure without exposing paths.
        error_log('Doopa: unable to open an attachment for streaming.');
        exit;
    }

    try {
        if ($start > 0 && fseek($handle, $start) !== 0) {
            http_response_code(500);
            exit;
        }

        $remaining = $length;
        $chunkSize = 1024 * 1024;

        while ($remaining > 0 && !feof($handle)) {
            $readLength = min($chunkSize, $remaining);
            $buffer = fread($handle, $readLength);

            if ($buffer === false || $buffer === '') {
                break;
            }

            echo $buffer;
            $remaining -= strlen($buffer);

            if (connection_aborted()) {
                break;
            }
        }
    } finally {
        fclose($handle);
    }

} catch (Throwable $e) {
    error_log('Doopa attachment delivery error: ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Unable to retrieve attachment.');
    }
}
