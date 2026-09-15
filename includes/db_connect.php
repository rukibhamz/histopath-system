<?php
/**
 * Central database connection.
 *
 * The system runs on PostgreSQL or SQLite; which one is recorded in
 * includes/config.php, written by the first-run setup wizard (install.php).
 * If setup never finished — missing config, empty SQLite path, missing
 * database file, or no users yet — the visitor is sent to the wizard.
 */
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/install_state.php';

$on_install_wizard = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'install.php';

if (!app_is_installed()) {
    if (!$on_install_wizard) {
        header('Location: ' . app_url('install.php'));
        exit;
    }
    return;
}

$config = app_load_config();

try {
    $pdo = app_pdo_from_config($config);
    db_ensure_lab_year($pdo);
} catch (PDOException $e) {
    // Never show connection details to the browser - they name the server and user.
    error_log('Database connection failed: ' . $e->getMessage());
    die("Database connection failed. Please contact IT support.");
}
