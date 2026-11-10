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
| Get registration information
|--------------------------------------------------------------------------
*/

$fullName = trim($input['full_name'] ?? '');
$username = trim($input['username'] ?? '');
$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

/*
|--------------------------------------------------------------------------
| Validate required fields
|--------------------------------------------------------------------------
*/

if (
    $fullName === '' ||
    $username === '' ||
    $email === '' ||
    $password === ''
) {
    jsonResponse(
        false,
        'Full name, username, email and password are required.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate email
|--------------------------------------------------------------------------
*/

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(
        false,
        'Please provide a valid email address.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate username
|--------------------------------------------------------------------------
*/

if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
    jsonResponse(
        false,
        'Username must contain 3-30 letters, numbers or underscores.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate password
|--------------------------------------------------------------------------
*/

if (strlen($password) < 8) {
    jsonResponse(
        false,
        'Password must contain at least 8 characters.',
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
    | Check whether email or username already exists
    |--------------------------------------------------------------------------
    */

    $check = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE email = :email
            OR username = :username
         LIMIT 1'
    );

    $check->execute([
        ':email' => $email,
        ':username' => $username
    ]);

    if ($check->fetch()) {
        jsonResponse(
            false,
            'Email or username is already registered.',
            [],
            409
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Securely hash password
    |--------------------------------------------------------------------------
    */

    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    /*
    |--------------------------------------------------------------------------
    | Create user
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'INSERT INTO users
        (
            full_name,
            username,
            email,
            password_hash,
            created_at
        )
        VALUES
        (
            :full_name,
            :username,
            :email,
            :password_hash,
            NOW()
        )'
    );

    $stmt->execute([
        ':full_name' => $fullName,
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $passwordHash
    ]);

    /*
    |--------------------------------------------------------------------------
    | Return successful response
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        'doopa account created successfully.',
        [
            'user_id' => (int) $pdo->lastInsertId(),
            'username' => $username
        ],
        201
    );

} catch (PDOException $e) {

    jsonResponse(
        false,
        'Unable to create the account at this time.',
        [],
        500
    );
}