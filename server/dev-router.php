<?php
// Local development only (php -S). Production uses Apache with server/public/.htaccess and public/api/.htaccess.
// Static files are served here (instead of by php -S) so they carry the same security headers as production.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (strpos($path, '/api/') === 0 || $path === '/api') { $_GET['r'] = substr($path, 4) ?: '/'; require __DIR__ . '/public/api/index.php'; return true; }
$root = realpath(__DIR__ . '/public'); $file = realpath($root . ($path === '/' ? '/index.html' : $path));
if (!$file || strpos($file, $root) !== 0 || !is_file($file) || preg_match('#/\.|\.(sql|log|ini|env|bak|md|map|php)$#i', $file)) { http_response_code(404); echo 'Not found'; return true; }
$types = ['html' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'json' => 'application/json', 'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION)); header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header_remove('X-Powered-By'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY'); header('Referrer-Policy: strict-origin-when-cross-origin'); header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
header('Cache-Control: ' . (strpos($path, '/assets/') !== false ? 'public, max-age=31536000, immutable' : 'no-cache')); header('Content-Length: ' . filesize($file)); readfile($file); return true;
