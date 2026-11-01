# LTI Travel Agency Management System — full-stack (Phases 0–4, Phase 4 awaiting approval)

Internal software for Luxury Travel Institute. React + TypeScript + Vite frontend (static build) and a plain-PHP REST API on MySQL, designed for the client's existing Network Solutions shared hosting with **$0 additional cost**.

## What works today (verified by tests)
Staff sign-in/out, secure sessions, CSRF, login rate limiting, eight roles with server-side permissions, account deactivation, administrator-assisted password reset, audit log, and the full contacts vertical slice (create, list, search, paginate, view, edit with conflict detection, soft delete, advisor-level record scoping) persisted in MySQL. The complete database schema for later phases is migrated (48 tables, 72 foreign keys) but its API endpoints are not built yet. See `docs/FEATURE_PARITY_CHECKLIST.md`.

## Phase 1 frontend restoration
After sign-in, the React shell boots the complete Revision 27 interface (`web/public/prototype/lti-prototype.js`, unchanged code) and `web/src/prototype/adapter.ts` connects it to the API: real role, sign-out, live **Contacts** (database tab), live **Staff accounts**. Every other page shows a “Prototype module” label and runs on browser-local sample data until its backend phase.

## Phase 4 increment 1
Apply `server/app/migrations/005_phase4.sql` (additive). New APIs: groups, registrations, sub-trips and sync, suppliers, bookings, forms (with public submission), documents (private storage, protected downloads) and the content library. All Phase 4 restored screens are live: Group trips, Suppliers, Bookings, Forms (with a public link page), Documents and the Content library (activities, packing lists). XAMPP: make sure `server/app/storage/documents` is writable by Apache/PHP and check `upload_max_filesize` and `post_max_size` in `php.ini`. See `docs/PHASE4_FEATURE_PARITY.md`, `docs/TEST_RESULTS.md`, `docs/PHASE4_SCHEMA_AND_SECURITY.md`, `docs/GITHUB_PHASE4_INSTRUCTIONS.md`.

## Phase 3 (trips, travelers, itineraries, client portal)
Trips, trip travelers, itineraries and the traveler portal now use MySQL through the API. Apply migration `004_phase3.sql` (additive; no existing data changes). See `docs/PHASE3_COMPLETION_CHECKLIST.md`, `docs/API_PHASE3.md`, `docs/TEST_RESULTS.md`.

### Windows / XAMPP
1. Back up your database (phpMyAdmin › Export).
2. Apply the migration: phpMyAdmin › your database › Import › `server/app/migrations/004_phase3.sql`, or `C:\xampp\php\php.exe server\app\bin\migrate.php` (applies only files not yet recorded).
3. Run: `C:\xampp\php\php.exe -S 127.0.0.1:8000 -t server\public server\dev-router.php` (set `LTI_CONFIG` if your config is not `server\app\config\config.php`).
4. Optional sample data for testing only: `seed_dev.php` adds two sample trips and traveler logins `traveler@lti.test` / `traveler2@lti.test`. **Do not run it against a database with real records.**
5. Tests: create a separate test database and config from `server/app/config/config.local.example.php`; `run-tests.sh` (Git Bash/WSL) runs every suite; on plain Windows run the PHP suites with `php server\tests\api_phase3_test.php http://127.0.0.1:8000` after `migrate.php --fresh` and `seed_dev.php` on the **test** database. The browser suites need Node, Playwright and the `mysql` client on PATH (XAMPP: `C:\xampp\mysql\bin`).

## Run locally on 127.0.0.1:8000 (your PHP 8.2 setup)
The ZIP includes the built frontend in `server/public/`, so npm is not needed just to run it.
```
# keep your existing server/app/config/config.php (not included in the ZIP)
php -S 127.0.0.1:8000 -t server/public server/dev-router.php
```
If your config is elsewhere, set `LTI_CONFIG=/path/to/config.php` first. After changing anything in `web/`: `cd web && npm ci && npm run build && cp -r dist/* ../server/public/`.

## Layout
- `web/` React + TS + Vite. `npm run build` -> `web/dist` (static files, hash routing, no server rewrite needed for pages).
- `server/public/api/` the only PHP file that must be web-reachable (`index.php` + `.htaccess`).
- `server/app/` application code, config, migrations, storage. Deploy **outside** `public_html`.
- `server/tests/api_test.php` API integration tests; `tests/e2e/slice.spec.js` browser tests.
- `tools/probe.php` Phase 0 hosting probe; `tools/hash_password.php` offline password hashing.

## Local development
1. Install PHP 7.4+ (tested on 8.3) with pdo_mysql, mbstring; MySQL 5.7+/MariaDB 10.3+; Node 18+.
2. Create databases `lti_dev`/`lti_test` and user `lti` (see `server/app/config/config.dev.php`).
3. `cd web && npm ci && npm run build`
4. `./dev-up.sh` (migrates + seeds the test DB fresh, serves on http://127.0.0.1:8080). Dev logins: `owner@lti.test` etc., password `Dev-Password-2026` (development only; the seed refuses to run in production).
5. Tests: `php server/tests/api_test.php http://127.0.0.1:8080` and `node tests/e2e/slice.spec.js` (needs Playwright).

## Architecture compatibility with shared hosting
- No Node.js, Docker, Redis or long-running process in production: static files + PHP per request + MySQL.
- Sessions use PHP's native session store (files) — no extra service.
- Background work (automations, scheduled email) will run from `bin/cron.php` via the hosting cron **if** Network Solutions provides cron on this plan (unverified). Page-load execution will not be used as a silent substitute.
- Internet calling will use browser WebRTC with PHP/MySQL polling for signaling; without a TURN relay some networks will not connect — documented, not hidden.
