<?php
// Copy to config.php OUTSIDE the public web root. Never commit config.php.
return [
  'env'      => 'production',            // 'development' shows error details
  'timezone' => 'America/New_York',
  'db' => ['host' => 'localhost', 'name' => 'CHANGE_ME', 'user' => 'CHANGE_ME', 'pass' => 'CHANGE_ME', 'port' => 3306],
  'session' => ['name' => 'LTISESS', 'idle_minutes' => 30, 'absolute_hours' => 12],
  'security' => ['login_max_failures' => 5, 'login_window_minutes' => 15, 'trust_proxy' => false],
  'storage_dir' => null,            // null = app/storage/documents (outside the web root)
  'upload_max_bytes' => 10485760,  // also limited by php.ini upload_max_filesize / post_max_size
  'app_url' => 'https://example.com',    // used for password-reset links
];
