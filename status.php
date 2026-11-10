<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function sendJson(bool $success, string $message, array $data = [], int $statusCode = 200): void
{
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
| Request Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(false, 'Only POST requests are allowed.', [], 405);
}

/*
|--------------------------------------------------------------------------
| Business ID
|--------------------------------------------------------------------------
*/

if (!isset($_POST['business_id'])) {
    sendJson(false, 'Business id is required.', [], 422);
}

$businessId = filter_var($_POST['business_id'], FILTER_VALIDATE_INT);

if ($businessId === false || $businessId <= 0) {
    sendJson(false, 'A valid business id is required.', [], 422);
}

/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

$status = trim((string) ($_POST['status'] ?? ''));

if ($status === '') {
    sendJson(false, 'Status is required.', [], 422);
}

$allowedStatuses = ['active', 'inactive', 'suspended'];

if (!in_array($status, $allowedStatuses, true)) {
    sendJson(
        false,
        'Invalid status. Allowed values are active, inactive, and suspended.',
        [],
        422
    );
}

try {
    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Check Business Ownership
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT id
         FROM businesses
         WHERE id = :id
           AND owner_id = :owner_id
         LIMIT 1'
    );

    $stmt->execute([
        ':id' => $businessId,
        ':owner_id' => $userId
    ]);

    if (!$stmt->fetch()) {
        sendJson(
            false,
            'Business not found or you are not the owner.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Status
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'UPDATE businesses
         SET status = :status
         WHERE id = :id
           AND owner_id = :owner_id'
    );

    $stmt->execute([
        ':status' => $status,
        ':id' => $businessId,
        ':owner_id' => $userId
    ]);

    sendJson(
        true,
        'Business status updated successfully.',
        [
            'business_id' => $businessId,
            'status' => $status
        ]
    );

} catch (PDOException $e) {

    sendJson(
        false,
        'Database error.',
        [],
        500
    );
}