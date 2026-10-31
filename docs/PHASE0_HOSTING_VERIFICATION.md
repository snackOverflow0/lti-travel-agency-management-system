# Phase 0 — Network Solutions capability verification

**Status: not yet verified.** No hosting access was provided in this session, so nothing below is confirmed. All development used local infrastructure. Do not purchase upgrades; record findings here.

| Capability | How to verify | Needed for | Fallback if missing | Result |
|---|---|---|---|---|
| PHP version & extensions | `tools/probe.php` | Entire API (needs pdo_mysql, mbstring, openssl, json, session; fileinfo for uploads) | Code targets PHP 7.4+; if older, select a newer PHP in the panel if offered at no cost | — |
| MySQL version | probe form / phpMyAdmin | Schema (needs InnoDB, utf8mb4, FKs; MySQL 5.7+/MariaDB 10.3+) | Adjust migrations for older versions | — |
| Databases available & storage quota | Control panel | All data, audit growth | Archive old audit rows | — |
| Max connections | probe form | Concurrent staff | Keep connections short (already per-request) | — |
| File storage allocation / file-count limits | Control panel, probe (disk free) | Documents | Restrict sizes/types; link out to existing company storage | — |
| Upload limits | probe (`upload_max_filesize`, `post_max_size`) | Documents | Lower client-side limits to match | — |
| App outside web root | probe (`parent_of_docroot_writable`) | Protect code/config | Put `app/` in a folder with `Require all denied` (.htaccess included) | — |
| `.htaccess` / mod_rewrite | Deployment guide check | `/api/*` routing | Use `api/index.php?r=` URLs | — |
| HTTPS certificate | Browse the domain | Secure cookies, login | **Required before go-live** | — |
| Cron jobs | Control panel | Automations, scheduled email, reminders | Free external scheduler after review; otherwise automations show “not running” — no silent page-load substitute | — |
| SMTP mailbox & sending limits | Control panel / email plan | Email, password-reset email | Admin-assisted reset (built); draft/outbox mode | — |
| IMAP | probe (`ext_imap`) + mailbox settings | Inbox sync | Manual logging | — |
| Outbound HTTPS | probe | Optional integrations | None needed for core features | — |
| Webhooks (inbound) | Any PHP endpoint works if HTTPS is available | Future integrations | — | — |
| Database backups | Control panel | Recovery | Manual phpMyAdmin exports (see deployment guide) | — |
| Deployment access (SFTP/FTP/File Manager/SSH) | Control panel | Uploading builds, running migrations | Without SSH: phpMyAdmin import + File Manager | — |

Procedure: set the token in `tools/probe.php`, upload, open with `?token=…`, run the MySQL test, copy the output into this table, **delete probe.php**.
