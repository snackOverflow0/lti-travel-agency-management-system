<?php
// Local development only (php -S). Production uses Apache + public/api/.htaccess.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/api/') === 0 || $path === '/api') { $_GET['r'] = substr($path, 4) ?: '/'; require __DIR__ . '/public/api/index.php'; return true; }
return false; // serve static files from public/
