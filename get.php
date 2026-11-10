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
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(false, 'Only GET requests are allowed.', [], 405);
}

/*
|--------------------------------------------------------------------------
| Education ID
|--------------------------------------------------------------------------
|
| Accepts:
| 52
| 000052
|
| Rejects:
| empty
| abc
| 0
| -1
| 52.5
|
|--------------------------------------------------------------------------
*/
$id = trim((string) ($_GET['id'] ?? ''));

if ($id === '' || !ctype_digit($id)) {
    sendJson(false, 'Education id is required.', [], 422);
}

$educationId = (int) $id;

if ($educationId <= 0) {
    sendJson(false, 'Education id is required.', [], 422);
}

try {
    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Get education record belonging to logged-in user
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare(
        'SELECT
            id,
            user_id,
            institution,
            qualification,
            field_of_study,
            start_date,
            end_date,
            description,
            created_at,
            updated_at
        FROM education
        WHERE id = :id
          AND user_id = :user_id
        LIMIT 1'
    );

    $stmt->execute([
        ':id' => $educationId,
        ':user_id' => $userId
    ]);

    $education = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$education) {
        sendJson(false, 'Education record not found.', [], 404);
    }

    sendJson(
        true,
        'Education retrieved successfully.',
        $education
    );

} catch (PDOException $e) {
    sendJson(false, 'Database error.', [], 500);
}