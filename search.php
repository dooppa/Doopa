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
| Search Query
|--------------------------------------------------------------------------
*/

$query = trim((string) ($_GET['q'] ?? ''));

if ($query === '') {
    sendJson(false, 'Search query is required.', [], 422);
}

try {
    $database = new Database();
    $pdo = $database->connect();

    $search = '%' . $query . '%';

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
           AND (
                name LIKE :search_name
                OR description LIKE :search_description
                OR category LIKE :search_category
                OR address LIKE :search_address
           )
         ORDER BY id DESC'
    );

    $stmt->execute([
        ':search_name' => $search,
        ':search_description' => $search,
        ':search_category' => $search,
        ':search_address' => $search
    ]);

    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendJson(
        true,
        'Businesses found successfully.',
        [
            'count' => count($businesses),
            'query' => $query,
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