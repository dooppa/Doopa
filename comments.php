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
                pc.content LIKE :content
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

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM post_comments pc
        INNER JOIN users u ON u.id = pc.user_id
        $whereSql
    ");

    $countStmt->execute($params);

    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT
            pc.id,
            pc.post_id,
            pc.user_id,
            u.username,
            u.full_name,
            u.avatar_url,
            pc.content,
            pc.created_at,
            pc.updated_at
        FROM post_comments pc
        INNER JOIN users u ON u.id = pc.user_id
        $whereSql
        ORDER BY pc.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();

    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Admin comments retrieved successfully.',
        'data' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'count' => count($comments),
            'query' => $q,
            'comments' => $comments
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve comments.',
        'data' => []
    ]);

    exit;
}