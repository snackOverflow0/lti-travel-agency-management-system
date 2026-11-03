# Production deployment guide — release 1.0.0-rc2 (DO NOT RUN WITHOUT WRITTEN APPROVAL)

**Package:** `lti-1.0.0-rc2.zip` from `tools/build_release.sh` (contains `public_html/lti`, `lti_app`, `tools`, `MANIFEST.sha256`). Verify checksums after upload: `sha256sum -c MANIFEST.sha256`.

**Order of migrations:** 001 → 011 in phpMyAdmin (all additive). Then, signed in as the owner: run the settings import and the library import (dry run first), as described in LIBRARY_MIGRATION_REPORT.md; both are optional and tag imported records.

**App location:** rename `public_html/lti/api/app_path.php.example` to `app_path.php` and set the absolute path of `lti_app` (outside the web root).

**Frontend updates later:** always rebuild with `tools/build_frontend.sh` / the release builder; never edit compiled JavaScript. The prototype script is versioned by content hash, so browsers refresh it automatically.

---
# Production deployment guide (Network Solutions) — DO NOT RUN WITHOUT APPROVAL

Release status: **NOT APPROVED** (see SCORECARD.md). This guide is for a rehearsal on a staging folder or for use after approval.

1. **Confirm hosting** — complete NETWORK_SOLUTIONS_COMPATIBILITY.md with the control panel and `tools/probe.php`.
2. **Confirm domain and document root** — e.g. web root `public_html/`, app at `public_html/lti/`. Do not touch existing site files or DNS.
3. **Back up** — export the existing database (if any) and download the current site files (BACKUP_AND_RECOVERY.md). Verify the backup opens.
4. **Build locally** — `cd web && npm ci && npm run build`; copy `web/dist/*` and `web/public/prototype/` into `server/public/`.
5. **Upload** — `server/public/*` (including `.htaccess` files) → `public_html/lti/`; `server/app/` → a folder **above** the web root (e.g. `/home/ACCOUNT/lti_app/`). If no such folder exists, upload inside but verify `https://DOMAIN/lti/../lti_app/config/config.php` and a document URL return 403.
6. **Point the API to the app folder** — edit the `require` path at the top of `public/api/index.php` if the app folder differs from `../../app`.
7. **Configure** — copy `config.production.example.php` to `config.php` on the server; fill database credentials; keep `require_https` false until step 9; set file permissions 640 for config, 750 for folders, 770 for `storage/`.
8. **Database** — create the database and user in the control panel; import migrations `001`→`009` in order with phpMyAdmin; run the verification queries below. Do **not** run `seed_dev.php`. Create the first owner account: with SSH, `php bin/create_user.php owner@agency.com "Full Name" owner` (prompts for a 12+ character password); without SSH, hash a password locally with `tools/hash_password.php` and insert the user through phpMyAdmin.
9. **HTTPS** — confirm the certificate; uncomment the redirect and HSTS lines in `public/.htaccess`; set `require_https => true`; ask the host to set `expose_php = Off`.
10. **Email** (if SMTP confirmed) — fill `mail`; send one test to an agency address; confirm it arrives.
11. **Scheduler** (if cron confirmed) — `php /home/ACCOUNT/lti_app/bin/worker.php --once` every 1–5 minutes; check `GET /api/jobs/status` shows a recent run.
12–19. **Smoke test with synthetic records** (UAT_CHECKLIST.md): sign-in and lockout; contact; trip, itinerary, booking; quote → invoice → payment on a clearly named test client, then void/reverse it; form and document upload/download; task and notification; call log and callback; staff and client permission checks.
20. **Logs** — check `storage/logs/php-error.log` is empty of new errors and is not web-accessible.
21. **Client approval** — sign off UAT before announcing the URL.

**Verification queries after migration:** `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();` (expect 87); `SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE();` (expect 187); `SELECT code FROM roles;` (8 roles); `SELECT COUNT(*) FROM users;` (only your owner account).
