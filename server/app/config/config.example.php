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
  // Outbound email (Phase 6). Use the mailbox/SMTP details from your hosting control panel. Never commit real values.
  'mail' => ['transport' => 'none', 'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'from_email' => 'advisors@example.com', 'from_name' => 'Luxury Travel Institute', 'max_per_hour' => 100, 'timeout' => 20],
  // Only for hosts whose cron can call a URL: a random secret of at least 32 characters (leave empty to disable /api/cron/run).
  'cron_token' => '',
  'app_url' => 'https://example.com',    // used for password-reset links
];
