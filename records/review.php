<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role(['reviewer', 'admin']);

$id = (int)($_GET['id'] ?? 0);
$type = ($_GET['type'] ?? '') === 'cytology' ? 'cytology' : 'histology';
$table = $type . '_reports';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $decision = $_POST['decision'] ?? '';
    $comments = trim($_POST['comments'] ?? '');

    if (!in_array($decision, ['approved', 'rejected'], true)) {
        $error = "Invalid decision.";
    } elseif ($decision === 'rejected' && $comments === '') {
        $error = "Please explain what needs correcting before rejecting a record - "
               . "the comment is what the submitting staff member sees.";
    } else {
        $now = sql_now();
        $stmt = $pdo->prepare("
            UPDATE $table
            SET status = :status, reviewed_by = :uid, reviewer_comments = :comments,
                reviewed_at = $now
            WHERE id = :id
        ");
        $stmt->execute([
            'status' => $decision,
            'uid' => current_user_id(),
            'comments' => $comments !== '' ? $comments : null,
            'id' => $id,
        ]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            die("Record not found.");
        }

        log_action($pdo, current_user_id(), $decision === 'approved' ? 'approve_record' : 'reject_record', $table, $id, $comments);

        redirect('records/list.php?status=pending');
    }
}

$stmt = $pdo->prepare("
    SELECT r.*, s.full_name AS submitted_by_name, v.full_name AS reviewed_by_name
    FROM $table r
    LEFT JOIN users s ON s.id = r.submitted_by
    LEFT JOIN users v ON v.id = r.reviewed_by
    WHERE r.id = :id
");
$stmt->execute(['id' => $id]);
$r = $stmt->fetch();
if (!$r) {
    http_response_code(404);
    die("Record not found.");
}

log_action($pdo, current_user_id(), 'view_record', $table, $id);

$own_record = (int)$r['submitted_by'] === current_user_id();

render_header([
    'title'   => 'Review ' . format_lab_no($r['lab_no'], $r['lab_year'] ?? null),
    'heading' => 'Review ' . ucfirst($type) . ' Report',
    'lead'    => 'Lab number ' . e(format_lab_no($r['lab_no'], $r['lab_year'] ?? null)) . ' &middot; ' . status_badge($r['status']),
    'nav'     => $r['status'] === 'pending' ? 'pending' : 'records',
    'narrow'  => false,
    'back'    => ['label' => 'Back to records', 'url' => 'records/list.php'],
    'actions' => print_button('Print report')
        . (can_edit_report($r)
            ? '<a class="btn" href="' . app_url('records/' . $type . '_form.php') . '?id=' . (int)$id . '">Edit</a>'
            : ''),
]);
?>

<div class="card no-print" style="margin-bottom:18px;">
    <div class="card__body">
        <div class="grid grid--2">
            <div>
                <span class="label">Submitted by</span>
                <?= e($r['submitted_by_name'] ?? 'Unknown') ?>
                <span class="muted">on <?= e($r['created_at']) ?></span>
            </div>
            <?php if ($r['reviewed_by_name']): ?>
            <div>
                <span class="label">Last reviewed by</span>
                <?= e($r['reviewed_by_name']) ?>
                <span class="muted">on <?= e($r['reviewed_at']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($r['reviewer_comments'])): ?>
    <div class="alert alert--warn no-print">
        <strong>Previous reviewer comments</strong><br><?= nl2br(e($r['reviewer_comments'])) ?>
    </div>
<?php endif; ?>

<div class="report" style="margin-bottom:18px;">
    <?php require __DIR__ . '/_' . $type . '_body.php'; ?>
</div>

<div class="card no-print">
    <div class="card__head"><h2>Review decision</h2></div>
    <div class="card__body">

        <?php if ($error): ?>
            <div class="alert alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($own_record): ?>
            <div class="alert alert--warn">
                You submitted this record yourself. Where possible, have a different reviewer
                sign it off &mdash; self-review is recorded in the audit log.
            </div>
        <?php endif; ?>

        <?php if ($r['status'] !== 'pending'): ?>
            <div class="alert alert--info">
                This record has already been <?= e($r['status']) ?>. Submitting again replaces
                the previous decision.
            </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="field">
                <label class="label" for="comments">
                    Reviewer comments <span class="muted">(required when rejecting)</span>
                </label>
                <textarea class="textarea" id="comments" name="comments"
                          placeholder="What needs correcting? This is what the submitting staff member sees."><?= e($_POST['comments'] ?? '') ?></textarea>
            </div>

            <div class="form-actions">
                <button class="btn btn--approve" type="submit" name="decision" value="approved"
                        onclick="return confirm('Approve this report?')">Approve</button>
                <button class="btn btn--reject" type="submit" name="decision" value="rejected"
                        onclick="return confirm('Reject and send back to staff?')">Reject and return</button>
                <a class="btn btn--ghost" href="<?= app_url('records/list.php') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php render_footer(); ?>
