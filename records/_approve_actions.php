<?php
/**
 * Approve / return controls for reviewers and admins, shown on the report view.
 * Expects $r, $id, and $type ('histology' or 'cytology') in scope.
 */
if (!in_array(current_role(), ['reviewer', 'admin'], true)) {
    return;
}
?>
<div class="card no-print" style="margin-top:18px;">
    <div class="card__head">
        <h2><?= $r['status'] === 'pending' ? 'Approve this report' : 'Review decision' ?></h2>
    </div>
    <div class="card__body">
        <?php if ($r['status'] !== 'pending'): ?>
            <p class="hint" style="margin-top:0;">
                This report is already <?= e($r['status']) ?>. Approving or returning again replaces the previous decision.
            </p>
        <?php endif; ?>
        <form method="POST" action="<?= app_url('records/review.php') ?>?id=<?= (int)$id ?>&amp;type=<?= e($type) ?>">
            <?= csrf_field() ?>
            <div class="form-actions">
                <button class="btn btn--approve" type="submit" name="decision" value="approved"
                        onclick="return confirm('Approve this report?')">Approve</button>
                <a class="btn btn--reject"
                   href="<?= app_url('records/review.php') ?>?id=<?= (int)$id ?>&amp;type=<?= e($type) ?>">Reject and return</a>
            </div>
        </form>
    </div>
</div>
