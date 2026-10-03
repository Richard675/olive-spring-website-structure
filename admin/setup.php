<?php
/**
 * First-run setup: creates the first admin account.
 * Requires the one-time key written to storage/setup-key.txt, so nobody else can claim a fresh install.
 * After the first account exists this page is permanently disabled.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/lib/admin.php';

$user = admin_boot(true);
if ($user) redirect('index.php');
if (users_exist()) {
    flash('info', 'Setup is already complete. Please sign in.');
    redirect('login.php');
}

$keyFile = storage_path('setup-key.txt');
if (!is_file($keyFile)) {
    $key = implode('-', str_split(strtoupper(bin2hex(random_bytes(10))), 5));
    @file_put_contents($keyFile, $key . "\n", LOCK_EX);
    @chmod($keyFile, 0600);
}
$expected = trim((string)@file_get_contents($keyFile));

$errors = [];
$values = ['name' => '', 'email' => '', 'setup_key' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $values['name'] = trim((string)($_POST['name'] ?? ''));
    $values['email'] = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $sentKey = strtoupper(trim((string)($_POST['setup_key'] ?? '')));
    $values['setup_key'] = mb_substr($sentKey, 0, 40);

    if (!rate_limit('setup:' . client_ip_hash(), 10, 900)) {
        $errors['setup_key'] = 'Too many attempts. Please wait 15 minutes.';
    } elseif ($expected === '' || !hash_equals($expected, $sentKey)) {
        $errors['setup_key'] = 'That setup key is not correct. Copy it from storage/setup-key.txt on your server.';
    }
    if ($values['name'] === '' || mb_strlen($values['name']) > 80) $errors['name'] = 'Enter your name.';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if ($msg = validate_password($password)) $errors['password'] = $msg;
    elseif ($password !== (string)($_POST['password_confirm'] ?? '')) $errors['password_confirm'] = 'The passwords do not match.';

    if (!$errors) {
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
            q('INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)',
              [$values['name'], $values['email'], hash_password($password), now()]);
            $id = (int)$pdo->lastInsertId();
            $pdo->exec('COMMIT');
            @unlink($keyFile);
            login_user(['id' => $id]);
            flash('success', 'Your admin account is ready. New website submissions will appear here.');
            redirect('index.php');
        }
        $pdo->exec('ROLLBACK');
        redirect('login.php');
    }
}

$err = static fn (string $f) => isset($errors[$f]) ? '<span class="field-error" id="' . $f . '-error">' . e($errors[$f]) . '</span>' : '';
$aria = static fn (string $f) => isset($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . $f . '-error"' : '';
admin_header('Set up your dashboard');
?>
<section class="auth-card">
  <h1>Create the first admin account</h1>
  <p class="muted">This page works once. To prove you own this website, open <code>storage/setup-key.txt</code> in your hosting file manager (or over SSH) and paste the key below.</p>
  <?php if ($errors): ?><div class="notice notice-error" role="alert">Please fix the highlighted fields.</div><?php endif; ?>
  <form method="post" class="stack" novalidate>
    <?= csrf_field() ?>
    <label class="field"><span>Setup key</span>
      <input name="setup_key" value="<?= e($values['setup_key']) ?>" autocomplete="off" spellcheck="false" required<?= $aria('setup_key') ?>><?= $err('setup_key') ?></label>
    <label class="field"><span>Your name</span>
      <input name="name" value="<?= e($values['name']) ?>" autocomplete="name" required<?= $aria('name') ?>><?= $err('name') ?></label>
    <label class="field"><span>Email</span>
      <input type="email" name="email" value="<?= e($values['email']) ?>" autocomplete="username" required<?= $aria('email') ?>><?= $err('email') ?></label>
    <label class="field"><span>Password</span>
      <input type="password" name="password" autocomplete="new-password" minlength="12" required aria-describedby="pw-hint"<?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
      <span class="hint" id="pw-hint">At least 12 characters, mixing letters with numbers or symbols.</span><?= $err('password') ?></label>
    <label class="field"><span>Confirm password</span>
      <input type="password" name="password_confirm" autocomplete="new-password" required<?= $aria('password_confirm') ?>><?= $err('password_confirm') ?></label>
    <button class="btn" type="submit">Create account</button>
  </form>
</section>
<?php admin_footer();
