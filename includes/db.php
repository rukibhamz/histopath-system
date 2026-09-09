<?php
/**
 * Database dialect helpers.
 *
 * The system runs on either PostgreSQL or SQLite. Almost all of its SQL is
 * identical on both - RETURNING, ON CONFLICT, SAVEPOINT and prepared statements
 * all behave the same. These helpers cover the handful of places where they differ.
 */

/**
 * The driver in use ('pgsql' or 'sqlite'). Called with an argument once, by
 * db_connect.php, to record which one this request is talking to.
 */
function db_driver(?string $set = null): string {
    static $driver = 'pgsql';
    if ($set !== null) {
        $driver = $set;
    }
    return $driver;
}

function db_is_sqlite(): bool {
    return db_driver() === 'sqlite';
}

/** SQL expression for "right now", in the server's local time. */
function sql_now(): string {
    return db_is_sqlite() ? "datetime('now','localtime')" : 'NOW()';
}

/**
 * SQL expression for a moment in the past.
 * Pass a plain interval: sql_ago('24 hours'), sql_ago('15 minutes').
 */
function sql_ago(string $interval): string {
    // Trusted, code-supplied values only - never interpolate user input here.
    if (!preg_match('/^\d+ (second|minute|hour|day|month|year)s?$/', $interval)) {
        throw new InvalidArgumentException("Unsupported interval: $interval");
    }
    return db_is_sqlite()
        ? "datetime('now','localtime','-$interval')"
        : "NOW() - INTERVAL '$interval'";
}

/**
 * Case-insensitive LIKE. SQLite's LIKE is already case-insensitive for ASCII,
 * which is what patient names and lab numbers are.
 */
function sql_ilike(): string {
    return db_is_sqlite() ? 'LIKE' : 'ILIKE';
}

/** Names of the system's tables that already exist in this database. */
function db_existing_tables(PDO $pdo): array {
    $wanted = ['users', 'access_logs', 'system_settings',
               'histology_reports', 'cytology_reports', 'login_attempts'];

    if (db_is_sqlite()) {
        $found = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")
                     ->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $found = $pdo->query("
            SELECT table_name FROM information_schema.tables
            WHERE table_schema = 'public'
        ")->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_values(array_intersect($found, $wanted));
}
