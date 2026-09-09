<?php
require_once __DIR__ . '/session.php';

/** The per-session CSRF token, generated on first use. */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Drop this inside every <form method="POST">. */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Call at the top of any page that handles POST, before acting on the data.
 * Does nothing on GET requests.
 */
function csrf_verify(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;

    $submitted = $_POST['csrf_token'] ?? '';
    if ($submitted === '' || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(400);
        die('This request could not be verified — your session may have expired. '
          . 'Go back, reload the page, and try again.');
    }
}
