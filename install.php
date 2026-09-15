<?php
/**
 * First-run setup wizard.
 *
 * Walks through: server requirements, database connection, the first administrator
 * account, and the address other computers on the intranet will use. On the last
 * step it writes includes/config.php, which is what marks the system as installed.
 *
 * Once a database exists and has a user this page refuses to run, so it cannot
 * be used to point a working installation at a different database. A leftover
 * or incomplete config.php (copied folder, empty SQLite path) does not count.
 */
require_once __DIR__ . '/includes/paths.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/install_state.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/schema_loader.php';

const CONFIG_FILE = __DIR__ . '/includes/config.php';
const MIN_PHP_VERSION = '8.1.0';

/** Escape for HTML output. */
function h(?string $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Already installed? Then this wizard is closed for business.
// ---------------------------------------------------------------------------
if (app_is_installed()) {
    http_response_code(403);
    ?>
    <!DOCTYPE html><html><head><meta charset="utf-8"><title>Already Installed</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 60px auto; }
        .box { border: 1px solid #f0d98c; background: #fff8e6; padding: 20px; }
        code { background: #eee; padding: 1px 4px; }
    </style></head><body>
    <h2>This system is already set up</h2>
    <div class="box">
        <p>The setup wizard has been disabled because this copy already has a working database and at least one user.</p>
        <p>To keep it that way, delete <code>install.php</code> from the server.
           To start over deliberately, remove <code>includes/config.php</code> first &mdash;
           note that this does not delete any data.</p>
    </div>
    <p><a href="<?= h(app_url('login.php')) ?>">Go to the login page &rarr;</a></p>
    </body></html>
    <?php
    exit;
}

// ---------------------------------------------------------------------------
// Requirement checks
// ---------------------------------------------------------------------------
$requirements = [
    [
        'label' => 'PHP ' . MIN_PHP_VERSION . ' or newer',
        'ok'    => version_compare(PHP_VERSION, MIN_PHP_VERSION, '>='),
        'found' => 'Found PHP ' . PHP_VERSION,
        'fix'   => 'Upgrade PHP, or ask your IT supplier to.',
    ],
    [
        'label' => 'A database driver',
        'ok'    => extension_loaded('pdo_pgsql') || extension_loaded('pdo_sqlite'),
        'found' => implode(', ', array_filter([
                       extension_loaded('pdo_sqlite') ? 'SQLite' : null,
                       extension_loaded('pdo_pgsql') ? 'PostgreSQL' : null,
                   ])) ?: 'None',
        'fix'   => 'Run: sudo apt install php-sqlite3 (or php-pgsql), then restart Apache.',
    ],
    [
        'label' => 'The includes/ folder is writable',
        'ok'    => is_writable(__DIR__ . '/includes'),
        'found' => is_writable(__DIR__ . '/includes') ? 'Writable' : 'Not writable',
        'fix'   => 'Run: sudo chown -R www-data:www-data ' . __DIR__,
    ],
    [
        'label' => 'Sessions are working',
        'ok'    => session_status() === PHP_SESSION_ACTIVE,
        'found' => session_status() === PHP_SESSION_ACTIVE ? 'Working' : 'Not starting',
        'fix'   => 'Check that PHP can write to its session save path.',
    ],
];
$requirements_met = !in_array(false, array_column($requirements, 'ok'), true);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Opens a connection using the details given, or throws. */
function connect(array $db): PDO {
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

    if (($db['db_driver'] ?? 'pgsql') === 'sqlite') {
        db_driver('sqlite');
        $pdo = new PDO('sqlite:' . $db['db_path'], null, null, $options);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    db_driver('pgsql');
    return new PDO(
        "pgsql:host={$db['db_host']};port={$db['db_port']};dbname={$db['db_name']}",
        $db['db_user'],
        $db['db_password'],
        $options
    );
}

/** The schema file matching the chosen database. */
function schema_file(string $driver): string {
    return __DIR__ . ($driver === 'sqlite' ? '/schema.sqlite.sql' : '/schema.sql');
}

/** Where the SQLite file should live by default: beside the web root, never inside it. */
function suggested_sqlite_path(): string {
    return app_suggested_sqlite_path();
}

/** True if the path sits somewhere a browser could request it. */
function inside_web_root(string $path): bool {
    $doc_root = str_replace(DIRECTORY_SEPARATOR, '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($doc_root === '') return false;

    $target = str_replace(DIRECTORY_SEPARATOR, '/', $path);
    $resolved = realpath(dirname($target));
    if ($resolved !== false) {
        $target = str_replace(DIRECTORY_SEPARATOR, '/', $resolved) . '/' . basename($target);
    }
    return str_starts_with($target, rtrim($doc_root, '/') . '/');
}

/**
 * Makes sure the SQLite file's folder exists and can be written to.
 * Returns an error message, or '' when everything is in order.
 */
function prepare_sqlite_location(string $path): string {
    if ($path === '') return "Database file location is required.";

    if (inside_web_root($path)) {
        return "That location is inside the web folder, where a browser could download "
             . "the whole patient database. Put it somewhere outside, for example "
             . suggested_sqlite_path();
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return "The folder $dir does not exist and could not be created. "
             . "Create it and give the web server ownership.";
    }
    if (!is_writable($dir)) {
        return "The folder $dir is not writable by the web server. "
             . "Run: sudo chown -R www-data:www-data $dir";
    }
    if (is_file($path) && !is_writable($path)) {
        return "The file $path exists but is not writable by the web server.";
    }
    return '';
}

/** A sensible default for the address staff will type into their browsers. */
function suggested_address(): string {
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_ADDR'] ?? 'localhost');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . app_base() . '/';
}

// ---------------------------------------------------------------------------
// Step handling
// ---------------------------------------------------------------------------
$step = (int)($_GET['step'] ?? 1);
$errors = [];
$notices = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $step = (int)($_POST['step'] ?? 1);

    // ---- Step 2: database connection -------------------------------------
    if ($step === 2) {
        $chosen_driver = ($_POST['db_driver'] ?? 'sqlite') === 'pgsql' ? 'pgsql' : 'sqlite';

        $db = ['db_driver' => $chosen_driver];

        if ($chosen_driver === 'sqlite') {
            $db['db_path'] = trim($_POST['db_path'] ?? '');
            $location_error = prepare_sqlite_location($db['db_path']);
            if ($location_error !== '') $errors[] = $location_error;
            if (!extension_loaded('pdo_sqlite')) $errors[] = "The SQLite driver (pdo_sqlite) is not installed.";
        } else {
            $db += [
                'db_host'     => trim($_POST['db_host'] ?? ''),
                'db_port'     => trim($_POST['db_port'] ?? '5432'),
                'db_name'     => trim($_POST['db_name'] ?? ''),
                'db_user'     => trim($_POST['db_user'] ?? ''),
                'db_password' => (string)($_POST['db_password'] ?? ''),
            ];
            foreach (['db_host' => 'Server', 'db_name' => 'Database name', 'db_user' => 'Username'] as $key => $label) {
                if ($db[$key] === '') $errors[] = "$label is required.";
            }
            if (!ctype_digit($db['db_port'])) $errors[] = "Port must be a number.";
            if (!extension_loaded('pdo_pgsql')) $errors[] = "The PostgreSQL driver (pdo_pgsql) is not installed.";
        }

        if (!$errors) {
            try {
                $pdo = connect($db);
                $tables = db_existing_tables($pdo);

                if (count($tables) === 0) {
                    $problems = load_schema($pdo, schema_file($chosen_driver));
                    if ($problems) {
                        $errors[] = "Connected, but the tables could not be created:";
                        foreach (array_slice($problems, 0, 5) as $p) $errors[] = $p;
                    } else {
                        $notices[] = "Connected, and the database tables were created.";
                    }
                } else {
                    $notices[] = "Connected. Found " . count($tables) . " existing table(s) - "
                               . "these will be used as they are, and no data will be touched.";
                }

                if (!$errors) {
                    $_SESSION['install_db'] = $db;
                    $step = 3;
                }
            } catch (PDOException $e) {
                $errors[] = "Could not connect: " . $e->getMessage();
            }
        }
    }

    // ---- Step 3: administrator account -----------------------------------
    if ($step === 3 && isset($_POST['create_admin'])) {
        $admin = [
            'full_name' => trim($_POST['full_name'] ?? ''),
            'email'     => trim($_POST['email'] ?? ''),
            'username'  => trim($_POST['username'] ?? ''),
        ];
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');

        if ($admin['full_name'] === '') $errors[] = "Full name is required.";
        if ($admin['username'] === '')  $errors[] = "Username is required.";
        if (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) $errors[] = "A valid email address is required.";
        if (strlen($password) < 8)      $errors[] = "Password must be at least 8 characters.";
        if ($password !== $confirm)     $errors[] = "Password and confirmation do not match.";

        if (!$errors) {
            try {
                $pdo = connect($_SESSION['install_db']);
                $stmt = $pdo->prepare("
                    INSERT INTO users (full_name, email, username, password_hash, role, is_active, must_reset_password)
                    VALUES (:full_name, :email, :username, :hash, 'admin', TRUE, FALSE)
                    RETURNING id
                ");
                $stmt->execute($admin + ['hash' => password_hash($password, PASSWORD_DEFAULT)]);
                $_SESSION['install_admin_id'] = (int)$stmt->fetchColumn();
                $_SESSION['install_admin_username'] = $admin['username'];
                $step = 4;
            } catch (PDOException $e) {
                $errors[] = str_contains($e->getMessage(), 'duplicate') || str_contains($e->getMessage(), 'unique')
                    ? "That username or email is already registered in this database."
                    : "The administrator account could not be created: " . $e->getMessage();
            }
        }
    }

    // ---- Step 4: hospital details and network address --------------------
    if ($step === 4 && isset($_POST['finish'])) {
        $details = [
            'hospital_name'   => trim($_POST['hospital_name'] ?? ''),
            'department_name' => trim($_POST['department_name'] ?? ''),
            'server_address'  => trim($_POST['server_address'] ?? ''),
        ];

        if ($details['hospital_name'] === '')  $errors[] = "Hospital name is required.";
        if ($details['server_address'] === '') $errors[] = "Network address is required.";
        if ($details['server_address'] !== '' && !preg_match('#^https?://#i', $details['server_address'])) {
            $errors[] = "Network address must start with http:// or https://";
        }

        if (!$errors) {
            try {
                $pdo = connect($_SESSION['install_db']);
                $now = sql_now();
                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
                    VALUES (:key, :value, :uid, $now)
                    ON CONFLICT (setting_key)
                    DO UPDATE SET setting_value = EXCLUDED.setting_value,
                                  updated_by = EXCLUDED.updated_by, updated_at = $now
                ");
                foreach ($details as $key => $value) {
                    if ($value === '') continue;
                    $stmt->execute(['key' => $key, 'value' => $value, 'uid' => $_SESSION['install_admin_id'] ?? null]);
                }

                $pdo->prepare("
                    INSERT INTO access_logs (user_id, action, ip_address, details)
                    VALUES (:uid, 'system_installed', :ip, 'Initial setup completed')
                ")->execute([
                    'uid' => $_SESSION['install_admin_id'] ?? null,
                    'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                // Writing the config file is what marks the system as installed,
                // so it happens last - after everything else has succeeded.
                $db = $_SESSION['install_db'];
                $settings_to_write = $db['db_driver'] === 'sqlite'
                    ? ['db_driver' => 'sqlite', 'db_path' => $db['db_path']]
                    : [
                        'db_driver'   => 'pgsql',
                        'db_host'     => $db['db_host'],
                        'db_port'     => $db['db_port'],
                        'db_name'     => $db['db_name'],
                        'db_user'     => $db['db_user'],
                        'db_password' => $db['db_password'],
                    ];

                if (!app_write_config($settings_to_write)) {
                    $_SESSION['install_manual_config'] = app_config_file_contents($settings_to_write);
                    $errors[] = "Setup finished, but includes/config.php could not be written. "
                              . "Create it by hand using the text shown below.";
                    $step = 5;
                } else {
                    $_SESSION['install_address'] = $details['server_address'];
                    $step = 5;
                }
            } catch (PDOException $e) {
                $errors[] = "Could not save the settings: " . $e->getMessage();
            }
        }
    }
}

// Steps 3 and 4 need the database details gathered in step 2.
if (in_array($step, [3, 4], true) && empty($_SESSION['install_db'])) {
    $step = 2;
    $errors[] = "Enter the database details first.";
}
if ($step === 1 && $requirements_met && ($_GET['step'] ?? '') !== '1') {
    // Landing on the page fresh - stay on step 1 so requirements get read.
}

$titles = [1 => 'Server Check', 2 => 'Database', 3 => 'Administrator', 4 => 'Hospital &amp; Network', 5 => 'Finished'];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Setup - Histopathology Records System</title>
<link rel="stylesheet" href="<?= h(asset_url('assets/app.css')) ?>">
<style>
    /* The wizard runs before any branding is configured, so the palette is fixed. */
    :root {
        --primary: #2c3e6f;
        --primary-hover: #273762;
        --primary-soft: #f2f3f6;
        --primary-line: #d1d5df;
        --primary-ring: #abb2c5;
        --accent: #5a2a83;
    }
    body { padding: 40px 20px 80px; }
    .wizard { max-width: 680px; margin: 0 auto; }
    .wizard__brand { margin-bottom: 26px; }
    .wizard__brand h1 { font-size: 1.4rem; }
    .wizard__brand p { margin: 4px 0 0; color: var(--text-muted); font-size: 0.9rem; }

    .steps { display: flex; gap: 6px; margin-bottom: 24px; flex-wrap: wrap; }
    .steps div {
        flex: 1; min-width: 96px; text-align: center;
        padding: 8px 6px; border-radius: var(--radius-sm);
        background: var(--surface); border: 1px solid var(--border);
        font-size: 0.72rem; font-weight: 500; text-transform: uppercase;
        letter-spacing: 0.04em; color: var(--text-subtle);
    }
    .steps div.active { background: var(--primary); border-color: var(--primary); color: #fff; }
    .steps div.done { background: var(--ok-bg); border-color: var(--ok-line); color: var(--ok-fg); }

    fieldset {
        border: 1px solid var(--border); border-radius: var(--radius);
        background: var(--surface); padding: 18px 20px; margin: 0 0 16px;
    }
    legend {
        font-size: 0.75rem; font-weight: 600; text-transform: uppercase;
        letter-spacing: 0.05em; color: var(--text-muted); padding: 0 7px;
    }
    h2 { margin-bottom: 6px; }
    h2 + p { margin-top: 0; color: var(--text-muted); font-size: 0.92rem; }
    label { display: block; font-size: 0.8rem; font-weight: 500;
            color: var(--text-muted); margin: 14px 0 5px; }
    fieldset > label:first-of-type { margin-top: 0; }

    .req-table { width: 100%; border-collapse: collapse; }
    .req-table td { padding: 11px 4px; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
    .req-table tr:last-child td { border-bottom: none; }
    .req-table .status { text-align: right; font-weight: 600; white-space: nowrap; }
    .yes { color: var(--ok-fg); }
    .no  { color: var(--stop-fg); }

    .address {
        font-family: var(--mono); font-size: 1rem;
        background: var(--primary-soft); border: 1px solid var(--primary-line);
        border-radius: var(--radius-sm); padding: 14px 16px; word-break: break-all;
    }
    pre {
        background: var(--surface-sunk); border: 1px solid var(--border);
        border-radius: var(--radius-sm); padding: 14px; overflow-x: auto; font-size: 0.82rem;
    }
    code { background: var(--surface-sunk); border: 1px solid var(--border);
           border-radius: 4px; padding: 1px 5px; font-family: var(--mono); font-size: 0.86em; }
</style>
</head>
<body>

<h1>Histopathology Records System</h1>
<p class="sub">First-time setup</p>

<div class="steps">
<?php foreach ($titles as $n => $label): ?>
    <div class="<?= $n === $step ? 'active' : ($n < $step ? 'done' : '') ?>"><?= $n ?>. <?= $label ?></div>
<?php endforeach; ?>
</div>

<?php if ($errors): ?>
    <div class="alert alert--error"><ul style="margin:0;"><?php foreach ($errors as $msg): ?><li><?= h($msg) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php foreach ($notices as $msg): ?>
    <div class="alert alert--ok"><?= h($msg) ?></div>
<?php endforeach; ?>

<?php if ($step === 1): ?>
    <h2>Server check</h2>
    <p>These need to be in place before the system can run.</p>
    <table class="req-table">
    <?php foreach ($requirements as $req): ?>
        <tr>
            <td><?= h($req['label']) ?></td>
            <td><?= h($req['found']) ?></td>
            <td class="status <?= $req['ok'] ? 'yes' : 'no' ?>"><?= $req['ok'] ? 'OK' : 'Missing' ?></td>
        </tr>
        <?php if (!$req['ok']): ?>
            <tr><td colspan="3" class="hint">Fix: <code><?= h($req['fix']) ?></code></td></tr>
        <?php endif; ?>
    <?php endforeach; ?>
    </table>

    <?php if ($requirements_met): ?>
        <form method="GET"><input type="hidden" name="step" value="2">
            <button class="btn btn--primary" type="submit">Continue</button>
        </form>
    <?php else: ?>
        <div class="alert alert--warn">Fix the items marked <strong>Missing</strong>, then reload this page.</div>
    <?php endif; ?>

<?php elseif ($step === 2): ?>
    <?php $chosen = ($_POST['db_driver'] ?? 'sqlite') === 'pgsql' ? 'pgsql' : 'sqlite'; ?>
    <h2>Database</h2>
    <p>Choose where the records are stored. If the tables don't exist yet they'll be
       created now; if they already exist, your data is left untouched.</p>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="2">

        <fieldset>
            <legend>Database type</legend>

            <label style="text-transform:none;font-size:1em;color:#222;">
                <input type="radio" name="db_driver" value="sqlite" style="width:auto;"
                       <?= $chosen === 'sqlite' ? 'checked' : '' ?>
                       <?= extension_loaded('pdo_sqlite') ? '' : 'disabled' ?>
                       onclick="showDb('sqlite')">
                <strong>SQLite</strong> &mdash; recommended for a single department
            </label>
            <div class="hint" style="margin-left:22px;">
                No database server to install or maintain. Everything lives in one file, so a
                backup is a file copy. Best for a site like this one: under ten people, one server.
                <?= extension_loaded('pdo_sqlite') ? '' : '<br><strong>Unavailable: pdo_sqlite is not installed.</strong>' ?>
            </div>

            <label style="text-transform:none;font-size:1em;color:#222;margin-top:14px;">
                <input type="radio" name="db_driver" value="pgsql" style="width:auto;"
                       <?= $chosen === 'pgsql' ? 'checked' : '' ?>
                       <?= extension_loaded('pdo_pgsql') ? '' : 'disabled' ?>
                       onclick="showDb('pgsql')">
                <strong>PostgreSQL</strong> &mdash; for a separate database server
            </label>
            <div class="hint" style="margin-left:22px;">
                Choose this if the database must run on a different machine from the web server,
                if many people will be saving reports at the same moment, or if your IT team
                already runs PostgreSQL backups.
                <?= extension_loaded('pdo_pgsql') ? '' : '<br><strong>Unavailable: pdo_pgsql is not installed.</strong>' ?>
            </div>
        </fieldset>

        <fieldset id="sqlite-fields" <?= $chosen === 'sqlite' ? '' : 'hidden' ?>>
            <legend>SQLite</legend>
            <label>Database file location</label>
            <input class="input" type="text" name="db_path" value="<?= h($_POST['db_path'] ?? suggested_sqlite_path()) ?>">
            <div class="hint">
                Must be <strong>outside</strong> the web folder, or a browser could download the
                entire patient database. The folder is created for you if it doesn't exist.
            </div>
        </fieldset>

        <fieldset id="pgsql-fields" <?= $chosen === 'pgsql' ? '' : 'hidden' ?>>
            <legend>PostgreSQL</legend>
            <label>Database server</label>
            <input class="input" type="text" name="db_host" value="<?= h($_POST['db_host'] ?? 'localhost') ?>">
            <div class="hint">Use <code>localhost</code> if PostgreSQL runs on this same machine.</div>

            <label>Port</label>
            <input class="input" type="number" name="db_port" value="<?= h($_POST['db_port'] ?? '5432') ?>">

            <label>Database name</label>
            <input class="input" type="text" name="db_name" value="<?= h($_POST['db_name'] ?? 'histopath_system') ?>">

            <label>Database username</label>
            <input class="input" type="text" name="db_user" value="<?= h($_POST['db_user'] ?? 'histopath_app') ?>">

            <label>Database password</label>
            <input class="input" type="password" name="db_password" value="<?= h($_POST['db_password'] ?? '') ?>">
        </fieldset>

        <button class="btn btn--primary" type="submit">Test connection &amp; continue</button>
    </form>

    <script>
    function showDb(which) {
        document.getElementById('sqlite-fields').hidden = (which !== 'sqlite');
        document.getElementById('pgsql-fields').hidden  = (which !== 'pgsql');
    }
    </script>

<?php elseif ($step === 3): ?>
    <h2>Administrator account</h2>
    <p>This is the account you'll use to add everyone else. Choose a password you'll
       remember &mdash; there is no "forgot password" email, so recovering it means
       editing the database directly.</p>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="3">
        <fieldset>
            <legend>Your details</legend>
            <label>Full name</label>
            <input class="input" type="text" name="full_name" value="<?= h($_POST['full_name'] ?? '') ?>" required>

            <label>Email address</label>
            <input class="input" type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>

            <label>Username</label>
            <input class="input" type="text" name="username" value="<?= h($_POST['username'] ?? 'admin') ?>" required>

            <label>Password</label>
            <input class="input" type="password" name="password" required minlength="8">
            <div class="hint">At least 8 characters.</div>

            <label>Confirm password</label>
            <input class="input" type="password" name="confirm_password" required minlength="8">
        </fieldset>
        <button class="btn btn--primary" type="submit" name="create_admin" value="1">Create administrator</button>
    </form>

<?php elseif ($step === 4): ?>
    <h2>Hospital details and network address</h2>
    <p>The name appears on every screen and printed report. The network address is what
       staff on other computers type into their browser to reach this system &mdash; it's
       shown on the final screen so you can pass it around.</p>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="step" value="4">
        <fieldset>
            <legend>Letterhead</legend>
            <label>Hospital name</label>
            <input class="input" type="text" name="hospital_name" value="<?= h($_POST['hospital_name'] ?? 'NATIONAL HOSPITAL ABUJA') ?>" required>

            <label>Department name</label>
            <input class="input" type="text" name="department_name" value="<?= h($_POST['department_name'] ?? 'DEPARTMENT OF HISTOPATHOLOGY') ?>">
        </fieldset>

        <fieldset>
            <legend>Network access</legend>
            <label>Address for other computers</label>
            <input class="input" type="text" name="server_address" value="<?= h($_POST['server_address'] ?? suggested_address()) ?>" required>
            <div class="hint">
                Detected from how you reached this page. If you opened it on the server itself it
                may say <code>localhost</code> &mdash; replace that with the server's fixed network
                address, for example <code>http://192.168.1.50/</code> or
                <code>http://histopath.intranet/</code>. Other machines cannot use
                <code>localhost</code>.
            </div>
        </fieldset>

        <button class="btn btn--primary" type="submit" name="finish" value="1">Finish setup</button>
    </form>

<?php elseif ($step === 5): ?>
    <h2>Setup complete</h2>

    <?php if (!empty($_SESSION['install_manual_config'])): ?>
        <div class="alert alert--warn">
            <p><strong>One manual step is needed.</strong> The web server could not write
               <code>includes/config.php</code>. Create that file on the server with exactly
               this content, then the system will start:</p>
            <pre><?= h($_SESSION['install_manual_config']) ?></pre>
        </div>
    <?php else: ?>
        <div class="alert alert--ok">The system is installed and ready to use.</div>
    <?php endif; ?>

    <p>Staff on the intranet reach the system at:</p>
    <div class="address"><?= h($_SESSION['install_address'] ?? suggested_address()) ?></div>

    <div class="alert alert--warn">
        <strong>Do these two things now:</strong>
        <ul>
            <li>Delete <code>install.php</code> from the server.</li>
            <li>Set up the nightly backup described in <code>DEPLOYMENT.md</code>, and test restoring it
                once, before real patient data goes in.</li>
        </ul>
    </div>

    <p>Then sign in as <strong><?= h($_SESSION['install_admin_username'] ?? 'your administrator account') ?></strong> and:</p>
    <ul>
        <li><strong>Manage Users</strong> &mdash; create the reviewer and staff accounts</li>
        <li><strong>System Settings</strong> &mdash; set colours and upload a logo</li>
        <li><strong>Import Legacy Records</strong> &mdash; bring in your existing Access data</li>
    </ul>

    <?php
    // The wizard is finished; don't leave the database password sitting in the session.
    unset($_SESSION['install_db'], $_SESSION['install_manual_config']);
    ?>
    <p><a href="<?= h(app_url('login.php')) ?>">Go to the login page &rarr;</a></p>
<?php endif; ?>

</body>
</html>
