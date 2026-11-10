<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=UTF-8');

/*
|--------------------------------------------------------------------------
| Only allow POST requests
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(
        false,
        'Only POST requests are allowed.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| Read JSON request
|--------------------------------------------------------------------------
*/

$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    jsonResponse(
        false,
        'Invalid JSON request.',
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| Get login information
|--------------------------------------------------------------------------
*/

$login = trim($input['login'] ?? '');
$password = $input['password'] ?? '';

if ($login === '' || $password === '') {
    jsonResponse(
        false,
        'Username/email and password are required.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Connect to database
|--------------------------------------------------------------------------
*/

try {
    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Find user
    |--------------------------------------------------------------------------
    */

  $stmt = $pdo->prepare(
    'SELECT
        id,
        full_name,
        username,
        email,
        password_hash
     FROM users
     WHERE email = :email_login
        OR username = :username_login
     LIMIT 1'
);

$stmt->execute([
    ':email_login' => $login,
    ':username_login' => $login
]);

    $user = $stmt->fetch();

    /*
    |--------------------------------------------------------------------------
    | Verify account
    |--------------------------------------------------------------------------
    */

    if (
        !$user ||
        !password_verify($password, $user['password_hash'])
    ) {
        jsonResponse(
            false,
            'Invalid username/email or password.',
            [],
            401
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Create authenticated session
    |--------------------------------------------------------------------------
    */

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['logged_in'] = true;

    /*
    |--------------------------------------------------------------------------
    | Return user information
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        'Login successful.',
        [
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => $user['full_name'],
                'username' => $user['username'],
                'email' => $user['email']
            ]
        ]
    );

} catch (PDOException $e) {

    jsonResponse(
        false,
        'Unable to process login at this time.',
        [],
        500
    );
}