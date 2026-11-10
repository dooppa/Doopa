<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only GET requests are allowed.',
        'data' => []
    ]);

    exit;
}

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

try {

    $where = [];
    $params = [];

    if ($q !== '') {

        $where[] = "
            (
                b.name LIKE :name
                OR b.category LIKE :category
                OR b.description LIKE :description
                OR b.address LIKE :address
                OR u.username LIKE :username
                OR u.full_name LIKE :full_name
            )
        ";

        $search = '%' . $q . '%';

        $params[':name'] = $search;
        $params[':category'] = $search;
        $params[':description'] = $search;
        $params[':address'] = $search;
        $params[':username'] = $search;
        $params[':full_name'] = $search;
    }

    $whereSql = !empty($where)
        ? 'WHERE ' . implode(' AND ', $where)
        : '';

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM businesses b
        INNER JOIN users u ON u.id = b.owner_id
        $whereSql
    ");

    $countStmt->execute($params);

    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT
            b.id,
            b.owner_id,
            u.username,
            u.full_name,
            b.name,
            b.slug,
            b.description,
            b.category,
            b.phone,
            b.email,
            b.address,
            b.status,
            b.created_at,
            b.updated_at
        FROM businesses b
        INNER JOIN users u ON u.id = b.owner_id
        $whereSql
        ORDER BY b.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();

    $businesses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Admin businesses retrieved successfully.',
        'data' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'count' => count($businesses),
            'query' => $q,
            'businesses' => $businesses
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve businesses.',
        'data' => []
    ]);

    exit;
}