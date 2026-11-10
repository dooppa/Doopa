<?php

declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

function sendJson(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

try {

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

    require_once __DIR__ . '/../../config/config.php';
    require_once __DIR__ . '/../../config/database.php';

    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Find post
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            cp.id,
            cp.community_id,
            cp.user_id,
            cp.content,
            cp.post_type,
            cp.media_url,
            cp.is_deleted,
            cp.created_at,
            cp.updated_at
        FROM community_posts cp
        WHERE cp.id = :post_id
        LIMIT 1
    ");

    $stmt->execute([
        ':post_id' => $postId
    ]);

    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        sendJson(
            false,
            'Community post not found.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Already deleted
    |--------------------------------------------------------------------------
    */

    if ((int) $post['is_deleted'] === 1) {
        sendJson(
            false,
            'Community post has already been deleted.',
            [
                'post_id' => (int) $post['id'],
                'is_deleted' => 1
            ],
            409
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Get current user's community role
    |--------------------------------------------------------------------------
    */

    $memberStmt = $pdo->prepare("
        SELECT
            role,
            status
        FROM community_members
        WHERE community_id = :community_id
          AND user_id = :user_id
        LIMIT 1
    ");

    $memberStmt->execute([
        ':community_id' => (int) $post['community_id'],
        ':user_id' => $currentUserId
    ]);

    $membership = $memberStmt->fetch(PDO::FETCH_ASSOC);

    if (!$membership || $membership['status'] !== 'active') {
        sendJson(
            false,
            'Your community membership is not active.',
            [
                'status' => $membership['status'] ?? null
            ],
            403
        );
    }

    $role = $membership['role'];

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |
    | Owner, admin and moderator may moderate posts.
    | Ordinary members may delete only their own posts.
    |--------------------------------------------------------------------------
    */

    $isPostOwner = (
        (int) $post['user_id'] === $currentUserId
    );

    $canModerate = in_array(
        $role,
        ['owner', 'admin', 'moderator'],
        true
    );

    if (!$isPostOwner && !$canModerate) {
        sendJson(
            false,
            'You can only delete your own community posts.',
            [
                'post_id' => (int) $post['id']
            ],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Soft delete
    |--------------------------------------------------------------------------
    */

    $update = $pdo->prepare("
        UPDATE community_posts
        SET
            is_deleted = 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :post_id
          AND is_deleted = 0
    ");

    $update->execute([
        ':post_id' => $postId
    ]);

    if ($update->rowCount() !== 1) {
        sendJson(
            false,
            'Community post could not be deleted.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Success
    |--------------------------------------------------------------------------
    */

    sendJson(
        true,
        'Community post deleted successfully.',
        [
            'post_id' => (int) $post['id'],
            'community_id' => (int) $post['community_id'],
            'is_deleted' => 1,
            'deleted_by_role' => $role
        ]
    );

} catch (Throwable $e) {

    sendJson(
        false,
        'An unexpected server error occurred.',
        [],
        500
    );
}