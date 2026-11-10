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
                e.institution LIKE :institution
                OR e.qualification LIKE :qualification
                OR e.field_of_study LIKE :field_of_study
                OR e.description LIKE :description
                OR u.username LIKE :username
                OR u.full_name LIKE :full_name
            )
        ";

        $search = '%' . $q . '%';

        $params[':institution'] = $search;
        $params[':qualification'] = $search;
        $params[':field_of_study'] = $search;
        $params[':description'] = $search;
        $params[':username'] = $search;
        $params[':full_name'] = $search;
    }

    $whereSql = !empty($where)
        ? 'WHERE ' . implode(' AND ', $where)
        : '';

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM education e
        INNER JOIN users u ON u.id = e.user_id
        $whereSql
    ");

    $countStmt->execute($params);

    $total = (int) $countStmt->fetchColumn();

    $sql = "
        SELECT
            e.id,
            e.user_id,
            u.username,
            u.full_name,
            e.institution,
            e.qualification,
            e.field_of_study,
            e.start_date,
            e.end_date,
            e.description,
            e.created_at,
            e.updated_at
        FROM education e
        INNER JOIN users u ON u.id = e.user_id
        $whereSql
        ORDER BY e.created_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();

    $education = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Admin education records retrieved successfully.',
        'data' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'count' => count($education),
            'query' => $q,
            'education' => $education
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve education records.',
        'data' => []
    ]);

    exit;
}