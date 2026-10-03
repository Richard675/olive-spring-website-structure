<?php
/**
 * Olive Sprigs Health Solutions — backend settings.
 *
 * Edit the values below, or set the matching environment variables on your host
 * (environment variables win). Never commit real passwords to a public repository.
 */
declare(strict_types=1);

$env = static fn (string $key, $default) => ($v = getenv($key)) !== false && $v !== '' ? $v : $default;

return [
    // Where the database and uploaded résumés live. Keep this folder private (see .htaccess).
    'storage_path' => $env('OS_STORAGE_PATH', dirname(__DIR__) . '/storage'),

    // Who receives notifications for new care requests and job applications.
    'notify_to'    => $env('OS_NOTIFY_TO', 'info@olivesprigshealthsolutions.com'),
    'careers_to'   => $env('OS_CAREERS_TO', 'info@olivesprigshealthsolutions.com'),

    // Sender address. Authorize it with your mail provider (SPF/DKIM).
    'mail_from'      => $env('OS_MAIL_FROM', 'website@olivesprigshealthsolutions.com'),
    'mail_from_name' => $env('OS_MAIL_FROM_NAME', 'Olive Sprigs Website'),

    // Mail driver: "mail" (PHP mail), "smtp" (recommended), or "log" (writes to storage/logs/mail.log for testing).
    'mail_driver' => $env('OS_MAIL_DRIVER', 'mail'),
    'smtp' => [
        'host'       => $env('OS_SMTP_HOST', ''),
        'port'       => (int)$env('OS_SMTP_PORT', 587),
        'encryption' => $env('OS_SMTP_ENCRYPTION', 'tls'), // "tls" (STARTTLS, port 587) or "ssl" (port 465)
        'username'   => $env('OS_SMTP_USERNAME', ''),
        'password'   => $env('OS_SMTP_PASSWORD', ''),
        'timeout'    => 15,
    ],

    // Send a short "we received your request" email to the visitor. Off by default so the
    // form cannot be used to send mail to arbitrary addresses; turn on once SMTP is set up.
    'send_confirmation' => filter_var($env('OS_SEND_CONFIRMATION', 'false'), FILTER_VALIDATE_BOOLEAN),

    // Résumé uploads
    'upload_max_bytes' => 5 * 1024 * 1024,

    // Admin sessions
    'session_idle_minutes' => 30,

    // Time zone used to show dates in the admin dashboard (stored in UTC).
    'timezone' => $env('OS_TIMEZONE', 'America/New_York'),

    // Public form limits: requests allowed per IP address within the window.
    'form_rate_limit'  => 5,
    'form_rate_window' => 600, // seconds

    // Delete submissions and résumés older than this many days when the cleanup runs (0 = never).
    'retention_days' => (int)$env('OS_RETENTION_DAYS', 0),

    // Site address used in notification emails (no trailing slash), e.g. https://www.olivesprigshealthsolutions.com
    'site_url' => $env('OS_SITE_URL', ''),
];
