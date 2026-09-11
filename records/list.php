<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$role = current_role();

$valid_statuses = ['pending', 'approved', 'rejected'];
$status_filter = $_GET['status'] ?? '';
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = '';
}

$valid_types = ['histology', 'cytology'];
$type_filter = $_GET['type'] ?? '';
if (!in_array($type_filter, $valid_types, true)) {
    $type_filter = '';
}

$search = trim($_GET['q'] ?? '');

// "Mine" narrows any view to the current user's own submissions.
$mine_only = ($_GET['mine'] ?? '') === '1';

/** Accept a date only in the format the date input produces. */
function valid_date(?string $value): bool {
    if (!$value) return false;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}
$from = valid_date($_GET['from'] ?? null) ? $_GET['from'] : '';
$to   = valid_date($_GET['to'] ?? null)   ? $_GET['to']   : '';

$params = [];

/**
 * Build the WHERE clause for one half of the UNION.
 *
 * Each half needs its own placeholder names - with native prepares, PDO rejects
 * a named placeholder that appears more than once in a statement - and its own
 * hospital-number column, which histology and cytology spell differently.
 * Columns are qualified with the "r." alias because the query joins users.
 */
$build_where = function (string $suffix, string $hosp_col, array $extra_cols = []) use ($role, $status_filter, $search, $from, $to, $mine_only, &$params): string {
    $conditions = [];

    if ($status_filter !== '') {
        $conditions[] = "r.status = :status$suffix";
        $params["status$suffix"] = $status_filter;
    }

    if ($mine_only) {
        $conditions[] = "r.submitted_by = :uid$suffix";
        $params["uid$suffix"] = current_user_id();
    } elseif ($role === 'staff') {
        // Staff get a read-only archive: any approved report, plus their own
        // work in progress. Other people's drafts stay private.
        $conditions[] = "(r.status = 'approved' OR r.submitted_by = :uid$suffix)";
        $params["uid$suffix"] = current_user_id();
    }

    if ($search !== '') {
        // One placeholder per column, since a name can't be reused in the statement.
        $cols = [
            'lab_no' => 'lab',
            'surname' => 'sur',
            'other_names' => 'oth',
            $hosp_col => 'hosp',
            'nature_of_specimen' => 'nat',
            'diagnosis' => 'dx',
        ] + $extra_cols;
        $like = sql_ilike();
        $clauses = [];
        foreach ($cols as $column => $key) {
            $placeholder = "q_{$key}{$suffix}";
            $clauses[] = "r.$column $like :$placeholder";
            $params[$placeholder] = '%' . $search . '%';
        }
        // Integers compared as text so a typed number still matches.
        $clauses[] = "CAST(r.id AS TEXT) $like :q_id{$suffix}";
        $params["q_id{$suffix}"] = '%' . $search . '%';
        $clauses[] = "CAST(r.lab_year AS TEXT) $like :q_yr{$suffix}";
        $params["q_yr{$suffix}"] = '%' . $search . '%';
        $conditions[] = '(' . implode(' OR ', $clauses) . ')';
    }

    if ($from !== '') {
        $conditions[] = "r.date_of_collection >= :from$suffix";
        $params["from$suffix"] = $from;
    }
    if ($to !== '') {
        $conditions[] = "r.date_of_collection <= :to$suffix";
        $params["to$suffix"] = $to;
    }

    return $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
};

// hospital_no in histology, hosp_no in cytology - aliased so the UNION lines up.
// Only build a half that's actually included: $build_where registers parameters as
// a side effect, and binding one for a half we left out is an error.
$parts = [];

if ($type_filter !== 'cytology') {
    $parts[] = "
    SELECT r.id AS id, r.lab_no AS lab_no, r.lab_year AS lab_year, r.surname AS surname, r.other_names AS other_names,
           r.hospital_no AS patient_hosp_no, r.date_of_collection AS date_of_collection,
           r.status AS status, r.submitted_by AS submitted_by, u.full_name AS submitted_by_name,
           'histology' AS report_type, r.created_at AS created_at
    FROM histology_reports r
    LEFT JOIN users u ON u.id = r.submitted_by " . $build_where('_h', 'hospital_no', ['provisional_diagnosis' => 'pdx']);
}

