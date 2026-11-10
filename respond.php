<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| doopa - Respond to Call API
|--------------------------------------------------------------------------
|
| POST /api/calls/respond.php
|
| JSON body:
|
| {
|     "call_id": 1,
|     "action": "accept"
| }
|
| Supported actions:
|   accept
|   reject
|
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=UTF-8');

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
    !is_numeric($_SESSION['user_id']) ||
    (int) $_SESSION['user_id'] <= 0
) {
    jsonResponse(
        false,
        'Authentication required.',
        [],
        401
    );
}

$userId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Read JSON Body
|--------------------------------------------------------------------------
*/

$rawInput = file_get_contents('php://input');

if ($rawInput === false || trim($rawInput) === '') {
    jsonResponse(
        false,
        'Request body is required.',
        [],
        422
    );
}

try {
    $input = json_decode(
        $rawInput,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $e) {
    jsonResponse(
        false,
        'Invalid JSON request body.',
        [],
        422
    );
}

if (!is_array($input)) {
    jsonResponse(
        false,
        'Request body must be a JSON object.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate call_id
|--------------------------------------------------------------------------
*/

if (!isset($input['call_id'])) {
    jsonResponse(
        false,
        'call_id is required.',
        [],
        422
    );
}

$callIdRaw = trim((string) $input['call_id']);

if (
    $callIdRaw === '' ||
    !ctype_digit($callIdRaw) ||
    (int) $callIdRaw <= 0
) {
    jsonResponse(
        false,
        'call_id must be a positive integer.',
        [],
        422
    );
}

$callId = (int) $callIdRaw;

/*
|--------------------------------------------------------------------------
| Validate Action
|--------------------------------------------------------------------------
*/

if (!isset($input['action'])) {
    jsonResponse(
        false,
        'action is required.',
        [],
        422
    );
}

$action = strtolower(trim((string) $input['action']));

if (!in_array($action, ['accept', 'reject'], true)) {
    jsonResponse(
        false,
        'action must be either accept or reject.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

try {
    $database = new Database();
    $pdo = $database->connect();
} catch (Throwable $e) {
    error_log(
        'doopa respond call database error: ' . $e->getMessage()
    );

    jsonResponse(
        false,
        'Database connection failed.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Retrieve Call
|--------------------------------------------------------------------------
*/

try {
    $callStmt = $pdo->prepare(
        "SELECT
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
         LIMIT 1"
    );

    $callStmt->execute([
        $callId
    ]);

    $call = $callStmt->fetch();

    if (!$call) {
        jsonResponse(
            false,
            'Call not found.',
            [],
            404
        );
    }
} catch (Throwable $e) {
    error_log(
        'doopa respond call retrieval error: ' . $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to retrieve call.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Verify Receiver
|--------------------------------------------------------------------------
|
| Only the intended receiver can accept or reject the call.
|--------------------------------------------------------------------------
*/

if ((int) $call['receiver_id'] !== $userId) {
    jsonResponse(
        false,
        'Only the receiver can respond to this call.',
        [],
        403
    );
}

/*
|--------------------------------------------------------------------------
| Verify Conversation Participation
|--------------------------------------------------------------------------
*/

try {
    $participantStmt = $pdo->prepare(
        'SELECT 1
         FROM conversation_participants
         WHERE conversation_id = ?
           AND user_id = ?
         LIMIT 1'
    );

    $participantStmt->execute([
        (int) $call['conversation_id'],
        $userId
    ]);

    if (!$participantStmt->fetchColumn()) {
        jsonResponse(
            false,
            'You are not a participant in this conversation.',
            [],
            403
        );
    }
} catch (Throwable $e) {
    error_log(
        'doopa respond call participant error: ' . $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to verify conversation access.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Call Must Still Be Ringing
|--------------------------------------------------------------------------
*/

if ($call['status'] !== 'ringing') {
    jsonResponse(
        false,
        'This call is no longer ringing.',
        [
            'call' => [
                'id' => (int) $call['id'],
                'status' => $call['status']
            ]
        ],
        409
    );
}

/*
|--------------------------------------------------------------------------
| Accept / Reject
|--------------------------------------------------------------------------
*/

try {
    $pdo->beginTransaction();

    if ($action === 'accept') {

        $updateStmt = $pdo->prepare(
            "UPDATE calls
             SET
                status = 'accepted',
                started_at = COALESCE(started_at, created_at),
                answered_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ?
               AND receiver_id = ?
               AND status = 'ringing'"
        );

        $updateStmt->execute([
            $callId,
            $userId
        ]);

        if ($updateStmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Call could not be accepted.'
            );
        }

        $successMessage = 'Call accepted successfully.';

    } else {

        $updateStmt = $pdo->prepare(
            "UPDATE calls
             SET
                status = 'rejected',
                ended_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ?
               AND receiver_id = ?
               AND status = 'ringing'"
        );

        $updateStmt->execute([
            $callId,
            $userId
        ]);

        if ($updateStmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Call could not be rejected.'
            );
        }

        $successMessage = 'Call rejected successfully.';
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieve Updated Call
    |--------------------------------------------------------------------------
    */

    $updatedStmt = $pdo->prepare(
        "SELECT
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
         LIMIT 1"
    );

    $updatedStmt->execute([
        $callId
    ]);

    $updatedCall = $updatedStmt->fetch();

    if (!$updatedCall) {
        throw new RuntimeException(
            'Updated call could not be retrieved.'
        );
    }

    $pdo->commit();

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'doopa respond call update error: ' . $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to update call.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

jsonResponse(
    true,
    $successMessage,
    [
        'call' => [
            'id' =>
                (int) $updatedCall['id'],

            'conversation_id' =>
                (int) $updatedCall['conversation_id'],

            'caller_id' =>
                (int) $updatedCall['caller_id'],

            'receiver_id' =>
                (int) $updatedCall['receiver_id'],

            'call_type' =>
                $updatedCall['call_type'],

            'status' =>
                $updatedCall['status'],

            'started_at' =>
                $updatedCall['started_at'],

            'answered_at' =>
                $updatedCall['answered_at'],

            'ended_at' =>
                $updatedCall['ended_at'],

            'duration_seconds' =>
                (int) $updatedCall['duration_seconds'],

            'created_at' =>
                $updatedCall['created_at'],

            'updated_at' =>
                $updatedCall['updated_at']
        ]
    ],
    200
);