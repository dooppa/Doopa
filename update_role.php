<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DOOPA - UPDATE COMMUNITY MEMBER ROLE
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');

function sendJson(bool $success, string $message, array $data = [], int $status = 200): never
{
    http_response_code($status);

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(
        false,
        'Only POST requests are allowed.',
        [],
        405
    );
}

session_start();

if (
    empty($_SESSION['logged_in']) ||
    empty($_SESSION['user_id'])
) {
    sendJson(
        false,
        'Authentication required.',
        [],
        401
    );
}

$currentUserId = (int) $_SESSION['user_id'];

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

try {
    $database = new Database();
    $pdo = $database->connect();

    /*
    |--------------------------------------------------------------------------
    | INPUT
    |--------------------------------------------------------------------------
    */

    $communityId = filter_input(
        INPUT_POST,
        'community_id',
        FILTER_VALIDATE_INT
    );

    $memberId = filter_input(
        INPUT_POST,
        'user_id',
        FILTER_VALIDATE_INT
    );

    $newRole = trim((string) ($_POST['role'] ?? ''));

    if (!$communityId || !$memberId || $newRole === '') {
        sendJson(
            false,
            'community_id, user_id and role are required.',
            [],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VALID ROLE
    |--------------------------------------------------------------------------
    */

    $allowedRoles = [
        'admin',
        'moderator',
        'member'
    ];

    if (!in_array($newRole, $allowedRoles, true)) {
        sendJson(
            false,
            'Invalid role. Allowed roles are admin, moderator and member.',
            [
                'allowed_roles' => $allowedRoles
            ],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY COMMUNITY
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            created_by
        FROM communities
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$communityId]);

    $community = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$community) {
        sendJson(
            false,
            'Community not found.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY CURRENT USER'S ROLE
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            role,
            status
        FROM community_members
        WHERE community_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $communityId,
        $currentUserId
    ]);

    $currentMember = $stmt->fetch(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | ONLY OWNER AND ADMIN CAN MANAGE ROLES
    |--------------------------------------------------------------------------
    */

    $isOwner = (
        (int) $community['created_by'] === $currentUserId
    );

    $isAdmin = (
        $currentMember &&
        $currentMember['role'] === 'admin' &&
        $currentMember['status'] === 'active'
    );

    if (!$isOwner && !$isAdmin) {
        sendJson(
            false,
            'You are not authorized to manage community roles.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFY TARGET MEMBER
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            user_id,
            role,
            status
        FROM community_members
        WHERE community_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $communityId,
        $memberId
    ]);

    $targetMember = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetMember) {
        sendJson(
            false,
            'The user is not a member of this community.',
            [],
            404
        );
    }

    if ($targetMember['status'] !== 'active') {
        sendJson(
            false,
            'The target member is not active.',
            [
                'status' => $targetMember['status']
            ],
            422
        );
    }

    /*
    |--------------------------------------------------------------------------
    | OWNER PROTECTION
    |--------------------------------------------------------------------------
    */

    if (
        (int) $community['created_by'] === $memberId
    ) {
        sendJson(
            false,
            'The community owner cannot have their role changed.',
            [
                'role' => 'owner'
            ],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ADMIN LIMITATION
    |--------------------------------------------------------------------------
    |
    | Admins may manage moderators/members, but only the owner
    | may promote another user to admin or change an admin's role.
    |
    */

    if (!$isOwner) {

        if ($newRole === 'admin') {
            sendJson(
                false,
                'Only the community owner can promote a member to admin.',
                [],
                403
            );
        }

        if ($targetMember['role'] === 'admin') {
            sendJson(
                false,
                'Only the community owner can change an admin role.',
                [],
                403
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | NO-OP
    |--------------------------------------------------------------------------
    */

    if ($targetMember['role'] === $newRole) {
        sendJson(
            true,
            'The member already has this role.',
            [
                'community_id' => $communityId,
                'user_id'      => $memberId,
                'role'         => $newRole,
                'status'       => $targetMember['status']
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE ROLE
    |--------------------------------------------------------------------------
    */

    $pdo->beginTransaction();

    try {

        $stmt = $pdo->prepare("
            UPDATE community_members
            SET
                role = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE community_id = ?
              AND user_id = ?
        ");

        $stmt->execute([
            $newRole,
            $communityId,
            $memberId
        ]);

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    /*
    |--------------------------------------------------------------------------
    | SUCCESS
    |--------------------------------------------------------------------------
    */

    sendJson(
        true,
        'Community member role updated successfully.',
        [
            'community_id' => $communityId,
            'user_id'      => $memberId,
            'role'         => $newRole,
            'status'       => 'active'
        ]
    );

} catch (Throwable $e) {

    error_log(
        'Doopa update_role.php error: ' . $e->getMessage()
    );

    sendJson(
        false,
        'A server error occurred while updating the community role.',
        [],
        500
    );
}