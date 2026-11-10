<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| doopa - Call History API
|--------------------------------------------------------------------------
| GET /api/calls/history.php
|
| Optional:
| ?conversation_id=1
| ?status=missed
| ?call_type=voice
| ?limit=20
| ?before_id=2
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

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
| Database
|--------------------------------------------------------------------------
*/

$database = new Database();
$pdo = $database->connect();

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
| Optional Status
|--------------------------------------------------------------------------
*/

$status = null;

$allowedStatuses = [
    'ringing',
    'accepted',
    'rejected',
    'missed',
    'ended',
    'cancelled'
];

if (
    isset($_GET['status']) &&
    $_GET['status'] !== ''
) {
    $status = strtolower(
        trim((string) $_GET['status'])
    );

    if (!in_array($status, $allowedStatuses, true)) {
        jsonResponse(
            false,
            'Invalid call status.',
            [
                'allowed_statuses' => $allowedStatuses
            ],
            400
        );
    }
}

/*
|--------------------------------------------------------------------------
| Optional Call Type
|--------------------------------------------------------------------------
*/

$callType = null;

$allowedCallTypes = [
    'voice',
    'video'
];

if (
    isset($_GET['call_type']) &&
    $_GET['call_type'] !== ''
) {
    $callType = strtolower(
        trim((string) $_GET['call_type'])
    );

    if (!in_array($callType, $allowedCallTypes, true)) {
        jsonResponse(
            false,
            'Invalid call_type.',
            [
                'allowed_call_types' => $allowedCallTypes
            ],
            400
        );
    }
}

/*
|--------------------------------------------------------------------------
| Limit
|--------------------------------------------------------------------------
*/

$limit = 20;

if (
    isset($_GET['limit']) &&
    $_GET['limit'] !== ''
) {
    if (
        filter_var(
            $_GET['limit'],
            FILTER_VALIDATE_INT
        ) === false ||
        (int) $_GET['limit'] <= 0
    ) {
        jsonResponse(
            false,
            'limit must be a positive integer.',
            [],
            400
        );
    }

    $limit = min(
        (int) $_GET['limit'],
        50
    );
}

/*
|--------------------------------------------------------------------------
| Before ID
|--------------------------------------------------------------------------
*/

$beforeId = null;

if (
    isset($_GET['before_id']) &&
    $_GET['before_id'] !== ''
) {
    if (
        filter_var(
            $_GET['before_id'],
            FILTER_VALIDATE_INT
        ) === false ||
        (int) $_GET['before_id'] <= 0
    ) {
        jsonResponse(
            false,
            'before_id must be a positive integer.',
            [],
            400
        );
    }

    $beforeId = (int) $_GET['before_id'];
}

/*
|--------------------------------------------------------------------------
| WHERE Conditions
|--------------------------------------------------------------------------
|
| IMPORTANT:
| caller and receiver use DIFFERENT PDO placeholders.
| This is required because emulate prepares is disabled.
|--------------------------------------------------------------------------
*/

$where = [
    '(c.caller_id = :caller_user_id OR c.receiver_id = :receiver_user_id)'
];

$params = [
    ':caller_user_id' => $userId,
    ':receiver_user_id' => $userId
];

/*
|--------------------------------------------------------------------------
| Conversation Filter
|--------------------------------------------------------------------------
*/

