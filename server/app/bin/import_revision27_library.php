<?php
// Import the Revision 27 prototype library into MySQL. Safe to re-run (skips records already imported; never overwrites edits).
// Usage: php server/app/bin/import_revision27_library.php [--dry-run]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require dirname(__DIR__) . '/bootstrap.php';
$dry = in_array('--dry-run', $argv, true); $owner = LTI\Db::one("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.code='owner' AND u.is_active=1 ORDER BY u.id LIMIT 1");
echo json_encode(LTI\LibraryImport::run($dry, $owner ? (int)$owner['id'] : null), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
