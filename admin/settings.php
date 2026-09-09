<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role(['admin']);

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $submitted = [];
    foreach (SETTING_DEFAULTS as $key => $default) {
        $submitted[$key] = trim((string)($_POST[$key] ?? ''));
    }

    foreach (['primary_color', 'accent_color'] as $color_key) {
        if ($submitted[$color_key] !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $submitted[$color_key])) {
            $errors[] = ucfirst(str_replace('_', ' ', $color_key)) . " must be a hex color like #2c3e6f.";
        }
    }

    if ($submitted['server_address'] !== '' && !preg_match('#^https?://#i', $submitted['server_address'])) {
        $errors[] = "Network address must start with http:// or https://";
    }

    // The logo is referenced by URL/path, so keep it to a same-origin path and
    // don't let it become an arbitrary external or javascript: URL.
    if ($submitted['logo_path'] !== '' && !preg_match('#^/[A-Za-z0-9._/\-]*$#', $submitted['logo_path'])) {
        $errors[] = "Logo path must be a path on this server starting with '/', e.g. /assets/logo.png";
    }

    if (!$errors) {
        $now = sql_now();
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
            VALUES (:key, :value, :uid, $now)
            ON CONFLICT (setting_key)
            DO UPDATE SET setting_value = EXCLUDED.setting_value,
                          updated_by = EXCLUDED.updated_by,
                          updated_at = $now
        ");
        foreach ($submitted as $key => $value) {
            $stmt->execute([
                'key' => $key,
                'value' => $value !== '' ? $value : SETTING_DEFAULTS[$key],
                'uid' => current_user_id(),
            ]);
        }
        log_action($pdo, current_user_id(), 'update_settings', 'system_settings', null, 'Updated branding/appearance settings');
        $message = "Settings saved. They now apply across every screen and printed report.";
    }
}

// Re-read after saving so the preview below reflects what was just stored.
$settings = $_SERVER['REQUEST_METHOD'] === 'POST' && !$errors
    ? array_merge(SETTING_DEFAULTS, $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR))
    : settings();

render_header([
    'title'  => 'Settings',
    'lead'   => 'These values are read by every screen and printed report, so changes apply system-wide as soon as they are saved.',
    'nav'    => 'settings',
    'narrow' => true,
]);
?>

<?php if ($message): ?><div class="alert alert--ok"><?= e($message) ?></div><?php endif; ?>
<?php render_errors($errors); ?>

<form method="POST">
    <?= csrf_field() ?>

    <div class="section">
        <div class="section__head">Letterhead</div>
        <div class="section__body">
            <div class="field">
                <label class="label" for="hospital_name">Hospital name</label>
                <input class="input" id="hospital_name" name="hospital_name" value="<?= e($settings['hospital_name']) ?>">
                <div class="hint">Appears in the top bar and on every printed report.</div>
            </div>
            <div class="field">
                <label class="label" for="department_name">Department name</label>
                <input class="input" id="department_name" name="department_name" value="<?= e($settings['department_name']) ?>">
            </div>
            <div class="field">
                <label class="label" for="logo_path">Logo path</label>
                <input class="input" id="logo_path" name="logo_path" value="<?= e($settings['logo_path']) ?>" placeholder="/assets/logo.png">
                <div class="hint">A path on this server starting with "/". Leave blank for no logo.</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section__head">Appearance</div>
        <div class="section__body">
            <div class="grid grid--2">
                <div class="field">
                    <label class="label" for="primary_color">Primary colour</label>
                    <input class="input" type="color" id="primary_color" name="primary_color" value="<?= e($settings['primary_color']) ?>">
                    <div class="hint">Navigation, buttons and links.</div>
                </div>
                <div class="field">
                    <label class="label" for="accent_color">Accent colour</label>
                    <input class="input" type="color" id="accent_color" name="accent_color" value="<?= e($settings['accent_color']) ?>">
                    <div class="hint">Report titles on printed output.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section__head">Network</div>
        <div class="section__body">
            <div class="field">
                <label class="label" for="server_address">Address for other computers</label>
                <input class="input" id="server_address" name="server_address" value="<?= e($settings['server_address']) ?>" placeholder="http://192.168.1.50/">
                <div class="hint">
                    What staff type into their browser to reach this system. Must not be
                    "localhost" &mdash; other machines cannot use that.
                </div>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit">Save settings</button>
        <a class="btn btn--ghost" href="<?= app_url('dashboard.php') ?>">Cancel</a>
    </div>
</form>

<div class="card" style="margin-top:22px;">
    <div class="card__head"><h2>Preview</h2></div>
    <div class="card__body">
        <div class="report" style="box-shadow:none;">
            <div class="report__head">
                <?php if ($settings['logo_path'] !== ''): ?>
                    <img src="<?= e($settings['logo_path']) ?>" alt="">
                <?php endif; ?>
                <div class="report__org"><?= e($settings['hospital_name']) ?></div>
                <div class="report__dept"><?= e($settings['department_name']) ?></div>
                <div class="report__title">Histology Report</div>
            </div>
            <div class="form-actions">
                <span class="btn btn--primary">Primary button</span>
                <span class="badge badge--approved">Approved</span>
                <span class="badge badge--pending">Pending</span>
                <span class="badge badge--rejected">Rejected</span>
            </div>
        </div>

        <?php if ($settings['server_address'] !== ''): ?>
            <div class="alert alert--info" style="margin:18px 0 0;">
                <strong>Staff reach this system at</strong><br>
                <span class="mono"><?= e($settings['server_address']) ?></span>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php render_footer(); ?>
