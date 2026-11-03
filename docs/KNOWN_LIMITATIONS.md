# Known limitations

- Finance (quotes, invoices, payments, payables, commissions, reports) and operations (tasks, email, workflows, notifications) screens are prototype; their APIs are complete and tested.
- Dashboard figures, agency settings, roles editor, integrations, AI workspace, destination guides, task templates and the workflow library are prototype.
- Phone (PSTN) calling, browser calling, call recording and voicemail are unavailable; calls are made on the user's own phone and logged.
- Inbound email is not connected; outbound sending requires a verified SMTP mailbox.
- Unattended automation requires a production scheduler (cron), not yet verified.
- Phone numbers: full rules for US/Canada; other countries are checked conservatively.
- 3-decimal currencies are inactive; no card processing or payment gateway; manual payments are labelled unverified.
- Content-Security-Policy must allow inline scripts and styles for the approved frontend.
- Tested on PHP 8.3 and MariaDB 10.11 only; not yet on PHP 8.2/XAMPP, MySQL or the host.
