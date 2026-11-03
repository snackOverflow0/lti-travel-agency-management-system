# Backup and disaster recovery

**What to back up:** the MySQL database; `storage/documents/` (private files); the application code (release ZIP); `config.php` (store in a password manager or encrypted vault, not with the other backups).

**How (shared hosting):** phpMyAdmin › Export › Custom › SQL with structure and data (or the host's backup tool); download `storage/documents` by SFTP or as a ZIP from the file manager. Locally: `tools/backup_restore_verify.sh` shows the exact `mysqldump` and `tar` commands.

**Suggested retention:** daily database export kept 14 days, weekly kept 8 weeks, monthly kept 12 months; documents weekly plus after large upload days. Store copies off the hosting account; restrict access to the owner and one administrator.

**Verified recovery (local):** `tools/backup_restore_verify.sh` dumped the test database (5,006 contacts, invoices, payments, audit events) and documents, restored them into an isolated database and folder, and confirmed: 87/87 tables identical by row count and `CHECKSUM TABLE`; 187 foreign keys present; 59/59 documents identical by SHA-256; sign-in works on the restored copy; API contact count equals the database; the financial audit hash chain verifies. Result: **RESTORE VERIFIED** (`test-output/backup_restore.log`). A restore on the production host has **not** been tested.

**Realistic objectives (to confirm with the host):** RPO — up to 24 h with daily exports (shorter only with more frequent exports); RTO — a few hours for a manual restore through phpMyAdmin and file upload. These are estimates, not guarantees.

**Restore steps:** put the app in maintenance (rename `public/index.html`); import the SQL export into a new empty database; restore documents; point `config.php` to the restored database; run the verification queries; sign in and check recent records; reopen.
