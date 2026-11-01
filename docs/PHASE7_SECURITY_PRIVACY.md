# Phase 7 security and privacy review

- Calls are visible to the staff member who handled or logged them, to users who may see the linked contact, and to `calls.view_all` roles; others get 404. Editing is limited to the handler/creator or supervisors; notes are editable by their author or supervisors; all edits are kept in `call_events` and the audit log.
- Phone lookup never reveals contacts the user cannot see (no names, counts or hints); linked records the user can no longer see are shown as “Restricted record”.
- Callbacks cannot be created for hidden contacts; exports apply the same scope and neutralise spreadsheet formulas.
- No telephony credentials exist; nothing secret is in the JavaScript bundle. No audio is captured, stored or recorded.
- Input validation on numbers, dates, durations and outcomes; prepared statements; CSRF on all writes; disposition changes audited.
- Retention: call records are archived rather than deleted; a retention/cleanup policy (with the timeline and job logs) is a Phase 8 item.
