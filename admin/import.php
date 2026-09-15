<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role(['admin']);

// Columns each table accepts from an import. Access AutoNumber (`id`) is kept,
// but namespaced with the import year so yearly dumps that reuse 1, 2, 3…
// do not collide. Lab year is set by the admin for the whole file.
$table_columns = [
    'histology_reports' => [
        'id', 'lab_no', 'surname', 'other_names', 'age', 'sex', 'ethnic_group', 'hospital_no',
        'requesting_hospital', 'ward_clinic', 'date_of_collection', 'clinical_history',
        'nature_of_specimen', 'special_requests', 'provisional_diagnosis', 'previous_lab_no',
        'clinician', 'specimen_status', 'gross', 'microscopy', 'further_tests', 'bone_marrow',
        'lymphomas', 'diagnosis', 'remarks', 'resident_doctors', 'consultant_pathologists',
        'date_out', 'adverse_incidents', 'cost',
    ],
    'cytology_reports' => [
        'id', 'lab_no', 'surname', 'other_names', 'age', 'sex', 'ethnic_group', 'requesting_hospital',
        'hosp_no', 'ward_clinic', 'patients_tel_no', 'date_of_collection', 'clinical_history',
        'lmp', 'drug_history', 'radiation', 'previous_lab_no', 'nature_of_specimen', 'clinician',
        'clinician_tel_no', 'microscopy', 'diagnosis', 'recommendation', 'resident_doctors',
        'consultant_pathologists', 'signout_date', 'cost',
    ],
];

// Date-type columns per table, so we know to run them through parse_date()
$date_columns = [
    'histology_reports' => ['date_of_collection', 'date_out'],
    'cytology_reports' => ['date_of_collection', 'lmp', 'signout_date'],
];

const MAX_IMPORT_BYTES = 20 * 1024 * 1024;   // 20MB is ample for an Access table export

require_once __DIR__ . '/_import_support.php';

/** Remove a staged upload and forget it. */
function clear_staged_import(): void {
    if (!empty($_SESSION['import_file']) && is_file($_SESSION['import_file'])) {
        @unlink($_SESSION['import_file']);
    }
    unset($_SESSION['import_file'], $_SESSION['import_table'],
          $_SESSION['import_headers'], $_SESSION['import_preview'],
          $_SESSION['import_year'], $_SESSION['import_filename']);
}

