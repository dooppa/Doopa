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

$commentId = isset($input['comment_id'])
    ? (int) $input['comment_id']
    : 0;

if ($commentId <= 0) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Valid comment ID is required.',
        'data' => []
    ]);

    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | Find comment
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            post_id,
            user_id,
            content
        FROM post_comments
        WHERE id = :comment_id
        LIMIT 1
    ");

    $stmt->execute([
        ':comment_id' => $commentId
    ]);

    $comment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$comment) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Comment not found.',
            'data' => []
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Store information before deletion
    |--------------------------------------------------------------------------
    */

    $postId = (int) $comment['post_id'];
    $commentOwnerId = (int) $comment['user_id'];

    /*
    |--------------------------------------------------------------------------
    | Delete comment
    |--------------------------------------------------------------------------
    */

    $delete = $pdo->prepare("
        DELETE FROM post_comments
        WHERE id = :comment_id
    ");

    $delete->execute([
        ':comment_id' => $commentId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Record admin activity
    |--------------------------------------------------------------------------
    */

    logAdminActivity(
        $pdo,
        (int) $_SESSION['user_id'],
        'delete_comment',
        'comment',
        $commentId,
        'Administrator deleted comment ' . $commentId .
        ' on post ' . $postId .
        ' owned by user ' . $commentOwnerId
    );

    /*
    |--------------------------------------------------------------------------
    | Success response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' => 'Comment deleted successfully by administrator.',
        'data' => [
            'comment_id' => $commentId,
            'post_id' => $postId,
            'comment_owner_id' => $commentOwnerId
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to delete comment.',
        'data' => []
    ]);

    exit;
}