<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - RETRIEVE COMMUNITY POST COMMENTS
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

function sendJson(
    bool $success,
    string $message,
    array $data = [],
    int $httpCode = 200
): never {
    http_response_code($httpCode);

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

/*
|--------------------------------------------------------------------------
| Request method
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
| Authentication
|--------------------------------------------------------------------------
*/

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
| Input
|--------------------------------------------------------------------------
*/

$postId = filter_input(
    INPUT_GET,
    'post_id',
    FILTER_VALIDATE_INT
);

$page = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT
);

$limit = filter_input(
    INPUT_GET,
    'limit',
    FILTER_VALIDATE_INT
);

if (!$postId || $postId <= 0) {
    sendJson(
        false,
        'A valid post_id is required.',
        [],
        422
    );
}

$page = ($page && $page > 0) ? $page : 1;
$limit = ($limit && $limit > 0) ? $limit : 20;

if ($limit > 100) {
    $limit = 100;
}

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

try {

    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Verify post
    |--------------------------------------------------------------------------
    */

    $postStmt = $pdo->prepare(
        "SELECT
            id,
            community_id,
            user_id,
            post_type,
            is_deleted
         FROM community_posts
         WHERE id = :post_id
         LIMIT 1"
    );

    $postStmt->execute([
        ':post_id' => $postId
    ]);

    $post = $postStmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        sendJson(
            false,
            'Community post not found.',
            [],
            404
        );
    }

    if ((int) $post['is_deleted'] === 1) {
        sendJson(
            false,
            'Cannot retrieve comments from a deleted community post.',
            [
                'post_id' => $postId
            ],
            410
        );
    }

    $communityId = (int) $post['community_id'];

    /*
    |--------------------------------------------------------------------------
    | Verify active community membership
    |--------------------------------------------------------------------------
    */

    $memberStmt = $pdo->prepare(
        "SELECT
            role,
            status
         FROM community_members
         WHERE community_id = :community_id
           AND user_id = :user_id
         LIMIT 1"
    );

    $memberStmt->execute([
        ':community_id' => $communityId,
        ':user_id' => $currentUserId
    ]);

    $membership = $memberStmt->fetch(PDO::FETCH_ASSOC);

    if (!$membership) {
        sendJson(
            false,
            'You are not a member of this community.',
            [
                'community_id' => $communityId
            ],
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
    | Count active comments
    |--------------------------------------------------------------------------
    */

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM community_post_comments
         WHERE post_id = :post_id
           AND is_deleted = 0"
    );

    $countStmt->execute([
        ':post_id' => $postId
    ]);

    $totalComments = (int) $countStmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | Retrieve comments
    |--------------------------------------------------------------------------
    */

    $commentsSql = "
        SELECT
            c.id,
            c.post_id,
            c.user_id,
            c.parent_id,
            c.comment,
            c.is_deleted,
            c.created_at,
            c.updated_at,
            u.username,
            u.full_name,
            u.avatar_url
        FROM community_post_comments c
        INNER JOIN users u
            ON u.id = c.user_id
        WHERE c.post_id = :post_id
          AND c.is_deleted = 0
        ORDER BY
            c.created_at ASC,
            c.id ASC
        LIMIT {$limit} OFFSET {$offset}
    ";

    $commentsStmt = $pdo->prepare($commentsSql);

    $commentsStmt->execute([
        ':post_id' => $postId
    ]);

    $comments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    $totalPages = $totalComments > 0
        ? (int) ceil($totalComments / $limit)
        : 0;

    sendJson(
        true,
        'Community comments retrieved successfully.',
        [
            'post_id' => $postId,
            'community_id' => $communityId,
            'count' => count($comments),
            'total_comments' => $totalComments,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => $totalPages,
            'comments' => $comments
        ]
    );

} catch (Throwable $e) {

    sendJson(
        false,
        'Failed to retrieve community comments.',
        [
            'error' => $e->getMessage()
        ],
        500
    );
}