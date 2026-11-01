# Phase 6 feature audit and parity

## Prerequisites (verified on unmodified code)
Phase 2–5 API suites and all browser suites passed before Phase 6 changes. Phase 5 financial screens are not yet connected and Phase 5 is unapproved; Phase 6 only uses its APIs and events.

| Feature | Restored screen | Data source before | Tables before | Now | Screen connected |
|---|---|---|---|---|---|
| Tasks and boards | Tasks, Calendar | Browser sample (`S.tasks`) | `tasks` (basic) | Full API: statuses, priorities, links, comments, history, filters | No (increment 2) |
| Staff assignment | Tasks | Sample | — | Assignment history + notifications | No |
| Email composer and history | Emails, Outbox | Sample (`X.outbox`) | `emails` (basic) | Drafts, queue, SMTP send, attempts, history, search | No |
| Templates | Emails › Templates | Sample (`X.etpl`) | `email_templates` | Allowlisted variables, preview, duplicate, archive | No |
| Workflow builder / automations | Automations, Builder, Editor, Workflows | Sample (`X.wfs`, `X.autos`) | `workflows`, `automations` (unused) | Validated definitions, triggers, conditions, actions, delays, history, retry, preview | No |
| Scheduled follow-ups | Scheduling | Sample | `scheduled_jobs` | Follow-ups with UTC + time zone, job-backed | No |
| Notifications | Notifications, bell | Sample | `notifications` | Per-user, read/unread/dismiss, categories | No |
| Activity feed | Contact/trip activity | Sample (`logA`) | — | `activity_events` from real events | No |
| Background jobs | — | Browser demo clock | `scheduled_jobs` | Lease-based queue, retries, recovery, monitoring | — (admin API) |
| Inbound mail sync | Smart inbox | Sample | — | **Not implemented** (needs confirmed IMAP; no simulated inbox) | — |

The older `automations` / `automation_runs` tables from migration 003 are superseded by `workflows` / `workflow_executions` and hold no data.

## Remaining (increment 2 and beyond)
Connect the restored screens listed above; screen tests for TC01–TC31; delivery/bounce tracking (needs provider evidence); IMAP inbox (needs confirmed access); Network Solutions scheduler verification (TC33).
