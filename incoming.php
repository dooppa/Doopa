<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| doopa - Incoming Call API
|--------------------------------------------------------------------------
|
| Endpoint:
| GET /api/calls/incoming.php
|
| Optional:
| ?conversation_id=1
|
| Purpose:
| - Retrieve the authenticated user's active incoming ringing call(s).
| - Does NOT accept, reject, or modify the call.
| - The receiver can use:
|      respond.php  -> accept/reject
|      missed.php   -> mark missed
|
| Security:
| - Authentication required.
| - Only calls where receiver_id = current user are returned.
| - Conversation participation is verified.
| - Caller information is returned only for the legitimate call.
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(
        false,
        'Only GET requests are allowed.',
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
| Optional Conversation ID
|--------------------------------------------------------------------------
*/

$conversationId = null;

if (
    isset($_GET['conversation_id']) &&
    $_GET['conversation_id'] !== ''
) {
    if (
        filter_var(
            $_GET['conversation_id'],
            FILTER_VALIDATE_INT
        ) === false ||
        (int) $_GET['conversation_id'] <= 0
    ) {
        jsonResponse(
            false,
            'conversation_id must be a positive integer.',
            [],
            400
        );
    }

    $conversationId = (int) $_GET['conversation_id'];
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
        'doopa incoming call database error: ' .
        $e->getMessage()
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
| Verify Conversation Access
|--------------------------------------------------------------------------
|
| If conversation_id is supplied, the authenticated user must be a
| participant in that conversation.
|--------------------------------------------------------------------------
*/

if ($conversationId !== null) {

    try {

        $participantStmt = $pdo->prepare(
            'SELECT 1
             FROM conversation_participants
             WHERE conversation_id = ?
               AND user_id = ?
             LIMIT 1'
        );

        $participantStmt->execute([
            $conversationId,
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
            'doopa incoming call participant error: ' .
            $e->getMessage()
        );

        jsonResponse(
            false,
            'Unable to verify conversation access.',
            [],
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| Build Query
|--------------------------------------------------------------------------
|
| Only calls where:
|
| receiver_id = authenticated user
| status       = ringing
|
| are considered incoming calls.
|--------------------------------------------------------------------------
*/

$where = [
    'c.receiver_id = :receiver_id',
    "c.status = 'ringing'"
];

$params = [
    ':receiver_id' => $userId
];

if ($conversationId !== null) {

    $where[] = 'c.conversation_id = :conversation_id';

    $params[':conversation_id'] = $conversationId;
}

/*
|--------------------------------------------------------------------------
| Retrieve Incoming Calls
|--------------------------------------------------------------------------
*/

try {

    $sql = "
        SELECT
            c.id,
            c.conversation_id,
            c.caller_id,
            c.receiver_id,
            c.call_type,
            c.status,
            c.started_at,
            c.answered_at,
            c.ended_at,
            c.duration_seconds,
            c.created_at,
            c.updated_at,

            caller.full_name AS caller_full_name,
            caller.username AS caller_username,
            caller.avatar_url AS caller_avatar_url

        FROM calls c

        INNER JOIN users caller
            ON caller.id = c.caller_id

        WHERE " . implode(' AND ', $where) . "

        ORDER BY c.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll();

} catch (Throwable $e) {

    error_log(
        'doopa incoming call retrieval error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Failed to retrieve incoming calls.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Additional Security Verification
|--------------------------------------------------------------------------
|
| Verify that each returned call's conversation actually contains both:
|
| 1. authenticated receiver
| 2. caller
|
| This provides an additional protection layer even though start.php
| already validates both participants.
|--------------------------------------------------------------------------
*/

$incomingCalls = [];

foreach ($rows as $row) {

    $callId = (int) $row['id'];
    $callConversationId = (int) $row['conversation_id'];
    $callerId = (int) $row['caller_id'];
    $receiverId = (int) $row['receiver_id'];

    /*
    |--------------------------------------------------------------------------
    | Verify Receiver Participation
    |--------------------------------------------------------------------------
    */

    try {

        $receiverParticipantStmt = $pdo->prepare(
            'SELECT 1
             FROM conversation_participants
             WHERE conversation_id = ?
               AND user_id = ?
             LIMIT 1'
        );

        $receiverParticipantStmt->execute([
            $callConversationId,
            $userId
        ]);

        if (!$receiverParticipantStmt->fetchColumn()) {
            continue;
        }

    } catch (Throwable $e) {

        error_log(
            'doopa incoming call receiver verification error: ' .
            $e->getMessage()
        );

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Verify Caller Participation
    |--------------------------------------------------------------------------
    */

    try {

        $callerParticipantStmt = $pdo->prepare(
            'SELECT 1
             FROM conversation_participants
             WHERE conversation_id = ?
               AND user_id = ?
             LIMIT 1'
        );

        $callerParticipantStmt->execute([
            $callConversationId,
            $callerId
        ]);

        if (!$callerParticipantStmt->fetchColumn()) {
            continue;
        }

    } catch (Throwable $e) {

        error_log(
            'doopa incoming call caller verification error: ' .
            $e->getMessage()
        );

        continue;
    }

    /*
    |--------------------------------------------------------------------------
    | Build Response
    |--------------------------------------------------------------------------
    */

    $incomingCalls[] = [
        'id' => $callId,

        'conversation_id' =>
            $callConversationId,

        'caller_id' =>
            $callerId,

        'receiver_id' =>
            $receiverId,

        'call_type' =>
            $row['call_type'],

        'status' =>
            $row['status'],

        'started_at' =>
            $row['started_at'],

        'answered_at' =>
            $row['answered_at'],

        'ended_at' =>
            $row['ended_at'],

        'duration_seconds' =>
            (int) $row['duration_seconds'],

        'created_at' =>
            $row['created_at'],

        'updated_at' =>
            $row['updated_at'],

        'caller' => [
            'id' =>
                $callerId,

            'full_name' =>
                $row['caller_full_name'],

            'username' =>
                $row['caller_username'],

            'avatar_url' =>
                $row['caller_avatar_url']
        ]
    ];
}

/*
|--------------------------------------------------------------------------
| Determine Latest Incoming Call
|--------------------------------------------------------------------------
*/

$latestCall = null;

if (!empty($incomingCalls)) {
    $latestCall = $incomingCalls[0];
}

/*
|--------------------------------------------------------------------------
| Success Response
|--------------------------------------------------------------------------
*/

jsonResponse(
    true,
    empty($incomingCalls)
        ? 'No incoming calls.'
        : 'Incoming calls retrieved successfully.',
    [
        'count' =>
            count($incomingCalls),

        'has_incoming_call' =>
            !empty($incomingCalls),

        'latest_call' =>
            $latestCall,

        'calls' =>
            $incomingCalls
    ],
    200
);