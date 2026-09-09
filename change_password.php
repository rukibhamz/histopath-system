<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

$forced = !empty($_SESSION['must_reset_password']);
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute(['id' => current_user_id()]);
    $user = $stmt->fetch();

    if (!$user) {
        // Account removed or disabled underneath us - don't keep the session alive.
        redirect('logout.php');
    }

    if (!$forced && !password_verify($current, $user['password_hash'])) {
        $error = "Current password is incorrect.";
    } elseif (strlen($new) < 8) {
        $error = "New password must be at least 8 characters.";
    } elseif ($new !== $confirm) {
        $error = "New password and confirmation do not match.";
    } elseif (password_verify($new, $user['password_hash'])) {
        $error = "New password must be different from your current password.";
    } else {
        $pdo->prepare("
            UPDATE users SET password_hash = :hash, must_reset_password = FALSE WHERE id = :id
        ")->execute([
            'hash' => password_hash($new, PASSWORD_DEFAULT),
            'id' => current_user_id(),
        ]);
        log_action($pdo, current_user_id(), 'change_password');

        // Fresh session ID after a credential change.
        session_regenerate_id(true);
        $_SESSION['must_reset_password'] = false;
        $forced = false;
        $success = true;
    }
}

render_header(['title' => 'Change password', 'bare' => true]);
?>

<div class="card">
    <div class="card__body">
        <h2 style="margin-bottom:4px;">Change password</h2>
        <p class="hint" style="margin:0 0 18px;">
            <?= e($_SESSION['full_name'] ?? '') ?>
        </p>

        <?php if ($forced && !$success): ?>
            <div class="alert alert--warn">
                You must set a new password before continuing.
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert--ok">Password updated.</div>
            <a class="btn btn--primary btn--block" href="<?= app_url('dashboard.php') ?>">Continue to dashboard</a>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert alert--error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <?= csrf_field() ?>
                <?php if (!$forced): ?>
                    <div class="field">
                        <label class="label" for="current_password">Current password</label>
                        <input class="input" type="password" id="current_password" name="current_password"
                               required autocomplete="current-password">
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label class="label" for="new_password">New password</label>
                    <input class="input" type="password" id="new_password" name="new_password"
                           required minlength="8" autocomplete="new-password">
                    <div class="hint">At least 8 characters.</div>
                </div>
                <div class="field">
                    <label class="label" for="confirm_password">Confirm new password</label>
                    <input class="input" type="password" id="confirm_password" name="confirm_password"
                           required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-actions">
                    <button class="btn btn--primary btn--block" type="submit">Update password</button>
                </div>
            </form>

            <?php if (!$forced): ?>
                <p class="hint" style="text-align:center;margin-top:14px;">
                    <a href="<?= app_url('dashboard.php') ?>">Back to dashboard</a>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php render_footer(['bare' => true]); ?>
