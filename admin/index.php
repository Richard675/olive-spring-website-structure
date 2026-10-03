<?php
/** Dashboard: overview counts plus a searchable, filterable inbox of care requests or job applications. */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/lib/admin.php';

$user = admin_boot();
$type = entity_type($_GET['type'] ?? null);
$meta = ENTITY_TYPES[$type];
$status = is_string($_GET['status'] ?? null) && isset($meta['statuses'][$_GET['status']]) ? $_GET['status'] : '';
$search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$page = max(1, (int)($_GET['page'] ?? 1));

[$where, $params] = inbox_filters($type, $status, $search);
$total = (int)q("SELECT COUNT(*) FROM {$meta['table']} $where", $params)->fetchColumn();
$pages = max(1, (int)ceil($total / PER_PAGE));
$page = min($page, $pages);
$rows = q("SELECT * FROM {$meta['table']} $where ORDER BY created_at DESC, id DESC LIMIT " . PER_PAGE . ' OFFSET ' . (($page - 1) * PER_PAGE), $params)->fetchAll();

$since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
$stats = [
    ['New care requests', (int)q("SELECT COUNT(*) FROM inquiries WHERE status = 'new'")->fetchColumn(), 'index.php?status=new'],
    ['Care requests, last 30 days', (int)q('SELECT COUNT(*) FROM inquiries WHERE created_at >= ?', [$since])->fetchColumn(), 'index.php'],
    ['New applications', (int)q("SELECT COUNT(*) FROM applications WHERE status = 'new'")->fetchColumn(), 'index.php?type=application&status=new'],
    ['Applications, last 30 days', (int)q('SELECT COUNT(*) FROM applications WHERE created_at >= ?', [$since])->fetchColumn(), 'index.php?type=application'],
];
$counts = q("SELECT status, COUNT(*) AS n FROM {$meta['table']} GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$failedMail = (int)q("SELECT (SELECT COUNT(*) FROM inquiries WHERE email_sent = 0 AND created_at >= ?) + (SELECT COUNT(*) FROM applications WHERE email_sent = 0 AND created_at >= ?)", [$since, $since])->fetchColumn();

$base = ['type' => $type === 'inquiry' ? '' : $type, 'status' => $status, 'q' => $search];
admin_header($meta['plural'], $user, $type);
?>
<div class="page-head">
  <div><p class="eyebrow">Dashboard</p><h1><?= e($meta['plural']) ?></h1></div>
  <a class="btn btn-ghost" href="export.php<?= e(query_string(['type' => $type, 'status' => $status, 'q' => $search])) ?>">Download CSV</a>
</div>

<ul class="stats" role="list">
  <?php foreach ($stats as [$label, $n, $href]): ?>
    <li><a href="<?= e($href) ?>"><strong><?= $n ?></strong><span><?= e($label) ?></span></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($failedMail): ?>
  <div class="notice notice-info" role="status"><?= $failedMail ?> submission<?= $failedMail === 1 ? '' : 's' ?> from the last 30 days could not be emailed to the office. They are saved here; check your email settings in <code>app/config.php</code>.</div>
<?php endif; ?>

<form class="filters" method="get" role="search" aria-label="Filter <?= e(strtolower($meta['plural'])) ?>">
  <?php if ($type !== 'inquiry'): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
  <label class="field grow"><span>Search</span>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Name, email, phone, county…"></label>
  <label class="field"><span>Status</span>
    <select name="status" data-autosubmit>
      <option value="">All statuses (<?= array_sum($counts) ?>)</option>
      <?php foreach ($meta['statuses'] as $key => $label): ?>
        <option value="<?= e($key) ?>"<?= $status === $key ? ' selected' : '' ?>><?= e($label) ?> (<?= (int)($counts[$key] ?? 0) ?>)</option>
      <?php endforeach; ?>
    </select></label>
  <button class="btn" type="submit">Apply</button>
  <?php if ($status !== '' || $search !== ''): ?><a class="link-button" href="index.php<?= e(query_string(['type' => $type === 'inquiry' ? '' : $type])) ?>">Clear</a><?php endif; ?>
</form>

<?php if (!$rows): ?>
  <div class="empty">
    <h2><?= $status !== '' || $search !== '' ? 'Nothing matches those filters' : 'Nothing here yet' ?></h2>
    <p class="muted"><?= $status !== '' || $search !== '' ? 'Try a different search or status.' : 'New ' . e(strtolower($meta['plural'])) . ' from the website will appear here.' ?></p>
  </div>
<?php else: ?>
  <p class="muted small" aria-live="polite">Showing <?= ($page - 1) * PER_PAGE + 1 ?>–<?= min($total, $page * PER_PAGE) ?> of <?= $total ?></p>
  <div class="table-wrap">
    <table class="inbox">
      <thead><tr>
        <th scope="col">Name</th><th scope="col">Contact</th>
        <th scope="col"><?= $type === 'inquiry' ? 'Service' : 'Position' ?></th>
        <th scope="col">County</th><th scope="col">Status</th><th scope="col">Received</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr<?= $r['status'] === 'new' ? ' class="is-new"' : '' ?>>
          <td data-label="Name"><span><a class="row-link" href="view.php?type=<?= $type ?>&amp;id=<?= (int)$r['id'] ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a>
            <?php if ($type === 'application' && $r['resume_file']): ?><span class="tag">Résumé</span><?php endif; ?></span></td>
          <td data-label="Contact"><span><span class="nowrap"><?= e($r['phone']) ?></span><br><span class="muted small"><?= e($r['email']) ?></span></span></td>
          <td data-label="<?= $type === 'inquiry' ? 'Service' : 'Position' ?>"><?= e(($type === 'inquiry' ? $r['service'] : $r['position']) ?: 'Not sure yet') ?></td>
          <td data-label="County"><?= e($r['county'] ?: '—') ?></td>
          <td data-label="Status"><span><?= status_badge($type, $r['status']) ?></span></td>
          <td data-label="Received"><time datetime="<?= e(str_replace(' ', 'T', $r['created_at'])) ?>Z"><?= e(local_time($r['created_at'])) ?></time></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Pages">
      <?php if ($page > 1): ?><a class="btn btn-ghost" href="index.php<?= e(query_string($base + ['page' => $page - 1])) ?>" rel="prev">Previous</a><?php endif; ?>
      <span>Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn btn-ghost" href="index.php<?= e(query_string($base + ['page' => $page + 1])) ?>" rel="next">Next</a><?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
<?php admin_footer();
