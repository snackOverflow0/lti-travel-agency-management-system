# Deployment checklist (do not start without written approval)

| # | Step | Done by | Date | Evidence |
|---|---|---|---|---|
| 1 | Written go-live approval received (UAT_SIGNOFF.md signed) | | | |
| 2 | Hosting compatibility verified (HOSTING_COMPATIBILITY_REPORT.md complete) | | | |
| 3 | Deployment window agreed; staff informed | | | |
| 4 | Existing website files backed up and downloaded | | | |
| 5 | Existing databases exported (if any) and backup opened to verify | | | |
| 6 | Release ZIP checksum matches MANIFEST.sha256 | | | |
| 7 | `public_html/lti/` and `lti_app/` uploaded; existing site files untouched | | | |
| 8 | `app_path.php` and `config.php` created; file permissions set | | | |
| 9 | Migrations 001–009 run; verification queries match | | | |
| 10 | Owner account created; no test accounts exist | | | |
| 11 | `/lti/api/health` returns `{"ok":true}` | | | |
| 12 | HTTPS confirmed; redirect + HSTS enabled; `require_https` true | | | |
| 13 | Private paths return 403/404 (`/lti/.htaccess`, `../lti_app/config/config.php`) | | | |
| 14 | SMTP configured and one test message received (or left disabled) | | | |
| 15 | Cron configured and `/api/jobs/status` shows a recent run (or documented as unavailable) | | | |
| 16 | Smoke tests completed (SMOKE_TEST_TEMPLATE.md) | | | |
| 17 | Error log reviewed; no sensitive data | | | |
| 18 | Go / no-go decision recorded; DNS untouched | | | |
