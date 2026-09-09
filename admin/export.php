<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role(['admin', 'reviewer']);

$table_columns = [
    'histology_reports' => [
        'lab_no', 'surname', 'other_names', 'age', 'sex', 'ethnic_group', 'hospital_no',
        'requesting_hospital', 'ward_clinic', 'date_of_collection', 'clinical_history',
        'nature_of_specimen', 'special_requests', 'provisional_diagnosis', 'previous_lab_no',
        'clinician', 'specimen_status', 'gross', 'microscopy', 'further_tests', 'bone_marrow',
        'lymphomas', 'diagnosis', 'remarks', 'resident_doctors', 'consultant_pathologists',
        'date_out', 'adverse_incidents', 'cost', 'status', 'reviewer_comments', 'reviewed_at',
    ],
    'cytology_reports' => [
        'lab_no', 'surname', 'other_names', 'age', 'sex', 'ethnic_group', 'requesting_hospital',
        'hosp_no', 'ward_clinic', 'patients_tel_no', 'date_of_collection', 'clinical_history',
        'lmp', 'drug_history', 'radiation', 'previous_lab_no', 'nature_of_specimen', 'clinician',
        'clinician_tel_no', 'microscopy', 'diagnosis', 'recommendation', 'resident_doctors',
        'consultant_pathologists', 'signout_date', 'cost', 'status', 'reviewer_comments', 'reviewed_at',
    ],
];

// ---- Handle actual download ----
if (isset($_GET['download'])) {
    $table = $_GET['table'] ?? '';
    if (!isset($table_columns[$table])) die("Invalid table.");

    /** Accept a date only in the format the date input produces. */
    $valid_date = function (?string $value): bool {
        if (!$value) return false;
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    };

    $where = [];
    $params = [];
    if (in_array($_GET['status'] ?? '', ['pending', 'approved', 'rejected'], true)) {
        $where[] = "status = :status";
        $params['status'] = $_GET['status'];
    }
    if ($valid_date($_GET['from'] ?? null)) { $where[] = "date_of_collection >= :from"; $params['from'] = $_GET['from']; }
    if ($valid_date($_GET['to'] ?? null))   { $where[] = "date_of_collection <= :to";   $params['to'] = $_GET['to']; }

    $cols = $table_columns[$table];
    $sql = "SELECT " . implode(', ', $cols) . " FROM $table";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY date_of_collection DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    log_action($pdo, current_user_id(), 'export_records', $table, null,
        "Exported with filters: " . json_encode($params));

    $filename = $table . '_export_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM, so Excel reads accented names correctly
    fputcsv($out, $cols); // header row
    while ($row = $stmt->fetch()) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

render_header([
    'title'  => 'Export Records',
    'lead'   => 'Download records as a CSV file for analysis or reporting.',
    'nav'    => 'export',
    'narrow' => true,
]);
?>

<div class="card">
    <div class="card__body">
        <form method="GET">
            <input type="hidden" name="download" value="1">

            <div class="field">
                <label class="label" for="table">Report type</label>
                <select class="select" id="table" name="table">
                    <option value="histology_reports">Histology</option>
                    <option value="cytology_reports">Cytology</option>
                </select>
            </div>

            <div class="field">
                <label class="label" for="status">Status</label>
                <select class="select" id="status" name="status">
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>

            <div class="grid grid--2">
                <div class="field">
                    <label class="label" for="from">Collected from</label>
                    <input class="input" type="date" id="from" name="from">
                </div>
                <div class="field">
                    <label class="label" for="to">Collected to</label>
                    <input class="input" type="date" id="to" name="to">
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn--primary" type="submit">Download CSV</button>
            </div>
        </form>
    </div>
</div>

<div class="alert alert--warn" style="margin-top:18px;">
    <strong>This file contains patient data.</strong>
    Handle the download the way you would a physical patient record: keep it on the hospital
    machine, do not email it, and delete local copies once you are finished. Every export is
    recorded in the activity log.
</div>

<?php render_footer(); ?>
