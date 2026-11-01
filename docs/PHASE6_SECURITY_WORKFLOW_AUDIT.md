# Phase 6 security and workflow audit

- **Permissions (new):** tasks.manage (all staff), tasks.view_all (owner, admin, supervisor, operations), email.send (owner, admin, supervisor, advisor, sales, operations), email.templates and workflows.manage (owner, admin, supervisor), jobs.view (owner, admin). Enforced server-side; tested for advisor, finance, sales and traveler.
- **Record visibility:** tasks show linked contacts/trips/bookings/invoices only to users who may see them ("Restricted record" otherwise); activity timelines require access to the record; notifications are per user; emails follow contact/trip scope.
- **Workflow safety:** triggers, condition fields, operators and actions come from allowlists; no SQL, code or expressions are executed; status changes are limited to trip status and contact lead status; financial records cannot be targeted (tested: finance-triggered workflows ran and totals were unchanged).
- **Idempotency:** executions are unique per workflow + event (or record + date); tasks, notifications, emails and follow-ups created by a run carry dedupe keys, so retries do not duplicate them; emails are sent at most once and unclear outcomes become Unknown.
- **Queue:** atomic claim with lease (MySQL 5.7+/MariaDB, no SKIP LOCKED needed); expired leases are recovered (idempotent jobs re-run; external sends flagged); bounded retries with exponential back-off; failures flagged for review; payloads never returned by the API.
- **Secrets:** SMTP credentials and the cron token live only in `config.php`; errors are redacted before storage (tested with a planted password).
- **Time:** all scheduled timestamps are stored in UTC; follow-ups store their time zone; date triggers use the agency time zone setting.
- **Retention:** domain events, activity and job logs are append-only and grow over time; a retention/cleanup job is a Phase 7 item.
