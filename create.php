<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function sendJson(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): void {
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
| Validate YYYY-MM-DD date
|--------------------------------------------------------------------------
*/
function validateDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $date));

    return checkdate($month, $day, $year);
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
| Request method
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(false, 'Only POST requests are allowed.', [], 405);
}

/*
|--------------------------------------------------------------------------
| Read JSON input
|--------------------------------------------------------------------------
*/
$rawInput = file_get_contents('php://input');

$data = json_decode($rawInput);

/*
|--------------------------------------------------------------------------
| Validate JSON input
|--------------------------------------------------------------------------
| Accept JSON objects only.
| Reject invalid JSON, arrays, strings, numbers, booleans and null.
|--------------------------------------------------------------------------
*/
if (!is_object($data)) {
    sendJson(false, 'Invalid JSON input.', [], 400);
}

$data = (array) $data;

$institution = trim((string) ($data['institution'] ?? ''));
$qualification = trim((string) ($data['qualification'] ?? ''));
$fieldOfStudy = trim((string) ($data['field_of_study'] ?? ''));
$startDate = trim((string) ($data['start_date'] ?? ''));
$endDate = trim((string) ($data['end_date'] ?? ''));
$description = trim((string) ($data['description'] ?? ''));

/*
|--------------------------------------------------------------------------
| Required fields
|--------------------------------------------------------------------------
*/
if ($institution === '') {
    sendJson(false, 'Institution is required.', [], 422);
}

if ($qualification === '') {
    sendJson(false, 'Qualification is required.', [], 422);
}

/*
|--------------------------------------------------------------------------
| Date validation
|--------------------------------------------------------------------------
*/
if ($startDate !== '' && !validateDate($startDate)) {
    sendJson(false, 'Invalid start date.', [], 422);
}

if ($endDate !== '' && !validateDate($endDate)) {
    sendJson(false, 'Invalid end date.', [], 422);
}

/*
|--------------------------------------------------------------------------
| Date order validation
|--------------------------------------------------------------------------
*/
if (
    $startDate !== '' &&
    $endDate !== '' &&
    $startDate > $endDate
) {
    sendJson(
        false,
        'Start date cannot be after end date.',
        [],
        422
    );
}

try {
    $database = new Database();
    $pdo = $database->connect();

    $stmt = $pdo->prepare(
        'INSERT INTO education (
            user_id,
            institution,
            qualification,
            field_of_study,
            start_date,
            end_date,
            description
        ) VALUES (
            :user_id,
            :institution,
            :qualification,
            :field_of_study,
            :start_date,
            :end_date,
            :description
        )'
    );

    $stmt->execute([
        ':user_id' => $userId,
        ':institution' => $institution,
        ':qualification' => $qualification,
        ':field_of_study' => $fieldOfStudy !== '' ? $fieldOfStudy : null,
        ':start_date' => $startDate !== '' ? $startDate : null,
        ':end_date' => $endDate !== '' ? $endDate : null,
        ':description' => $description !== '' ? $description : null
    ]);

    $educationId = (int) $pdo->lastInsertId();

    sendJson(
        true,
        'Education added successfully.',
        [
            'education_id' => $educationId,
            'user_id' => $userId
        ],
        201
    );

} catch (PDOException $e) {
    sendJson(false, 'Database error.', [], 500);
}

