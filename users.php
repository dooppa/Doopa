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
| Search and filters
|--------------------------------------------------------------------------
*/

$query = trim($_GET['q'] ?? '');
$role = trim($_GET['role'] ?? '');

/*
|--------------------------------------------------------------------------
| Validate search query
|--------------------------------------------------------------------------
*/

if (strlen($query) > 50) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Search query is too long.',
        'data' => []
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Validate role filter
|--------------------------------------------------------------------------
*/

if ($role !== '' && !in_array($role, ['user', 'admin'], true)) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid role. Allowed values are user and admin.',
        'data' => []
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);

$limit = min(
    50,
    max(
        1,
        (int) ($_GET['limit'] ?? 20)
    )
);

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| Build query
|--------------------------------------------------------------------------
*/

try {

    $where = [];
    $params = [];

    if ($query !== '') {

        $where[] = '
            (
                full_name LIKE :full_name
                OR username LIKE :username
                OR email LIKE :email
            )
        ';

        $searchTerm = '%' . $query . '%';

        $params[':full_name'] = $searchTerm;
        $params[':username'] = $searchTerm;
        $params[':email'] = $searchTerm;
    }

    if ($role !== '') {
        $where[] = 'role = :role';
        $params[':role'] = $role;
    }

    $whereSql = '';

    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

    /*
    |--------------------------------------------------------------------------
    | Get total count
    |--------------------------------------------------------------------------
    */

    $countSql = "
        SELECT COUNT(*)
        FROM users
        $whereSql
    ";

    $countStmt = $pdo->prepare($countSql);

    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $countStmt->execute();

    $total = (int) $countStmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | Get users
    |--------------------------------------------------------------------------
    */

    $sql = "
        SELECT
            id,
            full_name,
            username,
            email,
            avatar_url,
            role,
            created_at,
            updated_at
        FROM users
        $whereSql
        ORDER BY id ASC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->bindValue(
        ':limit',
        $limit,
        PDO::PARAM_INT
    );

    $stmt->bindValue(
        ':offset',
        $offset,
        PDO::PARAM_INT
    );

    $stmt->execute();

    $users = [];

    while ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {

        $users[] = [
            'id' => (int) $user['id'],
            'full_name' => $user['full_name'],
            'username' => $user['username'],
            'email' => $user['email'],
            'avatar_url' => $user['avatar_url'],
            'role' => $user['role'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at']
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Return results
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' => 'Users retrieved successfully.',
        'data' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'count' => count($users),
            'query' => $query,
            'role' => $role,
            'users' => $users
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve users.',
        'data' => []
    ]);
}