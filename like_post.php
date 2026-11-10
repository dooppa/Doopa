<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - LIKE COMMUNITY POST
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(
        false,
        'Only POST requests are allowed.',
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
    INPUT_POST,
    'post_id',
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
    | Get post
    |--------------------------------------------------------------------------
    */

    $postStmt = $pdo->prepare(
        "SELECT
            id,
            community_id,
            user_id,
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
            'Community post has been deleted.',
            [
                'post_id' => $postId
            ],
            409
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
    | Check existing like
    |--------------------------------------------------------------------------
    */

    $existingStmt = $pdo->prepare(
        "SELECT id
         FROM community_post_likes
         WHERE post_id = :post_id
           AND user_id = :user_id
         LIMIT 1"
    );

    $existingStmt->execute([
        ':post_id' => $postId,
        ':user_id' => $currentUserId
    ]);

    $existingLike = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingLike) {
        sendJson(
            false,
            'You have already liked this community post.',
            [
                'post_id' => $postId,
                'user_id' => $currentUserId,
                'like_id' => (int) $existingLike['id']
            ],
            409
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Create like
    |--------------------------------------------------------------------------
    */

    $likeStmt = $pdo->prepare(
        "INSERT INTO community_post_likes
            (post_id, user_id)
         VALUES
            (:post_id, :user_id)"
    );

    $likeStmt->execute([
        ':post_id' => $postId,
        ':user_id' => $currentUserId
    ]);

    $likeId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | Count likes
    |--------------------------------------------------------------------------
    */

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM community_post_likes
         WHERE post_id = :post_id"
    );

    $countStmt->execute([
        ':post_id' => $postId
    ]);

    $likeCount = (int) $countStmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */

    sendJson(
        true,
        'Community post liked successfully.',
        [
            'like_id' => $likeId,
            'post_id' => $postId,
            'user_id' => $currentUserId,
            'like_count' => $likeCount
        ]
    );

} catch (PDOException $e) {

    sendJson(
        false,
        'Failed to like community post.',
        [
            'error' => $e->getMessage()
        ],
        500
    );

} catch (Throwable $e) {

    sendJson(
        false,
        'Failed to like community post.',
        [
            'error' => $e->getMessage()
        ],
        500
    );
}