<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - CREATE COMMUNITY POST
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

    $communityId = filter_input(
        INPUT_POST,
        'community_id',
        FILTER_VALIDATE_INT
    );

    $content = trim(
        (string) ($_POST['content'] ?? '')
    );

    $postType = trim(
        (string) ($_POST['post_type'] ?? 'text')
    );

    $mediaUrl = trim(
        (string) ($_POST['media_url'] ?? '')
    );

    /*
    |--------------------------------------------------------------------------
    | BASIC VALIDATION
    |--------------------------------------------------------------------------
    */

    if (!$communityId) {
        sendJson(
            false,
            'community_id is required.',
            [],
            422
        );
    }

    if ($content === '') {
        sendJson(
            false,
            'Post content is required.',
            [],
            422
        );
    }

    if (mb_strlen($content) > 10000) {
        sendJson(
            false,
            'Post content cannot exceed 10000 characters.',
            [],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | POST TYPE
    |--------------------------------------------------------------------------
    */

    $allowedPostTypes = [
        'text',
        'image',
        'video',
        'link'
    ];

    if (!in_array($postType, $allowedPostTypes, true)) {
        sendJson(
            false,
            'Invalid post type. Allowed types are text, image, video and link.',
            [
                'allowed_types' => $allowedPostTypes
            ],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MEDIA VALIDATION
    |--------------------------------------------------------------------------
    */

    if (
        in_array($postType, ['image', 'video', 'link'], true)
        && $mediaUrl === ''
    ) {
        sendJson(
            false,
            'media_url is required for image, video and link posts.',
            [],
            422
        );
    }

    if (
        $postType === 'text'
        && $mediaUrl !== ''
    ) {
        sendJson(
            false,
            'Text posts cannot contain media_url.',
            [],
            422
        );
    }

    if (mb_strlen($mediaUrl) > 2000) {
        sendJson(
            false,
            'media_url cannot exceed 2000 characters.',
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
    | CREATE POST
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        INSERT INTO community_posts (
            community_id,
            user_id,
            content,
            post_type,
            media_url,
            is_deleted,
            created_at,
            updated_at
        )
        VALUES (
            ?,
            ?,
            ?,
            ?,
            ?,
            0,
            CURRENT_TIMESTAMP,
            CURRENT_TIMESTAMP
        )
    ");

    $stmt->execute([
        $communityId,
        $currentUserId,
        $content,
        $postType,
        $mediaUrl !== '' ? $mediaUrl : null
    ]);

    $postId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | RETURN POST
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
        WHERE cp.id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $postId
    ]);

    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    sendJson(
        true,
        'Community post created successfully.',
        [
            'post' => $post
        ],
        201
    );

} catch (Throwable $e) {

    error_log(
        'Doopa create_post.php error: ' . $e->getMessage()
    );

    sendJson(
        false,
        'A server error occurred while creating the community post.',
        [],
        500
    );
}