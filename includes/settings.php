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
    'server_address' => '',
];

/** All settings, DB values layered over the defaults. Queried once per request. */
function settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

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

/** The letterhead block used at the top of printed reports. */
function report_letterhead(string $report_title): string {
    $logo = setting('logo_path');
    $html = '<div class="header">';
    if ($logo !== '') {
        $html .= '<img src="' . htmlspecialchars($logo) . '" alt="" style="max-height:70px;margin-bottom:6px;">';
    }
    $html .= '<h2>' . htmlspecialchars(setting('hospital_name')) . '</h2>';
    $html .= '<h3>' . htmlspecialchars(setting('department_name')) . '</h3>';
    $html .= '<h3>' . htmlspecialchars($report_title) . '</h3>';
    $html .= '</div>';
    return $html;
}
