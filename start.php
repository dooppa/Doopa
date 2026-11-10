<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=UTF-8');

/*
|--------------------------------------------------------------------------
| Only allow POST requests
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
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id'])
) {
    jsonResponse(
        false,
        'Authentication required.',
        [],
        401
    );
}

$callerId = (int) $_SESSION['user_id'];

if ($callerId <= 0) {
    jsonResponse(
        false,
        'Invalid authenticated user.',
        [],
        401
    );
}

/*
|--------------------------------------------------------------------------
| Read JSON request
|--------------------------------------------------------------------------
*/

$rawInput = file_get_contents('php://input');

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
        'Invalid JSON request.',
        [],
        400
    );
}

if (!is_array($input)) {
    jsonResponse(
        false,
        'Invalid JSON request.',
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| Validate request
|--------------------------------------------------------------------------
*/

$conversationId = (int) ($input['conversation_id'] ?? 0);
$receiverId = (int) ($input['receiver_id'] ?? 0);
$callType = strtolower(trim((string) ($input['call_type'] ?? '')));

if ($conversationId <= 0) {
    jsonResponse(
        false,
        'Valid conversation ID is required.',
        [],
        422
    );
}

if ($receiverId <= 0) {
    jsonResponse(
        false,
        'Valid receiver ID is required.',
        [],
        422
    );
}

if (!in_array($callType, ['voice', 'video'], true)) {
    jsonResponse(
        false,
        'Call type must be voice or video.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Prevent self-call
|--------------------------------------------------------------------------
*/

if ($callerId === $receiverId) {
    jsonResponse(
        false,
        'You cannot call yourself.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

try {

    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | Verify conversation
    |--------------------------------------------------------------------------
    */

    $conversationStmt = $pdo->prepare(
        'SELECT
            id,
            type,
            created_by
         FROM conversations
         WHERE id = :conversation_id
         LIMIT 1'
    );

    $conversationStmt->execute([
        ':conversation_id' => $conversationId
    ]);

    $conversation = $conversationStmt->fetch();

    if (!$conversation) {
        jsonResponse(
            false,
            'Conversation not found.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Verify caller is a participant
    |--------------------------------------------------------------------------
    */

    $callerParticipantStmt = $pdo->prepare(
        'SELECT 1
         FROM conversation_participants
         WHERE conversation_id = :conversation_id
         AND user_id = :user_id
         LIMIT 1'
    );

    $callerParticipantStmt->execute([
        ':conversation_id' => $conversationId,
        ':user_id' => $callerId
    ]);

    if (!$callerParticipantStmt->fetchColumn()) {
        jsonResponse(
            false,
            'You are not a participant in this conversation.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Verify receiver is a participant
    |--------------------------------------------------------------------------
    */

    $receiverParticipantStmt = $pdo->prepare(
        'SELECT 1
         FROM conversation_participants
         WHERE conversation_id = :conversation_id
         AND user_id = :user_id
         LIMIT 1'
    );

    $receiverParticipantStmt->execute([
        ':conversation_id' => $conversationId,
        ':user_id' => $receiverId
    ]);

    if (!$receiverParticipantStmt->fetchColumn()) {
        jsonResponse(
            false,
            'The receiver is not a participant in this conversation.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Verify receiver exists
    |--------------------------------------------------------------------------
    */

    $receiverStmt = $pdo->prepare(
        'SELECT
            id,
            full_name,
            username,
            avatar_url
         FROM users
         WHERE id = :receiver_id
         LIMIT 1'
    );

    $receiverStmt->execute([
        ':receiver_id' => $receiverId
    ]);

    $receiver = $receiverStmt->fetch();

    if (!$receiver) {
        jsonResponse(
            false,
            'Receiver not found.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Prevent duplicate active calls
    |--------------------------------------------------------------------------
    */

    $activeCallStmt = $pdo->prepare(
        'SELECT
            id,
            status
         FROM calls
         WHERE conversation_id = :conversation_id
         AND (
             (caller_id = :caller_id_1 AND receiver_id = :receiver_id_1)
             OR
             (caller_id = :caller_id_2 AND receiver_id = :receiver_id_2)
         )
         AND status IN ("ringing", "accepted")
         ORDER BY id DESC
         LIMIT 1'
    );

    $activeCallStmt->execute([
        ':conversation_id' => $conversationId,
        ':caller_id_1' => $callerId,
        ':receiver_id_1' => $receiverId,
        ':caller_id_2' => $receiverId,
        ':receiver_id_2' => $callerId
    ]);

    $activeCall = $activeCallStmt->fetch();

    if ($activeCall) {
        jsonResponse(
            false,
            'An active call already exists between these users.',
            [
                'call_id' => (int) $activeCall['id'],
                'status' => $activeCall['status']
            ],
            409
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Begin transaction
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Create call
    |--------------------------------------------------------------------------
    */

    $insertCallStmt = $pdo->prepare(
        'INSERT INTO calls (
            conversation_id,
            caller_id,
            receiver_id,
            call_type,
            status
         ) VALUES (
            :conversation_id,
            :caller_id,
            :receiver_id,
            :call_type,
            "ringing"
         )'
    );

    $insertCallStmt->execute([
        ':conversation_id' => $conversationId,
        ':caller_id' => $callerId,
        ':receiver_id' => $receiverId,
        ':call_type' => $callType
    ]);

    $callId = (int) $pdo->lastInsertId();

    if ($callId <= 0) {
        throw new RuntimeException('Unable to create call.');
    }

    /*
    |--------------------------------------------------------------------------
    | Create incoming call notification
    |--------------------------------------------------------------------------
    */

    $notificationMessage = 'is calling you';

    $notificationStmt = $pdo->prepare(
        'INSERT INTO notifications (
            user_id,
            actor_id,
            type,
            reference_id,
            message,
            is_read
         ) VALUES (
            :user_id,
            :actor_id,
            :type,
            :reference_id,
            :message,
            0
         )'
    );

    $notificationStmt->execute([
        ':user_id' => $receiverId,
        ':actor_id' => $callerId,
        ':type' => 'call',
        ':reference_id' => $callId,
        ':message' => $notificationMessage
    ]);

    $notificationId = (int) $pdo->lastInsertId();

    /*
    |--------------------------------------------------------------------------
    | Retrieve created call
    |--------------------------------------------------------------------------
    */

    $callStmt = $pdo->prepare(
        'SELECT
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
         WHERE id = :call_id
         LIMIT 1'
    );

    $callStmt->execute([
        ':call_id' => $callId
    ]);

    $call = $callStmt->fetch();

    if (!$call) {
        throw new RuntimeException('Unable to retrieve created call.');
    }

    /*
    |--------------------------------------------------------------------------
    | Commit transaction
    |--------------------------------------------------------------------------
    */

    $pdo->commit();

    /*
    |--------------------------------------------------------------------------
    | Return success
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        'Call started successfully.',
        [
            'call' => [
                'id' => (int) $call['id'],
                'conversation_id' => (int) $call['conversation_id'],
                'caller_id' => (int) $call['caller_id'],
                'receiver_id' => (int) $call['receiver_id'],
                'call_type' => $call['call_type'],
                'status' => $call['status'],
                'started_at' => $call['started_at'],
                'answered_at' => $call['answered_at'],
                'ended_at' => $call['ended_at'],
                'duration_seconds' => (int) $call['duration_seconds'],
                'created_at' => $call['created_at'],
                'updated_at' => $call['updated_at']
            ],
            'receiver' => [
                'id' => (int) $receiver['id'],
                'full_name' => $receiver['full_name'],
                'username' => $receiver['username'],
                'avatar_url' => $receiver['avatar_url']
            ],
            'notification' => [
                'id' => $notificationId,
                'user_id' => $receiverId,
                'actor_id' => $callerId,
                'type' => 'call',
                'reference_id' => $callId,
                'message' => $notificationMessage,
                'is_read' => false
            ]
        ],
        201
    );

} catch (Throwable $e) {

    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Call start error: ' . $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to start call.',
        [],
        500
    );
}