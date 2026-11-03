# Production configuration guide (release 1.0.0-rc3)

1. Upload `public_html/lti/` into the web root and `lti_app/` **above** it (e.g. `/home/ACCOUNT/lti_app`).
2. Rename `public_html/lti/api/app_path.php.example` to `app_path.php` and set the absolute path of `lti_app`.
3. Copy `lti_app/config/config.production.example.php` to `lti_app/config/config.php` (never commit or email it) and set:
   - `db` — host, database, user, password from the control panel (a dedicated database user with rights on this database only);
   - `app_url` — `https://DOMAIN/lti`;
   - `env` => `production` (keeps errors out of responses);
   - `require_https` => `true` **only after** HTTPS works;
   - `storage_dir` — leave `null` (uses `lti_app/storage/documents`) or set another folder outside the web root;
   - `mail` — leave `transport` => `none` until an SMTP mailbox is confirmed; then fill host, port, encryption, username, password, from address, hourly limit;
   - `cron_token` — only if the host's cron can only call URLs (32+ random characters).
4. Permissions: `config.php` 640; folders 750; `lti_app/storage/` writable by the web server (750/770 as the host requires).
5. After HTTPS is confirmed: uncomment the redirect and HSTS lines in `public_html/lti/.htaccess`; ask the host for `expose_php = Off` and minimal `ServerTokens` if configurable.
6. Health check: `https://DOMAIN/lti/api/health` → `{"ok":true}`.
