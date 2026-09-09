<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role(['admin']);

// Build filters
$where = [];
$params = [];

if (!empty($_GET['user_id'])) {
    $where[] = "l.user_id = :user_id";
    $params['user_id'] = (int)$_GET['user_id'];
}
if (!empty($_GET['action'])) {
    $where[] = "l.action = :action";
    $params['action'] = $_GET['action'];
}
/** Accept a date only in the format the date input produces. */
function valid_date(?string $value): bool {
    if (!$value) return false;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

if (valid_date($_GET['from'] ?? null)) {
    $where[] = "l.created_at >= :from";
    $params['from'] = $_GET['from'];
}
if (valid_date($_GET['to'] ?? null)) {
    $where[] = "l.created_at <= :to";
    $params['to'] = $_GET['to'] . ' 23:59:59';
}

$sql = "
    SELECT l.*, u.full_name, u.username
    FROM access_logs l
    LEFT JOIN users u ON u.id = l.user_id
";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY l.created_at DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$users = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();
$actions = $pdo->query("SELECT DISTINCT action FROM access_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

render_header([
    'title' => 'Activity Log',
    'lead'  => 'Every sign-in, record view, edit and approval is recorded here.',
    'nav'   => 'logs',
]);
?>

<div class="card" style="margin-bottom:18px;">
    <div class="card__body">
        <form method="GET" class="filters">
            <div class="field">
                <label class="label" for="user_id">User</label>
                <select class="select" id="user_id" name="user_id">
                    <option value="">Everyone</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= ($_GET['user_id'] ?? '') == $u['id'] ? 'selected' : '' ?>>
                            <?= e($u['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label" for="action">Action</label>
                <select class="select" id="action" name="action">
                    <option value="">All actions</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= e($a) ?>" <?= ($_GET['action'] ?? '') === $a ? 'selected' : '' ?>>
                            <?= e(str_replace('_', ' ', $a)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label" for="from">From</label>
                <input class="input" type="date" id="from" name="from" value="<?= e($_GET['from'] ?? '') ?>">
            </div>
            <div class="field">
                <label class="label" for="to">To</label>
                <input class="input" type="date" id="to" name="to" value="<?= e($_GET['to'] ?? '') ?>">
            </div>
            <div class="field"><button class="btn btn--primary" type="submit">Filter</button></div>
            <div class="field"><a class="btn btn--ghost" href="<?= app_url('admin/logs.php') ?>">Clear</a></div>
        </form>
    </div>
</div>

<div class="card">
<?php if (!$logs): ?>
    <div class="empty">
        <div class="empty__title">No matching activity</div>
        <div class="empty__hint">Try widening the date range or clearing the filters.</div>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>When</th><th>User</th><th>Action</th><th>Target</th><th>IP</th><th>Details</th></tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="nowrap muted"><?= e($l['created_at']) ?></td>
                    <td>
                        <?= e($l['full_name'] ?? 'System') ?>
                        <?php if (!empty($l['username'])): ?>
                            <div class="muted mono"><?= e($l['username']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge--plain"><?= e(str_replace('_', ' ', $l['action'])) ?></span></td>
                    <td class="muted">
                        <?= e(str_replace('_reports', '', $l['target_table'] ?? '')) ?>
                        <?= $l['target_id'] ? '#' . (int)$l['target_id'] : '' ?>
                    </td>
                    <td class="mono muted"><?= e($l['ip_address'] ?? '') ?></td>
                    <td class="muted"><?= e($l['details'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="tablefoot">Showing the <?= count($logs) ?> most recent matching entries (maximum 500).</div>
<?php endif; ?>
</div>

<?php render_footer(); ?>
