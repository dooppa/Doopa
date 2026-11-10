<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function sendJson(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): void {
    http_response_code($statusCode);

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['logged_in'], $_SESSION['user_id']) ||
    $_SESSION['logged_in'] !== true
) {
    sendJson(false, 'Authentication required.', [], 401);
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Request method
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(false, 'Only POST requests are allowed.', [], 405);
}

/*
|--------------------------------------------------------------------------
| Read JSON body
|--------------------------------------------------------------------------
*/
$rawInput = file_get_contents('php://input');

$input = json_decode($rawInput, true);

if (!is_array($input)) {
    sendJson(false, 'Invalid JSON input.', [], 400);
}

/*
|--------------------------------------------------------------------------
| Education ID
|--------------------------------------------------------------------------
*/
$id = trim((string) ($input['id'] ?? ''));

if ($id === '') {
    sendJson(false, 'Education id is required.', [], 422);
}

if (!ctype_digit($id)) {
    sendJson(false, 'Invalid education id.', [], 422);
}

$educationId = (int) $id;

if ($educationId <= 0) {
    sendJson(false, 'Invalid education id.', [], 422);
}

try {

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    */
    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Check ownership
    |--------------------------------------------------------------------------
    */
    $check = $pdo->prepare(
        'SELECT id
         FROM education
         WHERE id = :id
           AND user_id = :user_id
         LIMIT 1'
    );

    $check->execute([
        ':id' => $educationId,
        ':user_id' => $userId
    ]);

    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        sendJson(false, 'Education record not found.', [], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete education record
    |--------------------------------------------------------------------------
    */
    $delete = $pdo->prepare(
        'DELETE FROM education
         WHERE id = :id
           AND user_id = :user_id'
    );

    $delete->execute([
        ':id' => $educationId,
        ':user_id' => $userId
    ]);

    if ($delete->rowCount() !== 1) {
        sendJson(
            false,
            'Education record could not be deleted.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */
    sendJson(
        true,
        'Education record deleted successfully.',
        [
            'id' => $educationId
        ]
    );

} catch (PDOException $e) {
    sendJson(false, 'Database error.', [], 500);
}