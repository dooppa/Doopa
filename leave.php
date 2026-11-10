<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - LEAVE COMMUNITY
|--------------------------------------------------------------------------
| Batch 10C
|
| POST /api/communities/leave.php
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
        'Doopa leave community database error: ' .
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
    | COMMUNITY
    |--------------------------------------------------------------------------
    */

    $communityStmt = $pdo->prepare(
        'SELECT
            id,
            name,
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
    | OWNER CANNOT LEAVE
    |--------------------------------------------------------------------------
    */

    if ((int) $community['created_by'] === $userId) {

        $pdo->rollBack();

        sendJson(
            false,
            'The community owner cannot leave the community.',
            [],
            403
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FIND MEMBERSHIP
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

    if (!$membership) {

        $pdo->rollBack();

        sendJson(
            false,
            'You are not a member of this community.',
            [],
            404
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ALREADY LEFT
    |--------------------------------------------------------------------------
    */

    if ($membership['status'] === 'left') {

        $pdo->rollBack();

        sendJson(
            false,
            'You have already left this community.',
            [],
            409
        );
    }


    /*
    |--------------------------------------------------------------------------
    | BAN STATUS
    |--------------------------------------------------------------------------
    */

    if ($membership['status'] === 'banned') {

        $pdo->rollBack();

        sendJson(
            false,
            'Banned members cannot leave this community.',
            [],
            403
        );
    }


    /*
    |--------------------------------------------------------------------------
    | MARK AS LEFT
    |--------------------------------------------------------------------------
    */

    $updateStmt = $pdo->prepare(
        'UPDATE community_members
         SET status = :status
         WHERE id = :id'
    );

    $updateStmt->execute([
        ':status' => 'left',
        ':id' => (int) $membership['id']
    ]);


    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | SUCCESS
    |--------------------------------------------------------------------------
    */

    sendJson(
        true,
        'You left the community successfully.',
        [
            'community_id' => $communityId,
            'status' => 'left'
        ]
    );


} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Doopa leave community PDO error: ' .
        $e->getMessage()
    );

    sendJson(
        false,
        'Failed to leave community.',
        [],
        500
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Doopa leave community error: ' .
        $e->getMessage()
    );

    sendJson(
        false,
        'An unexpected error occurred.',
        [],
        500
    );
}