/** Past imports, with how many of their reports are still in the database. */
function fetch_import_batches(PDO $pdo): array {
    try {
        return $pdo->query("
            SELECT b.*, u.full_name,
                CASE b.target_table
                    WHEN 'histology_reports' THEN (
                        SELECT COUNT(*) FROM histology_reports r WHERE r.import_batch_id = b.id
                    )
                    WHEN 'cytology_reports' THEN (
                        SELECT COUNT(*) FROM cytology_reports r WHERE r.import_batch_id = b.id
                    )
                    ELSE 0
                END AS remaining
            FROM import_batches b
            LEFT JOIN users u ON u.id = b.imported_by
            ORDER BY b.created_at DESC, b.id DESC
        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function render_import_history(array $batches): void {
    ?>
    <div class="card" style="margin-top:18px;">
        <div class="card__head">
            <h2>Import history</h2>
            <?php if ($batches): ?>
                <span class="badge badge--plain"><?= count($batches) ?></span>
            <?php endif; ?>
        </div>
        <?php if (!$batches): ?>
            <div class="card__body">
                <p class="hint" style="margin:0;">Files you import are listed here so you can remove a whole batch if it was brought in by mistake. Imports from before this list are grouped by type, year and day.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Type</th>
                            <th>Year</th>
                            <th>Records</th>
                            <th>Imported</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($batches as $batch):
                        $remaining = (int)$batch['remaining'];
                    ?>
                        <tr>
                            <td>
                                <strong><?= e($batch['filename']) ?></strong>
                                <div class="muted"><?= e($batch['full_name'] ?: 'Unknown') ?></div>
                            </td>
                            <td><?= e(import_report_type_label((string)$batch['target_table'])) ?></td>
                            <td class="mono"><?= (int)$batch['lab_year'] ?></td>
                            <td class="nowrap">
                                <?= $remaining ?>
                                <?php if ((int)$batch['skipped_count'] > 0): ?>
                                    <span class="muted"> · <?= (int)$batch['skipped_count'] ?> skipped</span>
                                <?php endif; ?>
                            </td>
                            <td class="muted nowrap"><?= e((string)$batch['created_at']) ?></td>
                            <td class="right">
                                <?php
                                $confirm = 'Delete all ' . $remaining . ' record'
                                    . ($remaining === 1 ? '' : 's')
                                    . ' imported from ' . $batch['filename']
                                    . '? This cannot be undone.';
                                ?>
                                <form method="POST" onsubmit="return confirm(<?= e(json_encode($confirm, JSON_UNESCAPED_UNICODE)) ?>);">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="step" value="delete_batch">
                                    <input type="hidden" name="batch_id" value="<?= (int)$batch['id'] ?>">
                                    <button class="btn btn--sm btn--reject" type="submit">Delete records</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

$step = $_POST['step'] ?? 'upload';
$upload_dir = sys_get_temp_dir();
$upload_error = '';
$notice = $_SESSION['import_notice'] ?? '';
unset($_SESSION['import_notice']);

// Explicit "start over" link
if (($_GET['reset'] ?? '') === '1') {
    clear_staged_import();
    redirect('admin/import.php');
}

// ---- Remove one import and every report it brought in ----
if ($step === 'delete_batch' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $batch_id = (int)($_POST['batch_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM import_batches WHERE id = :id');
    $stmt->execute(['id' => $batch_id]);
    $batch = $stmt->fetch();
    $target = $batch['target_table'] ?? '';

    if (!$batch || !isset($table_columns[$target])) {
        $upload_error = "That import could not be found.";
    } else {
        $pdo->beginTransaction();
        $del = $pdo->prepare("DELETE FROM $target WHERE import_batch_id = :id");
        $del->execute(['id' => $batch_id]);
        $removed = $del->rowCount();
        $pdo->prepare('DELETE FROM import_batches WHERE id = :id')->execute(['id' => $batch_id]);
        $pdo->commit();

        log_action($pdo, current_user_id(), 'delete_import', $target, $batch_id,
            "Removed $removed records from {$batch['filename']} ({$batch['lab_year']})");
        $_SESSION['import_notice'] = "Deleted $removed record"
            . ($removed === 1 ? '' : 's')
            . " from " . $batch['filename'] . ".";
        redirect('admin/import.php');
    }
}

// ---- STEP 1: upload CSV, show header row for mapping ----
if ($step === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    clear_staged_import();

    $target_table = $_POST['target_table'] ?? '';
    $import_year_posted = parse_import_year($_POST['import_year'] ?? null);
    if (!isset($table_columns[$target_table])) {
        $upload_error = "Invalid target table.";
    } elseif ($import_year_posted === null) {
        $upload_error = "Set the year this file belongs to (1990–2100).";
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_error = "Upload failed. The file may be larger than the server's upload limit.";
    } elseif ($_FILES['csv_file']['size'] > MAX_IMPORT_BYTES) {
        $upload_error = "That file is larger than " . (MAX_IMPORT_BYTES / 1024 / 1024) . "MB. Split the export into smaller files.";
    } else {
        $tmp_path = $upload_dir . '/import_' . bin2hex(random_bytes(8)) . '.csv';
        if (!move_uploaded_file($_FILES['csv_file']['tmp_name'], $tmp_path)) {
            $upload_error = "The uploaded file could not be saved on the server.";
        } else {
            $handle = fopen($tmp_path, 'r');
            $headers = $handle ? fgetcsv($handle) : false;

            if (!$headers) {
                @unlink($tmp_path);
                $upload_error = "That file has no readable header row. Export it again as CSV with headers.";
            } else {
                // Keep a few sample values per column so the mapping step is checkable by eye.
                $preview = [];
                for ($i = 0; $i < 3 && ($row = fgetcsv($handle)) !== false; $i++) {
                    $preview[] = $row;
                }
                $_SESSION['import_file'] = $tmp_path;
                $_SESSION['import_table'] = $target_table;
                $_SESSION['import_headers'] = $headers;
                $_SESSION['import_preview'] = $preview;
                $_SESSION['import_year'] = $import_year_posted;
                $_SESSION['import_filename'] = sanitize_import_filename(
                    (string)($_FILES['csv_file']['name'] ?? 'import.csv')
                );
            }
            if ($handle) fclose($handle);
        }
    }
}

// ---- STEP 2: confirm mapping, run the import ----
if ($step === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (empty($_SESSION['import_file']) || !is_file($_SESSION['import_file'])) {
        $upload_error = "The uploaded file is no longer available. Please upload it again.";
        clear_staged_import();
    } else {
        $import_year = parse_import_year($_POST['import_year'] ?? ($_SESSION['import_year'] ?? null));
        if ($import_year === null) {
            $upload_error = "Set the year this file belongs to (1990–2100).";
        } else {
            $_SESSION['import_year'] = $import_year;
            $target_table = $_SESSION['import_table'];
            $columns = $table_columns[$target_table];
            $dates = $date_columns[$target_table];
            $mapping = $_POST['map'] ?? [];   // [csv_column_index => db_column_or_""]

            $handle = fopen($_SESSION['import_file'], 'r');
            fgetcsv($handle); // skip the header row

            $inserted = 0;
            $skipped = 0;
            $errors = [];
            $row_number = 1;

            $pdo->beginTransaction();

            $now = sql_now();
            $batch_stmt = $pdo->prepare("
                INSERT INTO import_batches
                    (filename, target_table, lab_year, imported_by, inserted_count, skipped_count, created_at)
                VALUES
                    (:filename, :target_table, :lab_year, :imported_by, 0, 0, $now)
                RETURNING id
            ");
            $batch_stmt->execute([
                'filename' => $_SESSION['import_filename'] ?? 'import.csv',
                'target_table' => $target_table,
                'lab_year' => $import_year,
                'imported_by' => current_user_id(),
            ]);
            $batch_id = (int)$batch_stmt->fetchColumn();

            while (($row = fgetcsv($handle)) !== false) {
                $row_number++;

                $data = [];
                foreach ($mapping as $idx => $db_col) {
                    if ($db_col === '' || $db_col === 'lab_year' || $db_col === 'import_batch_id') continue;
                    if (!in_array($db_col, $columns, true)) continue;
                    $value = $row[$idx] ?? null;
                    $value = is_string($value) ? trim($value) : $value;

                    if (in_array($db_col, $dates, true)) {
                        $data[$db_col] = parse_date($value);
                    } elseif ($db_col === 'sex') {
                        $data[$db_col] = parse_sex($value);
                    } elseif ($db_col === 'id') {
                        $id = parse_number($value);
                        if ($id !== null) {
                            $namespaced = import_record_id($import_year, (int)$id);
                            if ($namespaced !== null) {
                                $data[$db_col] = $namespaced;
                            }
                        }
                    } elseif ($db_col === 'age' || $db_col === 'cost') {
                        $data[$db_col] = parse_number($value);
                    } else {
                        $data[$db_col] = ($value === '' || $value === null) ? null : $value;
                    }
                }

                if (empty($data['lab_no'])) {
                    $skipped++;
                    continue;
                }
                $data['lab_year'] = $import_year;
                $data['import_batch_id'] = $batch_id;
                if (empty($data['surname'])) {
                    // surname is NOT NULL - fill rather than lose the row
                    $data['surname'] = 'UNKNOWN';
                }

                $cols_sql = implode(', ', array_keys($data));
                $placeholders = ':' . implode(', :', array_keys($data));

                // A failed statement poisons the whole PostgreSQL transaction, so each
                // row gets its own savepoint: one bad row can't discard the rest.
                $pdo->exec('SAVEPOINT import_row');
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO $target_table ($cols_sql, submitted_by, status)
                        VALUES ($placeholders, :submitted_by, 'approved')
                        ON CONFLICT (lab_no, lab_year) DO NOTHING
                    ");
                    $stmt->execute($data + ['submitted_by' => current_user_id()]);
                    $pdo->exec('RELEASE SAVEPOINT import_row');
                    $stmt->rowCount() > 0 ? $inserted++ : $skipped++;
                } catch (Throwable $e) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT import_row');
                    $errors[] = "Row $row_number (Lab No {$data['lab_no']}): " . $e->getMessage();
                    $skipped++;
                }
            }
            fclose($handle);

            $pdo->prepare("
                UPDATE import_batches
                SET inserted_count = :inserted, skipped_count = :skipped
                WHERE id = :id
            ")->execute([
                'inserted' => $inserted,
                'skipped' => $skipped,
                'id' => $batch_id,
            ]);

            $pdo->commit();

            // Explicit IDs do not advance a PostgreSQL SERIAL sequence on their own.
            if (!db_is_sqlite()) {
                $pdo->exec(
                    "SELECT setval(pg_get_serial_sequence(" . $pdo->quote($target_table) . ", 'id'), "
                    . "COALESCE((SELECT MAX(id) FROM $target_table), 1))"
                );
            }

            log_action($pdo, current_user_id(), 'import_records', $target_table, null,
                "Imported $inserted records for $import_year, skipped $skipped");

            clear_staged_import();
            $result = ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors, 'year' => $import_year];
        }
    }
}

render_header([
    'title' => 'Import Legacy Records',
    'lead'  => 'Bring historical reports in from the old Microsoft Access database.',
    'nav'   => 'import',
]);
?>

<?php if ($notice): ?>
    <div class="alert alert--ok"><?= e($notice) ?></div>
<?php endif; ?>

<?php if ($upload_error): ?>
    <div class="alert alert--error"><?= e($upload_error) ?></div>
<?php endif; ?>

<?php if (isset($result)): ?>
    <div class="alert alert--ok">
        Imported <strong><?= (int)$result['inserted'] ?></strong> records
        for <strong><?= (int)($result['year'] ?? 0) ?></strong>.
        Skipped <strong><?= (int)$result['skipped'] ?></strong>
        (duplicate lab number in that year, missing lab number, or an error).
    </div>

    <?php if ($result['errors']): ?>
        <div class="card" style="margin-bottom:18px;">
            <div class="card__head">
                <h2>Rows that could not be imported</h2>
                <span class="badge badge--rejected"><?= count($result['errors']) ?></span>
            </div>
            <div class="card__body">
                <p class="hint" style="margin-top:0;">
                    Showing the first <?= min(10, count($result['errors'])) ?>. Fix these rows in
                    the CSV and import that file again &mdash; records already brought in will be
                    skipped as duplicates.
                </p>
                <ul style="margin:0;padding-left:20px;font-size:0.88rem;">
                    <?php foreach (array_slice($result['errors'], 0, 10) as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <a class="btn btn--primary" href="<?= app_url('admin/import.php') ?>">Import another file</a>
    <?php render_import_history(fetch_import_batches($pdo)); ?>

<?php elseif (!empty($_SESSION['import_headers'])): ?>
    <div class="card">
        <div class="card__head">
            <h2>Match your columns</h2>
            <span class="badge badge--plain">Step 2 of 2</span>
        </div>
        <div class="card__body card__body--flush">
            <p class="hint" style="padding:0 20px;">
                Each column from your file is matched to a field where the name is recognised.
                Check the sample values, correct anything wrong, and set columns you don't need
                to "Ignore". Access record IDs are stored with the year so 2023/178 and
                2024/178 do not collide.
            </p>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="step" value="import">

                <div class="field" style="padding:0 20px 16px;">
                    <label class="label" for="import_year">Year for this file <span class="req">*</span></label>
                    <input class="input" type="number" id="import_year" name="import_year" required
                           min="1990" max="2100"
                           value="<?= e((string)($_SESSION['import_year'] ?? '')) ?>"
                           style="max-width:12rem;">
                    <div class="hint">
                        Lab numbers stay as they appear in the file. This year is stored separately
                        so the same lab number can exist in 2023 and 2024. Staff still see the
                        original lab number.
                    </div>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Column in your file</th><th>Sample values</th><th>Import as</th></tr></thead>
                        <tbody>
                        <?php
                        $columns = $table_columns[$_SESSION['import_table']];
                        $preview = $_SESSION['import_preview'] ?? [];
                        foreach ($_SESSION['import_headers'] as $idx => $header):
                            $guess = guess_column((string)$header, $columns);
                            $samples = [];
                            foreach ($preview as $prow) {
                                $val = trim((string)($prow[$idx] ?? ''));
                                if ($val !== '') $samples[] = mb_strimwidth($val, 0, 40, '...');
                            }
                        ?>
                        <tr>
                            <td><strong><?= e((string)$header) ?></strong></td>
                            <td class="muted"><?= $samples ? e(implode('  ·  ', $samples)) : '—' ?></td>
                            <td>
                                <select class="select" name="map[<?= (int)$idx ?>]" style="min-width:200px;">
                                    <option value="">— Ignore this column —</option>
                                    <?php foreach ($columns as $col): ?>
                                        <option value="<?= e($col) ?>" <?= $guess === $col ? 'selected' : '' ?>>
                                            <?= e(str_replace('_', ' ', $col)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card__body">
                    <div class="alert alert--info" style="margin-bottom:16px;">
                        Records are imported as <strong>approved</strong>, since they are already
                        finalised historical reports. Every row needs a lab number; rows without one,
                        or whose lab number is already used in the year chosen above, are skipped.
                        A row that fails does not stop the rest of the file.
                    </div>
                    <div class="form-actions">
                        <button class="btn btn--primary" type="submit">Run import</button>
                        <a class="btn btn--ghost" href="<?= app_url('admin/import.php?reset=1') ?>">Cancel and start over</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php else: ?>
    <div class="card">
        <div class="card__head">
            <h2>Upload your file</h2>
            <span class="badge badge--plain">Step 1 of 2</span>
        </div>
        <div class="card__body">
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="step" value="upload">

                <div class="field">
                    <label class="label" for="target_table">Report type</label>
                    <select class="select" id="target_table" name="target_table">
                        <option value="histology_reports" <?= (($_POST['target_table'] ?? '') === 'histology_reports') ? 'selected' : '' ?>>Histology</option>
                        <option value="cytology_reports" <?= (($_POST['target_table'] ?? '') === 'cytology_reports') ? 'selected' : '' ?>>Cytology</option>
                    </select>
                    <div class="hint">Import histology and cytology separately.</div>
                </div>

                <div class="field">
                    <label class="label" for="import_year">Year for this file <span class="req">*</span></label>
                    <input class="input" type="number" id="import_year" name="import_year" required
                           min="1990" max="2100"
                           value="<?= e((string)($_POST['import_year'] ?? '')) ?>"
                           placeholder="e.g. 2024" style="max-width:12rem;">
                    <div class="hint">
                        Set the year this export belongs to. Lab numbers restart each year; this
                        keeps 2023/196 distinct from 2024/196 without changing the number staff see.
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="csv_file">CSV file</label>
                    <input class="input" type="file" id="csv_file" name="csv_file" accept=".csv" required>
                    <div class="hint">Maximum <?= MAX_IMPORT_BYTES / 1024 / 1024 ?>MB.</div>
                </div>

                <div class="form-actions">
                    <button class="btn btn--primary" type="submit">Upload and continue</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card" style="margin-top:18px;">
        <div class="card__head"><h2>Getting the CSV out of Access</h2></div>
        <div class="card__body">
            <p style="margin-top:0;font-size:0.9rem;">
                In Access, choose <strong>External Data &rarr; Export &rarr; Text File</strong>, tick
                <em>Include field names on first row</em>, and save as <code>.csv</code>. If the
                database won't open in Access, <code>mdb-export</code> will read it instead.
                Import one year at a time and set that year above. Access ID columns are
                kept; the year is folded into the ID so the same AutoNumber can exist in
                more than one year.
            </p>
        </div>
    </div>
    <?php render_import_history(fetch_import_batches($pdo)); ?>
<?php endif; ?>

<?php render_footer(); ?>
