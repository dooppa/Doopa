<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - CREATE COMMUNITY
|--------------------------------------------------------------------------
| Batch 10B
|
| POST /api/communities/create.php
|
| Creates a community and automatically makes the authenticated
| user the owner and first active member.
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';


/*
|--------------------------------------------------------------------------
| JSON RESPONSE HELPER
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
        'Doopa create community database error: ' .
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
| INPUT
|--------------------------------------------------------------------------
*/

$name = '';

if (isset($_POST['name'])) {
    $name = trim((string) $_POST['name']);
}

$description = null;

if (isset($_POST['description'])) {
    $description = trim((string) $_POST['description']);

    if ($description === '') {
        $description = null;
    }
}

$privacy = 'public';

if (isset($_POST['privacy'])) {
    $privacy = strtolower(
        trim((string) $_POST['privacy'])
    );
}


/*
|--------------------------------------------------------------------------
| VALIDATE NAME
|--------------------------------------------------------------------------
*/

if ($name === '') {
    sendJson(
        false,
        'Community name is required.',
        [],
        422
    );
}

$nameLength = function_exists('mb_strlen')
    ? mb_strlen($name)
    : strlen($name);

if ($nameLength < 2) {
    sendJson(
        false,
        'Community name must contain at least 2 characters.',
        [],
        422
    );
}

if ($nameLength > 100) {
    sendJson(
        false,
        'Community name cannot exceed 100 characters.',
        [],
        422
    );
}


/*
|--------------------------------------------------------------------------
| VALIDATE DESCRIPTION
|--------------------------------------------------------------------------
*/

if ($description !== null) {

    $descriptionLength = function_exists('mb_strlen')
        ? mb_strlen($description)
        : strlen($description);

    if ($descriptionLength > 5000) {
        sendJson(
            false,
            'Community description cannot exceed 5000 characters.',
            [],
            422
        );
    }
}


/*
|--------------------------------------------------------------------------
| VALIDATE PRIVACY
|--------------------------------------------------------------------------
*/

if (!in_array(
    $privacy,
    ['public', 'private'],
    true
)) {
    sendJson(
        false,
        'Privacy must be either public or private.',
        [],
        422
    );
}


/*
|--------------------------------------------------------------------------
| CREATE BASE SLUG
|--------------------------------------------------------------------------
*/

$slugBase = strtolower($name);

$slugBase = preg_replace(
    '/[^a-z0-9]+/i',
    '-',
    $slugBase
);

$slugBase = trim(
    (string) $slugBase,
    '-'
);

if ($slugBase === '') {
    $slugBase = 'community';
}


/*
|--------------------------------------------------------------------------
| LIMIT SLUG LENGTH
|--------------------------------------------------------------------------
*/

$slugBase = substr($slugBase, 0, 100);

$slug = $slugBase;


/*
|--------------------------------------------------------------------------
| DATABASE TRANSACTION
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();


    /*
    |--------------------------------------------------------------------------
    | VERIFY USER
    |--------------------------------------------------------------------------
    */

    $userStmt = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE id = :id
         LIMIT 1'
    );

    $userStmt->execute([
        ':id' => $userId
    ]);

    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $pdo->rollBack();

        sendJson(
            false,
            'Authenticated user was not found.',
            [],
            401
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FIND UNIQUE SLUG
    |--------------------------------------------------------------------------
    */

    $slugNumber = 1;

    while (true) {

        $slugStmt = $pdo->prepare(
            'SELECT id
             FROM communities
             WHERE slug = :slug
             LIMIT 1'
        );

        $slugStmt->execute([
            ':slug' => $slug
        ]);

        $existingSlug = $slugStmt->fetch(PDO::FETCH_ASSOC);

        if (!$existingSlug) {
            break;
        }

        $slugNumber++;

        $suffix = '-' . $slugNumber;

        $maxBaseLength = 120 - strlen($suffix);

        $slug = substr(
            $slugBase,
            0,
            $maxBaseLength
        ) . $suffix;
    }


    /*
    |--------------------------------------------------------------------------
    | INSERT COMMUNITY
    |--------------------------------------------------------------------------
    */

    $communityStmt = $pdo->prepare(
        'INSERT INTO communities
        (
            name,
            slug,
            description,
            privacy,
            created_by
        )
        VALUES
        (
            :name,
            :slug,
            :description,
            :privacy,
            :created_by
        )'
    );

    $communityStmt->execute([
        ':name' => $name,
        ':slug' => $slug,
        ':description' => $description,
        ':privacy' => $privacy,
        ':created_by' => $userId
    ]);

    $communityId = (int) $pdo->lastInsertId();


    /*
    |--------------------------------------------------------------------------
    | ADD CREATOR AS OWNER
    |--------------------------------------------------------------------------
    */

    $memberStmt = $pdo->prepare(
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

    $memberStmt->execute([
        ':community_id' => $communityId,
        ':user_id' => $userId,
        ':role' => 'owner',
        ':status' => 'active'
    ]);


    /*
    |--------------------------------------------------------------------------
    | COMMIT
    |--------------------------------------------------------------------------
    */

    $pdo->commit();


    /*
    |--------------------------------------------------------------------------
    | RETURN COMMUNITY
    |--------------------------------------------------------------------------
    */

    sendJson(
        true,
        'Community created successfully.',
        [
            'community' => [
                'id' => $communityId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'avatar_url' => null,
                'cover_url' => null,
                'privacy' => $privacy,
                'created_by' => $userId,
                'role' => 'owner',
                'member_status' => 'active'
            ]
        ],
        201
    );


} catch (PDOException $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Doopa create community PDO error: ' .
        $e->getMessage()
    );

    /*
    |--------------------------------------------------------------------------
    | UNIQUE SLUG FALLBACK
    |--------------------------------------------------------------------------
    */

    if ((int) $e->errorInfo[1] === 1062) {
        sendJson(
            false,
            'A community with this generated slug already exists. Please try again.',
            [],
            409
        );
    }

    sendJson(
        false,
        'Failed to create community.',
        [],
        500
    );


} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Doopa create community error: ' .
        $e->getMessage()
    );

    sendJson(
        false,
        'An unexpected error occurred.',
        [],
        500
    );
}