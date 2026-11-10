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
| Validate date
|--------------------------------------------------------------------------
*/
function validateDate(?string $date, string $fieldName): void
{
    if ($date === null) {
        return;
    }

    // Must be exactly YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        sendJson(
            false,
            "Invalid {$fieldName}. Date must use YYYY-MM-DD format.",
            [],
            422
        );
    }

    [$year, $month, $day] = array_map('intval', explode('-', $date));

    // Prevent year 0000
    if ($year < 1) {
        sendJson(
            false,
            "Invalid {$fieldName}.",
            [],
            422
        );
    }

    // Validate actual calendar date
    if (!checkdate($month, $day, $year)) {
        sendJson(
            false,
            "Invalid {$fieldName}.",
            [],
            422
        );
    }
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
| Read JSON body
|--------------------------------------------------------------------------
*/
$rawInput = file_get_contents('php://input');

$input = json_decode($rawInput, true);

if (!is_array($input)) {
    sendJson(false, 'Invalid JSON input.', [], 400);
}

/*
|--------------------------------------------------------------------------
| Education ID
|--------------------------------------------------------------------------
*/
$id = trim((string) ($input['id'] ?? ''));

if ($id === '') {
    sendJson(false, 'Education id is required.', [], 422);
}

if (!ctype_digit($id)) {
    sendJson(false, 'Invalid education id.', [], 422);
}

$educationId = (int) $id;

if ($educationId <= 0) {
    sendJson(false, 'Invalid education id.', [], 422);
}

/*
|--------------------------------------------------------------------------
| Required fields
|--------------------------------------------------------------------------
*/
$institution = isset($input['institution'])
    ? trim((string) $input['institution'])
    : '';

$qualification = isset($input['qualification'])
    ? trim((string) $input['qualification'])
    : '';

if ($institution === '') {
    sendJson(false, 'Institution is required.', [], 422);
}

if ($qualification === '') {
    sendJson(false, 'Qualification is required.', [], 422);
}

/*
|--------------------------------------------------------------------------
| Optional fields
|--------------------------------------------------------------------------
*/
$fieldOfStudy = isset($input['field_of_study'])
    ? trim((string) $input['field_of_study'])
    : null;

$startDate = isset($input['start_date'])
    ? trim((string) $input['start_date'])
    : null;

$endDate = isset($input['end_date'])
    ? trim((string) $input['end_date'])
    : null;

$description = isset($input['description'])
    ? trim((string) $input['description'])
    : null;

/*
|--------------------------------------------------------------------------
| Convert blank optional fields to NULL
|--------------------------------------------------------------------------
*/
$fieldOfStudy = ($fieldOfStudy === '') ? null : $fieldOfStudy;
$startDate = ($startDate === '') ? null : $startDate;
$endDate = ($endDate === '') ? null : $endDate;
$description = ($description === '') ? null : $description;

/*
|--------------------------------------------------------------------------
| Validate dates
|--------------------------------------------------------------------------
*/
validateDate($startDate, 'start date');
validateDate($endDate, 'end date');

/*
|--------------------------------------------------------------------------
| Validate date order
|--------------------------------------------------------------------------
*/
if (
    $startDate !== null &&
    $endDate !== null &&
    $endDate < $startDate
) {
    sendJson(
        false,
        'End date cannot be earlier than start date.',
        [],
        422
    );
}

try {

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    */
    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Check ownership
    |--------------------------------------------------------------------------
    */
    $check = $pdo->prepare(
        'SELECT id
         FROM education
         WHERE id = :id
           AND user_id = :user_id
         LIMIT 1'
    );

    $check->execute([
        ':id' => $educationId,
        ':user_id' => $userId
    ]);

    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        sendJson(false, 'Education record not found.', [], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Update education record
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare(
        'UPDATE education
         SET
            institution = :institution,
            qualification = :qualification,
            field_of_study = :field_of_study,
            start_date = :start_date,
            end_date = :end_date,
            description = :description,
            updated_at = CURRENT_TIMESTAMP
         WHERE id = :id
           AND user_id = :user_id'
    );

    $stmt->execute([
        ':institution' => $institution,
        ':qualification' => $qualification,
        ':field_of_study' => $fieldOfStudy,
        ':start_date' => $startDate,
        ':end_date' => $endDate,
        ':description' => $description,
        ':id' => $educationId,
        ':user_id' => $userId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Retrieve updated record
    |--------------------------------------------------------------------------
    */
    $get = $pdo->prepare(
        'SELECT
            id,
            user_id,
            institution,
            qualification,
            field_of_study,
            start_date,
            end_date,
            description,
            created_at,
            updated_at
         FROM education
         WHERE id = :id
           AND user_id = :user_id
         LIMIT 1'
    );

    $get->execute([
        ':id' => $educationId,
        ':user_id' => $userId
    ]);

    $education = $get->fetch(PDO::FETCH_ASSOC);

    if (!$education) {
        sendJson(
            false,
            'Education record could not be retrieved after update.',
            [],
            500
        );
    }

    sendJson(
        true,
        'Education record updated successfully.',
        $education
    );

} catch (PDOException $e) {
    sendJson(false, 'Database error.', [], 500);
}