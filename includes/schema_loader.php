<?php
/**
 * Loads a schema file into a fresh database. Used by the first-run setup wizard,
 * for both schema.sql (PostgreSQL) and schema.sqlite.sql (SQLite).
 */

/**
 * Removes whole-line SQL comments.
 *
 * Run this over the whole script BEFORE splitting: a comment may contain a
 * semicolon, and splitting first would cut the comment in half and leave the
 * tail behind as a bogus statement.
 */
function strip_sql_comments(string $sql): string {
    $lines = array_filter(
        explode("\n", $sql),
        fn($line) => !str_starts_with(trim($line), '--')
    );
    return trim(implode("\n", $lines));
}

/**
 * Splits a SQL script into statements on semicolons, leaving alone any semicolon
 * that sits inside a statement body:
 *   - PostgreSQL: the $$ ... $$ body of the updated_at trigger function
 *   - SQLite:     the BEGIN ... END body of a CREATE TRIGGER
 */
function split_sql(string $sql): array {
    $statements = [];
    $buffer = '';
    $in_dollar_block = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        if (substr($sql, $i, 2) === '$$') {
            $in_dollar_block = !$in_dollar_block;
            $buffer .= '$$';
            $i++;
            continue;
        }

        if ($sql[$i] === ';' && !$in_dollar_block && !in_open_trigger_body($buffer)) {
            $statements[] = $buffer;
            $buffer = '';
            continue;
        }

        $buffer .= $sql[$i];
    }

    if (trim($buffer) !== '') {
        $statements[] = $buffer;
    }
    return $statements;
}

/** True while we are inside the BEGIN ... END body of a CREATE TRIGGER. */
function in_open_trigger_body(string $buffer): bool {
    if (!preg_match('/\bCREATE\s+TRIGGER\b/i', $buffer)) {
        return false;
    }
    $begins = preg_match_all('/\bBEGIN\b/i', $buffer);
    $ends   = preg_match_all('/\bEND\b/i', $buffer);
    return $begins > $ends;
}

/**
 * Runs a schema file, skipping the placeholder admin row - the setup wizard
 * creates a real administrator instead. Returns any error messages produced.
 */
function load_schema(PDO $pdo, string $schema_path): array {
    $sql = strip_sql_comments((string)file_get_contents($schema_path));
    $problems = [];

    foreach (split_sql($sql) as $chunk) {
        $statement = trim($chunk);
        if ($statement === '' || str_contains($statement, 'CHANGE_ME_HASH')) continue;

        // PRAGMAs are for the sqlite3 command line; db_connect.php sets the ones
        // that matter on each connection, and some of them return rows.
        if (stripos($statement, 'PRAGMA ') === 0) continue;

        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            $problems[] = $e->getMessage();
        }
    }
    return $problems;
}
