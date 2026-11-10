<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Doopa - List Conversation Messages
|--------------------------------------------------------------------------
| Endpoint:
| GET /api/messages/list.php?conversation_id=1
|
| Features:
| - Authentication
| - Conversation existence check
| - Conversation participation check
| - Latest/older/newer pagination
| - Sender information
| - Attachments
| - Deleted-message handling
| - Read-state update
| - Reply information
| - Forward information
| - Message reactions
| - Current-user reaction information
| - Message mentions
| - Mention count
| - Strict pagination validation
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
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id']) ||
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
| Input
|--------------------------------------------------------------------------
*/

$conversationIdRaw = $_GET['conversation_id'] ?? null;
$limitRaw         = $_GET['limit'] ?? null;
$beforeIdRaw      = $_GET['before_id'] ?? null;
$afterIdRaw       = $_GET['after_id'] ?? null;

/*
|--------------------------------------------------------------------------
| Validate Conversation ID
|--------------------------------------------------------------------------
*/

if (
    $conversationIdRaw === null ||
    is_array($conversationIdRaw) ||
    !is_scalar($conversationIdRaw) ||
    !preg_match('/^[1-9][0-9]*$/', (string) $conversationIdRaw)
) {
    jsonResponse(
        false,
        'Valid conversation ID is required.',
        [],
        422
    );
}

$conversationId = (int) $conversationIdRaw;

