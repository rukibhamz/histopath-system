<?php
/**
 * Whether this copy of the app has actually been set up.
 *
 * Presence of includes/config.php is not enough: a copied folder, a failed
 * wizard run, or an empty SQLite path all leave that file behind without a
 * working database. The wizard stays closed only when a database exists and
 * already has at least one user.
 */
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/db.php';

function app_config_path(): string {
    return __DIR__ . '/config.php';
}

/** The parsed config array, or null when the file is missing or unreadable. */
function app_load_config(): ?array {
    static $done = false;
    static $config = null;
    if ($done) return $config;

    $done = true;
    $file = app_config_path();
    if (!is_file($file)) return null;
    $data = require $file;
    $config = is_array($data) ? $data : null;
    return $config;
}

/**
 * True when the config file has the keys needed to attempt a connection.
 * An empty SQLite path, or PostgreSQL with no host/database/user, is leftover
 * scaffolding — not an install.
 */
function app_config_is_complete(?array $config): bool {
    if ($config === null) return false;
    $driver = $config['db_driver'] ?? 'pgsql';
    if ($driver === 'sqlite') {
        return trim((string)($config['db_path'] ?? '')) !== '';
    }
    return trim((string)($config['db_host'] ?? '')) !== ''
        && trim((string)($config['db_name'] ?? '')) !== ''
        && trim((string)($config['db_user'] ?? '')) !== '';
}

/** Opens a PDO connection from a config array. Throws on failure. */
function app_pdo_from_config(array $config): PDO {
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $driver = $config['db_driver'] ?? 'pgsql';
    db_driver($driver);

    if ($driver === 'sqlite') {
        $pdo = new PDO('sqlite:' . $config['db_path'], null, null, $options);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    $options[PDO::ATTR_EMULATE_PREPARES] = false;
    $dsn = "pgsql:host={$config['db_host']};port=" . ($config['db_port'] ?? '5432')
         . ";dbname={$config['db_name']}";
    return new PDO($dsn, $config['db_user'], $config['db_password'] ?? '', $options);
}

/** True when the connected database has the users table and at least one account. */
function app_database_has_users(PDO $pdo): bool {
    if (!in_array('users', db_existing_tables($pdo), true)) {
        return false;
    }
    return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

/**
 * True only for a finished install: complete config, reachable database, and
 * at least one user. Incomplete leftover files do not count, so a copied
 * project or a new machine still gets the setup wizard.
 */
function app_is_installed(): bool {
    $config = app_load_config();
    if (!app_config_is_complete($config)) {
        return false;
    }

    $driver = $config['db_driver'] ?? 'pgsql';
    if ($driver === 'sqlite' && !is_file((string)$config['db_path'])) {
        return false;
    }

    try {
        $pdo = app_pdo_from_config($config);
        return app_database_has_users($pdo);
    } catch (Throwable $e) {
        // SQLite is a local file: if it cannot be opened, this copy is not set up.
        // PostgreSQL with complete credentials that refuse to connect is treated
        // as installed-but-down so a brief outage cannot reopen the wizard.
        return $driver !== 'sqlite';
    }
}
