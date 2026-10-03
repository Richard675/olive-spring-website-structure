<?php
/** Sign out (POST only, with CSRF, so links and images cannot sign you out). */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require APP_ROOT . '/lib/admin.php';

admin_boot(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
verify_csrf();
logout_user();
start_admin_session();
flash('success', 'You have signed out.');
redirect('login.php');
