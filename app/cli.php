<?php
/**
 * Command-line tools (run on the server over SSH):
 *   php app/cli.php create-admin "Full Name" you@example.com   Create an admin (asks for the password)
 *   php app/cli.php reset-password you@example.com             Set a new password for an admin
 *   php app/cli.php cleanup                                    Delete records older than retention_days
 *   php app/cli.php test-email                                 Send a test email to notify_to
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require APP_ROOT . '/lib/admin.php';

function ask_password(): string
{
    $read = static function (string $prompt): string {
        fwrite(STDOUT, $prompt);
        if (DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) shell_exec('stty -echo');
        $value = rtrim((string)fgets(STDIN), "\r\n");
        if (DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN)) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
        return $value;
    };
    $pw = $read('Password: ');
    if ($msg = validate_password($pw)) fail($msg);
    if ($read('Confirm password: ') !== $pw) fail('The passwords do not match.');
    return $pw;
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$command = $argv[1] ?? '';
switch ($command) {
    case 'create-admin':
        [$name, $email] = [trim($argv[2] ?? ''), strtolower(trim($argv[3] ?? ''))];
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Usage: php app/cli.php create-admin "Full Name" you@example.com');
        if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetchColumn()) fail('An admin with that email already exists.');
        q('INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)', [$name, $email, hash_password(ask_password()), now()]);
        @unlink(storage_path('setup-key.txt'));
        echo "Admin created. Sign in at /admin/login.php\n";
        break;

    case 'reset-password':
        $email = strtolower(trim($argv[2] ?? ''));
        if (!q('SELECT 1 FROM users WHERE email = ?', [$email])->fetchColumn()) fail('No admin with that email.');
        q('UPDATE users SET password_hash = ? WHERE email = ?', [hash_password(ask_password()), $email]);
        clear_rate_limit('login-email:' . hash('sha256', $email));
        echo "Password updated.\n";
        break;

    case 'cleanup':
        $days = (int)config('retention_days', 0);
        if ($days <= 0) { echo "retention_days is 0, so nothing is deleted automatically.\n"; break; }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        foreach (ENTITY_TYPES as $type => $meta) {
            $ids = q("SELECT id FROM {$meta['table']} WHERE created_at < ?", [$cutoff])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                if ($type === 'application') delete_resume(q('SELECT resume_file FROM applications WHERE id = ?', [$id])->fetchColumn() ?: null);
                q("DELETE FROM {$meta['table']} WHERE id = ?", [$id]);
                q('DELETE FROM activity WHERE entity = ? AND entity_id = ?', [$type, $id]);
            }
            echo count($ids) . " old {$meta['plural']} deleted.\n";
        }
        db()->exec('VACUUM');
        break;

    case 'test-email':
        $ok = send_mail((string)config('notify_to'), 'Test email from your website', "If you can read this, website email is working.\n");
        echo $ok ? 'Sent to ' . config('notify_to') . " (driver: " . config('mail_driver') . ").\n" : "Failed. See storage/logs/app.log.\n";
        exit($ok ? 0 : 1);

    default:
        fwrite(STDOUT, "Commands: create-admin, reset-password, cleanup, test-email\n");
}
