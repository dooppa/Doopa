<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/log_activity.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only DELETE requests are allowed.',
        'data' => []
    ]);

    exit;
}

$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON request.',
        'data' => []
    ]);

    exit;
}

$educationId = isset($input['education_id'])
    ? (int) $input['education_id']
    : 0;

if ($educationId <= 0) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Valid education ID is required.',
        'data' => []
    ]);

    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | Find education record
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            user_id,
            institution,
            qualification
        FROM education
        WHERE id = :education_id
        LIMIT 1
    ");

    $stmt->execute([
        ':education_id' => $educationId
    ]);

    $education = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$education) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Education record not found.',
            'data' => []
        ]);

        exit;
    }

    $educationOwnerId = (int) $education['user_id'];
    $institution = (string) $education['institution'];
    $qualification = (string) $education['qualification'];

    /*
    |--------------------------------------------------------------------------
    | Delete education record
    |--------------------------------------------------------------------------
    */

    $delete = $pdo->prepare("
        DELETE FROM education
        WHERE id = :education_id
    ");

    $delete->execute([
        ':education_id' => $educationId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Record admin activity
    |--------------------------------------------------------------------------
    */

    logAdminActivity(
        $pdo,
        (int) $_SESSION['user_id'],
        'delete_education',
        'education',
        $educationId,
        'Administrator deleted education record ' . $educationId .
        ' (' . $institution . ' - ' . $qualification . ')' .
        ' owned by user ' . $educationOwnerId
    );

    /*
    |--------------------------------------------------------------------------
    | Success response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' => 'Education record deleted successfully by administrator.',
        'data' => [
            'education_id' => $educationId,
            'education_owner_id' => $educationOwnerId
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to delete education record.',
        'data' => []
    ]);

    exit;
}