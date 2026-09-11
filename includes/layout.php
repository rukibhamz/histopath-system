<?php
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/settings.php';

/**
 * Page shell: the <head>, the collapsible sidebar, and the closing markup.
 * Every screen goes through here so the chrome stays identical.
 *
 *   render_header(['title' => 'Records', 'nav' => 'records']);
 *   ... page content ...
 *   render_footer();
 */

/** Escape for HTML output. Used throughout the templates. */
function e(?string $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Lab number with its year, e.g. "2070 / 2026". */
function format_lab_no(?string $lab_no, mixed $year = null): string {
    $lab = trim((string)$lab_no);
    $y = (int)$year;
    return $y > 0 ? $lab . ' / ' . $y : $lab;
}

/** The signed-in user's role, read directly so the layout needs no auth.php. */
function layout_role(): string {
    return $_SESSION['role'] ?? '';
}

/**
 * Inline icons, so nothing has to be fetched over a network the server may not have.
 * Each is a 24x24 stroke path drawn at the size the stylesheet sets.
 */
function nav_icon(string $key): string {
    $paths = [
        'dashboard'     => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'records'       => '<path d="M4 4a2 2 0 0 1 2-2h8l6 6v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h5"/>',
        'new-histology' => '<path d="M12 5v14M5 12h14"/>',
        'new-cytology'  => '<path d="M12 5v14M5 12h14"/>',
        'pending'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'users'         => '<path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 20v-2a4 4 0 0 0-3-3.87"/>',
        'logs'          => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
        'import'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
        'export'        => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/>',
        'settings'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09A1.65 1.65 0 0 0 15 4.6a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'collapse'      => '<path d="M15 18l-6-6 6-6"/>',
        'password'      => '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'signout'       => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'menu'          => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    ];
    $d = $paths[$key] ?? $paths['records'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

/** The navigation items this role should see, in order. */
function nav_items(string $role): array {
    $records = ['key' => 'records', 'label' => 'Records', 'url' => 'records/list.php'];

    return match ($role) {
        'staff' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => 'dashboard.php'],
            $records,
            ['key' => 'new-histology', 'label' => 'New Histology', 'url' => 'records/histology_form.php'],
            ['key' => 'new-cytology',  'label' => 'New Cytology',  'url' => 'records/cytology_form.php'],
        ],
        'reviewer' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => 'dashboard.php'],
            ['key' => 'pending',   'label' => 'Awaiting Review', 'url' => 'records/list.php?status=pending'],
            $records,
            ['key' => 'export',    'label' => 'Export', 'url' => 'admin/export.php'],
        ],
        'admin' => [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'url' => 'dashboard.php'],
            ['key' => 'pending',   'label' => 'Awaiting Review', 'url' => 'records/list.php?status=pending'],
            $records,
            ['key' => 'users',     'label' => 'Users',    'url' => 'admin/users.php'],
            ['key' => 'logs',      'label' => 'Activity', 'url' => 'admin/logs.php'],
            ['key' => 'import',    'label' => 'Import',   'url' => 'admin/import.php'],
            ['key' => 'export',    'label' => 'Export',   'url' => 'admin/export.php'],
            ['key' => 'settings',  'label' => 'Settings', 'url' => 'admin/settings.php'],
        ],
        default => [],
    };
}

