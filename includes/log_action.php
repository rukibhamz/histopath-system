<?php
/**
 * Writes a row to access_logs. Call this after any meaningful action:
 * login, logout, record create/view/edit, approve/reject, user management, settings changes.
 *
 * Pass null for $user_id when no one is signed in yet (e.g. a blocked login attempt).
 */
function log_action(PDO $pdo, ?int $user_id, string $action, ?string $target_table = null, ?int $target_id = null, ?string $details = null): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO access_logs (user_id, action, target_table, target_id, ip_address, details)
        VALUES (:user_id, :action, :target_table, :target_id, :ip_address, :details)
    ");
    $stmt->execute([
        'user_id' => $user_id ?: null,
        'action' => $action,
        'target_table' => $target_table,
        'target_id' => $target_id,
        'ip_address' => $ip,
        'details' => $details,
    ]);
}
