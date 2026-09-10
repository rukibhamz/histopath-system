<?php
/**
 * System settings (hospital name, branding colors, logo), stored by admin/settings.php
 * and read by every page so appearance changes apply system-wide.
 */

const SETTING_DEFAULTS = [
    'hospital_name' => 'NATIONAL HOSPITAL ABUJA',
    'department_name' => 'DEPARTMENT OF HISTOPATHOLOGY',
    'primary_color' => '#2c3e6f',
    'accent_color'  => '#5a2a83',
    'logo_path'     => '',
    'report_contact' => 'Tel: 07067165091; 0700HISTOPATH',
    'server_address' => '',
];

/**
 * All settings, DB values layered over the defaults. Queried once per request;
 * pass true to re-read them, e.g. straight after saving new values.
 */
function settings(bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;

    global $pdo;
    $rows = [];
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        // Settings table missing or unreadable - fall back to defaults rather than
        // taking down every page in the system.
    }
    $rows = array_filter($rows, fn($v) => $v !== null && trim((string)$v) !== '');
    $cache = array_merge(SETTING_DEFAULTS, $rows);
    return $cache;
}

function setting(string $key): string {
    return (string)(settings()[$key] ?? SETTING_DEFAULTS[$key] ?? '');
}

/**
 * Browser URL for the configured logo, or '' when there is none or its file is missing
 * (so pages never show a broken image).
 *
 * Uploaded logos are stored app-relative, e.g. "assets/uploads/logo-3f9a.png". A path
 * typed with a leading slash, such as "/nhalogo.jpg", is looked up in the app folder
 * first - that is what it always meant - and only then from the web server's root.
 */
function logo_url(): string {
    $stored = trim(setting('logo_path'));
    if ($stored === '' || str_contains($stored, '..') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $stored)) {
        return '';
    }

    $relative = ltrim($stored, '/');
    if (is_file(dirname(__DIR__) . '/' . $relative)) {
        return asset_url($relative);   // versioned, so a replaced logo shows immediately
    }

    if ($stored[0] === '/') {
        $doc_root = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        if ($doc_root !== '' && is_file($doc_root . $stored)) {
            return $stored;
        }
    }
    return '';
}

/** A valid hex colour from settings, or the default if the stored value is unusable. */
function theme_color(string $key): string {
    $value = setting($key);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : SETTING_DEFAULTS[$key];
}

/** Mixes a hex colour towards white ($amount 0-1) and returns hex. */
function tint(string $hex, float $amount): string {
    [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    $mix = fn(int $c) => (int)round($c + (255 - $c) * $amount);
    return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
}

/** Mixes a hex colour towards black ($amount 0-1) and returns hex. */
function shade(string $hex, float $amount): string {
    [$r, $g, $b] = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    $mix = fn(int $c) => (int)round($c * (1 - $amount));
    return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
}

/**
 * Emits the branding colours as CSS custom properties, including the tints and
 * shades the stylesheet needs. Everything else lives in assets/app.css.
 */
function theme_css(): string {
    $primary = theme_color('primary_color');
    $accent  = theme_color('accent_color');

    return '<style>:root{'
         . "--primary:$primary;"
         . '--primary-hover:' . shade($primary, 0.12) . ';'
         . '--primary-soft:'  . tint($primary, 0.94) . ';'
         . '--primary-line:'  . tint($primary, 0.78) . ';'
         . '--primary-ring:'  . tint($primary, 0.60) . ';'
         . "--accent:$accent;"
         . '}</style>';
}

/**
 * The letterhead of the report form: logo on the left, hospital, department and
 * report title centred in the accent colour, and the department's contact line.
 * An empty column balances the logo so the titles stay centred with or without one.
 */
function report_letterhead(string $report_title): string {
    $esc = static fn(?string $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    $logo = logo_url();
    $contact = setting('report_contact');

    $html  = '<div class="rf__head">';
    $html .= '<div class="rf__logo">' . ($logo !== '' ? '<img src="' . $esc($logo) . '" alt="">' : '') . '</div>';
    $html .= '<div class="rf__titles">';
    $html .= '<div class="rf__org">' . $esc(setting('hospital_name')) . '</div>';
    $html .= '<div class="rf__dept">' . $esc(setting('department_name')) . '</div>';
    $html .= '<div class="rf__doc">' . $esc($report_title) . '</div>';
    if ($contact !== '') {
        $html .= '<div class="rf__contact">' . $esc($contact) . '</div>';
    }
    $html .= '</div>';
    $html .= '<div class="rf__balance" aria-hidden="true"></div>';
    $html .= '</div>';
    return $html;
}
