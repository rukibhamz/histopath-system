<?php
require_once __DIR__ . '/paths.php';

/**
 * Starts the session with hardened cookie settings.
 * Must be required before anything writes output. Safe to call more than once.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => app_base() . '/',   // scoped to this app, not the whole host
        'httponly' => true,               // not readable from JavaScript
        'samesite' => 'Lax',              // blocks cross-site POSTs carrying the cookie
        // 'secure' => true,              // enable once the intranet server serves HTTPS
    ]);
    session_name('HISTOPATH_SESSID');
    session_start();
}
