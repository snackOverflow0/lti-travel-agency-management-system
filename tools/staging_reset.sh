#!/bin/sh
# Resets the STAGING database (synthetic data only) using the release's own migrations, then seeds synthetic accounts.
set -e; ST=/srv/lti-staging; CFG=$ST/lti_app/config/config.php
mysql -uroot -e "DROP DATABASE IF EXISTS lti_staging; CREATE DATABASE lti_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON lti_staging.* TO 'lti'@'localhost';"
LTI_CONFIG=$CFG php $ST/lti_app/bin/migrate.php >/dev/null
LTI_CONFIG=$CFG php /home/claude/lti/server/app/bin/seed_dev.php >/dev/null
find $ST/lti_app/storage/documents -mindepth 1 ! -name .keep -exec rm -rf {} + 2>/dev/null || true
