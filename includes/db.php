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

/**
 * Adds lab_year and switches uniqueness from lab_no alone to (lab_no, lab_year).
 * Safe to call on every request: it no-ops once the column and composite unique
 * key are in place. Lab numbers rotate each calendar year.
 */
function db_ensure_lab_year(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $tables = db_existing_tables($pdo);
    if (!in_array('histology_reports', $tables, true)) {
        $done = true;
        return;
    }

    if (db_is_sqlite()) {
        db_ensure_lab_year_sqlite($pdo);
    } else {
        db_ensure_lab_year_pgsql($pdo);
    }
    $done = true;
}

function db_table_has_column(PDO $pdo, string $table, string $column): bool {
    if (db_is_sqlite()) {
        foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if ($col['name'] === $column) return true;
        }
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = :table AND column_name = :column
    ");
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (bool)$stmt->fetchColumn();
}

function db_ensure_lab_year_pgsql(PDO $pdo): void {
    foreach (['histology_reports', 'cytology_reports'] as $table) {
        if (!db_table_has_column($pdo, $table, 'lab_year')) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN lab_year INTEGER");
        }
        $pdo->exec("
            UPDATE $table SET lab_year = COALESCE(
                EXTRACT(YEAR FROM date_of_collection)::integer,
                EXTRACT(YEAR FROM created_at)::integer,
                EXTRACT(YEAR FROM CURRENT_DATE)::integer
            ) WHERE lab_year IS NULL
        ");
        $pdo->exec("ALTER TABLE $table ALTER COLUMN lab_year SET NOT NULL");
        $pdo->exec("ALTER TABLE $table DROP CONSTRAINT IF EXISTS {$table}_lab_no_key");
        $pdo->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS {$table}_lab_no_year_key
            ON $table (lab_no, lab_year)
        ");
    }
}

function db_lab_no_still_unique_alone_sqlite(PDO $pdo, string $table): bool {
    foreach ($pdo->query("PRAGMA index_list(" . $pdo->quote($table) . ")")->fetchAll(PDO::FETCH_ASSOC) as $idx) {
        if (empty($idx['unique'])) continue;
        $quoted = $pdo->quote($idx['name']);
        $info = $pdo->query("PRAGMA index_info($quoted)")->fetchAll(PDO::FETCH_ASSOC);
        if (count($info) === 1 && ($info[0]['name'] ?? '') === 'lab_no') {
            return true;
        }
    }
    return false;
}

function db_ensure_lab_year_sqlite(PDO $pdo): void {
    foreach (['histology_reports', 'cytology_reports'] as $table) {
        if (!db_table_has_column($pdo, $table, 'lab_year')) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN lab_year INTEGER");
        }
        $pdo->exec("
            UPDATE $table SET lab_year = CAST(substr(date_of_collection, 1, 4) AS INTEGER)
            WHERE lab_year IS NULL AND date_of_collection IS NOT NULL
              AND length(date_of_collection) >= 4
        ");
        $pdo->exec("
            UPDATE $table SET lab_year = CAST(substr(created_at, 1, 4) AS INTEGER)
            WHERE lab_year IS NULL AND created_at IS NOT NULL
              AND length(created_at) >= 4
        ");
        $pdo->exec("UPDATE $table SET lab_year = CAST(strftime('%Y', 'now') AS INTEGER) WHERE lab_year IS NULL");

        if (db_lab_no_still_unique_alone_sqlite($pdo, $table)) {
            db_rebuild_sqlite_report_table($pdo, $table);
        } else {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$table}_lab_no_year_key ON $table (lab_no, lab_year)");
        }
    }
}

