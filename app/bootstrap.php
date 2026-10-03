<?php
/**
 * Shared backend bootstrap: settings, database, security helpers.
 * Every public endpoint and admin page starts with: require __DIR__ . '/../app/bootstrap.php';
 */
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Olive Sprigs backend requires PHP 8.1 or newer.');
}

const APP_ROOT = __DIR__;
$GLOBALS['config'] = require APP_ROOT . '/config.php';

require APP_ROOT . '/lib/data.php';
require APP_ROOT . '/lib/db.php';
require APP_ROOT . '/lib/security.php';
require APP_ROOT . '/lib/mailer.php';

function config(string $key, $default = null)
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

function storage_path(string $path = ''): string
{
    $base = rtrim((string)config('storage_path'), '/');
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

/** HTML-escape for output. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** Write a line to storage/logs/app.log (never includes form contents). */
function app_log(string $message): void
{
    @file_put_contents(storage_path('logs/app.log'), '[' . now() . " UTC] $message\n", FILE_APPEND | LOCK_EX);
}

// Make sure private folders exist and are not web-readable on Apache.
foreach (['', 'uploads', 'logs'] as $dir) {
    $path = storage_path($dir);
    if (!is_dir($path)) @mkdir($path, 0750, true);
}
if (!is_file(storage_path('.htaccess'))) {
    @file_put_contents(storage_path('.htaccess'), "Require all denied\nDeny from all\n");
}

set_exception_handler(static function (Throwable $e): void {
    app_log('Unhandled ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Something went wrong on our side. Please call 336-329-2695.']);
    } else {
        echo 'Something went wrong on our side. Please call 336-329-2695.';
    }
});
