<?php
/** Stream a stored résumé to signed-in staff only. Files never live in a public folder. */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/lib/admin.php';

$user = admin_boot();
$id = (int)($_GET['id'] ?? 0);
$row = q('SELECT resume_file, resume_name, resume_mime FROM applications WHERE id = ?', [$id])->fetch();
$stored = (string)($row['resume_file'] ?? '');
$path = storage_path('uploads/' . $stored);

if (!preg_match('/^[a-f0-9]{32}\.(pdf|docx?)$/', $stored) || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}
$real = realpath($path);
$uploads = realpath(storage_path('uploads'));
if ($real === false || $uploads === false || !str_starts_with($real, $uploads . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('File not found.');
}

$allowed = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$mime = in_array($row['resume_mime'], $allowed, true) ? $row['resume_mime'] : 'application/octet-stream';
$name = (string)$row['resume_name'] ?: 'resume';
$ascii = preg_replace('/[^A-Za-z0-9 ._()-]/', '_', $name);

audit('application', $id, 'downloaded', 'Résumé downloaded');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header("Content-Security-Policy: default-src 'none'");
readfile($real);
