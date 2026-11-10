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

/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(false, 'Only GET requests are allowed.', [], 405);
}

/*
|--------------------------------------------------------------------------
| Business ID
|--------------------------------------------------------------------------
*/

if (!isset($_GET['id'])) {
    sendJson(false, 'Business id is required.', [], 422);
}

$businessId = filter_var($_GET['id'], FILTER_VALIDATE_INT);

if ($businessId === false || $businessId <= 0) {
    sendJson(false, 'A valid business id is required.', [], 422);
}

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
    | Get Active Business
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT
            id,
            owner_id,
            name,
            slug,
            description,
            category,
            phone,
            email,
            website,
            address,
            logo_url,
            status,
            created_at,
            updated_at
         FROM businesses
         WHERE id = :id
           AND status = "active"
         LIMIT 1'
    );

    $stmt->execute([
        ':id' => $businessId
    ]);

    $business = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$business) {
        sendJson(false, 'Business not found.', [], 404);
    }

    sendJson(
        true,
        'Business retrieved successfully.',
        [
            'business' => $business
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