if ($conversationId <= 0) {
    jsonResponse(
        false,
        'Valid conversation ID is required.',
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

if ($limitRaw !== null) {

    if (
        is_array($limitRaw) ||
        !is_scalar($limitRaw)
    ) {
        jsonResponse(
            false,
            'Invalid limit. Limit must be a whole number.',
            [],
            422
        );
    }

    $limitString = (string) $limitRaw;

    if (!preg_match('/^-?[0-9]+$/', $limitString)) {
        jsonResponse(
            false,
            'Invalid limit. Limit must be a whole number.',
            [],
            422
        );
    }

    $limit = (int) $limitString;

    if ($limit <= 0) {
        $limit = 20;
    }

    if ($limit > 100) {
        $limit = 100;
    }
}

/*
|--------------------------------------------------------------------------
| Validate before_id
|--------------------------------------------------------------------------
*/

$beforeId = 0;

if ($beforeIdRaw !== null) {

    if (
        is_array($beforeIdRaw) ||
        !is_scalar($beforeIdRaw) ||
        !preg_match('/^[1-9][0-9]*$/', (string) $beforeIdRaw)
    ) {
        jsonResponse(
            false,
            'Invalid before_id. before_id must be a positive integer.',
            [],
            422
        );
    }

    $beforeId = (int) $beforeIdRaw;

    if ($beforeId <= 0) {
        jsonResponse(
            false,
            'Invalid before_id. before_id must be a positive integer.',
            [],
            422
        );
    }
}

/*
|--------------------------------------------------------------------------
| Validate after_id
|--------------------------------------------------------------------------
*/

$afterId = 0;

if ($afterIdRaw !== null) {

    if (
        is_array($afterIdRaw) ||
        !is_scalar($afterIdRaw) ||
        !preg_match('/^[1-9][0-9]*$/', (string) $afterIdRaw)
    ) {
        jsonResponse(
            false,
            'Invalid after_id. after_id must be a positive integer.',
            [],
            422
        );
    }

    $afterId = (int) $afterIdRaw;

    if ($afterId <= 0) {
        jsonResponse(
            false,
            'Invalid after_id. after_id must be a positive integer.',
            [],
            422
        );
    }
}

/*
|--------------------------------------------------------------------------
| Validate Pagination Direction
|--------------------------------------------------------------------------
*/

if ($beforeId > 0 && $afterId > 0) {
    jsonResponse(
        false,
        'before_id and after_id cannot be used together.',
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
    | Verify Conversation Exists
    |--------------------------------------------------------------------------
    */

    $conversationStmt = $pdo->prepare(
        'SELECT id
         FROM conversations
         WHERE id = :conversation_id
         LIMIT 1'
    );

    $conversationStmt->execute([
        ':conversation_id' => $conversationId
    ]);

    $conversation = $conversationStmt->fetch(PDO::FETCH_ASSOC);

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
    | Verify User Is a Participant
    |--------------------------------------------------------------------------
    */

    $participantStmt = $pdo->prepare(
        'SELECT id, last_read_at
         FROM conversation_participants
         WHERE conversation_id = :conversation_id
           AND user_id = :user_id
         LIMIT 1'
    );

    $participantStmt->execute([
        ':conversation_id' => $conversationId,
        ':user_id' => $userId
    ]);

    $participant = $participantStmt->fetch(PDO::FETCH_ASSOC);

    if (!$participant) {
        jsonResponse(
            false,
            'You are not a participant in this conversation.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Determine Pagination Mode
    |--------------------------------------------------------------------------
    */

    $paginationMode = 'latest';

    if ($beforeId > 0) {
        $paginationMode = 'before';
    } elseif ($afterId > 0) {
        $paginationMode = 'after';
    }

    /*
    |--------------------------------------------------------------------------
    | Retrieve Messages
    |--------------------------------------------------------------------------
    */

    $baseSelect = '
        SELECT
            m.id,
            m.conversation_id,
            m.sender_id,

            u.full_name AS sender_name,
            u.username AS sender_username,
            u.avatar_url AS sender_avatar,

            m.reply_to_message_id,
            m.forwarded_from_message_id,

            m.message,
            m.message_type,
            m.is_deleted,
            m.created_at,
            m.updated_at

        FROM messages m

        INNER JOIN users u
            ON u.id = m.sender_id
    ';

    if ($paginationMode === 'before') {

        $sql = $baseSelect . '
            WHERE m.conversation_id = :conversation_id
              AND m.id < :before_id
            ORDER BY m.id DESC
            LIMIT ' . ($limit + 1);

        $messageStmt = $pdo->prepare($sql);

        $messageStmt->execute([
            ':conversation_id' => $conversationId,
            ':before_id' => $beforeId
        ]);

        $messages = $messageStmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($messages) > $limit;

        if ($hasMore) {
            array_pop($messages);
        }

        $messages = array_reverse($messages);

    } elseif ($paginationMode === 'after') {

        $sql = $baseSelect . '
            WHERE m.conversation_id = :conversation_id
              AND m.id > :after_id
            ORDER BY m.id ASC
            LIMIT ' . ($limit + 1);

        $messageStmt = $pdo->prepare($sql);

        $messageStmt->execute([
            ':conversation_id' => $conversationId,
            ':after_id' => $afterId
        ]);

        $messages = $messageStmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($messages) > $limit;

        if ($hasMore) {
            array_pop($messages);
        }

    } else {

        $sql = $baseSelect . '
            WHERE m.conversation_id = :conversation_id
            ORDER BY m.id DESC
            LIMIT ' . ($limit + 1);

        $messageStmt = $pdo->prepare($sql);

        $messageStmt->execute([
            ':conversation_id' => $conversationId
        ]);

        $messages = $messageStmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore = count($messages) > $limit;

        if ($hasMore) {
            array_pop($messages);
        }

        $messages = array_reverse($messages);
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination IDs
    |--------------------------------------------------------------------------
    */

    $firstMessageId = null;
    $lastMessageId = null;

    if (!empty($messages)) {

        $firstMessageId = (int) $messages[0]['id'];

        $lastMessageId =
            (int) $messages[count($messages) - 1]['id'];
    }

    /*
    |--------------------------------------------------------------------------
    | Collect Message IDs
    |--------------------------------------------------------------------------
    */

    $messageIds = [];

    foreach ($messages as $messageRow) {
        $messageIds[] = (int) $messageRow['id'];
    }

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    */

    $attachmentsByMessage = [];

    if (!empty($messageIds)) {

        $placeholders = implode(
            ',',
            array_fill(0, count($messageIds), '?')
        );

        $attachmentStmt = $pdo->prepare(
            "SELECT
                id,
                message_id,
                file_name,
                original_name,
                file_path,
                mime_type,
                file_size,
                attachment_type,
                created_at
             FROM message_attachments
             WHERE message_id IN ($placeholders)
             ORDER BY id ASC"
        );

        $attachmentStmt->execute($messageIds);

        $attachments = $attachmentStmt->fetchAll(PDO::FETCH_ASSOC);

       
foreach ($attachments as $attachment) {

    $attachmentMessageId =
        (int) $attachment['message_id'];

    if (!isset($attachmentsByMessage[$attachmentMessageId])) {
        $attachmentsByMessage[$attachmentMessageId] = [];
    }

    // Keep the stored relative path for existing API compatibility.
    $filePath = str_replace(
        '\\',
        '/',
        (string) $attachment['file_path']
    );

    $filePath = ltrim($filePath, '/');

    // Serve attachments through the protected delivery endpoint.
    $attachmentId = (int) $attachment['id'];

    $fileUrl =
        '/login%20template/doopa/api/messages/attachment_file.php?id=' .
        rawurlencode((string) $attachmentId);

    $attachmentsByMessage[$attachmentMessageId][] = [
        'id' => $attachmentId,
        'message_id' => $attachmentMessageId,
        'file_name' => $attachment['file_name'],
        'original_name' => $attachment['original_name'],
        'file_path' => $filePath,
        'file_url' => $fileUrl,
        'mime_type' => $attachment['mime_type'],
        'file_size' => (int) $attachment['file_size'],
        'attachment_type' => $attachment['attachment_type'],
        'created_at' => $attachment['created_at']
    ];
}
    }

    /*
    |--------------------------------------------------------------------------
    | Reactions
    |--------------------------------------------------------------------------
    */

    $reactionsByMessage = [];

    if (!empty($messageIds)) {

        $placeholders = implode(
            ',',
            array_fill(0, count($messageIds), '?')
        );

        $reactionSql = "
            SELECT
                mr.id,
                mr.message_id,
                mr.user_id,
                u.full_name,
                u.username,
                u.avatar_url,
                mr.reaction,
                mr.created_at,
                mr.updated_at
            FROM message_reactions mr
            INNER JOIN users u
                ON u.id = mr.user_id
            WHERE mr.message_id IN ($placeholders)
            ORDER BY mr.id ASC
        ";

        $reactionStmt = $pdo->prepare($reactionSql);

        $reactionStmt->execute($messageIds);

        $reactions = $reactionStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($reactions as $reaction) {

            $reactionMessageId =
                (int) $reaction['message_id'];

            if (!isset($reactionsByMessage[$reactionMessageId])) {
                $reactionsByMessage[$reactionMessageId] = [];
            }

            $reactionsByMessage[$reactionMessageId][] = [
                'id' => (int) $reaction['id'],
                'user_id' => (int) $reaction['user_id'],
                'user' => [
                    'id' => (int) $reaction['user_id'],
                    'full_name' => $reaction['full_name'],
                    'username' => $reaction['username'],
                    'avatar_url' => $reaction['avatar_url']
                ],
                'reaction' => $reaction['reaction'],
                'created_at' => $reaction['created_at'],
                'updated_at' => $reaction['updated_at']
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Mentions
    |--------------------------------------------------------------------------
    */

    $mentionsByMessage = [];

    if (!empty($messageIds)) {

        $placeholders = implode(
            ',',
            array_fill(0, count($messageIds), '?')
        );

        $mentionSql = "
            SELECT
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
            INNER JOIN messages m
                ON m.id = mm.message_id
            WHERE mm.message_id IN ($placeholders)
              AND m.conversation_id = ?
            ORDER BY mm.id ASC
        ";

        $mentionStmt = $pdo->prepare($mentionSql);

        $mentionParams = $messageIds;
        $mentionParams[] = $conversationId;

        $mentionStmt->execute($mentionParams);

        $mentions = $mentionStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($mentions as $mention) {

            $mentionMessageId =
                (int) $mention['message_id'];

            if (!isset($mentionsByMessage[$mentionMessageId])) {
                $mentionsByMessage[$mentionMessageId] = [];
            }

            $mentionsByMessage[$mentionMessageId][] = [
                'id' => (int) $mention['id'],
                'message_id' => (int) $mention['message_id'],
                'user_id' => (int) $mention['user_id'],
                'user' => [
                    'id' => (int) $mention['user_id'],
                    'full_name' => $mention['full_name'],
                    'username' => $mention['username'],
                    'avatar_url' => $mention['avatar_url']
                ],
                'created_at' => $mention['created_at']
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reply / Forward Reference IDs
    |--------------------------------------------------------------------------
    */

    $referenceMessageIds = [];

    foreach ($messages as $messageRow) {

        if (
            !empty($messageRow['reply_to_message_id']) &&
            (int) $messageRow['reply_to_message_id'] > 0
        ) {
            $referenceMessageIds[] =
                (int) $messageRow['reply_to_message_id'];
        }

        if (
            !empty($messageRow['forwarded_from_message_id']) &&
            (int) $messageRow['forwarded_from_message_id'] > 0
        ) {
            $referenceMessageIds[] =
                (int) $messageRow['forwarded_from_message_id'];
        }
    }

    $referenceMessageIds =
        array_values(array_unique($referenceMessageIds));

    $referenceMessages = [];

    if (!empty($referenceMessageIds)) {

        $placeholders = implode(
            ',',
            array_fill(0, count($referenceMessageIds), '?')
        );

        $referenceStmt = $pdo->prepare(
            "SELECT
                id,
                conversation_id,
                sender_id,
                message,
                message_type,
                is_deleted,
                created_at,
                updated_at
             FROM messages
             WHERE id IN ($placeholders)"
        );

        $referenceStmt->execute($referenceMessageIds);

        $referenceRows =
            $referenceStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($referenceRows as $reference) {

            $referenceId =
                (int) $reference['id'];

            $referenceMessages[$referenceId] = [
                'id' => $referenceId,
                'conversation_id' =>
                    (int) $reference['conversation_id'],
                'sender_id' =>
                    (int) $reference['sender_id'],
                'message' =>
                    ((int) $reference['is_deleted'] === 1)
                        ? null
                        : $reference['message'],
                'message_type' =>
                    $reference['message_type'],
                'is_deleted' =>
                    (int) $reference['is_deleted'],
                'created_at' =>
                    $reference['created_at'],
                'updated_at' =>
                    $reference['updated_at']
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Build Final Message Response
    |--------------------------------------------------------------------------
    */

    $formattedMessages = [];

    foreach ($messages as $messageRow) {

        $messageId =
            (int) $messageRow['id'];

        $messageAttachments =
            $attachmentsByMessage[$messageId] ?? [];

        $messageReactions =
            $reactionsByMessage[$messageId] ?? [];

        $messageMentions =
            $mentionsByMessage[$messageId] ?? [];

        /*
        |----------------------------------------------------------------------
        | Current User Reactions
        |----------------------------------------------------------------------
        */

        $myReactions = [];

        foreach ($messageReactions as $reaction) {

            if (
                (int) $reaction['user_id'] === $userId
            ) {
                $myReactions[] =
                    $reaction['reaction'];
            }
        }

        /*
        |----------------------------------------------------------------------
        | Reply Information
        |----------------------------------------------------------------------
        */

        $replyTo = null;

        if (
            !empty($messageRow['reply_to_message_id']) &&
            (int) $messageRow['reply_to_message_id'] > 0
        ) {

            $replyId =
                (int) $messageRow['reply_to_message_id'];

            $replyTo =
                $referenceMessages[$replyId] ?? null;
        }

        /*
        |----------------------------------------------------------------------
        | Forward Information
        |----------------------------------------------------------------------
        */

        $forwardedFrom = null;

        if (
            !empty($messageRow['forwarded_from_message_id']) &&
            (int) $messageRow['forwarded_from_message_id'] > 0
        ) {

            $forwardId =
                (int) $messageRow['forwarded_from_message_id'];

            $forwardedFrom =
                $referenceMessages[$forwardId] ?? null;
        }

        /*
        |----------------------------------------------------------------------
        | Final Message
        |----------------------------------------------------------------------
        */

        $formattedMessages[] = [

            'id' =>
                $messageId,

            'conversation_id' =>
                (int) $messageRow['conversation_id'],

            'sender_id' =>
                (int) $messageRow['sender_id'],

            'sender' => [
                'id' =>
                    (int) $messageRow['sender_id'],

                'full_name' =>
                    $messageRow['sender_name'],

                'username' =>
                    $messageRow['sender_username'],

                'avatar_url' =>
                    $messageRow['sender_avatar']
            ],

            'message' =>
                ((int) $messageRow['is_deleted'] === 1)
                    ? null
                    : $messageRow['message'],

            'message_type' =>
                $messageRow['message_type'],

            'is_deleted' =>
                (int) $messageRow['is_deleted'],

            'reply_to_message_id' =>
                !empty($messageRow['reply_to_message_id'])
                    ? (int) $messageRow['reply_to_message_id']
                    : null,

            'reply_to' =>
                $replyTo,

            'forwarded_from_message_id' =>
                !empty($messageRow['forwarded_from_message_id'])
                    ? (int) $messageRow['forwarded_from_message_id']
                    : null,

            'forwarded_from' =>
                $forwardedFrom,

            'reaction_count' =>
                count($messageReactions),

            'reactions' =>
                $messageReactions,

            'my_reactions' =>
                $myReactions,

            'mention_count' =>
                count($messageMentions),

            'mentions' =>
                $messageMentions,

            'attachment_count' =>
                count($messageAttachments),

            'attachments' =>
                $messageAttachments,

            'created_at' =>
                $messageRow['created_at'],

            'updated_at' =>
                $messageRow['updated_at']
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Mark Conversation as Read
    |--------------------------------------------------------------------------
    |
    | Latest and after requests mark the conversation as read.
    | Before requests intentionally do not change read state.
    |
    */

    if ($paginationMode !== 'before') {

        $readStmt = $pdo->prepare(
            'UPDATE conversation_participants
             SET last_read_at = CURRENT_TIMESTAMP
             WHERE conversation_id = :conversation_id
               AND user_id = :user_id'
        );

        $readStmt->execute([
            ':conversation_id' => $conversationId,
            ':user_id' => $userId
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination Metadata
    |--------------------------------------------------------------------------
    */

    $pagination = [
        'mode' =>
            $paginationMode,

        'limit' =>
            $limit,

        'has_more' =>
            $hasMore,

        'first_message_id' =>
            $firstMessageId,

        'last_message_id' =>
            $lastMessageId,

        'before_id' =>
            $beforeId > 0
                ? $beforeId
                : null,

        'after_id' =>
            $afterId > 0
                ? $afterId
                : null
    ];

    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    jsonResponse(
        true,
        'Messages retrieved successfully.',
        [
            'conversation_id' =>
                $conversationId,

            'count' =>
                count($formattedMessages),

            'pagination' =>
                $pagination,

            'messages' =>
                $formattedMessages
        ],
        200
    );

} catch (PDOException $e) {

    error_log(
        'Doopa message list database error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to retrieve messages.',
        [],
        500
    );

} catch (Throwable $e) {

    error_log(
        'Doopa message list general error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'An unexpected error occurred.',
        [],
        500
    );
}