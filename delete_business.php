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

$businessId = isset($input['business_id'])
    ? (int) $input['business_id']
    : 0;

if ($businessId <= 0) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Valid business ID is required.',
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
            name
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

    $businessOwnerId = (int) $business['owner_id'];
    $businessName = (string) $business['name'];

    /*
    |--------------------------------------------------------------------------
    | Delete business
    |--------------------------------------------------------------------------
    */

    $delete = $pdo->prepare("
        DELETE FROM businesses
        WHERE id = :business_id
    ");

    $delete->execute([
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
        'delete_business',
        'business',
        $businessId,
        'Administrator deleted business ' . $businessId .
        ' (' . $businessName . ')' .
        ' owned by user ' . $businessOwnerId
    );

    /*
    |--------------------------------------------------------------------------
    | Success response
    |--------------------------------------------------------------------------
    */

    echo json_encode([
        'success' => true,
        'message' => 'Business deleted successfully by administrator.',
        'data' => [
            'business_id' => $businessId,
            'business_owner_id' => $businessOwnerId
        ]
    ]);

} catch (PDOException $e) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Unable to delete business.',
        'data' => []
    ]);

    exit;
}