if ($conversationId !== null) {

    /*
    |--------------------------------------------------------------------------
    | Verify conversation participant
    |--------------------------------------------------------------------------
    */

    $participantStmt = $pdo->prepare(
        "
        SELECT 1
        FROM conversation_participants
        WHERE conversation_id = ?
          AND user_id = ?
        LIMIT 1
        "
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

    $where[] = 'c.conversation_id = :conversation_id';

    $params[':conversation_id'] = $conversationId;
}

/*
|--------------------------------------------------------------------------
| Status Filter
|--------------------------------------------------------------------------
*/

if ($status !== null) {
    $where[] = 'c.status = :status';
    $params[':status'] = $status;
}

/*
|--------------------------------------------------------------------------
| Call Type Filter
|--------------------------------------------------------------------------
*/

if ($callType !== null) {
    $where[] = 'c.call_type = :call_type';
    $params[':call_type'] = $callType;
}

/*
|--------------------------------------------------------------------------
| Pagination Filter
|--------------------------------------------------------------------------
*/

if ($beforeId !== null) {
    $where[] = 'c.id < :before_id';
    $params[':before_id'] = $beforeId;
}

/*
|--------------------------------------------------------------------------
| Fetch One Extra Record
|--------------------------------------------------------------------------
*/

$sqlLimit = $limit + 1;

/*
|--------------------------------------------------------------------------
| Main Query
|--------------------------------------------------------------------------
*/

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
        caller.avatar_url AS caller_avatar_url,

        receiver.full_name AS receiver_full_name,
        receiver.username AS receiver_username,
        receiver.avatar_url AS receiver_avatar_url

    FROM calls c

    INNER JOIN users caller
        ON caller.id = c.caller_id

    INNER JOIN users receiver
        ON receiver.id = c.receiver_id

    WHERE " . implode(' AND ', $where) . "

    ORDER BY c.id DESC

    LIMIT " . $sqlLimit;

/*
|--------------------------------------------------------------------------
| Execute Query
|--------------------------------------------------------------------------
*/

try {

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = $stmt->fetchAll();

} catch (Throwable $e) {

    jsonResponse(
        false,
        'Failed to retrieve call history.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Determine More Records
|--------------------------------------------------------------------------
*/

$hasMore = count($rows) > $limit;

if ($hasMore) {
    array_pop($rows);
}

/*
|--------------------------------------------------------------------------
| Format Results
|--------------------------------------------------------------------------
*/

$calls = [];

foreach ($rows as $row) {

    $callerId = (int) $row['caller_id'];
    $receiverId = (int) $row['receiver_id'];

    /*
    |--------------------------------------------------------------------------
    | Determine Direction
    |--------------------------------------------------------------------------
    */

    if ($callerId === $userId) {

        $direction = 'outgoing';

        $otherParticipant = [
            'id' => $receiverId,
            'full_name' => $row['receiver_full_name'],
            'username' => $row['receiver_username'],
            'avatar_url' => $row['receiver_avatar_url']
        ];

    } else {

        $direction = 'incoming';

        $otherParticipant = [
            'id' => $callerId,
            'full_name' => $row['caller_full_name'],
            'username' => $row['caller_username'],
            'avatar_url' => $row['caller_avatar_url']
        ];
    }

    $calls[] = [
        'id' => (int) $row['id'],
        'conversation_id' => (int) $row['conversation_id'],
        'caller_id' => $callerId,
        'receiver_id' => $receiverId,
        'direction' => $direction,
        'call_type' => $row['call_type'],
        'status' => $row['status'],
        'started_at' => $row['started_at'],
        'answered_at' => $row['answered_at'],
        'ended_at' => $row['ended_at'],
        'duration_seconds' => (int) $row['duration_seconds'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'other_participant' => $otherParticipant
    ];
}

/*
|--------------------------------------------------------------------------
| Next Pagination ID
|--------------------------------------------------------------------------
*/

$nextBeforeId = null;

if (
    $hasMore &&
    !empty($calls)
) {
    $lastCall = end($calls);

    $nextBeforeId = (int) $lastCall['id'];
}

/*
|--------------------------------------------------------------------------
| Success Response
|--------------------------------------------------------------------------
*/

jsonResponse(
    true,
    'Call history retrieved successfully.',
    [
        'page' => [
            'limit' => $limit,
            'count' => count($calls),
            'has_more' => $hasMore,
            'next_before_id' => $nextBeforeId
        ],

        'filters' => [
            'conversation_id' => $conversationId,
            'status' => $status,
            'call_type' => $callType
        ],

        'calls' => $calls
    ]
);