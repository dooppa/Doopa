<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/log_activity.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Only PATCH requests are allowed.',
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

$businessId = isset($input['business_id'])
    ? (int) $input['business_id']
    : 0;

$status = isset($input['status'])
    ? trim((string) $input['status'])
    : '';

if ($businessId <= 0) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Valid business ID is required.',
        'data' => []
    ]);

    exit;
}

$allowedStatuses = [
    'active',
    'inactive',
    'suspended'
];

if (!in_array($status, $allowedStatuses, true)) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Invalid business status.',
        'data' => []
    ]);

    exit;
}

try {

    /*
    |--------------------------------------------------------------------------
    | Find business
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            owner_id,
            name,
            status
        FROM businesses
        WHERE id = :business_id
        LIMIT 1
    ");

    $stmt->execute([
        ':business_id' => $businessId
    ]);

    $business = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$business) {
        http_response_code(404);

        echo json_encode([
            'success' => false,
            'message' => 'Business not found.',
            'data' => []
        ]);

        exit;
    }

    $oldStatus = (string) $business['status'];
    $businessOwnerId = (int) $business['owner_id'];
    $businessName = (string) $business['name'];

    /*
    |--------------------------------------------------------------------------
    | Update status
    |--------------------------------------------------------------------------
    */

    $update = $pdo->prepare("
        UPDATE businesses
        SET status = :status
        WHERE id = :business_id
    ");

    $update->execute([
        ':status' => $status,
        ':business_id' => $businessId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Record admin activity
    |--------------------------------------------------------------------------
    */

    logAdminActivity(
        $pdo,
        (int) $_SESSION['user_id'],
        'change_business_status',
        'business',
        $businessId,
        'Administrator changed business ' . $businessId .
        ' (' . $businessName . ')' .
        ' status from ' . $oldStatus .
        ' to ' . $status .
        ' for user ' . $businessOwnerId
    );

    /*
    |--------------------------------------------------------------------------
    | Success response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' => 'Business status updated successfully.',
        'data' => [
            'business_id' => $businessId,
            'owner_id' => $businessOwnerId,
            'old_status' => $oldStatus,
            'status' => $status
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to update business status.',
        'data' => []
    ]);

    exit;
}