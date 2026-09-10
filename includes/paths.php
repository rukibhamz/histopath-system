<?php
/**
 * URL helpers, so the app works whether it lives at the web root
 * (/var/www/histopath as a vhost) or in a subdirectory (/histopath_system under XAMPP).
 */

/** The app's URL prefix, e.g. "" at the web root or "/histopath_system" in a subdirectory. */
function app_base(): string {
    static $base = null;
    if ($base !== null) return $base;

    $app_root = str_replace(DIRECTORY_SEPARATOR, '/', (string)realpath(dirname(__DIR__)));
    $doc_root = str_replace(DIRECTORY_SEPARATOR, '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));

    $base = '';
    if ($doc_root !== '' && $app_root !== '' && str_starts_with($app_root, $doc_root)) {
        $base = rtrim(substr($app_root, strlen($doc_root)), '/');
    }
    return $base;
}

/** Absolute URL path for an app-relative path: app_url('login.php') => "/histopath_system/login.php" */
function app_url(string $path): string {
    return app_base() . '/' . ltrim($path, '/');
}

/**
 * URL for a static file with its modification time appended, e.g.
 * /histopath_system/assets/app.css?v=1757502600. Browsers cache stylesheets
 * aggressively; a new version number whenever the file changes means an update
 * can never be paired with an old cached copy.
 */
function asset_url(string $path): string {
    $file = dirname(__DIR__) . '/' . ltrim($path, '/');
    $version = is_file($file) ? filemtime($file) : 0;
    return app_url($path) . '?v=' . $version;
}

/** Redirect to an app-relative path and stop. */
function redirect(string $path): never {
    header('Location: ' . app_url($path));
    exit;
}