/** Initials for the sidebar avatar. */
function user_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_filter($parts);
    if (!$parts) return '?';
    $first = mb_substr(reset($parts), 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/**
 * Opens the page.
 *
 * Options:
 *   title   - the browser title and, unless 'heading' is given, the page heading
 *   heading - page heading, when it should differ from the title
 *   lead    - a sentence under the heading
 *   nav     - key of the navigation item to highlight
 *   back    - ['label' => ..., 'url' => ...] for a back link above the heading
 *   actions - HTML for buttons on the right of the heading
 *   narrow  - true for a reading-width column
 *   bare    - true for a centred card with no navigation (login, setup)
 *   head    - extra markup for <head>
 */
function render_header(array $options = []): void {
    $title   = $options['title'] ?? 'Histopathology Records';
    $bare    = !empty($options['bare']);
    $narrow  = !empty($options['narrow']);
    $logo    = logo_url();
    $org     = setting('hospital_name');
    $full_name = $_SESSION['full_name'] ?? '';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> &middot; <?= e($org) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('assets/app.css')) ?>">
<?= theme_css() ?>
<?= $options['head'] ?? '' ?>
</head>
<body<?= $bare ? ' class="centred"' : '' ?>>
<?php if (!$bare): ?>
<script>
    // Runs before the sidebar is parsed, so the remembered state never flashes.
    try {
        if (localStorage.getItem('navCollapsed') === '1') {
            document.body.classList.add('nav-collapsed');
        }
    } catch (e) {}
</script>
<?php endif; ?>

<?php if ($bare): ?>
    <div class="centred__card">
        <div class="centred__brand">
            <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?>
            <h1><?= e($org) ?></h1>
            <p><?= e(setting('department_name')) ?></p>
        </div>
<?php else: ?>
    <div class="backdrop" data-nav-close></div>

    <aside class="sidebar">
        <div class="sidebar__head">
            <span class="sidebar__logo<?= $logo !== '' ? ' sidebar__logo--image' : '' ?>">
                <?php if ($logo !== ''): ?>
                    <img src="<?= e($logo) ?>" alt="">
                <?php else: ?>
                    <?= e(user_initials($org)) ?>
                <?php endif; ?>
            </span>
            <div class="sidebar__titles">
                <div class="sidebar__org"><?= e($org) ?></div>
                <div class="sidebar__sub">Histopathology</div>
            </div>
        </div>

        <nav class="sidebar__nav">
            <?php foreach (nav_items(layout_role()) as $item): ?>
                <a class="sidebar__link <?= ($options['nav'] ?? '') === $item['key'] ? 'is-active' : '' ?>"
                   href="<?= app_url($item['url']) ?>"
                   data-label="<?= e($item['label']) ?>">
                    <?= nav_icon($item['key']) ?><span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <button class="sidebar__toggle" type="button" data-nav-toggle
                aria-label="Collapse or expand the navigation">
            <?= nav_icon('collapse') ?><span>Collapse</span>
        </button>

        <div class="sidebar__foot">
            <div class="sidebar__user">
                <span class="sidebar__avatar"><?= e(user_initials($full_name)) ?></span>
                <div class="sidebar__userinfo" style="min-width:0;">
                    <div class="sidebar__username"><?= e($full_name) ?></div>
                    <div class="sidebar__role"><?= e(layout_role()) ?></div>
                </div>
            </div>
            <div class="sidebar__actions">
                <a class="btn btn--sm btn--ghost" href="<?= app_url('change_password.php') ?>"
                   title="Change password"><?= nav_icon('password') ?><span>Password</span></a>
                <a class="btn btn--sm" href="<?= app_url('logout.php') ?>"
                   title="Sign out"><?= nav_icon('signout') ?><span>Sign out</span></a>
            </div>
        </div>
    </aside>

    <div class="shell">
        <div class="mobilebar">
            <button class="iconbtn" type="button" data-nav-open aria-label="Open navigation">
                <?= nav_icon('menu') ?>
            </button>
            <span class="mobilebar__title"><?= e($options['heading'] ?? $title) ?></span>
        </div>

        <main class="page<?= $narrow ? ' page--narrow' : '' ?>">
            <div class="print-head">
                <strong><?= e($org) ?></strong> &middot; <?= e(setting('department_name')) ?>
                <span><?= e($options['heading'] ?? $title) ?> &middot; printed <?= date('Y-m-d H:i') ?></span>
            </div>
            <?php if (!empty($options['back'])): ?>
                <a class="backlink" href="<?= app_url($options['back']['url']) ?>">&larr; <?= e($options['back']['label']) ?></a>
            <?php endif; ?>

            <?php if (!empty($options['heading']) || !empty($options['title'])): ?>
                <div class="page-head">
                    <div class="page-head__text">
                        <h1><?= e($options['heading'] ?? $title) ?></h1>
                        <?php if (!empty($options['lead'])): ?><p><?= $options['lead'] ?></p><?php endif; ?>
                    </div>
                    <?php if (!empty($options['actions'])): ?>
                        <div class="page-head__actions"><?= $options['actions'] ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
<?php endif; ?>
<?php
}

/** Closes the page opened by render_header(). */
function render_footer(array $options = []): void {
    if (!empty($options['bare'])) {
        echo "    </div>\n</body>\n</html>\n";
        return;
    }
    ?>
        </main>
    </div>

    <script>
    (function () {
        var body = document.body;

        var toggle = document.querySelector('[data-nav-toggle]');
        if (toggle) {
            toggle.addEventListener('click', function () {
                var collapsed = body.classList.toggle('nav-collapsed');
                try { localStorage.setItem('navCollapsed', collapsed ? '1' : '0'); } catch (e) {}
            });
        }

        // The drawer on small screens.
        var open = document.querySelector('[data-nav-open]');
        if (open) open.addEventListener('click', function () { body.classList.add('nav-open'); });

        var close = document.querySelector('[data-nav-close]');
        if (close) close.addEventListener('click', function () { body.classList.remove('nav-open'); });

        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') body.classList.remove('nav-open');
        });
    })();
    </script>
</body>
</html>
<?php
}

/** A status pill. */
function status_badge(string $status): string {
    $known = ['pending', 'approved', 'rejected'];
    $class = in_array($status, $known, true) ? "badge--$status" : 'badge--plain';
    return '<span class="badge ' . $class . '">' . e(ucfirst($status)) . '</span>';
}

/** A button that prints the current page. */
function print_button(string $label = 'Print'): string {
    return '<button class="btn btn--primary no-print" type="button" onclick="window.print()">'
         . e($label) . '</button>';
}

/** Renders a list of error messages, or nothing when there are none. */
function render_errors(array $errors, string $intro = 'Please correct the following:'): void {
    if (!$errors) return;
    echo '<div class="alert alert--error"><strong>' . e($intro) . '</strong><ul>';
    foreach ($errors as $message) {
        echo '<li>' . e($message) . '</li>';
    }
    echo '</ul></div>';
}