if ($type_filter !== 'histology') {
    $parts[] = "
    SELECT r.id AS id, r.lab_no AS lab_no, r.lab_year AS lab_year, r.surname AS surname, r.other_names AS other_names,
           r.hosp_no AS patient_hosp_no, r.date_of_collection AS date_of_collection,
           r.status AS status, r.submitted_by AS submitted_by, u.full_name AS submitted_by_name,
           'cytology' AS report_type, r.created_at AS created_at
    FROM cytology_reports r
    LEFT JOIN users u ON u.id = r.submitted_by " . $build_where('_c', 'hosp_no');
}

const RECORD_LIST_LIMIT = 200;

$sql = implode("\n    UNION ALL\n", $parts) . "
    ORDER BY created_at DESC
    LIMIT " . (RECORD_LIST_LIMIT + 1);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// One extra row was requested purely to detect truncation.
$truncated = count($records) > RECORD_LIST_LIMIT;
if ($truncated) {
    array_pop($records);
}

$searching = ($search !== '' || $from !== '' || $to !== '');

/** Rebuild the current query string with one parameter changed. */
function current_link(array $overrides = []): string {
    $query = array_merge([
        'status' => $_GET['status'] ?? '',
        'type'   => $_GET['type'] ?? '',
        'q'      => $_GET['q'] ?? '',
        'from'   => $_GET['from'] ?? '',
        'to'     => $_GET['to'] ?? '',
        'mine'   => $_GET['mine'] ?? '',
    ], $overrides);
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
    return app_url('records/list.php') . ($query ? '?' . http_build_query($query) : '');
}

$actions = '';
if ($role === 'staff' || $role === 'admin') {
    $actions = '<a class="btn btn--primary btn--sm" href="' . app_url('records/histology_form.php') . '">New histology</a>'
             . '<a class="btn btn--sm" href="' . app_url('records/cytology_form.php') . '">New cytology</a>';
}
if ($records) {
    $actions .= print_button('Print list');
}

render_header([
    'title' => $mine_only ? 'My Submissions' : 'Records',
    'lead'  => $role === 'staff' && !$mine_only
        ? 'Search every approved report, including imported historical records. Reports still awaiting review are visible only to whoever submitted them.'
        : 'Search by lab number, hospital number, patient name, diagnosis, or nature of specimen.',
    'nav' => $status_filter === 'pending' && in_array($role, ['reviewer', 'admin'], true) ? 'pending' : 'records',
    'actions' => $actions,
]);
?>

