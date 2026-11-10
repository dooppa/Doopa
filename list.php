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
| Pagination
|--------------------------------------------------------------------------
| Default:
| page  = 1
| limit = 20
|
| Maximum limit = 100
|--------------------------------------------------------------------------
*/

$page = 1;
$limit = 20;

/*
|--------------------------------------------------------------------------
| Validate page
|--------------------------------------------------------------------------
*/
if (isset($_GET['page']) && is_string($_GET['page'])) {
    $validatedPage = filter_var(
        $_GET['page'],
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );

    if ($validatedPage !== false) {
        $page = $validatedPage;
    }
}

/*
|--------------------------------------------------------------------------
| Validate limit
|--------------------------------------------------------------------------
*/
if (isset($_GET['limit']) && is_string($_GET['limit'])) {
    $validatedLimit = filter_var(
        $_GET['limit'],
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
                'max_range' => 100
            ]
        ]
    );

    if ($validatedLimit !== false) {
        $limit = $validatedLimit;
    }
}

/*
|--------------------------------------------------------------------------
| Calculate offset
|--------------------------------------------------------------------------
*/
$offset = ($page - 1) * $limit;

try {
    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Get total number of education records
    |--------------------------------------------------------------------------
    */
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM education
         WHERE user_id = :user_id'
    );

    $countStmt->execute([
        ':user_id' => $userId
    ]);

    $totalRecords = (int) $countStmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | Get paginated education records
    |--------------------------------------------------------------------------
    |
    | $limit and $offset have already been validated as integers,
    | so they are safe to use in the SQL statement.
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
        WHERE user_id = :user_id
        ORDER BY start_date DESC, id DESC
        LIMIT ' . $limit . ' OFFSET ' . $offset
    );

    $stmt->execute([
        ':user_id' => $userId
    ]);

    $education = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Calculate total pages
    |--------------------------------------------------------------------------
    */
    $totalPages = $limit > 0
        ? (int) ceil($totalRecords / $limit)
        : 0;

    sendJson(
        true,
        'Education records retrieved successfully.',
        [
            'count' => count($education),
            'total_records' => $totalRecords,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $totalPages,
            'education' => $education
        ]
    );

} catch (PDOException $e) {
    sendJson(false, 'Database error.', [], 500);
}