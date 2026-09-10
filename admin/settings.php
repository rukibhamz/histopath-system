<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role(['admin']);

const LOGO_MAX_BYTES = 2 * 1024 * 1024;
const LOGO_DIR = 'assets/uploads';

$message = '';
$errors = [];

/**
 * Checks an uploaded logo and stores it under assets/uploads with a name of our choosing.
 * Returns the app-relative path, or null with $error explaining why not.
 */
function store_logo_upload(array $file, ?string &$error): ?string {
    $error = null;

    switch ($file['error'] ?? UPLOAD_ERR_NO_FILE) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $error = 'That image is larger than the server allows (' . ini_get('upload_max_filesize') . '). Save a smaller copy and try again.';
            return null;
        case UPLOAD_ERR_PARTIAL:
            $error = 'The logo only partly uploaded. Please try again.';
            return null;
        default:
            $error = 'The logo could not be uploaded (error code ' . (int)$file['error'] . ').';
            return null;
    }

    if ($file['size'] > LOGO_MAX_BYTES) {
        $error = 'The logo must be 2MB or smaller.';
        return null;
    }

    // Judge the file by its contents, never by its name or the type the browser claims.
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($types[$info[2]])) {
        $error = 'The logo must be a PNG, JPG, GIF or WebP image.';
        return null;
    }

    $dir = dirname(__DIR__) . '/' . LOGO_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        $error = 'The folder ' . LOGO_DIR . ' could not be created. Make sure the web server can write to the application folder.';
        return null;
    }
    if (!is_writable($dir)) {
        $error = 'The folder ' . LOGO_DIR . ' is not writable by the web server.';
        return null;
    }

    $name = 'logo-' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        $error = 'The logo could not be saved on the server.';
        return null;
    }
    return LOGO_DIR . '/' . $name;
}

/** Deletes a logo this page uploaded earlier. Never touches any other file. */
function delete_uploaded_logo(string $stored): void {
    $relative = ltrim($stored, '/');
    if (preg_match('#^assets/uploads/logo-[a-f0-9]+\.(png|jpg|gif|webp)$#', $relative)) {
        @unlink(dirname(__DIR__) . '/' . $relative);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // A request bigger than post_max_size arrives with every field and file stripped,
    // which would otherwise be rejected as a forged form. Say what actually happened.
    // (A post carrying only a file still has $_FILES, so it goes through the CSRF check.)
    $errors[] = 'That upload is larger than the server accepts (' . ini_get('post_max_size') . '). Use a smaller logo image.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $current_logo = setting('logo_path');

    $submitted = [];
    foreach (SETTING_DEFAULTS as $key => $default) {
        if ($key === 'logo_path') continue;
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

    // Logo: a new upload replaces it, the remove box clears it, otherwise it is kept.
    $new_logo = $current_logo;
    $uploaded = null;
    if (($_FILES['logo_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $uploaded = store_logo_upload($_FILES['logo_file'], $upload_error);
        if ($uploaded === null) {
            $errors[] = $upload_error;
        } else {
            $new_logo = $uploaded;
        }
    } elseif (!empty($_POST['remove_logo'])) {
        $new_logo = '';
    }
    $submitted['logo_path'] = $new_logo;

    if ($errors) {
        // Nothing is being saved, so don't leave the new file lying around.
        if ($uploaded !== null) delete_uploaded_logo($uploaded);
    } else {
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

        if ($new_logo !== $current_logo) {
            delete_uploaded_logo($current_logo);
        }

        $details = 'Updated branding/appearance settings'
                 . ($uploaded !== null ? '; uploaded a new logo' : ($new_logo === '' && $current_logo !== '' ? '; removed the logo' : ''));
        log_action($pdo, current_user_id(), 'update_settings', 'system_settings', null, $details);
        $message = "Settings saved. They now apply across every screen and printed report.";
    }
}

// Re-read so the page, sidebar and preview all reflect what was just stored.
$settings = settings(true);
$logo_now = logo_url();

render_header([
    'title'  => 'Settings',
    'lead'   => 'These values are read by every screen and printed report, so changes apply system-wide as soon as they are saved.',
    'nav'    => 'settings',
    'narrow' => true,
]);
?>

<?php if ($message): ?><div class="alert alert--ok"><?= e($message) ?></div><?php endif; ?>
<?php render_errors($errors); ?>

<form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="section">
        <div class="section__head">Letterhead</div>
        <div class="section__body">
            <div class="field">
                <label class="label" for="hospital_name">Hospital name</label>
                <input class="input" id="hospital_name" name="hospital_name" value="<?= e($settings['hospital_name']) ?>">
                <div class="hint">Appears in the sidebar and on every printed report.</div>
            </div>
            <div class="field">
                <label class="label" for="department_name">Department name</label>
                <input class="input" id="department_name" name="department_name" value="<?= e($settings['department_name']) ?>">
            </div>
            <div class="field">
                <label class="label" for="report_contact">Contact line</label>
                <input class="input" id="report_contact" name="report_contact" value="<?= e($settings['report_contact']) ?>" placeholder="Tel: 07067165091; 0700HISTOPATH">
                <div class="hint">Printed under the report title on every histology and cytology report.</div>
            </div>

            <div class="field">
                <label class="label" for="logo_file">Logo</label>

                <?php if ($logo_now !== ''): ?>
                    <div style="display:flex;gap:16px;align-items:center;margin-bottom:12px;">
                        <img src="<?= e($logo_now) ?>" alt="Current logo"
                             style="max-height:84px;max-width:140px;object-fit:contain;border:1px solid var(--border);border-radius:var(--radius-sm);padding:6px;background:#fff;">
                        <div>
                            <div class="hint" style="margin:0 0 6px;">Current logo</div>
                            <label style="display:flex;gap:7px;align-items:center;font-size:0.88rem;cursor:pointer;">
                                <input type="checkbox" name="remove_logo" value="1"> Remove logo
                            </label>
                        </div>
                    </div>
                <?php elseif ($settings['logo_path'] !== ''): ?>
                    <div class="alert alert--warn" style="margin-bottom:12px;">
                        The saved logo <span class="mono"><?= e($settings['logo_path']) ?></span> can't be found
                        on the server, so no logo is being shown. Upload the image again below.
                    </div>
                <?php endif; ?>

                <input class="input" type="file" id="logo_file" name="logo_file"
                       accept="image/png,image/jpeg,image/gif,image/webp">
                <div class="hint">
                    <?= $logo_now !== '' ? 'Choose a file to replace the current logo.' : 'Choose an image to use as the logo.' ?>
                    PNG, JPG, GIF or WebP, up to 2MB. It appears on the sign-in page, in the sidebar
                    and on every report.
                </div>
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
            <div class="rf"><?= report_letterhead('Histology Report') ?></div>
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
