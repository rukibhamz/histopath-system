<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

log_action($pdo, current_user_id(), 'logout');

$_SESSION = [];

// Expire the session cookie too, not just the server-side data.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

redirect('login.php');
