<?php
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/log_action.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/layout.php';

/** Call at the top of every protected page. Redirects to login if not authenticated. */
function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        redirect('login.php');
    }

    // Force a password reset for accounts flagged must_reset_password, unless
    // we're already on the reset page itself (or logging out).
    $current_script = basename($_SERVER['SCRIPT_NAME']);
    if (!empty($_SESSION['must_reset_password']) && !in_array($current_script, ['change_password.php', 'logout.php'], true)) {
        redirect('change_password.php');
    }
}

/**
 * Call after require_login() on pages restricted to specific roles.
 * Example: require_role(['admin']); or require_role(['admin', 'reviewer']);
 */
function require_role(array $allowed_roles): void {
    if (empty($_SESSION['role']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
        http_response_code(403);
        die("Access denied. You do not have permission to view this page.");
    }
}

function current_user_id(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_role(): string {
    return $_SESSION['role'] ?? '';
}
