<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');

/*
|--------------------------------------------------------------------------
| Only allow GET requests
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only GET requests are allowed.',
        'data' => []
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;

if ($page < 1) {
    $page = 1;
}

if ($limit < 1) {
    $limit = 20;
}

if ($limit > 50) {
    $limit = 50;
}

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$q = trim($_GET['q'] ?? '');

if (mb_strlen($q) > 50) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Search query must not exceed 50 characters.',
        'data' => []
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Retrieve posts
|--------------------------------------------------------------------------
*/

try {

    $where = [];
    $params = [];

    /*
    |--------------------------------------------------------------------------
    | Search by content, username, or full name
    |--------------------------------------------------------------------------
    */

    if ($q !== '') {

        $where[] = "
            (
                p.content LIKE :content
                OR u.username LIKE :username
                OR u.full_name LIKE :full_name
            )
        ";

        $search = '%' . $q . '%';

        $params[':content'] = $search;
        $params[':username'] = $search;
        $params[':full_name'] = $search;
    }

    $whereSql = '';

    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

    /*
    |--------------------------------------------------------------------------
    | Count posts
    |--------------------------------------------------------------------------
    */

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        $whereSql
    ");

    $countStmt->execute($params);

    $total = (int) $countStmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | Retrieve posts
    |--------------------------------------------------------------------------
    |
    | The posts table does NOT contain post_type.
    |
    */

    $sql = "
        SELECT
            p.id,
            p.user_id,
            u.username,
            u.full_name,
            u.avatar_url,
            p.content,
            p.media_url,
            p.created_at,
            p.updated_at
        FROM posts p
        INNER JOIN users u ON u.id = p.user_id
        $whereSql
        ORDER BY p.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);

    /*
    |--------------------------------------------------------------------------
    | Bind search parameters
    |--------------------------------------------------------------------------
    */

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    /*
    |--------------------------------------------------------------------------
    | Bind pagination parameters
    |--------------------------------------------------------------------------
    */

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();

    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Success response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' => 'Admin posts retrieved successfully.',
        'data' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'count' => count($posts),
            'query' => $q,
            'posts' => $posts
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve posts.',
        'data' => []
    ]);

    exit;
}

