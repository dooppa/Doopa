<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - COMMUNITY POST RETRIEVAL
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

function sendJson(
    bool $success,
    string $message,
    array $data = [],
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

/*
|--------------------------------------------------------------------------
| METHOD
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(
        false,
        'Only GET requests are allowed.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| SESSION
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id'])
) {
    sendJson(
        false,
        'Authentication required.',
        [],
        401
    );
}

$currentUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

try {

    $database = new Database();
    $pdo = $database->connect();

   /*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

if (!isset($_GET['community_id'])) {
    sendJson(
        false,
        'community_id is required.',
        [],
        422
    );
}

$communityId = filter_var(
    $_GET['community_id'],
    FILTER_VALIDATE_INT
);

if ($communityId === false || $communityId <= 0) {
    sendJson(
        false,
        'A valid community_id is required.',
        [],
        422
    );
}
    /*
    |--------------------------------------------------------------------------
    | VERIFY COMMUNITY
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            slug,
            privacy
        FROM communities
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $communityId
    ]);

    $community = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$community) {
        sendJson(
            false,
            'Community not found.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY MEMBERSHIP
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            role,
            status
        FROM community_members
        WHERE community_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $communityId,
        $currentUserId
    ]);

    $membership = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$membership) {
        sendJson(
            false,
            'You are not a member of this community.',
            [],
            403
        );
    }

    if ($membership['status'] !== 'active') {
        sendJson(
            false,
            'Your community membership is not active.',
            [
                'status' => $membership['status']
            ],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RETRIEVE POSTS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            cp.id,
            cp.community_id,
            cp.user_id,
            u.username,
            u.full_name,
            u.avatar_url,
            cp.content,
            cp.post_type,
            cp.media_url,
            cp.is_deleted,
            cp.created_at,
            cp.updated_at
        FROM community_posts cp
        INNER JOIN users u
            ON u.id = cp.user_id
        WHERE cp.community_id = ?
          AND cp.is_deleted = 0
        ORDER BY cp.created_at DESC
    ");

    $stmt->execute([
        $communityId
    ]);

    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | RESPONSE
    |--------------------------------------------------------------------------
    */

    sendJson(
        true,
        'Community posts retrieved successfully.',
        [
            'community' => $community,
            'community_id' => $communityId,
            'count' => count($posts),
            'posts' => $posts
        ]
    );

} catch (Throwable $e) {

    error_log(
        'Doopa posts.php error: ' . $e->getMessage()
    );

    sendJson(
        false,
        'A server error occurred while retrieving community posts.',
        [],
        500
    );
}