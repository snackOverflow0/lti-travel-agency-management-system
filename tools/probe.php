<?php
/* LTI Phase 0 hosting capability probe.
   1) Change PROBE_TOKEN below. 2) Upload to public_html. 3) Open https://YOUR-DOMAIN/probe.php?token=YOUR_TOKEN
   4) Optionally enter MySQL credentials in the form to test the database. 5) DELETE THIS FILE immediately afterwards. */
const PROBE_TOKEN = 'CHANGE-THIS-TOKEN';
if (PROBE_TOKEN === 'CHANGE-THIS-TOKEN' || !hash_equals(PROBE_TOKEN, (string)($_GET['token'] ?? ''))) { http_response_code(404); exit; }
header("Content-Type: text/html; charset=utf-8"); echo "<pre>"; header('Cache-Control: no-store');
$r = [];
$r['php_version'] = PHP_VERSION; $r['sapi'] = PHP_SAPI; $r['os'] = PHP_OS;
foreach (['pdo_mysql','mysqli','mbstring','openssl','curl','fileinfo','gd','zip','intl','sodium','imap','json','session'] as $e) $r['ext_' . $e] = extension_loaded($e) ? 'yes' : 'NO';
foreach (['upload_max_filesize','post_max_size','memory_limit','max_execution_time','max_input_time','file_uploads','allow_url_fopen','session.save_path','disable_functions','open_basedir'] as $k) $r['ini_' . $k] = (string)ini_get($k);
$r['https'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'yes' : 'no (check certificate / forced redirect)';
$r['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
$r['mod_rewrite'] = function_exists('apache_get_modules') ? (in_array('mod_rewrite', apache_get_modules(), true) ? 'yes' : 'NO') : 'cannot detect from PHP (test with the .htaccess check in the deployment guide)';
$r['mail_function'] = function_exists('mail') && strpos((string)ini_get('disable_functions'), 'mail') === false ? 'available (delivery still unverified)' : 'NO';
$r['proc_exec'] = function_exists('proc_open') && strpos((string)ini_get('disable_functions'), 'proc_open') === false ? 'available' : 'disabled';
$home = dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
$r['document_root'] = $_SERVER['DOCUMENT_ROOT'] ?? '?'; $r['parent_of_docroot_writable'] = is_writable($home) ? 'yes (app can live outside public_html)' : 'NO (use the protected-folder fallback)';
$r['disk_free_bytes'] = @disk_free_space($home) ?: 'unavailable';
$r['session_save_path_writable'] = is_writable(session_save_path() ?: sys_get_temp_dir()) ? 'yes' : 'NO';
if (function_exists('curl_init')) { $ch = curl_init('https://www.example.com/'); curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 8, CURLOPT_RETURNTRANSFER => true]); curl_exec($ch); $r['outbound_https'] = curl_errno($ch) ? 'FAILED: ' . curl_error($ch) : 'ok (HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ')'; curl_close($ch); }
foreach ($r as $k => $v) echo str_pad($k, 34) . ' ' . $v . "\n";
echo "\nCron, SMTP mailbox limits, IMAP access, database quota, backups and SSH/SFTP cannot be detected from PHP: check the Network Solutions control panel and record them in PHASE0_HOSTING_VERIFICATION.md.\n";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $pdo = new PDO('mysql:host=' . $_POST['h'] . ';dbname=' . $_POST['d'] . ';charset=utf8mb4', $_POST['u'], $_POST['p'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "\nMySQL version: " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
    foreach (['max_connections','max_user_connections','innodb_file_per_table','character_set_server','max_allowed_packet'] as $v) { $x = $pdo->query("SHOW VARIABLES LIKE '$v'")->fetch(PDO::FETCH_NUM); echo str_pad($v, 34) . ' ' . ($x[1] ?? '?') . "\n"; }
    $pdo->exec('CREATE TEMPORARY TABLE lti_probe_a (id INT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'); echo "InnoDB + utf8mb4 temporary table   ok\n";
    $sz = $pdo->query('SELECT ROUND(SUM(data_length+index_length)/1048576,2) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn(); echo "Current database size (MB)         " . ($sz ?: '0') . "\n";
  } catch (Throwable $e) { echo "\nMySQL test FAILED: " . $e->getMessage() . "\n"; }
} else {
  echo "\nTo test MySQL, POST host (h), database (d), user (u), password (p) to this URL, e.g. with the small form below in a browser.\n";
  echo "</pre><form method=post><input name=h placeholder=host><input name=d placeholder=database><input name=u placeholder=user><input name=p type=password placeholder=password><button>Test MySQL</button></form>";
}
echo "\nREMINDER: delete probe.php now.\n";
