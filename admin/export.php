<?php
/** CSV export of the current inbox view (same filters as the dashboard). Opens cleanly in Excel and Google Sheets. */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/lib/admin.php';

$user = admin_boot();
$type = entity_type($_GET['type'] ?? null);
$meta = ENTITY_TYPES[$type];
$status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
[$where, $params] = inbox_filters($type, $status, $search);

$columns = $type === 'inquiry'
    ? ['id' => 'ID', 'created_at' => 'Received', 'status' => 'Status', 'first_name' => 'First name', 'last_name' => 'Last name',
       'email' => 'Email', 'phone' => 'Phone', 'county' => 'County', 'service' => 'Service', 'message' => 'Message', 'notes' => 'Internal notes']
    : ['id' => 'ID', 'created_at' => 'Received', 'status' => 'Status', 'first_name' => 'First name', 'last_name' => 'Last name',
       'email' => 'Email', 'phone' => 'Phone', 'position' => 'Position', 'county' => 'County', 'availability' => 'Availability',
       'experience' => 'Experience', 'message' => 'Message', 'resume_name' => 'Résumé file', 'notes' => 'Internal notes'];

/** Stop spreadsheet apps from treating submitted text as formulas (CSV injection). */
$cell = static function ($value): string {
    $value = (string)$value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
};

$slug = $type === 'inquiry' ? 'care-requests' : 'job-applications';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="olive-sprigs-' . $slug . '-' . local_time(now(), 'Y-m-d') . '.csv"');
app_log("CSV export of {$meta['plural']} by user #{$user['id']}");

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows accents correctly
fputcsv($out, array_values($columns), ',', '"', '');
$stmt = q("SELECT * FROM {$meta['table']} $where ORDER BY created_at DESC, id DESC", $params);
while ($r = $stmt->fetch()) {
    $line = [];
    foreach ($columns as $key => $_) {
        $line[] = match ($key) {
            'created_at' => local_time($r[$key], 'Y-m-d H:i'),
            'status' => $meta['statuses'][$r['status']] ?? $r['status'],
            default => $cell($r[$key] ?? ''),
        };
    }
    fputcsv($out, $line, ',', '"', '');
}
fclose($out);
