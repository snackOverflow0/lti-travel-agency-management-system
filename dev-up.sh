#!/bin/sh
# Starts local MariaDB + PHP built-in server on :8080 using the TEST database, rebuilt fresh.
set -e
cd "$(dirname "$0")"
pgrep mariadbd >/dev/null || pgrep mysqld >/dev/null || { (setsid nohup mysqld_safe --user=mysql --port=3306 --bind-address=127.0.0.1 </dev/null >/tmp/mysqld.log 2>&1 &); sleep 6; }
export LTI_CONFIG="$PWD/server/app/config/config.${LTI_ENV:-test}.php"
php server/app/bin/migrate.php --fresh >/dev/null && php server/app/bin/seed_dev.php >/dev/null
rm -rf server/public/assets server/public/index.html server/public/logo.png && cp -r web/dist/* server/public/
pkill -f "php -S 127.0.0.1:8080" 2>/dev/null || true
(LTI_CONFIG="$LTI_CONFIG" PHP_CLI_SERVER_WORKERS=4 setsid nohup php -S 127.0.0.1:8080 -t server/public server/dev-router.php </dev/null >/tmp/php-server.log 2>&1 &)
sleep 1; curl -s http://127.0.0.1:8080/api/health; echo
