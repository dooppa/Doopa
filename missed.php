<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| doopa - Mark Call As Missed API
|--------------------------------------------------------------------------
| Endpoint:
| POST /api/calls/missed.php
|
| Purpose:
| Marks an unanswered ringing call as missed.
|
| Rules:
| - User must be authenticated.
| - Only the receiver can mark a call as missed.
| - Call must currently be ringing.
| - Finished/accepted calls cannot be marked as missed.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

/*
|--------------------------------------------------------------------------
| Request Method
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
| Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !is_numeric($_SESSION['user_id'])
) {
    jsonResponse(
        false,
        'Authentication required.',
        [],
        401
    );
}

$userId = (int) $_SESSION['user_id'];

if ($userId <= 0) {
    jsonResponse(
        false,
        'Invalid authenticated user.',
        [],
        401
    );
}

/*
|--------------------------------------------------------------------------
| Read JSON Body
|--------------------------------------------------------------------------
*/

$rawInput = file_get_contents('php://input');

if ($rawInput === false || trim($rawInput) === '') {
    jsonResponse(
        false,
        'Request body cannot be empty.',
        [],
        400
    );
}

$data = json_decode($rawInput, true);

if (
    !is_array($data) ||
    json_last_error() !== JSON_ERROR_NONE
) {
    jsonResponse(
        false,
        'Invalid JSON request body.',
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| Validate Call ID
|--------------------------------------------------------------------------
*/

$callId = $data['call_id'] ?? null;

if (
    $callId === null ||
    filter_var($callId, FILTER_VALIDATE_INT) === false ||
    (int) $callId <= 0
) {
    jsonResponse(
        false,
        'call_id must be a positive integer.',
        [],
        400
    );
}

$callId = (int) $callId;

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

$database = new Database();
$pdo = $database->connect();

/*
|--------------------------------------------------------------------------
| Load Call
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "
    SELECT
        id,
        conversation_id,
        caller_id,
        receiver_id,
        call_type,
        status,
        started_at,
        answered_at,
        ended_at,
        duration_seconds,
        created_at,
        updated_at
    FROM calls
    WHERE id = ?
    LIMIT 1
    "
);

$stmt->execute([$callId]);

$call = $stmt->fetch();

if (!$call) {
    jsonResponse(
        false,
        'Call not found.',
        [],
        404
    );
}

/*
|--------------------------------------------------------------------------
| Verify Receiver
|--------------------------------------------------------------------------
*/

if ((int) $call['receiver_id'] !== $userId) {
    jsonResponse(
        false,
        'Only the receiver can mark this call as missed.',
        [],
        403
    );
}

/*
|--------------------------------------------------------------------------
| Verify Current Status
|--------------------------------------------------------------------------
*/

if ($call['status'] !== 'ringing') {

    if ($call['status'] === 'accepted') {
        jsonResponse(
            false,
            'An accepted call cannot be marked as missed.',
            [],
            409
        );
    }

    if ($call['status'] === 'rejected') {
        jsonResponse(
            false,
            'This call was already rejected.',
            [],
            409
        );
    }

    if ($call['status'] === 'ended') {
        jsonResponse(
            false,
            'This call has already ended.',
            [],
            409
        );
    }

    if ($call['status'] === 'cancelled') {
        jsonResponse(
            false,
            'This call was already cancelled.',
            [],
            409
        );
    }

    if ($call['status'] === 'missed') {
        jsonResponse(
            false,
            'This call is already marked as missed.',
            [],
            409
        );
    }

    jsonResponse(
        false,
        'The call is not in a valid state for marking as missed.',
        [],
        409
    );
}

/*
|--------------------------------------------------------------------------
| Mark Call As Missed
|--------------------------------------------------------------------------
*/

try {

    $pdo->beginTransaction();

    $update = $pdo->prepare(
        "
        UPDATE calls
        SET
            status = 'missed',
            ended_at = CURRENT_TIMESTAMP,
            duration_seconds = 0
        WHERE id = ?
          AND receiver_id = ?
          AND status = 'ringing'
        "
    );

    $update->execute([
        $callId,
        $userId
    ]);

    if ($update->rowCount() !== 1) {

        $pdo->rollBack();

        jsonResponse(
            false,
            'The call could not be marked as missed. Its status may have changed.',
            [],
            409
        );
    }

    $pdo->commit();

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonResponse(
        false,
        'Failed to mark the call as missed.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Retrieve Updated Call
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare(
    "
    SELECT
        id,
        conversation_id,
        caller_id,
        receiver_id,
        call_type,
        status,
        started_at,
        answered_at,
        ended_at,
        duration_seconds,
        created_at,
        updated_at
    FROM calls
    WHERE id = ?
    LIMIT 1
    "
);

$stmt->execute([$callId]);

$updatedCall = $stmt->fetch();

if (!$updatedCall) {
    jsonResponse(
        false,
        'Call was updated but could not be retrieved.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Success Response
|--------------------------------------------------------------------------
*/

jsonResponse(
    true,
    'Call marked as missed successfully.',
    [
        'call' => $updatedCall
    ]
);