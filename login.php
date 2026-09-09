<?php
require_once __DIR__ . '/includes/paths.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/log_action.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/layout.php';

// Already signed in? Nothing to do here.
if (!empty($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

const LOGIN_MAX_ATTEMPTS = 5;          // failures before a lockout kicks in
const LOGIN_LOCKOUT_MINUTES = 15;      // how long the lockout lasts

$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // How many recent failures from this username/IP pair?
    // The window is a trusted integer constant, so it's built into the SQL rather
    // than bound - neither engine can infer a parameter's type inside an interval.
    $window = sql_ago(LOGIN_LOCKOUT_MINUTES . ' minutes');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE username = :username
          AND ip_address = :ip
          AND successful = FALSE
          AND attempted_at > $window
    ");
    $stmt->execute(['username' => $username, 'ip' => $ip]);
    $recent_failures = (int)$stmt->fetchColumn();

    if ($recent_failures >= LOGIN_MAX_ATTEMPTS) {
        $error = "Too many failed attempts. Try again in " . LOGIN_LOCKOUT_MINUTES . " minutes, "
               . "or ask an administrator to reset your password.";
        log_action($pdo, null, 'login_blocked', null, null, "Locked out: $username");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        $record_attempt = $pdo->prepare("
            INSERT INTO login_attempts (username, ip_address, successful)
            VALUES (:username, :ip, :successful)
        ");

        if (!$user || !password_verify($password, $user['password_hash'])) {
            // Same message either way, so this can't be used to enumerate usernames.
            $error = "Invalid username or password.";
            $record_attempt->execute(['username' => $username, 'ip' => $ip, 'successful' => false]);
        } elseif (!$user['is_active']) {
            $error = "This account has been deactivated. Contact your administrator.";
            $record_attempt->execute(['username' => $username, 'ip' => $ip, 'successful' => false]);
            log_action($pdo, $user['id'], 'login_denied_inactive');
        } else {
            // New session ID on privilege change - defeats session fixation.
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['must_reset_password'] = $user['must_reset_password'];

            $record_attempt->execute(['username' => $username, 'ip' => $ip, 'successful' => true]);

            // Clear this user's failure history so the lockout doesn't linger.
            $pdo->prepare("DELETE FROM login_attempts WHERE username = :username AND successful = FALSE")
                ->execute(['username' => $username]);

            $pdo->prepare("UPDATE users SET last_login = " . sql_now() . " WHERE id = :id")
                ->execute(['id' => $user['id']]);

            log_action($pdo, $user['id'], 'login');

            redirect('dashboard.php');
        }
    }
}

render_header(['title' => 'Sign in', 'bare' => true]);
?>

<div class="card">
    <div class="card__body">
        <h2 style="margin-bottom:4px;">Sign in</h2>
        <p class="hint" style="margin:0 0 18px;">Histopathology Records System</p>

        <?php if ($error): ?>
            <div class="alert alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="field">
                <label class="label" for="username">Username</label>
                <input class="input" id="username" name="username" required autofocus
                       autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
            </div>
            <div class="field">
                <label class="label" for="password">Password</label>
                <input class="input" type="password" id="password" name="password" required
                       autocomplete="current-password">
            </div>
            <button class="btn btn--primary btn--block" type="submit">Sign in</button>
        </form>
    </div>
</div>

<p class="hint" style="text-align:center;margin-top:16px;">
    Forgotten your password? Ask an administrator to reset it.
</p>

<?php render_footer(['bare' => true]); ?>
