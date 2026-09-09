<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_role(['admin']);

$message = '';
$error = '';

/** Temporary passwords are shown once to the admin, then must be changed on first login. */
function generate_temp_password(): string {
    return bin2hex(random_bytes(5));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Add new user
    if (isset($_POST['add_user'])) {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $role = $_POST['role'] ?? '';

        if ($full_name === '' || $email === '' || $username === '') {
            $error = "Name, email and username are all required.";
        } elseif (!in_array($role, ['admin', 'reviewer', 'staff'], true)) {
            $error = "Invalid role.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "That email address doesn't look valid.";
        } else {
            $temp_password = generate_temp_password();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO users (full_name, email, username, password_hash, role, is_active, must_reset_password)
                    VALUES (:full_name, :email, :username, :password_hash, :role, TRUE, TRUE)
                    RETURNING id
                ");
                $stmt->execute([
                    'full_name' => $full_name,
                    'email' => $email,
                    'username' => $username,
                    'password_hash' => password_hash($temp_password, PASSWORD_DEFAULT),
                    'role' => $role,
                ]);
                $new_id = (int)$stmt->fetchColumn();
                log_action($pdo, current_user_id(), 'create_user', 'users', $new_id, "Created user $username with role $role");
                $message = "User created. Temporary password: <strong>" . e($temp_password) . "</strong> "
                         . "(share securely &mdash; they'll be required to change it on first login).";
            } catch (PDOException $ex) {
                $error = "That username or email is already in use.";
                error_log('create_user failed: ' . $ex->getMessage());
            }
        }
    }

    // Change role
    if (isset($_POST['change_role'])) {
        $uid = (int)($_POST['user_id'] ?? 0);
        $new_role = $_POST['new_role'] ?? '';

        if (!in_array($new_role, ['admin', 'reviewer', 'staff'], true)) {
            $error = "Invalid role.";
        } elseif ($uid === current_user_id() && $new_role !== 'admin') {
            $error = "You can't remove your own admin role — ask another administrator.";
        } else {
            $pdo->prepare("UPDATE users SET role = :role WHERE id = :id")
                ->execute(['role' => $new_role, 'id' => $uid]);
            log_action($pdo, current_user_id(), 'change_role', 'users', $uid, "Role changed to $new_role");
            $message = "Role updated.";
        }
    }

    // Toggle active/inactive
    if (isset($_POST['toggle_active'])) {
        $uid = (int)($_POST['user_id'] ?? 0);

        if ($uid === current_user_id()) {
            $error = "You can't deactivate your own account.";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = :id RETURNING is_active");
            $stmt->execute(['id' => $uid]);
            $new_status = $stmt->fetchColumn();
            log_action($pdo, current_user_id(), $new_status ? 'reactivate_user' : 'deactivate_user', 'users', $uid);
            $message = $new_status ? "User reactivated." : "User deactivated.";
        }
    }

    // Admin-triggered password reset (for staff who are locked out and can't self-serve)
    if (isset($_POST['reset_password'])) {
        $uid = (int)($_POST['user_id'] ?? 0);
        $temp_password = generate_temp_password();
        $pdo->prepare("
            UPDATE users SET password_hash = :hash, must_reset_password = TRUE WHERE id = :id
        ")->execute([
            'hash' => password_hash($temp_password, PASSWORD_DEFAULT),
            'id' => $uid,
        ]);

        // Clear any lockout so they can actually use the new password immediately.
        $pdo->prepare("
            DELETE FROM login_attempts
            WHERE successful = FALSE
              AND username = (SELECT username FROM users WHERE id = :id)
        ")->execute(['id' => $uid]);

        log_action($pdo, current_user_id(), 'reset_password', 'users', $uid);
        $message = "Password reset. New temporary password: <strong>" . e($temp_password) . "</strong> "
                 . "(share securely &mdash; they'll be required to change it on next login).";
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY full_name")->fetchAll();

render_header([
    'title' => 'Users',
    'lead'  => 'Accounts are never deleted, only deactivated &mdash; this preserves the audit history attached to records they submitted or reviewed.',
    'nav'   => 'users',
]);
?>

<?php if ($message): ?><div class="alert alert--ok"><?= $message ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert--error"><?= e($error) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:18px;">
    <div class="card__head"><h2><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?></h2></div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr<?= $u['is_active'] ? '' : ' style="opacity:.55"' ?>>
                    <td>
                        <strong><?= e($u['full_name']) ?></strong>
                        <?php if ((int)$u['id'] === current_user_id()): ?>
                            <span class="badge badge--plain">you</span>
                        <?php endif; ?>
                    </td>
                    <td class="mono"><?= e($u['username']) ?></td>
                    <td class="muted"><?= e($u['email']) ?></td>
                    <td>
                        <form method="POST" style="display:flex;gap:6px;align-items:center;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <select class="select" name="new_role" style="width:auto;padding:5px 8px;font-size:0.82rem;">
                                <?php foreach (['admin','reviewer','staff'] as $r): ?>
                                    <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn--sm" type="submit" name="change_role">Save</button>
                        </form>
                    </td>
                    <td>
                        <span class="badge <?= $u['is_active'] ? 'badge--approved' : 'badge--rejected' ?>">
                            <?= $u['is_active'] ? 'Active' : 'Deactivated' ?>
                        </span>
                    </td>
                    <td class="muted nowrap"><?= e($u['last_login'] ?? 'Never') ?></td>
                    <td>
                        <div class="row-actions" style="justify-content:flex-end;">
                            <?php if ((int)$u['id'] !== current_user_id()): ?>
                            <form method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn--sm" type="submit" name="toggle_active">
                                    <?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" onsubmit="return confirm('Reset this user\'s password?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn--sm btn--ghost" type="submit" name="reset_password">Reset password</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card__head"><h2>Add an account</h2></div>
    <div class="card__body">
        <form method="POST">
            <?= csrf_field() ?>
            <div class="grid grid--2">
                <div class="field">
                    <label class="label" for="full_name">Full name <span class="req">*</span></label>
                    <input class="input" id="full_name" name="full_name" required>
                </div>
                <div class="field">
                    <label class="label" for="email">Email <span class="req">*</span></label>
                    <input class="input" type="email" id="email" name="email" required>
                </div>
                <div class="field">
                    <label class="label" for="username">Username <span class="req">*</span></label>
                    <input class="input" id="username" name="username" required>
                </div>
                <div class="field">
                    <label class="label" for="role">Role</label>
                    <select class="select" id="role" name="role">
                        <option value="staff">Staff &mdash; enters reports</option>
                        <option value="reviewer">Reviewer &mdash; approves reports</option>
                        <option value="admin">Admin &mdash; full access</option>
                    </select>
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn--primary" type="submit" name="add_user">Create account</button>
                <span class="hint">A temporary password is generated and shown once. The user must
                change it at first sign-in.</span>
            </div>
        </form>
    </div>
</div>

<?php render_footer(); ?>
