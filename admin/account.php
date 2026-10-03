<?php
/** Your password, plus the team members who can sign in. */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/lib/admin.php';

$user = admin_boot();
$errors = [];
$form = '';
$add = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $form = (string)($_POST['action'] ?? '');

    if ($form === 'password') {
        $hash = (string)q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetchColumn();
        $new = (string)($_POST['new_password'] ?? '');
        if (!rate_limit('pw:' . $user['id'], 10, 900)) $errors['current_password'] = 'Too many attempts. Please wait 15 minutes.';
        elseif (!password_verify((string)($_POST['current_password'] ?? ''), $hash)) $errors['current_password'] = 'Your current password is not correct.';
        if ($msg = validate_password($new)) $errors['new_password'] = $msg;
        elseif ($new !== (string)($_POST['new_password_confirm'] ?? '')) $errors['new_password_confirm'] = 'The passwords do not match.';
        if (!$errors) {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [hash_password($new), $user['id']]);
            session_regenerate_id(true);
            app_log("User #{$user['id']} changed their password");
            flash('success', 'Your password was changed.');
            redirect('account.php');
        }
    } elseif ($form === 'add') {
        $add['name'] = trim((string)($_POST['name'] ?? ''));
        $add['email'] = strtolower(trim((string)($_POST['email'] ?? '')));
        $pw = (string)($_POST['password'] ?? '');
        if ($add['name'] === '' || mb_strlen($add['name']) > 80) $errors['name'] = 'Enter their name.';
        if (!filter_var($add['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
        elseif (q('SELECT 1 FROM users WHERE email = ?', [$add['email']])->fetchColumn()) $errors['email'] = 'Someone with that email can already sign in.';
        if ($msg = validate_password($pw)) $errors['password'] = $msg;
        if (!$errors) {
            q('INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, ?)', [$add['name'], $add['email'], hash_password($pw), now()]);
            app_log("User #{$user['id']} added a team member (#" . db()->lastInsertId() . ')');
            flash('success', $add['name'] . ' can now sign in. Share their temporary password privately and ask them to change it.');
            redirect('account.php');
        }
    } elseif ($form === 'remove') {
        $target = (int)($_POST['user_id'] ?? 0);
        if ($target === (int)$user['id']) {
            flash('error', 'You cannot remove your own account.');
        } elseif ($name = q('SELECT name FROM users WHERE id = ?', [$target])->fetchColumn()) {
            q('DELETE FROM users WHERE id = ?', [$target]);
            app_log("User #{$user['id']} removed team member #$target");
            flash('success', "$name can no longer sign in.");
        }
        redirect('account.php');
    }
}

$team = q('SELECT id, name, email, created_at, last_login_at FROM users ORDER BY name COLLATE NOCASE')->fetchAll();
$err = static fn (string $f, string $which) => $form === $which && isset($errors[$f]) ? '<span class="field-error" id="' . $which . '-' . $f . '-error">' . e($errors[$f]) . '</span>' : '';
$aria = static fn (string $f, string $which) => $form === $which && isset($errors[$f]) ? ' aria-invalid="true" aria-describedby="' . $which . '-' . $f . '-error"' : '';
admin_header('Team & account', $user, 'account');
?>
<div class="page-head"><div><p class="eyebrow">Settings</p><h1>Team &amp; account</h1></div></div>

<div class="detail-grid">
  <section class="panel" aria-labelledby="team-h">
    <h2 id="team-h">People who can sign in</h2>
    <ul class="team" role="list">
      <?php foreach ($team as $t): ?>
        <li>
          <div><strong><?= e($t['name']) ?></strong><?= (int)$t['id'] === (int)$user['id'] ? ' <span class="tag">You</span>' : '' ?><br>
            <span class="muted small"><?= e($t['email']) ?> · <?= $t['last_login_at'] ? 'Last signed in ' . e(local_time($t['last_login_at'])) : 'Has not signed in yet' ?></span></div>
          <?php if ((int)$t['id'] !== (int)$user['id']): ?>
            <form method="post" data-confirm="Remove <?= e($t['name']) ?>? They will no longer be able to sign in.">
              <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>">
              <button class="link-button danger" type="submit">Remove<span class="sr-only"> <?= e($t['name']) ?></span></button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <h3>Add a team member</h3>
    <form method="post" class="stack" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <label class="field"><span>Name</span><input name="name" value="<?= e($add['name']) ?>" required<?= $aria('name', 'add') ?>><?= $err('name', 'add') ?></label>
      <label class="field"><span>Email</span><input type="email" name="email" value="<?= e($add['email']) ?>" required autocomplete="off"<?= $aria('email', 'add') ?>><?= $err('email', 'add') ?></label>
      <label class="field"><span>Temporary password</span><input type="password" name="password" required autocomplete="new-password"<?= $aria('password', 'add') ?>>
        <span class="hint">At least 12 characters. They can change it after signing in.</span><?= $err('password', 'add') ?></label>
      <button class="btn" type="submit">Add team member</button>
    </form>
  </section>

  <section class="panel" aria-labelledby="pw-h">
    <h2 id="pw-h">Change your password</h2>
    <form method="post" class="stack" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <input type="email" name="username" value="<?= e($user['email']) ?>" autocomplete="username" hidden>
      <label class="field"><span>Current password</span><input type="password" name="current_password" required autocomplete="current-password"<?= $aria('current_password', 'password') ?>><?= $err('current_password', 'password') ?></label>
      <label class="field"><span>New password</span><input type="password" name="new_password" required autocomplete="new-password"<?= $aria('new_password', 'password') ?>>
        <span class="hint">At least 12 characters, mixing letters with numbers or symbols.</span><?= $err('new_password', 'password') ?></label>
      <label class="field"><span>Confirm new password</span><input type="password" name="new_password_confirm" required autocomplete="new-password"<?= $aria('new_password_confirm', 'password') ?>><?= $err('new_password_confirm', 'password') ?></label>
      <button class="btn" type="submit">Change password</button>
    </form>
  </section>
</div>
<?php admin_footer();
