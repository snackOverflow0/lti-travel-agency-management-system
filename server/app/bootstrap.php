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
