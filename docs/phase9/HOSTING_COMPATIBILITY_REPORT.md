# Network Solutions hosting compatibility — Phase 9

**Status: BLOCKED — not verified.** I have no access to the hosting account. Nothing below about the actual plan is confirmed.

## What has been verified (on an Apache 2.4 + PHP 8.3 rehearsal server, not Network Solutions)
| Requirement | Result on the rehearsal server |
|---|---|
| Release package installs with code outside the web root (`lti_app/`) and `api/app_path.php` | Works |
| `.htaccess` API routing (mod_rewrite) | Works (`/lti/api/health` → `{"ok":true}`) |
| Security headers from `.htaccess` (mod_headers) | Present on app pages; API headers appear once (rc2 fix) |
| Dotfiles, backup files, directory listings | 403 |
| Path traversal to private config | 404 |
| Compression (mod_deflate) | Prototype script 882 KB → 280 KB transferred |
| `app_path.php` reveals nothing | Empty response |

## What I need from you to verify the real plan
1. Upload `tools/probe.php` to the web root, open it once in a browser, save the page as text, **delete the file**, and send me the text. It reports PHP version and extensions, upload/execution limits, writable paths, and whether a folder above the web root exists — no passwords.
2. From the control panel: plan name; MySQL/MariaDB version and database size limit; whether **cron jobs** are available (and whether they run PHP CLI or only URLs); whether an **SMTP mailbox** is included and its sending limit; whether **SSL** is active on the domain; how backups work; and how you upload files (file manager, FTP/SFTP).

| Capability | Required for | Status |
|---|---|---|
| PHP 8.1+ with pdo_mysql, mbstring, openssl, fileinfo, curl | Everything | Requires client access |
| MySQL 5.7+ / MariaDB 10.3+, InnoDB, utf8mb4 | Database | Requires client access |
| Apache with .htaccess (rewrite, headers) | API routing, headers | Requires client access |
| Folder above the web root | Private code, config, documents | Requires client access |
| SSL certificate | HTTPS, secure cookies | Requires client access |
| Cron (CLI or URL) | Emails, delayed workflows, reminders | Requires client access — blocks unattended automation if absent |
| SMTP mailbox | Email sending | Requires client access — sending stays off if absent |
| Upload ≥ 10 MB, execution ≥ 30 s | Documents, worker | Requires client access |
| Backups | Recovery | Requires client access |
| Paid upgrades | — | None proposed |
