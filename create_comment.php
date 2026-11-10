<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - CREATE COMMUNITY POST COMMENT
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

/*
|--------------------------------------------------------------------------
| JSON Response
|--------------------------------------------------------------------------
*/

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
| HTTP Method
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
| Read Request Data
|--------------------------------------------------------------------------
|
| Supported:
| 1. application/x-www-form-urlencoded
| 2. multipart/form-data
| 3. application/json
| 4. POST + query-string parameters
|
*/

/*
|--------------------------------------------------------------------------
| Start with GET parameters
|--------------------------------------------------------------------------
*/

$input = $_GET;

/*
|--------------------------------------------------------------------------
| Merge normal POST parameters
|--------------------------------------------------------------------------
*/

if (!empty($_POST)) {
    $input = array_merge($input, $_POST);
}

/*
|--------------------------------------------------------------------------
| Read JSON body when present
|--------------------------------------------------------------------------
*/

$contentType = strtolower(
    trim(
        explode(
            ';',
            $_SERVER['CONTENT_TYPE'] ?? ''
        )[0]
    )
);

if ($contentType === 'application/json') {

    $rawInput = file_get_contents('php://input');

    if (
        $rawInput !== false &&
        trim($rawInput) !== ''
    ) {

        $jsonInput = json_decode(
            $rawInput,
            true
        );

        if (
            json_last_error() === JSON_ERROR_NONE &&
            is_array($jsonInput)
        ) {
            $input = array_merge(
                $input,
                $jsonInput
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Input Values
|--------------------------------------------------------------------------
*/

$postIdRaw = $input['post_id'] ?? null;
$parentIdRaw = $input['parent_id'] ?? null;
$comment = trim(
    (string) ($input['comment'] ?? '')
);

/*
|--------------------------------------------------------------------------
| Validate post_id
|--------------------------------------------------------------------------
*/

if (
    $postIdRaw === null ||
    $postIdRaw === '' ||
    filter_var(
        $postIdRaw,
        FILTER_VALIDATE_INT
    ) === false
) {
    sendJson(
        false,
        'A valid post_id is required.',
        [],
        422
    );
}

$postId = (int) $postIdRaw;

if ($postId <= 0) {
    sendJson(
        false,
        'A valid post_id is required.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate parent_id
|--------------------------------------------------------------------------
|
| NULL = top-level comment
| Positive integer = reply
|
*/

$parentId = null;

if (
    $parentIdRaw !== null &&
    $parentIdRaw !== ''
) {

    if (
        is_array($parentIdRaw) ||
        filter_var(
            $parentIdRaw,
            FILTER_VALIDATE_INT
        ) === false
    ) {
        sendJson(
            false,
            'Invalid parent_id.',
            [],
            422
        );
    }

    $parentId = (int) $parentIdRaw;

    if ($parentId <= 0) {
        sendJson(
            false,
            'Invalid parent_id.',
            [],
            422
        );
    }
}

/*
|--------------------------------------------------------------------------
| Validate comment
|--------------------------------------------------------------------------
*/

if ($comment === '') {
    sendJson(
        false,
        'Comment is required.',
        [],
        422
    );
}

if (mb_strlen($comment) > 5000) {
    sendJson(
        false,
        'Comment cannot exceed 5000 characters.',
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
    | Verify Community Post
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
            'Cannot comment on a deleted community post.',
            [
                'post_id' => $postId
            ],
            410
        );
    }

    $communityId = (int) $post['community_id'];

    /*
    |--------------------------------------------------------------------------
    | Verify Active Membership
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
    | Verify Parent Comment
    |--------------------------------------------------------------------------
    */

    if ($parentId !== null) {

        $parentStmt = $pdo->prepare(
            "SELECT
                id,
                post_id,
                is_deleted
             FROM community_post_comments
             WHERE id = :parent_id
             LIMIT 1"
        );

        $parentStmt->execute([
            ':parent_id' => $parentId
        ]);

        $parentComment = $parentStmt->fetch(PDO::FETCH_ASSOC);

        if (!$parentComment) {
            sendJson(
                false,
                'Parent comment not found.',
                [],
                404
            );
        }

        if ((int) $parentComment['post_id'] !== $postId) {
            sendJson(
                false,
                'Parent comment does not belong to this post.',
                [],
                422
            );
        }

        if ((int) $parentComment['is_deleted'] === 1) {
            sendJson(
                false,
                'Cannot reply to a deleted comment.',
                [],
                410
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Insert Comment
    |--------------------------------------------------------------------------
    */

    $insertStmt = $pdo->prepare(
        "INSERT INTO community_post_comments
        (
            post_id,
            user_id,
            parent_id,
            comment,
            is_deleted
        )
        VALUES
        (
            :post_id,
            :user_id,
            :parent_id,
            :comment,
            0
        )"
    );

    $insertStmt->bindValue(
        ':post_id',
        $postId,
        PDO::PARAM_INT
    );

    $insertStmt->bindValue(
        ':user_id',
        $currentUserId,
        PDO::PARAM_INT
    );

    if ($parentId === null) {

        $insertStmt->bindValue(
            ':parent_id',
            null,
            PDO::PARAM_NULL
        );

    } else {

        $insertStmt->bindValue(
            ':parent_id',
            $parentId,
            PDO::PARAM_INT
        );
    }

    $insertStmt->bindValue(
        ':comment',
        $comment,
        PDO::PARAM_STR
    );

    $insertStmt->execute();

    $commentId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | Retrieve Created Comment
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
            c.created_at,
            c.updated_at,
            u.username,
            u.full_name,
            u.avatar_url
         FROM community_post_comments c
         INNER JOIN users u
             ON u.id = c.user_id
         WHERE c.id = :comment_id
         LIMIT 1"
    );

    $commentStmt->execute([
        ':comment_id' => $commentId
    ]);

    $createdComment = $commentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$createdComment) {
        sendJson(
            false,
            'Comment was created but could not be retrieved.',
            [
                'comment_id' => $commentId
            ],
            500
        );
    }

    sendJson(
        true,
        'Community comment created successfully.',
        [
            'comment' => $createdComment
        ],
        201
    );

} catch (Throwable $e) {

    sendJson(
        false,
        'Failed to create community comment.',
        [
            'error' => $e->getMessage()
        ],
        500
    );
}

