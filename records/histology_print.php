<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM histology_reports WHERE id = :id");
$stmt->execute(['id' => $id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    http_response_code(404);
    die("Report not found.");
}

// Staff get a read-only archive: any approved report, plus their own work in
// progress. Someone else's unreviewed draft stays private. Reviewers and admins
// may view anything.
if (current_role() === 'staff'
    && $r['status'] !== 'approved'
    && (int)$r['submitted_by'] !== current_user_id()) {
    http_response_code(403);
    die("Access denied - this report is still under review by the staff member who submitted it.");
}

log_action($pdo, current_user_id(), 'view_record', 'histology_reports', $id);

$can_edit = current_role() === 'admin'
         || (current_role() === 'staff'
             && (int)$r['submitted_by'] === current_user_id()
             && $r['status'] !== 'approved');

$actions = print_button('Print report');
if ($can_edit) {
    $actions .= '<a class="btn" href="' . app_url('records/histology_form.php') . '?id=' . $id . '">Edit</a>';
}
if (in_array(current_role(), ['reviewer', 'admin'], true)) {
    $actions .= '<a class="btn" href="' . app_url('records/review.php') . '?id=' . $id . '&type=histology">Review</a>';
}

render_header([
    'title'   => 'Histology Report ' . $r['lab_no'],
    'heading' => 'Histology report',
    'lead'    => 'Lab number ' . e($r['lab_no']) . ' &middot; ' . status_badge($r['status']),
    'nav'     => 'records',
    'narrow'  => true,
    'back'    => ['label' => 'Back to records', 'url' => 'records/list.php'],
    'actions' => $actions,
]);
?>

<?php if ($r['status'] === 'rejected' && !empty($r['reviewer_comments'])): ?>
    <div class="alert alert--error no-print">
        <strong>Returned by reviewer:</strong><br><?= nl2br(e($r['reviewer_comments'])) ?>
    </div>
<?php endif; ?>

<div class="report">
    <?php require __DIR__ . '/_histology_body.php'; ?>
</div>

<?php render_footer(); ?>
