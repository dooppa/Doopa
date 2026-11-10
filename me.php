<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=UTF-8');

/*
|--------------------------------------------------------------------------
| Only allow GET requests
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(
        false,
        'Only GET requests are allowed.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| Check authentication
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id'])
) {
    jsonResponse(
        false,
        'Authentication required.',
        [],
        401
    );
}

$userId = (int) $_SESSION['user_id'];

try {

    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Get current user
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT
            id,
            full_name,
            username,
            email,
            avatar_url
         FROM users
         WHERE id = :id
         LIMIT 1'
    );

    $stmt->execute([
        ':id' => $userId
    ]);

    $user = $stmt->fetch();

    if (!$user) {
        session_unset();
        session_destroy();

        jsonResponse(
            false,
            'User account not found.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Return authenticated user
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        'User is authenticated.',
        [
            'authenticated' => true,
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'username' => $user['username'],
                'email' => $user['email'],
                'avatar_url' => $user['avatar_url']
            ]
        ]
    );

} catch (PDOException $e) {

    jsonResponse(
        false,
        'Unable to retrieve authenticated user.',
        [],
        500
    );
}