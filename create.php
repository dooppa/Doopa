<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function sendJson(bool $success, string $message, array $data = [], int $statusCode = 200): void
{
    http_response_code($statusCode);

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);

    exit;
}

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in'], $_SESSION['user_id']) ||
    $_SESSION['logged_in'] !== true
) {
    sendJson(false, 'Authentication required.', [], 401);
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(false, 'Only POST requests are allowed.', [], 405);
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
    | Input
    |--------------------------------------------------------------------------
    */

    $name = trim((string) ($_POST['name'] ?? ''));
    $slug = trim((string) ($_POST['slug'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $category = isset($_POST['category'])? trim((string) $_POST['category']): '';
	
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $website = trim((string) ($_POST['website'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($name === '') {
        sendJson(false, 'Business name is required.', [], 422);
    }

    if ($slug === '') {
        sendJson(false, 'Business slug is required.', [], 422);
    }

    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        sendJson(
            false,
            'Slug may only contain lowercase letters, numbers, and hyphens.',
            [],
            422
        );
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendJson(false, 'Invalid email address.', [], 422);
    }

    /*
    |--------------------------------------------------------------------------
    | Check Duplicate Slug
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT id
         FROM businesses
         WHERE slug = :slug
         LIMIT 1'
    );

    $stmt->execute([
        ':slug' => $slug
    ]);

    if ($stmt->fetch()) {
        sendJson(false, 'Business slug already exists.', [], 409);
    }

    /*
    |--------------------------------------------------------------------------
    | Create Business
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'INSERT INTO businesses (
            owner_id,
            name,
            slug,
            description,
            category,
            phone,
            email,
            website,
            address,
            status
        ) VALUES (
            :owner_id,
            :name,
            :slug,
            :description,
            :category,
            :phone,
            :email,
            :website,
            :address,
            :status
        )'
    );

    $stmt->execute([
        ':owner_id' => $userId,
        ':name' => $name,
        ':slug' => $slug,
        ':description' => $description !== '' ? $description : null,
        ':category' => $category !== '' ? $category : null,
        ':phone' => $phone !== '' ? $phone : null,
        ':email' => $email !== '' ? $email : null,
        ':website' => $website !== '' ? $website : null,
        ':address' => $address !== '' ? $address : null,
        ':status' => 'active'
    ]);

    $businessId = (int) $pdo->lastInsertId();

    sendJson(
        true,
        'Business created successfully.',
        [
            'business_id' => $businessId,
            'owner_id' => $userId,
            'name' => $name,
            'slug' => $slug,
            'status' => 'active'
        ],
        201
    );

} catch (PDOException $e) {

    sendJson(
        false,
        'Database error.',
        [],
        500
    );
}