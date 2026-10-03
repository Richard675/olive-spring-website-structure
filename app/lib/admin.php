<?php
/** Shared helpers for the admin dashboard pages in /admin. */
declare(strict_types=1);

const PER_PAGE = 25;

/** Every admin page starts here: security headers, session, and (unless $public) a signed-in user. */
function admin_boot(bool $public = false): ?array
{
    send_security_headers(true);
    start_admin_session();
    return $public ? current_user() : require_login();
}

function users_exist(): bool
{
    return (int)q('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

/** Entity type from the query string, defaulting to care requests. */
function entity_type(?string $value): string
{
    return isset(ENTITY_TYPES[$value]) ? $value : 'inquiry';
}

/** Format a stored UTC timestamp in the office's time zone. */
function local_time(?string $utc, string $format = 'M j, Y g:i a'): string
{
    if (!$utc) return '';
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $dt->setTimezone(new DateTimeZone((string)config('timezone', 'America/New_York')))->format($format);
    } catch (Throwable) {
        return $utc;
    }
}

function status_badge(string $type, string $status): string
{
    $label = ENTITY_TYPES[$type]['statuses'][$status] ?? ucfirst($status);
    return '<span class="badge badge-' . e($status) . '">' . e($label) . '</span>';
}

/** WHERE clause for the inbox list and CSV export, shared so both always match. */
function inbox_filters(string $type, string $status, string $search): array
{
    $where = [];
    $params = [];
    if ($status !== '' && isset(ENTITY_TYPES[$type]['statuses'][$status])) {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $like = '%' . strtr($search, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $cols = $type === 'inquiry'
            ? ['first_name', 'last_name', 'email', 'phone', 'county', 'service', 'message']
            : ['first_name', 'last_name', 'email', 'phone', 'county', 'position', 'experience', 'message'];
        $parts = array_map(static fn ($c) => "$c LIKE ? ESCAPE '\\'", $cols);
        $parts[] = "(first_name || ' ' || last_name) LIKE ? ESCAPE '\\'";
        $where[] = '(' . implode(' OR ', $parts) . ')';
        array_push($params, ...array_fill(0, count($parts), $like));
    }
    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function query_string(array $params): string
{
    $params = array_filter($params, static fn ($v) => $v !== '' && $v !== null && $v !== 1);
    return $params ? '?' . http_build_query($params) : '';
}

function format_bytes(?int $bytes): string
{
    if (!$bytes) return '';
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
}

/** Delete a résumé file safely (only files that match the generated name pattern). */
function delete_resume(?string $stored): void
{
    if ($stored && preg_match('/^[a-f0-9]{32}\.(pdf|docx?)$/', $stored)) {
        @unlink(storage_path('uploads/' . $stored));
    }
}

// ---------------------------------------------------------------- Layout

function admin_header(string $title, ?array $user = null, string $active = ''): void
{
    $flash = flash();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . ' | Olive Sprigs Admin</title>'
        . '<link rel="icon" href="../assets/images/logo-mark.png" type="image/png">'
        . '<link rel="stylesheet" href="admin.css">'
        . '<script src="admin.js" defer></script></head><body>'
        . '<a class="skip" href="#main">Skip to content</a>'
        . '<header class="topbar"><div class="topbar-in">'
        . '<a class="brand" href="index.php"><img src="../assets/images/logo-mark.webp" alt="" width="40" height="39">'
        . '<span>Olive Sprigs <small>Admin</small></span></a>';
    if ($user) {
        $links = ['inquiry' => ['index.php', 'Care requests'], 'application' => ['index.php?type=application', 'Applications'], 'account' => ['account.php', 'Team &amp; account']];
        echo '<nav class="topnav" aria-label="Admin">';
        foreach ($links as $key => [$href, $label]) {
            echo '<a href="' . $href . '"' . ($active === $key ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        }
        echo '</nav><div class="whoami"><span title="' . e($user['email']) . '">' . e($user['name']) . '</span>'
            . '<form method="post" action="logout.php">' . csrf_field() . '<button class="link-button" type="submit">Sign out</button></form>'
            . '<a class="link-button" href="../index.html">View site</a></div>';
    }
    echo '</div></header><main id="main" class="wrap">';
    if ($flash) {
        echo '<div class="notice notice-' . e($flash['type']) . '" role="' . ($flash['type'] === 'error' ? 'alert' : 'status') . '">' . e($flash['text']) . '</div>';
    }
}

function admin_footer(): void
{
    echo '</main><footer class="foot wrap">Olive Sprigs Health Solutions · Admin dashboard · Dates shown in '
        . e((string)config('timezone')) . ' time</footer></body></html>';
}
