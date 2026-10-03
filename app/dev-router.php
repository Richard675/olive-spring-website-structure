<?php
/**
 * Local development server that mimics the production .htaccess rules.
 * From the website folder run:  php -d upload_max_filesize=6M -S localhost:8000 app/dev-router.php
 * Then open http://localhost:8000/ (site) and http://localhost:8000/admin/ (dashboard).
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('~(^|/)(app|storage)(/|$)|/\.|\.(sqlite|log|key|txt|md|py)$~i', $path) && $path !== '/robots.txt') {
    http_response_code(404);
    readfile(dirname(__DIR__) . '/404.html');
    return true;
}
if ($path === '/admin' || $path === '/admin/') {
    header('Location: /admin/index.php');
    return true;
}
if (!is_file(dirname(__DIR__) . $path) && !is_dir(dirname(__DIR__) . $path)) {
    http_response_code(404);
    readfile(dirname(__DIR__) . '/404.html');
    return true;
}
return false;
