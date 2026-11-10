<?php

declare(strict_types=1);

function logAdminActivity(
    PDO $pdo,
    int $adminId,
    string $action,
    string $targetType,
    ?int $targetId = null,
    ?string $description = null
): bool {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_activity_logs (
                admin_id,
                action,
                target_type,
                target_id,
                description
            )
            VALUES (
                :admin_id,
                :action,
                :target_type,
                :target_id,
                :description
            )
        ");

        return $stmt->execute([
            ':admin_id' => $adminId,
            ':action' => $action,
            ':target_type' => $targetType,
            ':target_id' => $targetId,
            ':description' => $description
        ]);

    } catch (PDOException $e) {
        return false;
    }
}