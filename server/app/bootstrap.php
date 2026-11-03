<?php
declare(strict_types=1);
// LTI API bootstrap. Compatible with PHP 7.4+ (no 8.x-only syntax).
define('LTI_APP', __DIR__);
spl_autoload_register(function ($c) {
    $p = LTI_APP . '/src/' . str_replace(['LTI\\', '\\'], ['', '/'], $c) . '.php';
    if (strpos($c, 'LTI\\') === 0 && is_file($p)) require $p;
});
$cfgFile = getenv('LTI_CONFIG') ?: LTI_APP . '/config/config.php';
if (!is_file($cfgFile)) { http_response_code(500); header('Content-Type: application/json'); echo '{"error":"Server not configured"}'; exit; }
$GLOBALS['LTI_CFG'] = require $cfgFile;
date_default_timezone_set($GLOBALS['LTI_CFG']['timezone'] ?? 'UTC');
// Production error handling: never display errors; log them privately. 'env' defaults to production.
if (($GLOBALS['LTI_CFG']['env'] ?? 'production') !== 'development') { ini_set('display_errors', '0'); ini_set('display_startup_errors', '0'); }
ini_set('log_errors', '1'); ini_set('error_log', $GLOBALS['LTI_CFG']['error_log'] ?? (LTI_APP . '/storage/logs/php-error.log'));
set_exception_handler(function (Throwable $e) { error_log('[LTI] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "Error: see the PHP error log\n"); exit(1); }
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); } echo '{"error":"Something went wrong. Please try again."}'; });
