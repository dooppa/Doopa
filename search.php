<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| doopa - Message Search
|--------------------------------------------------------------------------
|
| GET /api/messages/search.php
|
| Required:
|   conversation_id
|   q
|
| Optional:
|   limit
|   before_id
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
| Database Connection
|--------------------------------------------------------------------------
*/

try {
    $database = new Database();
    $pdo = $database->connect();
} catch (Throwable $e) {
    error_log(
        'doopa message search database error: ' . $e->getMessage()
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
| Validate conversation_id
|--------------------------------------------------------------------------
*/

if (!isset($_GET['conversation_id'])) {
    jsonResponse(
        false,
        'conversation_id is required.',
        [],
        422
    );
}

$conversationIdRaw = trim((string) $_GET['conversation_id']);

if (
    $conversationIdRaw === '' ||
    !ctype_digit($conversationIdRaw) ||
    (int) $conversationIdRaw <= 0
) {
    jsonResponse(
        false,
        'conversation_id must be a positive integer.',
        [],
        422
    );
}

$conversationId = (int) $conversationIdRaw;

/*
|--------------------------------------------------------------------------
| Validate Search Query
|--------------------------------------------------------------------------
*/

if (!isset($_GET['q'])) {
    jsonResponse(
        false,
        'Search query q is required.',
        [],
        422
    );
}

$query = trim((string) $_GET['q']);

if ($query === '') {
    jsonResponse(
        false,
        'Search query q cannot be empty.',
        [],
        422
    );
}

if (mb_strlen($query) > 200) {
    jsonResponse(
        false,
        'Search query q must not exceed 200 characters.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Validate Limit
|--------------------------------------------------------------------------
*/

$limit = 20;

if (isset($_GET['limit'])) {
    $limitRaw = trim((string) $_GET['limit']);

    if (
        $limitRaw === '' ||
        !ctype_digit($limitRaw) ||
        (int) $limitRaw <= 0
    ) {
        jsonResponse(
            false,
            'limit must be a positive integer.',
            [],
            422
        );
    }

    $limit = min((int) $limitRaw, 50);
}

/*
|--------------------------------------------------------------------------
| Validate before_id
|--------------------------------------------------------------------------
*/

$beforeId = null;

if (isset($_GET['before_id'])) {
    $beforeIdRaw = trim((string) $_GET['before_id']);

    if (
        $beforeIdRaw === '' ||
        !ctype_digit($beforeIdRaw) ||
        (int) $beforeIdRaw <= 0
    ) {
        jsonResponse(
            false,
            'before_id must be a positive integer.',
            [],
            422
        );
    }

    $beforeId = (int) $beforeIdRaw;
}

/*
|--------------------------------------------------------------------------
| Verify Conversation Exists
|--------------------------------------------------------------------------
*/

try {
    $conversationStmt = $pdo->prepare(
        'SELECT id
         FROM conversations
         WHERE id = ?
         LIMIT 1'
    );

    $conversationStmt->execute([$conversationId]);

    $conversation = $conversationStmt->fetch();

    if (!$conversation) {
        jsonResponse(
            false,
            'Conversation not found.',
            [],
            404
        );
    }
} catch (Throwable $e) {
    error_log(
        'doopa message search conversation error: ' . $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to verify conversation.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Verify User Is a Conversation Participant
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
        'doopa message search participant error: ' . $e->getMessage()
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
| Search Messages
|--------------------------------------------------------------------------
|
| Only non-deleted messages inside an authorised conversation
| are searched.
|
|--------------------------------------------------------------------------
*/

try {
    $sql = '
        SELECT
            m.id,
            m.conversation_id,
            m.sender_id,
            m.reply_to_message_id,
            m.forwarded_from_message_id,
            m.message,
            m.message_type,
            m.is_deleted,
            m.created_at,
            m.updated_at,

            u.full_name AS sender_full_name,
            u.username AS sender_username,
            u.avatar_url AS sender_avatar_url

        FROM messages m

        INNER JOIN users u
            ON u.id = m.sender_id

        WHERE m.conversation_id = ?
          AND m.is_deleted = 0
          AND m.message LIKE ?
    ';

    $params = [
        $conversationId,
        '%' . $query . '%'
    ];

    if ($beforeId !== null) {
        $sql .= ' AND m.id < ? ';
        $params[] = $beforeId;
    }

    $sql .= '
        ORDER BY m.id DESC
        LIMIT ?
    ';

    /*
    |--------------------------------------------------------------------------
    | Fetch One Extra Record For Pagination
    |--------------------------------------------------------------------------
    */

    $params[] = $limit + 1;

    $stmt = $pdo->prepare($sql);

    /*
    |--------------------------------------------------------------------------
    | PDO LIMIT Parameter
    |--------------------------------------------------------------------------
    |
    | MySQL prepared statements can require an integer parameter here.
    |
    */

    foreach ($params as $index => $value) {
        if ($index === count($params) - 1) {
            $stmt->bindValue(
                $index + 1,
                (int) $value,
                PDO::PARAM_INT
            );
        } else {
            $stmt->bindValue(
                $index + 1,
                $value
            );
        }
    }

    $stmt->execute();

    $messages = $stmt->fetchAll();

} catch (Throwable $e) {
    error_log(
        'doopa message search query error: ' . $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to search messages.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$hasMore = count($messages) > $limit;

if ($hasMore) {
    array_pop($messages);
}

/*
|--------------------------------------------------------------------------
| Reverse Results
|--------------------------------------------------------------------------
|
| Database returns newest first.
| API returns chronological order.
|--------------------------------------------------------------------------
*/

$messages = array_reverse($messages);

/*
|--------------------------------------------------------------------------
| Collect Message IDs
|--------------------------------------------------------------------------
*/

$messageIds = [];

foreach ($messages as $message) {
    $messageIds[] = (int) $message['id'];
}

/*
|--------------------------------------------------------------------------
| Prepare Supporting Data
|--------------------------------------------------------------------------
*/

$reactionsByMessage = [];
$myReactionsByMessage = [];
$mentionsByMessage = [];
$attachmentsByMessage = [];

/*
|--------------------------------------------------------------------------
| Load Reactions
|--------------------------------------------------------------------------
*/

if (!empty($messageIds)) {
    try {
        $placeholders = implode(
            ',',
            array_fill(0, count($messageIds), '?')
        );

        $reactionStmt = $pdo->prepare(
            "SELECT
                mr.id,
                mr.message_id,
                mr.user_id,
                mr.reaction,
                mr.created_at,
                u.full_name,
                u.username,
                u.avatar_url
             FROM message_reactions mr
             INNER JOIN users u
                ON u.id = mr.user_id
             WHERE mr.message_id IN ($placeholders)
             ORDER BY mr.id ASC"
        );

        $reactionStmt->execute($messageIds);

        while ($reaction = $reactionStmt->fetch()) {
            $messageId = (int) $reaction['message_id'];

            if (!isset($reactionsByMessage[$messageId])) {
                $reactionsByMessage[$messageId] = [];
            }

            $reactionsByMessage[$messageId][] = [
                'id' => (int) $reaction['id'],
                'user_id' => (int) $reaction['user_id'],
                'reaction' => $reaction['reaction'],
                'created_at' => $reaction['created_at'],
                'user' => [
                    'id' => (int) $reaction['user_id'],
                    'full_name' => $reaction['full_name'],
                    'username' => $reaction['username'],
                    'avatar_url' => $reaction['avatar_url']
                ]
            ];

            if ((int) $reaction['user_id'] === $userId) {
                if (!isset($myReactionsByMessage[$messageId])) {
                    $myReactionsByMessage[$messageId] = [];
                }

                $myReactionsByMessage[$messageId][] =
                    $reaction['reaction'];
            }
        }

    } catch (Throwable $e) {
        error_log(
            'doopa message search reactions error: ' . $e->getMessage()
        );

        jsonResponse(
            false,
            'Unable to load message reactions.',
            [],
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| Load Mentions
|--------------------------------------------------------------------------
*/

if (!empty($messageIds)) {
    try {
        $placeholders = implode(
            ',',
            array_fill(0, count($messageIds), '?')
        );

        $mentionStmt = $pdo->prepare(
            "SELECT
                mm.id,
                mm.message_id,
                mm.user_id,
                mm.created_at,
                u.full_name,
                u.username,
                u.avatar_url
             FROM message_mentions mm
             INNER JOIN users u
                ON u.id = mm.user_id
             WHERE mm.message_id IN ($placeholders)
             ORDER BY mm.id ASC"
        );

        $mentionStmt->execute($messageIds);

        while ($mention = $mentionStmt->fetch()) {
            $messageId = (int) $mention['message_id'];

            if (!isset($mentionsByMessage[$messageId])) {
                $mentionsByMessage[$messageId] = [];
            }

            $mentionsByMessage[$messageId][] = [
                'id' => (int) $mention['id'],
                'user_id' => (int) $mention['user_id'],
                'created_at' => $mention['created_at'],
                'user' => [
                    'id' => (int) $mention['user_id'],
                    'full_name' => $mention['full_name'],
                    'username' => $mention['username'],
                    'avatar_url' => $mention['avatar_url']
                ]
            ];
        }

    } catch (Throwable $e) {
        error_log(
            'doopa message search mentions error: ' . $e->getMessage()
        );

        jsonResponse(
            false,
            'Unable to load message mentions.',
            [],
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| Load Attachments
|--------------------------------------------------------------------------
*/

if (!empty($messageIds)) {
    try {
        $placeholders = implode(
            ',',
            array_fill(0, count($messageIds), '?')
        );

        /*
        |--------------------------------------------------------------------------
        | Check available attachment structure dynamically
        |--------------------------------------------------------------------------
        |
        | This keeps search compatible with the attachment implementation
        | already completed in Batch 8.
        |
        |--------------------------------------------------------------------------
        */

        $attachmentStmt = $pdo->prepare(
            "SELECT *
             FROM message_attachments
             WHERE message_id IN ($placeholders)
             ORDER BY id ASC"
        );

        $attachmentStmt->execute($messageIds);

        while ($attachment = $attachmentStmt->fetch()) {
            $messageId = (int) $attachment['message_id'];

            if (!isset($attachmentsByMessage[$messageId])) {
                $attachmentsByMessage[$messageId] = [];
            }

            $attachmentsByMessage[$messageId][] = $attachment;
        }

    } catch (Throwable $e) {
        /*
        |--------------------------------------------------------------------------
        | Do not make message search fail solely because attachment
        | retrieval has a schema mismatch.
        |--------------------------------------------------------------------------
        */

        error_log(
            'doopa message search attachments warning: ' .
            $e->getMessage()
        );

        $attachmentsByMessage = [];
    }
}

/*
|--------------------------------------------------------------------------
| Build Message Response
|--------------------------------------------------------------------------
*/

$responseMessages = [];

foreach ($messages as $message) {
    $messageId = (int) $message['id'];

    $replyTo = null;

    if (!empty($message['reply_to_message_id'])) {
        $replyTo = [
            'message_id' => (int) $message['reply_to_message_id']
        ];
    }

    $forwardedFrom = null;

    if (!empty($message['forwarded_from_message_id'])) {
        $forwardedFrom = [
            'message_id' =>
                (int) $message['forwarded_from_message_id']
        ];
    }

    $messageReactions =
        $reactionsByMessage[$messageId] ?? [];

    $messageMyReactions =
        $myReactionsByMessage[$messageId] ?? [];

    $messageMentions =
        $mentionsByMessage[$messageId] ?? [];

    $messageAttachments =
        $attachmentsByMessage[$messageId] ?? [];

    $responseMessages[] = [
        'id' => $messageId,

        'conversation_id' =>
            (int) $message['conversation_id'],

        'sender_id' =>
            (int) $message['sender_id'],

        'sender' => [
            'id' => (int) $message['sender_id'],
            'full_name' => $message['sender_full_name'],
            'username' => $message['sender_username'],
            'avatar_url' => $message['sender_avatar_url']
        ],

        'message' => $message['message'],

        'message_type' =>
            $message['message_type'],

        'is_deleted' =>
            (bool) $message['is_deleted'],

        'created_at' =>
            $message['created_at'],

        'updated_at' =>
            $message['updated_at'],

        'reply_to' => $replyTo,

        'forwarded_from' => $forwardedFrom,

        'reaction_count' =>
            count($messageReactions),

        'reactions' =>
            $messageReactions,

        'my_reactions' =>
            $messageMyReactions,

        'mention_count' =>
            count($messageMentions),

        'mentions' =>
            $messageMentions,

        'attachment_count' =>
            count($messageAttachments),

        'attachments' =>
            $messageAttachments
    ];
}

/*
|--------------------------------------------------------------------------
| Pagination Metadata
|--------------------------------------------------------------------------
*/

$firstMessageId = null;
$lastMessageId = null;

if (!empty($responseMessages)) {
    $firstMessageId =
        (int) $responseMessages[0]['id'];

    $lastMessageId =
        (int) $responseMessages[count($responseMessages) - 1]['id'];
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

jsonResponse(
    true,
    'Messages search completed successfully.',
    [
        'conversation_id' => $conversationId,

        'query' => $query,

        'count' => count($responseMessages),

        'pagination' => [
            'mode' => 'search',
            'limit' => $limit,
            'has_more' => $hasMore,
            'first_message_id' => $firstMessageId,
            'last_message_id' => $lastMessageId,
            'before_id' => $beforeId
        ],

        'messages' => $responseMessages
    ],
    200
);