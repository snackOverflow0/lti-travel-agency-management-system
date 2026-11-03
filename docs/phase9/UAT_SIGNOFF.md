# User acceptance test sign-off — NOT SIGNED

| Field | Value |
|---|---|
| Project | Luxury Travel Institute (LTI) Travel Agency Management System |
| Release | 1.0.0-rc3 (source commit 6a26bc8) |
| Test environment | Apache 2.4 + PHP 8.3 staging rehearsal, separate database `lti_staging`, synthetic data; local test mail server; not Network Solutions |
| Testing dates | 1 November 2026 (automated acceptance runs) |
| Test summary | See UAT_RESULTS.md |
| Open issues | See DEFECT_LOG.md |

## Limitations requiring the client's explicit acceptance (tick to accept)
- [ ] Phone calls are made on the user's own phone ("Open phone app") and logged in LTI; browser calling to phone numbers, hold, mute, transfer, recording and voicemail are **unavailable** (no telephony provider).
- [ ] Inbound email is not shown in LTI; email campaigns and segments are not implemented.
- [ ] Commission invoices to suppliers, workflow templates/library, the report builder, AI assist and appointment scheduling are not implemented (labelled in the app).
- [ ] Agency settings, roles editor, team, integrations, destination guides and task templates screens remain prototype (labelled) — **or** they must be completed before go-live (see SCOPE DECISION below).
- [ ] Email sending requires an SMTP mailbox on the hosting plan; until confirmed, sending is off.
- [ ] Unattended automation (emails, delayed workflow steps, reminders) requires a cron scheduler on the hosting plan; until confirmed, queued work waits.
- [ ] Payments are recorded manually; there is no card processing; recorded payments are labelled unverified until staff confirm them.

## Scope decision (choose one)
- [ ] The remaining prototype screens are **out of scope** for this release (accepted limitation above).
- [ ] The remaining prototype screens are **in scope**; complete them before deployment approval.

## Approval
- [ ] UAT accepted
- [ ] Production deployment approved (DNS changes require separate approval)

| Role | Name | Signature | Date |
|---|---|---|---|
| Client representative | | | |
| Project representative | | | |

This document has not been signed. No acceptance or approval has been given.
