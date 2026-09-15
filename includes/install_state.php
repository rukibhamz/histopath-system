<?php
/**
 * Whether this copy of the app has actually been set up.
 *
 * Presence of includes/config.php is not enough: a copied folder, a failed
 * wizard run, or an empty SQLite path all leave that file behind without a
 * working database. The wizard stays closed only when a database exists and
 * already has at least one user.
 *
 * If git pull removed a previously tracked config.php, we look for the SQLite
 * file the installer creates by default and rewrite config so the existing
 * install keeps working.
 */
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/db.php';

function app_config_path(): string {
    return __DIR__ . '/config.php';
}

/** Default SQLite location: beside the web root, never inside it. */
function app_suggested_sqlite_path(): string {
    $doc_root = str_replace(DIRECTORY_SEPARATOR, '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $parent = $doc_root !== '' ? dirname($doc_root) : dirname(__DIR__);
    return $parent . '/histopath_data/histopath.sqlite';
}

/** Places an existing SQLite install is likely to still be after a git pull. */
function app_sqlite_search_paths(): array {
    $paths = [
        app_suggested_sqlite_path(),
        dirname(__DIR__) . '/histopath.sqlite',
        dirname(dirname(__DIR__)) . '/histopath_data/histopath.sqlite',
    ];
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $paths[] = 'C:/xampp/histopath_data/histopath.sqlite';
    }
    $unique = [];
    foreach ($paths as $path) {
        $normalized = str_replace('\\', '/', $path);
        if ($normalized !== '' && !in_array($normalized, $unique, true)) {
            $unique[] = $normalized;
        }
    }
    return $unique;
}

/** The parsed config array, or null when the file is missing or unreadable. */
function app_load_config(bool $reload = false): ?array {
    static $done = false;
    static $config = null;
    if ($reload) {
        $done = false;
        $config = null;
    }
    if ($done) return $config;

    $done = true;
    $file = app_config_path();
    if (!is_file($file)) return null;
    if ($reload && function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
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

/** PHP source for includes/config.php. */
function app_config_file_contents(array $settings): string {
    return "<?php\n"
        . "/**\n"
        . " * Database configuration, written by install.php on " . date('Y-m-d H:i') . ".\n"
        . " * Keep this file out of the web root's reach - includes/.htaccess denies it.\n"
        . " */\n"
        . "return " . var_export($settings, true) . ";\n";
}

/** Writes includes/config.php and refreshes the in-request cache. */
function app_write_config(array $settings): bool {
    $file = app_config_path();
    if (@file_put_contents($file, app_config_file_contents($settings)) === false) {
        return false;
    }
    @chmod($file, 0640);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
    clearstatcache(true, $file);
    app_load_config(true);
    return true;
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

/** True when this config points at a finished install. */
function app_config_reaches_users(?array $config): bool {
    if (!app_config_is_complete($config)) return false;

    $driver = $config['db_driver'] ?? 'pgsql';
    if ($driver === 'sqlite' && !is_file((string)$config['db_path'])) {
        return false;
    }

    try {
        return app_database_has_users(app_pdo_from_config($config));
    } catch (Throwable $e) {
        return $driver !== 'sqlite';
    }
}

/**
 * If config.php was deleted or points at a missing file, reuse a SQLite
 * database that is already on this machine and rewrite config.php.
 */
function app_recover_sqlite_install(): bool {
    foreach (app_sqlite_search_paths() as $path) {
        if (!is_file($path)) continue;
        $candidate = ['db_driver' => 'sqlite', 'db_path' => $path];
        try {
            if (!app_database_has_users(app_pdo_from_config($candidate))) continue;
        } catch (Throwable $e) {
            continue;
        }
        app_write_config($candidate);
        return app_config_reaches_users(app_load_config());
    }
    return false;
}

/**
 * True only for a finished install: complete config, reachable database, and
 * at least one user. Incomplete leftover files do not count, so a copied
 * project or a new machine still gets the setup wizard.
 */
function app_is_installed(): bool {
    if (app_config_reaches_users(app_load_config())) {
        return true;
    }
    return app_recover_sqlite_install();
}
