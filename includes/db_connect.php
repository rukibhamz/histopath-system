<?php
/**
 * Central database connection.
 *
 * The system runs on PostgreSQL or SQLite; which one is recorded in
 * includes/config.php, written by the first-run setup wizard (install.php).
 * If that file is missing, the system hasn't been set up yet and we hand the
 * visitor to the wizard.
 */
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/db.php';

$config_file = __DIR__ . '/config.php';

if (!is_file($config_file)) {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        header('Location: ' . app_url('install.php'));
        exit;
    }
    return;
}

$config = require $config_file;

// Configurations written before SQLite support was added have no driver key.
$driver = $config['db_driver'] ?? 'pgsql';
db_driver($driver);

try {
    if ($driver === 'sqlite') {
        $pdo = new PDO('sqlite:' . $config['db_path'], null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // WAL lets readers carry on while someone is saving a report; the busy
        // timeout makes a brief write collision wait rather than fail outright.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = "pgsql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']}";
        $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
} catch (PDOException $e) {
    // Never show connection details to the browser - they name the server and user.
    error_log('Database connection failed: ' . $e->getMessage());
    die("Database connection failed. Please contact IT support.");
}
