# Deployment guide — Network Solutions shared hosting

Do **not** deploy over the live company website without written approval. Recommended: a subdomain (e.g. `lti.company.com`) or a new folder (e.g. `public_html/lti/`). Complete Phase 0 first.

## 1. Build locally
`cd web && npm ci && npm run build` → `web/dist/` (index.html, assets/, logo.png).

## 2. Database
1. Control panel → MySQL: create a database and a user with a strong password; note host/name/user.
2. phpMyAdmin → Import, in order: `server/app/migrations/001_core.sql`, `002_crm.sql`, `003_operations.sql`. (Each file is idempotent.)
3. Create the owner account without SSH: run `php tools/hash_password.php` locally, then in phpMyAdmin:
   `INSERT INTO users (email, full_name, password_hash, role_id, is_active, created_at, updated_at) SELECT 'owner@company.com','Owner Name','<HASH>', id, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM roles WHERE code='owner';`
   (With SSH: `php server/app/bin/migrate.php` and `php server/app/bin/create_user.php`.)

## 3. Files
| Upload | To |
|---|---|
| `server/app/` | a folder **outside** the web root, e.g. `/home/ACCOUNT/lti_app/` |
| `web/dist/*` | the site folder, e.g. `public_html/lti/` |
| `server/public/api/` | `public_html/lti/api/` |

- If `app/` cannot live outside the web root, place it at `public_html/lti/_app/` — its `.htaccess` (`Require all denied`) blocks web access; verify by opening `/lti/_app/bootstrap.php` (must be 403).
- Point the API at the app folder: edit the `$appDir` line in `api/index.php`, or add `SetEnv LTI_APP_DIR /home/ACCOUNT/lti_app` to `api/.htaccess` if SetEnv is allowed.
- Copy `config.example.php` to `lti_app/config/config.php`; fill in DB values, `app_url`, `'env' => 'production'`. Never place it under the web root.
- Never upload `config.dev.php`, `config.test.php`, `bin/seed_dev.php` outputs, or `node_modules`.

## 4. Checks
1. `https://…/lti/api/health` → `{"ok":true}` (if 404, mod_rewrite is off → see Phase 0 fallback).
2. Sign in as owner; create and reopen a contact.
3. Confirm the browser shows the session cookie as Secure + HttpOnly (requires HTTPS).

## 5. Backups and restore
- Before every deployment and at least weekly: phpMyAdmin → Export (SQL, all tables) and download; plus a copy of `lti_app/config/config.php` and `lti_app/storage/` stored securely off-server. Use host backups too if included (unverified).
- Restore: create an empty database, import the export, update `config.php`, upload the matching build.

## 6. Rollback
Keep the previous build in `public_html/lti_prev/` and the previous `lti_app` copy. To roll back: swap folders back; if a migration was applied, restore the pre-deployment database export (migrations are additive; never edit applied files).