<div class="card no-print" style="margin-bottom:18px;">
    <div class="card__head">
        <div class="tabs">
            <a href="<?= current_link(['status' => '']) ?>" class="<?= $status_filter === '' ? 'is-active' : '' ?>">All</a>
            <a href="<?= current_link(['status' => 'pending']) ?>" class="<?= $status_filter === 'pending' ? 'is-active' : '' ?>">Pending</a>
            <a href="<?= current_link(['status' => 'approved']) ?>" class="<?= $status_filter === 'approved' ? 'is-active' : '' ?>">Approved</a>
            <a href="<?= current_link(['status' => 'rejected']) ?>" class="<?= $status_filter === 'rejected' ? 'is-active' : '' ?>">Rejected</a>
            <span class="tabs__sep"></span>
            <?php if ($mine_only): ?>
                <a href="<?= current_link(['mine' => '']) ?>">All records</a>
            <?php else: ?>
                <a href="<?= current_link(['mine' => '1']) ?>">Only mine</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card__body">
        <form method="GET" class="filters">
            <?php if ($status_filter !== ''): ?><input type="hidden" name="status" value="<?= e($status_filter) ?>"><?php endif; ?>
            <?php if ($mine_only): ?><input type="hidden" name="mine" value="1"><?php endif; ?>

            <div class="field field--grow">
                <label class="label" for="q">Search</label>
                <input class="input" id="q" name="q" value="<?= e($search) ?>"
                       placeholder="Lab number, name, diagnosis, specimen, or record ID">
            </div>
            <div class="field">
                <label class="label" for="type">Type</label>
                <select class="select" id="type" name="type">
                    <option value="">Both</option>
                    <option value="histology" <?= $type_filter === 'histology' ? 'selected' : '' ?>>Histology</option>
                    <option value="cytology" <?= $type_filter === 'cytology' ? 'selected' : '' ?>>Cytology</option>
                </select>
            </div>
            <div class="field">
                <label class="label" for="from">Collected from</label>
                <input class="input" type="date" id="from" name="from" value="<?= e($from) ?>">
            </div>
            <div class="field">
                <label class="label" for="to">To</label>
                <input class="input" type="date" id="to" name="to" value="<?= e($to) ?>">
            </div>
            <div class="field">
                <button class="btn btn--primary" type="submit">Search</button>
            </div>
            <?php if ($searching || $type_filter !== ''): ?>
                <div class="field">
                    <a class="btn btn--ghost" href="<?= current_link(['q' => '', 'from' => '', 'to' => '', 'type' => '']) ?>">Clear</a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
<?php if (!$records): ?>
    <div class="empty">
        <div class="empty__title"><?= $searching ? 'No matching records' : 'No records to show' ?></div>
        <div class="empty__hint">
            <?= $searching
                ? 'Try part of a lab number, surname, diagnosis, or specimen, or widen the date range.'
                : 'Reports will appear here as they are entered.' ?>
        </div>
    </div>
<?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead>
            <tr>
                <th>Lab No</th><th>Patient</th><th>Hospital No</th><th>Type</th>
                <th>Collected</th><th>Status</th><th>Submitted by</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $r): ?>
            <tr>
                <td class="mono nowrap"><?= e(format_lab_no($r['lab_no'], $r['lab_year'] ?? null)) ?></td>
                <td><?= e(trim($r['surname'] . ' ' . $r['other_names'])) ?></td>
                <td class="mono muted"><?= e($r['patient_hosp_no']) ?></td>
                <td class="muted"><?= ucfirst(e($r['report_type'])) ?></td>
                <td class="nowrap"><?= e($r['date_of_collection']) ?></td>
                <td><?= status_badge($r['status']) ?></td>
                <td>
                    <?= e($r['submitted_by_name'] ?? 'Unknown') ?>
                    <div class="muted"><?= e($r['created_at']) ?></div>
                </td>
                <td>
                    <div class="row-actions" style="justify-content:flex-end;">
                        <a class="btn btn--sm btn--ghost"
                           href="<?= app_url('records/' . $r['report_type'] . '_print.php') ?>?id=<?= (int)$r['id'] ?>">View</a>
                        <?php if ($role === 'reviewer' || $role === 'admin'): ?>
                            <a class="btn btn--sm"
                               href="<?= app_url('records/review.php') ?>?id=<?= (int)$r['id'] ?>&amp;type=<?= e($r['report_type']) ?>">Review</a>
                        <?php endif; ?>
                        <?php
                        $can_edit = can_edit_report($r);
                        ?>
                        <?php if ($can_edit): ?>
                            <a class="btn btn--sm"
                               href="<?= app_url('records/' . $r['report_type'] . '_form.php') ?>?id=<?= (int)$r['id'] ?>">Edit</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="tablefoot">
        <?php if ($truncated): ?>
            Showing the first <?= RECORD_LIST_LIMIT ?> matches &mdash; there are more.
            Narrow the search with a lab number, diagnosis, specimen, or a collection date range.
        <?php else: ?>
            <?= count($records) ?> record<?= count($records) === 1 ? '' : 's' ?>.
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<?php render_footer(); ?>
