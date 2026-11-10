<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| doopa - End Call API
|--------------------------------------------------------------------------
| Endpoint:
| POST /api/calls/end.php
|
| Allows the caller or receiver to end an active call.
|
| Rules:
| - ringing call:
|      caller can cancel it
|      receiver must use respond.php to reject it
|
| - accepted call:
|      caller or receiver can end it
|      duration is calculated automatically
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
| Verify User Is Part Of The Call
|--------------------------------------------------------------------------
*/

$isCaller = ((int) $call['caller_id'] === $userId);
$isReceiver = ((int) $call['receiver_id'] === $userId);

if (!$isCaller && !$isReceiver) {
    jsonResponse(
        false,
        'You are not a participant in this call.',
        [],
        403
    );
}

/*
|--------------------------------------------------------------------------
| Handle Call Status
|--------------------------------------------------------------------------
*/

$currentStatus = (string) $call['status'];

/*
|--------------------------------------------------------------------------
| Ringing Call
|--------------------------------------------------------------------------
| Only the caller can cancel a ringing call.
| The receiver should use respond.php?action=reject.
|--------------------------------------------------------------------------
*/

if ($currentStatus === 'ringing') {

    if (!$isCaller) {
        jsonResponse(
            false,
            'A ringing call must be rejected using the call response endpoint.',
            [],
            409
        );
    }

    try {

        $pdo->beginTransaction();

        $update = $pdo->prepare(
            "
            UPDATE calls
            SET
                status = 'cancelled',
                ended_at = CURRENT_TIMESTAMP
            WHERE id = ?
              AND caller_id = ?
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
                'The call could not be cancelled. Its status may have changed.',
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
            'Failed to cancel the call.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Return Cancelled Call
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

    jsonResponse(
        true,
        'Call cancelled successfully.',
        [
            'call' => $updatedCall
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Accepted Call
|--------------------------------------------------------------------------
*/

if ($currentStatus === 'accepted') {

    try {

        $pdo->beginTransaction();

        /*
        |--------------------------------------------------------------------------
        | Calculate duration from answered_at
        |--------------------------------------------------------------------------
        | TIMESTAMPDIFF returns whole seconds.
        | GREATEST prevents negative duration.
        |--------------------------------------------------------------------------
        */

        $update = $pdo->prepare(
            "
            UPDATE calls
            SET
                status = 'ended',
                ended_at = CURRENT_TIMESTAMP,
                duration_seconds =
                    GREATEST(
                        0,
                        TIMESTAMPDIFF(
                            SECOND,
                            COALESCE(answered_at, started_at, created_at),
                            CURRENT_TIMESTAMP
                        )
                    )
            WHERE id = ?
              AND status = 'accepted'
              AND (caller_id = ? OR receiver_id = ?)
            "
        );

        $update->execute([
            $callId,
            $userId,
            $userId
        ]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();

            jsonResponse(
                false,
                'The call could not be ended. It may already have ended.',
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
            'Failed to end the call.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Return Ended Call
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

    jsonResponse(
        true,
        'Call ended successfully.',
        [
            'call' => $updatedCall
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Already Finished / Invalid State
|--------------------------------------------------------------------------
*/

if (
    in_array(
        $currentStatus,
        ['rejected', 'missed', 'ended', 'cancelled'],
        true
    )
) {
    jsonResponse(
        false,
        'This call has already finished.',
        [
            'call' => [
                'id' => (int) $call['id'],
                'status' => $currentStatus
            ]
        ],
        409
    );
}

/*
|--------------------------------------------------------------------------
| Unknown Status
|--------------------------------------------------------------------------
*/

jsonResponse(
    false,
    'The call is in an invalid state.',
    [],
    409
);