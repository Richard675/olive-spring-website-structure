<?php
/** Admin sign-in. */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/lib/admin.php';

$user = admin_boot(true);
if (!users_exist()) redirect('setup.php');
if ($user) redirect('index.php');

$error = null;
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $ipBucket = 'login-ip:' . client_ip_hash();
    $emailBucket = 'login-email:' . hash('sha256', $email);

    if (!rate_limit($ipBucket, 20, 900) || !rate_limit($emailBucket, 6, 900)) {
        $error = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
    } else {
        $row = $email !== '' ? q('SELECT * FROM users WHERE email = ?', [$email])->fetch() : false;
        // Verify against a dummy hash when the account does not exist, so timing does not reveal valid emails.
        $hash = $row['password_hash'] ?? password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        if (password_verify($password, $hash) && $row) {
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($password), $row['id']]);
            }
            clear_rate_limit($emailBucket);
            $next = $_SESSION['after_login'] ?? 'index.php';
            unset($_SESSION['after_login']);
            login_user($row);
            redirect(safe_return_path($next) ? $next : 'index.php');
        }
        $error = 'That email and password do not match. Please try again.';
    }
}

admin_header('Sign in');
?>
<section class="auth-card">
  <h1>Sign in</h1>
  <p class="muted">Manage care requests and job applications from the website.</p>
  <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="stack" novalidate>
    <?= csrf_field() ?>
    <label class="field"><span>Email</span>
      <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></label>
    <label class="field"><span>Password</span>
      <input type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn" type="submit">Sign in</button>
  </form>
  <p class="muted small">Forgot your password? Ask another admin to reset it from Team &amp; account, or run <code>php app/cli.php reset-password you@example.com</code> on the server.</p>
</section>
<?php admin_footer();
