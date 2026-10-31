<?php
// Run locally: php tools/hash_password.php  -> prints a bcrypt hash to paste into the owner INSERT (no SSH needed on the host).
echo 'New owner password (12+ chars): '; $p = trim((string)fgets(STDIN));
if (strlen($p) < 12) exit("Too short\n");
echo password_hash($p, PASSWORD_DEFAULT) . "\n";
