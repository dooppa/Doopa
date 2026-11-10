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

try {

    // Total users
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
    ");
    $totalUsers = (int) $stmt->fetchColumn();

    // Total administrators
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'admin'
    ");
    $totalAdmins = (int) $stmt->fetchColumn();

    // Total normal users
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'user'
    ");
    $totalNormalUsers = (int) $stmt->fetchColumn();

    // Total posts
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM posts
    ");
    $totalPosts = (int) $stmt->fetchColumn();

    // Total comments
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM community_post_comments
    ");
    $totalComments = (int) $stmt->fetchColumn();

    // Total businesses
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM businesses
    ");
    $totalBusinesses = (int) $stmt->fetchColumn();

    // Total education records
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM education
    ");
    $totalEducation = (int) $stmt->fetchColumn();

    // Total communities
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM communities
    ");
    $totalCommunities = (int) $stmt->fetchColumn();

    // Total community posts
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM community_posts
    ");
    $totalCommunityPosts = (int) $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'message' => 'Admin dashboard data retrieved successfully.',
        'data' => [
            'users' => [
                'total' => $totalUsers,
                'admins' => $totalAdmins,
                'normal_users' => $totalNormalUsers
            ],
            'content' => [
                'posts' => $totalPosts,
                'comments' => $totalComments,
                'community_posts' => $totalCommunityPosts
            ],
            'businesses' => $totalBusinesses,
            'education_records' => $totalEducation,
            'communities' => $totalCommunities
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to retrieve admin dashboard data.',
        'data' => []
    ]);

    exit;
}

