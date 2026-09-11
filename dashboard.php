<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$role = current_role();

// Pull a quick summary depending on role.
// Note: each half of a UNION gets its own placeholder name - PDO with native
// prepares rejects the same named placeholder appearing twice.
if ($role === 'staff') {
    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) FROM (
            SELECT status FROM histology_reports WHERE submitted_by = :uid_h
            UNION ALL
            SELECT status FROM cytology_reports WHERE submitted_by = :uid_c
        ) t GROUP BY status
    ");
    $stmt->execute(['uid_h' => current_user_id(), 'uid_c' => current_user_id()]);
    $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
    $pending_count = (int)$pdo->query("
        SELECT COUNT(*) FROM (
            SELECT status FROM histology_reports WHERE status = 'pending'
            UNION ALL
            SELECT status FROM cytology_reports WHERE status = 'pending'
        ) t
    ")->fetchColumn();

    if ($role === 'admin') {
        $active_users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = TRUE")->fetchColumn();
        $recent_actions = (int)$pdo->query(
            "SELECT COUNT(*) FROM access_logs WHERE created_at > " . sql_ago('24 hours')
        )->fetchColumn();
    }
}

// The most recent records this person is allowed to see.
$recent = $pdo->prepare("
    SELECT lab_no, lab_year, surname, other_names, status, created_at, 'histology' AS report_type, id
    FROM histology_reports " . ($role === 'staff' ? 'WHERE submitted_by = :uid_h' : '') . "
    UNION ALL
    SELECT lab_no, lab_year, surname, other_names, status, created_at, 'cytology' AS report_type, id
    FROM cytology_reports " . ($role === 'staff' ? 'WHERE submitted_by = :uid_c' : '') . "
    ORDER BY created_at DESC
    LIMIT 8
");
$recent->execute($role === 'staff'
    ? ['uid_h' => current_user_id(), 'uid_c' => current_user_id()]
    : []);
$recent_records = $recent->fetchAll();

render_header([
    'title' => 'Dashboard',
    'heading' => 'Welcome, ' . explode(' ', trim($_SESSION['full_name'] ?? ''))[0],
    'lead' => 'Histopathology records for ' . e(setting('hospital_name')) . '.',
    'nav' => 'dashboard',
]);
?>

<div class="stats">
<?php if ($role === 'staff'): ?>
    <div class="stat">
        <div class="stat__label">Awaiting review</div>
        <div class="stat__value"><?= (int)($counts['pending'] ?? 0) ?></div>
        <a class="stat__link" href="<?= app_url('records/list.php?status=pending&mine=1') ?>">View</a>
    </div>
    <div class="stat">
        <div class="stat__label">Approved</div>
        <div class="stat__value"><?= (int)($counts['approved'] ?? 0) ?></div>
        <a class="stat__link" href="<?= app_url('records/list.php?status=approved&mine=1') ?>">View</a>
    </div>
    <div class="stat <?= (int)($counts['rejected'] ?? 0) > 0 ? 'stat--attention' : '' ?>">
        <div class="stat__label">Returned to you</div>
        <div class="stat__value"><?= (int)($counts['rejected'] ?? 0) ?></div>
        <?php if ((int)($counts['rejected'] ?? 0) > 0): ?>
            <a class="stat__link" href="<?= app_url('records/list.php?status=rejected&mine=1') ?>">Correct these &rarr;</a>
        <?php else: ?>
            <span class="stat__link" style="color:var(--text-subtle)">Nothing to fix</span>
        <?php endif; ?>
    </div>
<?php elseif ($role === 'reviewer'): ?>
    <div class="stat">
        <div class="stat__label">Awaiting your review</div>
        <div class="stat__value"><?= $pending_count ?></div>
        <a class="stat__link" href="<?= app_url('records/list.php?status=pending') ?>">Start reviewing &rarr;</a>
    </div>
<?php else: ?>
    <div class="stat">
        <div class="stat__label">Awaiting review</div>
        <div class="stat__value"><?= $pending_count ?></div>
        <a class="stat__link" href="<?= app_url('records/list.php?status=pending') ?>">Review and approve &rarr;</a>
    </div>
    <div class="stat">
        <div class="stat__label">Active accounts</div>
        <div class="stat__value"><?= $active_users ?></div>
        <a class="stat__link" href="<?= app_url('admin/users.php') ?>">Manage users</a>
    </div>
    <div class="stat">
        <div class="stat__label">Actions in last 24h</div>
        <div class="stat__value"><?= $recent_actions ?></div>
        <a class="stat__link" href="<?= app_url('admin/logs.php') ?>">Activity log</a>
    </div>
<?php endif; ?>
</div>

<?php if ($role === 'staff' || $role === 'admin'): ?>
<div class="tiles" style="margin-bottom:22px;">
    <a class="tile" href="<?= app_url('records/histology_form.php') ?>">
        <div class="tile__title">New histology report</div>
        <div class="tile__desc">Enter a specimen for histological examination</div>
    </a>
    <a class="tile" href="<?= app_url('records/cytology_form.php') ?>">
        <div class="tile__title">New cytology report</div>
        <div class="tile__desc">Enter a specimen for cytological examination</div>
    </a>
    <a class="tile" href="<?= app_url('records/list.php') ?>">
        <div class="tile__title">Find a record</div>
        <div class="tile__desc">Search by lab number, name, diagnosis, or nature of specimen</div>
    </a>
</div>
<?php endif; ?>

<div class="card">
    <div class="card__head">
        <h2><?= $role === 'staff' ? 'Your recent records' : 'Recent records' ?></h2>
        <a class="btn btn--sm" href="<?= app_url('records/list.php') ?>">View all</a>
    </div>

    <?php if (!$recent_records): ?>
        <div class="empty">
            <div class="empty__title">No records yet</div>
            <div class="empty__hint">Reports will appear here as they are entered.</div>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Lab No</th><th>Patient</th><th>Type</th><th>Status</th><th>Entered</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($recent_records as $r): ?>
                <tr>
                    <td class="mono nowrap"><?= e(format_lab_no($r['lab_no'], $r['lab_year'] ?? null)) ?></td>
                    <td><?= e(trim($r['surname'] . ' ' . $r['other_names'])) ?></td>
                    <td class="muted"><?= ucfirst(e($r['report_type'])) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="muted nowrap"><?= e($r['created_at']) ?></td>
                    <td class="right">
                        <a class="btn btn--sm btn--ghost"
                           href="<?= app_url('records/' . $r['report_type'] . '_print.php') ?>?id=<?= (int)$r['id'] ?>">Open</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