/** Recreate a report table so the old UNIQUE(lab_no) can be replaced. */
function db_rebuild_sqlite_report_table(PDO $pdo, string $table): void {
    $defs = [
        'histology_reports' => "
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lab_no TEXT NOT NULL,
            lab_year INTEGER NOT NULL,
            surname TEXT NOT NULL,
            other_names TEXT,
            age INTEGER,
            sex TEXT CHECK (sex IS NULL OR sex IN ('MALE','FEMALE')),
            ethnic_group TEXT,
            hospital_no TEXT,
            requesting_hospital TEXT,
            ward_clinic TEXT,
            date_of_collection TEXT,
            clinical_history TEXT,
            nature_of_specimen TEXT,
            special_requests TEXT,
            provisional_diagnosis TEXT,
            previous_lab_no TEXT,
            clinician TEXT,
            specimen_status TEXT,
            gross TEXT,
            microscopy TEXT,
            further_tests TEXT,
            bone_marrow TEXT,
            lymphomas TEXT,
            diagnosis TEXT,
            remarks TEXT,
            resident_doctors TEXT,
            consultant_pathologists TEXT,
            date_out TEXT,
            adverse_incidents TEXT,
            cost REAL,
            status TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
            submitted_by INTEGER REFERENCES users(id),
            reviewed_by INTEGER REFERENCES users(id),
            reviewer_comments TEXT,
            reviewed_at TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime')),
            UNIQUE (lab_no, lab_year)
        ",
        'cytology_reports' => "
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lab_no TEXT NOT NULL,
            lab_year INTEGER NOT NULL,
            surname TEXT NOT NULL,
            other_names TEXT,
            age INTEGER,
            sex TEXT CHECK (sex IS NULL OR sex IN ('MALE','FEMALE')),
            ethnic_group TEXT,
            requesting_hospital TEXT,
            hosp_no TEXT,
            ward_clinic TEXT,
            patients_tel_no TEXT,
            date_of_collection TEXT,
            clinical_history TEXT,
            lmp TEXT,
            drug_history TEXT,
            radiation TEXT,
            previous_lab_no TEXT,
            nature_of_specimen TEXT,
            clinician TEXT,
            clinician_tel_no TEXT,
            microscopy TEXT,
            diagnosis TEXT,
            recommendation TEXT,
            resident_doctors TEXT,
            consultant_pathologists TEXT,
            signout_date TEXT,
            cost REAL,
            status TEXT DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
            submitted_by INTEGER REFERENCES users(id),
            reviewed_by INTEGER REFERENCES users(id),
            reviewer_comments TEXT,
            reviewed_at TEXT,
            created_at TEXT DEFAULT (datetime('now','localtime')),
            updated_at TEXT DEFAULT (datetime('now','localtime')),
            UNIQUE (lab_no, lab_year)
        ",
    ];
    if (!isset($defs[$table])) return;

    $existing = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $wanted = preg_match_all('/^\s+([a-z_]+) /m', $defs[$table], $m) ? $m[1] : [];
    $copy = array_values(array_intersect($wanted, $existing));
    if (!$copy) return;

    $new = $table . '_new';
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec("DROP TABLE IF EXISTS $new");
    $pdo->exec("CREATE TABLE $new (" . $defs[$table] . ")");
    $cols = implode(', ', $copy);
    $pdo->exec("INSERT INTO $new ($cols) SELECT $cols FROM $table");
    $pdo->exec("DROP TABLE $table");
    $pdo->exec("ALTER TABLE $new RENAME TO $table");

    $prefix = $table === 'histology_reports' ? 'histology' : 'cytology';
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$prefix}_status ON $table (status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$prefix}_submitted_by ON $table (submitted_by)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$prefix}_created_at ON $table (created_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$prefix}_collection ON $table (date_of_collection)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$prefix}_surname ON $table (surname)");
    $pdo->exec("DROP TRIGGER IF EXISTS trg_{$prefix}_updated_at");
    $pdo->exec("
        CREATE TRIGGER trg_{$prefix}_updated_at
        AFTER UPDATE ON $table
        FOR EACH ROW WHEN NEW.updated_at IS OLD.updated_at
        BEGIN
            UPDATE $table SET updated_at = datetime('now','localtime') WHERE id = NEW.id;
        END
    ");
    $pdo->exec('PRAGMA foreign_keys = ON');
}
