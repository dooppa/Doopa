<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/log_activity.php';

header('Content-Type: application/json; charset=UTF-8');

/*
|--------------------------------------------------------------------------
| Only allow POST requests
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are allowed.',
        'data' => []
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Read JSON or form data
|--------------------------------------------------------------------------
*/

$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    $input = $_POST;
}

/*
|--------------------------------------------------------------------------
| Get parameters
|--------------------------------------------------------------------------
*/

$userId = isset($input['user_id'])
    ? (int) $input['user_id']
    : 0;

$role = trim($input['role'] ?? '');

/*
|--------------------------------------------------------------------------
| Validate user ID
|--------------------------------------------------------------------------
*/

if ($userId <= 0) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'A valid user ID is required.',
        'data' => []
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Validate role
|--------------------------------------------------------------------------
*/

if (!in_array($role, ['user', 'admin'], true)) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid role. Allowed values are user and admin.',
        'data' => []
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Prevent changing own role
|--------------------------------------------------------------------------
*/

$currentAdminId = (int) $_SESSION['user_id'];

if ($userId === $currentAdminId) {
    http_response_code(403);

    echo json_encode([
        'success' => false,
        'message' => 'Administrators cannot change their own role.',
        'data' => []
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Update role
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | Check user exists
    |--------------------------------------------------------------------------
    */

    $checkStmt = $pdo->prepare("
        SELECT id, full_name, username, role
        FROM users
        WHERE id = :id
        LIMIT 1
    ");

    $checkStmt->execute([
        ':id' => $userId
    ]);

    $user = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'User not found.',
            'data' => []
        ]);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Update role
    |--------------------------------------------------------------------------
    */

    $updateStmt = $pdo->prepare("
        UPDATE users
        SET role = :role
        WHERE id = :id
    ");

    $updateStmt->execute([
        ':role' => $role,
        ':id' => $userId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Record admin activity
    |--------------------------------------------------------------------------
    */

    logAdminActivity(
        $pdo,
        $currentAdminId,
        'change_user_role',
        'user',
        $userId,
        'Changed role for user ' . $user['username'] .
        ' from ' . $user['role'] .
        ' to ' . $role
    );

    /*
    |--------------------------------------------------------------------------
    | Success response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' => 'User role updated successfully.',
        'data' => [
            'user_id' => $userId,
            'username' => $user['username'],
            'old_role' => $user['role'],
            'new_role' => $role
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update user role.',
        'data' => []
    ]);

    exit;
}