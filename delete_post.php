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

$postId = isset($input['post_id'])
    ? (int) $input['post_id']
    : 0;

if ($postId <= 0) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Valid post ID is required.',
        'data' => []
    ]);

    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | Find post
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            media_url
        FROM posts
        WHERE id = :post_id
        LIMIT 1
    ");

    $stmt->execute([
        ':post_id' => $postId
    ]);

    $post = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$post) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Post not found.',
            'data' => []
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Store information before deletion
    |--------------------------------------------------------------------------
    */

    $mediaUrl = $post['media_url'] ?? null;

    $postOwnerId = (int) $post['user_id'];

    /*
    |--------------------------------------------------------------------------
    | Delete post
    |--------------------------------------------------------------------------
    */

    $delete = $pdo->prepare("
        DELETE FROM posts
        WHERE id = :post_id
    ");

    $delete->execute([
        ':post_id' => $postId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Delete associated media file
    |--------------------------------------------------------------------------
    */

    if (!empty($mediaUrl)) {

        $mediaPrefix = '/login%20template/doopa/uploads/posts/';

        if (str_starts_with($mediaUrl, $mediaPrefix)) {

            $fileName = basename($mediaUrl);

            $filePath = __DIR__ . '/../../uploads/posts/' . $fileName;

            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Record admin activity
    |--------------------------------------------------------------------------
    */

    logAdminActivity(
        $pdo,
        (int) $_SESSION['user_id'],
        'delete_post',
        'post',
        $postId,
        'Administrator deleted post ' . $postId .
        ' owned by user ' . $postOwnerId
    );

    /*
    |--------------------------------------------------------------------------
    | Success response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' => 'Post deleted successfully by administrator.',
        'data' => [
            'post_id' => $postId,
            'post_owner_id' => $postOwnerId
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to delete post.',
        'data' => []
    ]);

    exit;
}