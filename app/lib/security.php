<?php
/** Sessions, authentication, CSRF, rate limiting and request checks. */
declare(strict_types=1);

/** Per-installation secret, generated on first use and kept in the private storage folder. */
function app_secret(): string
{
    static $secret = null;
    if ($secret !== null) return $secret;
    $file = storage_path('app.key');
    if (!is_file($file)) {
        @file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($file, 0600);
    }
    $secret = trim((string)@file_get_contents($file));
    if (strlen($secret) < 32) throw new RuntimeException('Could not create storage/app.key. Check folder permissions.');
    return $secret;
}

/** Visitors' IP addresses are never stored, only a keyed hash used for rate limiting. */
function client_ip_hash(): string
{
    return hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', app_secret());
}

/** Returns true when the action is allowed, and records the hit. */
function rate_limit(string $bucket, int $max, int $windowSeconds): bool
{
    $cutoff = time() - $windowSeconds;
    q('DELETE FROM rate_limits WHERE hit_at < ?', [time() - 86400]);
    $count = (int)q('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND hit_at >= ?', [$bucket, $cutoff])->fetchColumn();
    if ($count >= $max) return false;
    q('INSERT INTO rate_limits (bucket, hit_at) VALUES (?, ?)', [$bucket, time()]);
    return true;
}

function clear_rate_limit(string $bucket): void
{
    q('DELETE FROM rate_limits WHERE bucket = ?', [$bucket]);
}

/** Browsers send Origin on cross-site POSTs; reject any that are not this site. */
function same_origin_request(): bool
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') return true;
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    return strtolower((string)parse_url($origin, PHP_URL_HOST)) === $host;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function send_security_headers(bool $admin = false): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
    if ($admin) {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
    }
}

// ---------------------------------------------------------------- Sessions & auth

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('os_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();

    $idle = (int)config('session_idle_minutes', 30) * 60;
    if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $idle) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'] = ['type' => 'info', 'text' => 'You were signed out after a period of inactivity.'];
    }
    $_SESSION['last_seen'] = time();
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) return $user = null;
    $row = q('SELECT id, name, email, created_at, last_login_at FROM users WHERE id = ?', [$_SESSION['user_id']])->fetch();
    return $user = $row ?: null;
}

function require_login(): array
{
    start_admin_session();
    $user = current_user();
    if (!$user) {
        // Remember a deep link (e.g. from a notification email) so sign-in returns to it.
        $page = basename((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
        $query = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && safe_return_path($page . ($query !== '' ? "?$query" : ''))) {
            $_SESSION['after_login'] = $page . ($query !== '' ? "?$query" : '');
        }
        header('Location: login.php');
        exit;
    }
    return $user;
}

/** Only allow returning to known admin pages after sign-in (no open redirects). */
function safe_return_path(string $path): bool
{
    return (bool)preg_match('~^(index\.php(\?[\w=&%+.-]*)?|view\.php\?type=(inquiry|application)&id=\d+)$~', $path);
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    q('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
}

function validate_password(string $password): ?string
{
    if (strlen($password) < 12) return 'Use at least 12 characters.';
    if (strlen($password) > 200) return 'That password is too long.';
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9\W_]/', $password)) return 'Mix letters with numbers or symbols.';
    return null;
}

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_DEFAULT);
}

// ---------------------------------------------------------------- CSRF

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent) || !same_origin_request()) {
        http_response_code(400);
        exit('Your session expired or the request was not valid. Please go back, refresh the page, and try again.');
    }
}

function flash(?string $type = null, ?string $text = null): ?array
{
    if ($type !== null) {
        $_SESSION['flash'] = ['type' => $type, 'text' => $text];
        return null;
    }
    $msg = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $msg;
}
