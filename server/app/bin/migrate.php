<?php
// Usage: php bin/migrate.php [--fresh]   (reads config via LTI_CONFIG or config/config.php)
// Without SSH, import migrations/*.sql in order through phpMyAdmin instead (see deployment guide).
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use LTI\Db;
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$pdo = Db::pdo();
if (in_array('--fresh', $argv, true)) {
    if (($GLOBALS['LTI_CFG']['env'] ?? '') === 'production') exit("Refusing --fresh in production\n");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    echo "Dropped all tables\n";
}
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$done = $pdo->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(dirname(__DIR__) . '/migrations/*.sql'); sort($files);
foreach ($files as $f) {
    $name = basename($f);
    if (in_array($name, $done, true)) continue;
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($f));
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) $pdo->exec($stmt);   // DDL auto-commits in MySQL; each file is idempotent (IF NOT EXISTS / INSERT IGNORE)
    $pdo->prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, UTC_TIMESTAMP())')->execute([$name]);
    echo "Applied $name\n";
}
echo "Migrations complete\n";
