# Database migration instructions

**This is a new installation** — no previous LTI database exists in production, so no data is migrated. If any existing database is used, export it first.

1. Back up: export the target database (if it has any content) and keep the file off the server.
2. Create an empty database (utf8mb4) and a dedicated user in the control panel.
3. Run, in order: `001_core.sql`, `002_crm.sql`, `003_operations.sql`, `004_phase3.sql`, `005_phase4.sql`, `006_phase5.sql`, `007_phase6.sql`, `008_phase7.sql`, `009_phase8.sql` — through phpMyAdmin (Import, one file at a time) or `php lti_app/bin/migrate.php` with SSH.
4. Verify: 87 tables; 187 foreign keys; 8 roles; zero users before you create the owner:
   `SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE();`
   `SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE();`
   `SELECT code FROM roles;` and `SELECT COUNT(*) FROM users;`
5. Create the owner (`create_user.php` or hashed password via phpMyAdmin). **Never** run `seed_dev.php` (it is not in the release package).
6. Rollback: all migrations are additive. For a failed migration on a new install, drop the database and start again; never edit tables by hand.

Tested: the release's own `migrate.php` built the staging database from empty for every UAT suite run (87 tables each time).
