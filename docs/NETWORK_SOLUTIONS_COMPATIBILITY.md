# Network Solutions hosting compatibility

**Nothing on the actual plan has been verified** — I have never had access to the account. Run `tools/probe.php` (upload temporarily, open once, then delete) and confirm the items below in the control panel.

| Capability | Needed for | Status |
|---|---|---|
| PHP 7.4+ (8.1–8.3 preferred); `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `curl` | Everything | Unconfirmed — requires client access |
| MySQL 5.7+ / MariaDB 10.3+, InnoDB, utf8mb4 | Database | Unconfirmed |
| Database size and connection limits | Growth | Unconfirmed |
| Apache `.htaccess` with mod_rewrite and mod_headers | API routing, security headers | Unconfirmed (headers degrade gracefully if mod_headers is missing — then add them in the control panel) |
| Folder above the web root | Private code, config, documents | Unconfirmed; if unavailable, the included `Require all denied` files must be verified to block access |
| SSL certificate | HTTPS, secure cookies | Unconfirmed |
| Cron jobs (CLI) or URL cron | Emails, delayed workflows, reminders | Unconfirmed — scheduler BLOCKED |
| SMTP mailbox and sending limits | Email | Unconfirmed — sending BLOCKED |
| Upload limit (`upload_max_filesize`, `post_max_size`) ≥ 10 MB | Documents | Unconfirmed |
| `max_execution_time` ≥ 30 s | Worker, exports | Unconfirmed |
| Backups (frequency, restore process) | Recovery | Unconfirmed |
| Error log location | Diagnosis | Unconfirmed |
| Deployment access (SFTP/FTP, phpMyAdmin) | Deployment | Unconfirmed |

Nothing here requires a paid upgrade if the plan includes these features; if cron or SMTP are not included, the affected features stay unavailable rather than adding a paid service.
