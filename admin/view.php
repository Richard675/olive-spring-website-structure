<?php
/** One care request or job application: details, status, internal notes, activity history, delete. */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/lib/admin.php';

$user = admin_boot();
$type = entity_type($_GET['type'] ?? null);
$meta = ENTITY_TYPES[$type];
$id = (int)($_GET['id'] ?? 0);
$self = "view.php?type=$type&id=$id";
$back = 'index.php' . ($type === 'inquiry' ? '' : '?type=application');

$find = static fn () => q("SELECT * FROM {$meta['table']} WHERE id = ?", [$id])->fetch();
$row = $find();
if (!$row) {
    http_response_code(404);
    admin_header('Not found', $user, $type);
    echo '<div class="empty"><h1>That record was not found</h1><p class="muted">It may have been deleted.</p><a class="btn" href="' . e($back) . '">Back to ' . e(strtolower($meta['plural'])) . '</a></div>';
    admin_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'status') {
        $new = (string)($_POST['status'] ?? '');
        if (isset($meta['statuses'][$new]) && $new !== $row['status']) {
            q("UPDATE {$meta['table']} SET status = ?, updated_at = ? WHERE id = ?", [$new, now(), $id]);
            audit($type, $id, 'status', $meta['statuses'][$row['status']] . ' → ' . $meta['statuses'][$new]);
            flash('success', 'Status changed to ' . $meta['statuses'][$new] . '.');
        }
    } elseif ($action === 'notes') {
        $notes = mb_substr(trim(str_replace("\0", '', (string)($_POST['notes'] ?? ''))), 0, 10000);
        if ($notes !== $row['notes']) {
            q("UPDATE {$meta['table']} SET notes = ?, updated_at = ? WHERE id = ?", [$notes, now(), $id]);
            audit($type, $id, 'notes', 'Updated internal notes');
            flash('success', 'Notes saved.');
        }
    } elseif ($action === 'delete') {
        if (($_POST['confirm'] ?? '') !== 'yes') {
            flash('error', 'Tick the confirmation box to delete this record.');
        } else {
            if ($type === 'application') delete_resume($row['resume_file']);
            q("DELETE FROM {$meta['table']} WHERE id = ?", [$id]);
            q('DELETE FROM activity WHERE entity = ? AND entity_id = ?', [$type, $id]);
            app_log("{$meta['label']} #$id deleted by user #{$user['id']}");
            flash('success', $meta['label'] . ' #' . $id . ' was deleted.');
            redirect($back);
        }
    }
    redirect($self);
}

