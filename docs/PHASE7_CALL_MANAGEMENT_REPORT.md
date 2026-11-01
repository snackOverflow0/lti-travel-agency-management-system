# Call management database integration

**Migration `008_phase7.sql` (additive):** extends `calls` (source, verification, E.164 number, booking, end time, disposition, creator, archive, version, unique provider-event id reserved for a future provider; legacy statuses mapped); adds `call_dispositions` (11 seeded, configurable), `call_notes`, `call_events` (edit history), `contact_phones` (multiple numbers, preferred, mirrored from the contact’s main phone) and `callbacks`. New permissions: `calls.view_all` (owner, admin, supervisor), `calls.configure` (owner, admin).

**Record types and labels:** manual log → “Entered by staff — not verified by a phone network”; handoff → “Handoff only — connection not confirmed” until staff record the outcome. Durations are only staff-entered (or derived from entered start/end times); unanswered calls and pending handoffs never have a duration.

**Phone numbers:** conservative normalisation to E.164 — full rules for US/Canada, trunk-prefix rules for selected countries, `+`/`00`/`011` international formats with a known calling code and 8–15 digits. Not full worldwide validation (no library added). Contacts can hold several numbers; duplicates are reported only among contacts the user may see. Run **Call settings › Sync contact phone numbers** once after migrating (or `POST /api/phone/backfill`).

**Callbacks and tasks:** each callback creates a high-priority task (“Call back …”) for the assignee and queues a reminder job; rescheduling moves the task due date and replaces the reminder; completing closes the task. Reminders are delivered only when the Phase 6 scheduler runs (production scheduler still unverified).

**Timeline and workflows:** logs, outcomes, notes and callbacks appear on the contact, trip and booking timelines; a new workflow trigger `call.logged` (fields: direction, status, disposition) lets Phase 6 workflows react to calls (tested: missed call → follow-up task).

**Reporting:** totals by direction, outcome, staff, disposition, record type and date; callbacks completed/pending/overdue; missed inbound calls followed up; entered talk time. All labelled as unverified.
