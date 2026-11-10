<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/log_activity.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only DELETE requests are allowed.',
        'data' => []
    ]);

    exit;
}

$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON request.',
        'data' => []
    ]);

    exit;
}

$communityPostId = isset($input['community_post_id'])
    ? (int) $input['community_post_id']
    : 0;

if ($communityPostId <= 0) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Valid community post ID is required.',
        'data' => []
    ]);

    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | Find community post
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            community_id,
            user_id,
            is_deleted
        FROM community_posts
        WHERE id = :community_post_id
        LIMIT 1
    ");

    $stmt->execute([
        ':community_post_id' => $communityPostId
    ]);

    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Community post not found.',
            'data' => []
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Check if already deleted
    |--------------------------------------------------------------------------
    */

    if ((int) $post['is_deleted'] === 1) {
        http_response_code(409);

        echo json_encode([
            'success' => false,
            'message' => 'Community post is already deleted.',
            'data' => []
        ]);

        exit;
    }

    $communityId = (int) $post['community_id'];
    $postOwnerId = (int) $post['user_id'];

    /*
    |--------------------------------------------------------------------------
    | Soft delete community post
    |--------------------------------------------------------------------------
    */

    $delete = $pdo->prepare("
        UPDATE community_posts
        SET
            is_deleted = 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :community_post_id
    ");

    $delete->execute([
        ':community_post_id' => $communityPostId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Record admin activity
    |--------------------------------------------------------------------------
    */

    logAdminActivity(
        $pdo,
        (int) $_SESSION['user_id'],
        'delete_community_post',
        'community_post',
        $communityPostId,
        'Administrator deleted community post ' . $communityPostId .
        ' in community ' . $communityId .
        ' owned by user ' . $postOwnerId
    );

    /*
    |--------------------------------------------------------------------------
    | Success response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' => 'Community post deleted successfully by administrator.',
        'data' => [
            'community_post_id' => $communityPostId,
            'community_id' => $communityId,
            'post_owner_id' => $postOwnerId,
            'is_deleted' => 1
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to delete community post.',
        'data' => []
    ]);

    exit;
}