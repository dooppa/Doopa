<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - JOIN COMMUNITY
|--------------------------------------------------------------------------
| Batch 10C
|
| POST /api/communities/join.php
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';


/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/

function sendJson(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): void {
    http_response_code($statusCode);

    echo json_encode(
        [
            'success' => $success,
            'message' => $message,
            'data' => $data
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| REQUEST METHOD
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
| AUTHENTICATION
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

$userId = (int) $_SESSION['user_id'];

if ($userId <= 0) {
    sendJson(
        false,
        'Invalid authenticated user.',
        [],
        401
    );
}


/*
|--------------------------------------------------------------------------
| INPUT
|--------------------------------------------------------------------------
*/

$communityId = isset($_POST['community_id'])
    ? (int) $_POST['community_id']
    : 0;

if ($communityId <= 0) {
    sendJson(
        false,
        'Valid community_id is required.',
        [],
        422
    );
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

try {

    $database = new Database();
    $pdo = $database->connect();

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

} catch (Throwable $e) {

    error_log(
        'Doopa join community database error: ' .
        $e->getMessage()
    );

    sendJson(
        false,
        'Database connection failed.',
        [],
        500
    );
}


/*
|--------------------------------------------------------------------------
| PROCESS
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | GET COMMUNITY
    |--------------------------------------------------------------------------
    */

    $communityStmt = $pdo->prepare(
        'SELECT
            id,
            name,
            slug,
            privacy,
            created_by
         FROM communities
         WHERE id = :community_id
         LIMIT 1
         FOR UPDATE'
    );

    $communityStmt->execute([
        ':community_id' => $communityId
    ]);

    $community = $communityStmt->fetch(PDO::FETCH_ASSOC);

    if (!$community) {

        $pdo->rollBack();

        sendJson(
            false,
            'Community not found.',
            [],
            404
        );
    }


    /*
    |--------------------------------------------------------------------------
    | OWNER CHECK
    |--------------------------------------------------------------------------
    */

    if ((int) $community['created_by'] === $userId) {

        $pdo->rollBack();

        sendJson(
            true,
            'You are already the owner of this community.',
            [
                'community_id' => $communityId,
                'role' => 'owner',
                'status' => 'active'
            ]
        );
    }


    /*
    |--------------------------------------------------------------------------
    | EXISTING MEMBERSHIP
    |--------------------------------------------------------------------------
    */

    $memberStmt = $pdo->prepare(
        'SELECT
            id,
            role,
            status
         FROM community_members
         WHERE community_id = :community_id
         AND user_id = :user_id
         LIMIT 1
         FOR UPDATE'
    );

    $memberStmt->execute([
        ':community_id' => $communityId,
        ':user_id' => $userId
    ]);

    $membership = $memberStmt->fetch(PDO::FETCH_ASSOC);


    /*
    |--------------------------------------------------------------------------
    | EXISTING MEMBERSHIP HANDLING
    |--------------------------------------------------------------------------
    */

    if ($membership) {

        $status = $membership['status'];

        if ($status === 'active') {

            $pdo->rollBack();

            sendJson(
                false,
                'You are already a member of this community.',
                [
                    'community_id' => $communityId,
                    'role' => $membership['role'],
                    'status' => 'active'
                ],
                409
            );
        }


        if ($status === 'banned') {

            $pdo->rollBack();

            sendJson(
                false,
                'You are banned from this community.',
                [],
                403
            );
        }


        /*
        |--------------------------------------------------------------------------
        | REACTIVATE LEFT/PENDING MEMBERSHIP
        |--------------------------------------------------------------------------
        */

        $updateStmt = $pdo->prepare(
            'UPDATE community_members
             SET
                status = :status,
                role = :role,
                joined_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $updateStmt->execute([
            ':status' => 'active',
            ':role' => 'member',
            ':id' => (int) $membership['id']
        ]);

        $pdo->commit();

        sendJson(
            true,
            'You joined the community successfully.',
            [
                'community_id' => $communityId,
                'name' => $community['name'],
                'slug' => $community['slug'],
                'privacy' => $community['privacy'],
                'role' => 'member',
                'status' => 'active'
            ],
            201
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PRIVATE COMMUNITY
    |--------------------------------------------------------------------------
    */

    if ($community['privacy'] !== 'public') {

        $pdo->rollBack();

        sendJson(
            false,
            'This is a private community. You cannot join it directly.',
            [],
            403
        );
    }


    /*
    |--------------------------------------------------------------------------
    | INSERT MEMBERSHIP
    |--------------------------------------------------------------------------
    */

    $insertStmt = $pdo->prepare(
        'INSERT INTO community_members
        (
            community_id,
            user_id,
            role,
            status
        )
        VALUES
        (
            :community_id,
            :user_id,
            :role,
            :status
        )'
    );

    $insertStmt->execute([
        ':community_id' => $communityId,
        ':user_id' => $userId,
        ':role' => 'member',
        ':status' => 'active'
    ]);


    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | SUCCESS
    |--------------------------------------------------------------------------
    */

    sendJson(
        true,
        'You joined the community successfully.',
        [
            'community_id' => $communityId,
            'name' => $community['name'],
            'slug' => $community['slug'],
            'privacy' => $community['privacy'],
            'role' => 'member',
            'status' => 'active'
        ],
        201
    );


} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Doopa join community PDO error: ' .
        $e->getMessage()
    );

    sendJson(
        false,
        'Failed to join community.',
        [],
        500
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Doopa join community error: ' .
        $e->getMessage()
    );

    sendJson(
        false,
        'An unexpected error occurred.',
        [],
        500
    );
}