$activity = q('SELECT a.*, u.name AS user_name FROM activity a LEFT JOIN users u ON u.id = a.user_id
               WHERE a.entity = ? AND a.entity_id = ? ORDER BY a.id DESC', [$type, $id])->fetchAll();
$actionLabels = ['received' => 'Received', 'status' => 'Status changed', 'notes' => 'Notes updated', 'downloaded' => 'Résumé downloaded'];
$name = $row['first_name'] . ' ' . $row['last_name'];
$tel = preg_replace('/[^\d+]/', '', $row['phone']);
if (strlen($tel) === 10) $tel = '+1' . $tel;

$details = $type === 'inquiry'
    ? ['Service' => $row['service'] ?: 'Not sure yet', 'County' => $row['county'] ?: 'Not given']
    : ['Position' => $row['position'], 'County' => $row['county'] ?: 'Not given', 'Availability' => $row['availability'] ?: 'Not given'];
$details['Received'] = local_time($row['created_at']);
$details['Office email'] = $row['email_sent'] ? 'Sent' : 'Not sent (check email settings)';

admin_header("$name · {$meta['label']}", $user, $type);
?>
<p class="crumbs"><a href="<?= e($back) ?>">← <?= e($meta['plural']) ?></a></p>
<div class="page-head">
  <div><p class="eyebrow"><?= e($meta['label']) ?> #<?= $id ?></p><h1><?= e($name) ?></h1></div>
  <?= status_badge($type, $row['status']) ?>
</div>

<div class="detail-grid">
  <div class="stack-lg">
    <section class="panel" aria-labelledby="contact-h">
      <h2 id="contact-h">Contact</h2>
      <div class="contact-actions">
        <a class="btn" href="tel:<?= e($tel) ?>">Call <?= e($row['phone']) ?></a>
        <a class="btn btn-ghost" href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a>
      </div>
      <dl class="facts">
        <?php foreach ($details as $label => $value): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach; ?>
      </dl>
    </section>

    <?php if ($type === 'application'): ?>
      <section class="panel" aria-labelledby="resume-h">
        <h2 id="resume-h">Résumé</h2>
        <?php if ($row['resume_file']): ?>
          <p><a class="btn btn-ghost" href="file.php?id=<?= $id ?>">Download <?= e($row['resume_name']) ?></a>
            <span class="muted small"><?= e(format_bytes((int)$row['resume_size'])) ?></span></p>
          <p class="muted small">Résumés come from the public website. Open them with your usual, up-to-date document viewer.</p>
        <?php else: ?><p class="muted">No résumé was attached.</p><?php endif; ?>
      </section>
      <section class="panel" aria-labelledby="exp-h">
        <h2 id="exp-h">Experience</h2>
        <p class="prewrap"><?= $row['experience'] !== '' ? e($row['experience']) : '<span class="muted">Not provided.</span>' ?></p>
      </section>
    <?php endif; ?>

    <section class="panel" aria-labelledby="msg-h">
      <h2 id="msg-h">Message</h2>
      <p class="prewrap"><?= $row['message'] !== '' ? e($row['message']) : '<span class="muted">No message.</span>' ?></p>
    </section>
  </div>

  <div class="stack-lg">
    <section class="panel" aria-labelledby="status-h">
      <h2 id="status-h">Status</h2>
      <form method="post" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="status">
        <label class="field grow"><span class="sr-only">Status</span>
          <select name="status">
            <?php foreach ($meta['statuses'] as $key => $label): ?>
              <option value="<?= e($key) ?>"<?= $row['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select></label>
        <button class="btn" type="submit">Update</button>
      </form>
    </section>

    <section class="panel" aria-labelledby="notes-h">
      <h2 id="notes-h">Internal notes</h2>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="notes">
        <label class="field"><span class="sr-only">Internal notes</span>
          <textarea name="notes" rows="6" maxlength="10000" placeholder="Call outcomes, assessment dates, follow-ups… Only your team sees these."><?= e($row['notes']) ?></textarea></label>
        <button class="btn btn-ghost" type="submit">Save notes</button>
      </form>
    </section>

    <section class="panel" aria-labelledby="hist-h">
      <h2 id="hist-h">History</h2>
      <ol class="timeline">
        <?php foreach ($activity as $a): ?>
          <li><span><strong><?= e($actionLabels[$a['action']] ?? ucfirst($a['action'])) ?></strong><?= $a['detail'] !== '' && !in_array($a['action'], ['notes', 'downloaded'], true) ? ' · ' . e($a['detail']) : '' ?></span>
            <span class="muted small"><?= e(local_time($a['created_at'])) ?> · <?= e($a['user_name'] ?? ($a['user_id'] ? 'Former team member' : 'Website')) ?></span></li>
        <?php endforeach; ?>
      </ol>
    </section>

    <section class="panel panel-danger" aria-labelledby="del-h">
      <h2 id="del-h">Delete</h2>
      <form method="post" class="stack" data-confirm="Delete this <?= e(strtolower($meta['label'])) ?> permanently? This cannot be undone.">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <label class="check"><input type="checkbox" name="confirm" value="yes" required> I understand this permanently deletes <?= e($name) ?>’s record<?= $type === 'application' ? ' and résumé' : '' ?>.</label>
        <button class="btn btn-danger" type="submit">Delete permanently</button>
      </form>
    </section>
  </div>
</div>
<?php admin_footer();
