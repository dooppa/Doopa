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
| Category
|--------------------------------------------------------------------------
*/

$category = trim((string) ($_GET['category'] ?? ''));

if ($category === '') {
    sendJson(false, 'Category is required.', [], 422);
}

try {
    $database = new Database();
    $pdo = $database->connect();

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
         WHERE status = "active"
           AND category = :category
         ORDER BY id DESC'
    );

    $stmt->execute([
        ':category' => $category
    ]);

    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendJson(
        true,
        'Businesses retrieved successfully.',
        [
            'count' => count($businesses),
            'category' => $category,
            'businesses' => $businesses
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