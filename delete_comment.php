<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - DELETE COMMUNITY POST COMMENT
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

$commentId = filter_input(
    INPUT_POST,
    'comment_id',
    FILTER_VALIDATE_INT
);

if (!$commentId || $commentId <= 0) {
    sendJson(
        false,
        'A valid comment_id is required.',
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
    | Get comment + post + community
    |--------------------------------------------------------------------------
    */

    $commentStmt = $pdo->prepare(
        "SELECT
            c.id,
            c.post_id,
            c.user_id,
            c.parent_id,
            c.comment,
            c.is_deleted,
            p.community_id
         FROM community_post_comments c
         INNER JOIN community_posts p
             ON p.id = c.post_id
         WHERE c.id = :comment_id
         LIMIT 1"
    );

    $commentStmt->execute([
        ':comment_id' => $commentId
    ]);

    $comment = $commentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$comment) {
        sendJson(
            false,
            'Community comment not found.',
            [],
            404
        );
    }

    if ((int) $comment['is_deleted'] === 1) {
        sendJson(
            false,
            'Community comment has already been deleted.',
            [
                'comment_id' => $commentId,
                'is_deleted' => 1
            ],
            409
        );
    }

    $communityId = (int) $comment['community_id'];

    /*
    |--------------------------------------------------------------------------
    | Verify active membership
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
    | Authorization
    |--------------------------------------------------------------------------
    */

    $role = $membership['role'];

    $isCommentOwner =
        (int) $comment['user_id'] === $currentUserId;

    $canModerate = in_array(
        $role,
        ['owner', 'admin', 'moderator'],
        true
    );

    if (!$isCommentOwner && !$canModerate) {
        sendJson(
            false,
            'You can only delete your own community comments.',
            [
                'comment_id' => $commentId
            ],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Soft delete
    |--------------------------------------------------------------------------
    */

    $deleteStmt = $pdo->prepare(
        "UPDATE community_post_comments
         SET is_deleted = 1
         WHERE id = :comment_id
           AND is_deleted = 0"
    );

    $deleteStmt->execute([
        ':comment_id' => $commentId
    ]);

    if ($deleteStmt->rowCount() !== 1) {
        sendJson(
            false,
            'Community comment could not be deleted.',
            [
                'comment_id' => $commentId
            ],
            500
        );
    }

    sendJson(
        true,
        'Community comment deleted successfully.',
        [
            'comment_id' => $commentId,
            'post_id' => (int) $comment['post_id'],
            'community_id' => $communityId,
            'is_deleted' => 1,
            'deleted_by_role' => $role,
            'deleted_own_comment' => $isCommentOwner
        ]
    );

} catch (Throwable $e) {

    sendJson(
        false,
        'Failed to delete community comment.',
        [
            'error' => $e->getMessage()
        ],
        500
    );
}