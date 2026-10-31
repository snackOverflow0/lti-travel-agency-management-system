<?php
// Usage: php bin/create_user.php owner@company.com "Full Name" owner
// Prompts for a password (min 12 chars). For hosting without SSH use tools/hash_password.php locally and insert via phpMyAdmin.
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
use LTI\Db;
if (PHP_SAPI !== 'cli') exit("CLI only\n");
[$_, $email, $name, $role] = array_pad($argv, 4, null);
if (!$email || !$name || !$role) exit("Usage: php bin/create_user.php EMAIL \"FULL NAME\" ROLE\n");
$pw = getenv('LTI_NEW_PASSWORD') ?: (function () { echo 'Password: '; return trim((string)fgets(STDIN)); })();
if (strlen($pw) < 12) exit("Password must be at least 12 characters\n");
$r = Db::one('SELECT id FROM roles WHERE code=?', [$role]); if (!$r) exit("Unknown role $role\n");
Db::exec('INSERT INTO users (email, full_name, password_hash, role_id, is_active, created_at, updated_at) VALUES (?,?,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [strtolower($email), $name, password_hash($pw, PASSWORD_DEFAULT), $r['id']]);
echo "Created $role $email\n";
