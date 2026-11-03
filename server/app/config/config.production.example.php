<?php
// Production configuration template — copy to server/app/config/config.php ON THE SERVER ONLY. Never commit real values.
return [
  'env' => 'production',            // hides PHP errors from responses
  'require_https' => true,          // set only after HTTPS is confirmed working
  'error_log' => null,              // null = server/app/storage/logs/php-error.log (outside the web root)
  'app_url' => 'https://YOUR-DOMAIN/lti',
  'timezone' => 'UTC',              // storage time zone; the agency time zone is a setting in the database
  'db' => ['host' => 'HOST-FROM-CONTROL-PANEL', 'name' => 'DATABASE', 'user' => 'USER', 'pass' => 'PASSWORD'],
  'session' => ['idle_minutes' => 30, 'absolute_hours' => 12],
  'security' => ['login_max_failures' => 5, 'login_max_failures_per_ip' => 30, 'login_window_minutes' => 15, 'trust_proxy' => false],
  'storage_dir' => null,            // private documents folder; null = lti_app/storage/documents (must be outside the web root)
  'mail' => ['transport' => 'none', 'host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'from_email' => '', 'from_name' => 'Luxury Travel Institute', 'max_per_hour' => 100, 'timeout' => 20],
  'cron_token' => '',               // only for URL-based cron: 32+ random characters
];
