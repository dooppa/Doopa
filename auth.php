<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Admin Authentication Protection
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id'])
) {
    http_response_code(401);

    echo json_encode([
        'success' => false,
        'message' => 'Authentication required.',
        'data' => []
    ]);

    exit;
}

$userId = (int) $_SESSION['user_id'];

try {
    $database = new Database();
    $pdo = $database->connect();

    $stmt = $pdo->prepare("
        SELECT id, role
        FROM users
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $userId
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);

        echo json_encode([
            'success' => false,
            'message' => 'User account not found.',
            'data' => []
        ]);

        exit;
    }

    if ($user['role'] !== 'admin') {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Administrator access required.',
            'data' => []
        ]);

        exit;
    }

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to verify administrator access.',
        'data' => []
    ]);

    exit;
}