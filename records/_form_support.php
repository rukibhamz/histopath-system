<?php
/**
 * Shared field handling for the histology and cytology entry forms:
 * collecting POST data, validating it, and writing it back to the database.
 */

/** Pull the named fields out of $_POST, normalising blanks to NULL. */
function collect_post_fields(array $fields): array {
    $data = [];
    foreach ($fields as $field) {
        $value = $_POST[$field] ?? '';
        $value = is_string($value) ? trim($value) : $value;
        $data[$field] = ($value === '') ? null : $value;
    }
    return $data;
}

/**
 * Validates a report row. Returns a list of human-readable error messages;
 * an empty list means the data is good to save.
 */
function validate_report(array $data, array $date_fields): array {
    $errors = [];

    if (($data['lab_no'] ?? null) === null)  $errors[] = "Lab No is required.";
    if (($data['surname'] ?? null) === null) $errors[] = "Surname is required.";

    if (($data['lab_no'] ?? null) !== null && mb_strlen($data['lab_no']) > 20) {
        $errors[] = "Lab No must be 20 characters or fewer.";
    }

    if (($data['sex'] ?? null) !== null && !in_array($data['sex'], ['MALE', 'FEMALE'], true)) {
        $errors[] = "Sex must be Male or Female.";
    }

    if (($data['age'] ?? null) !== null) {
        if (!ctype_digit((string)$data['age']) || (int)$data['age'] > 150) {
            $errors[] = "Age must be a whole number between 0 and 150.";
        }
    }

    if (($data['cost'] ?? null) !== null) {
        if (!is_numeric($data['cost']) || (float)$data['cost'] < 0) {
            $errors[] = "Cost must be a positive number.";
        }
    }

    foreach ($date_fields as $field) {
        if (($data[$field] ?? null) === null) continue;
        $parsed = DateTime::createFromFormat('Y-m-d', $data[$field]);
        if (!$parsed || $parsed->format('Y-m-d') !== $data[$field]) {
            $errors[] = "'" . str_replace('_', ' ', $field) . "' is not a valid date.";
        }
    }

    return $errors;
}

/** True if another record in $table already uses this Lab No. */
function lab_no_taken(PDO $pdo, string $table, string $lab_no, int $exclude_id = 0): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM $table WHERE lab_no = :lab_no AND id <> :exclude_id LIMIT 1");
    $stmt->execute(['lab_no' => $lab_no, 'exclude_id' => $exclude_id]);
    return (bool)$stmt->fetchColumn();
}

/** Inserts a new report as 'pending' and returns its id. */
function insert_report(PDO $pdo, string $table, array $data, int $submitted_by): int {
    $columns = array_keys($data);
    $sql = "INSERT INTO $table (" . implode(', ', $columns) . ", submitted_by, status)
            VALUES (:" . implode(', :', $columns) . ", :submitted_by, 'pending')
            RETURNING id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data + ['submitted_by' => $submitted_by]);
    return (int)$stmt->fetchColumn();
}

/**
 * Updates an existing report and sends it back through review.
 * Any content change clears the previous decision - an approved report whose
 * findings have been edited must not keep its approval.
 */
function update_report(PDO $pdo, string $table, int $id, array $data): void {
    $columns = array_keys($data);
    $assignments = implode(', ', array_map(fn($c) => "$c = :$c", $columns));
    $sql = "UPDATE $table
            SET $assignments,
                status = 'pending',
                reviewed_by = NULL,
                reviewed_at = NULL
            WHERE id = :record_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data + ['record_id' => $id]);
}

/**
 * Loads a record for editing and enforces who may edit it.
 * Ends the request with 403/404 rather than returning on failure.
 */
function load_editable_report(PDO $pdo, string $table, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $record = $stmt->fetch();

    if (!$record) {
        http_response_code(404);
        die("Record not found.");
    }

    if (!can_edit_report($record)) {
        http_response_code(403);
        die($record['status'] === 'approved'
            ? "This report has been approved and can no longer be edited. "
              . "Ask a reviewer or administrator if a correction is needed."
            : "Access denied - you may only edit records you submitted.");
    }

    return $record;
}
