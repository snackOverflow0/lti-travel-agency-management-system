
<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.production.example.php';

$config['env'] = 'production';
$config['require_https'] = true;
$config['app_url'] = getenv('RENDER_EXTERNAL_URL') ?: '';

$config['db'] = [
    'host' => getenv('DB_HOST') ?: '',
    'name' => getenv('DB_NAME') ?: '',
    'user' => getenv('DB_USER') ?: '',
    'pass' => getenv('DB_PASSWORD') ?: '',
];

return $config;
