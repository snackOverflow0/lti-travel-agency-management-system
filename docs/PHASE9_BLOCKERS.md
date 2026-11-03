# Unresolved blockers and open items

| ID | Type | Item | Needed from |
|---|---|---|---|
| B1 | Release blocker | Network Solutions plan unverified (PHP version/extensions, MySQL, SSL, .htaccess, cron, SMTP, upload limits, backups, deployment access) | Agency: run `tools/probe.php` once and send the output, or grant control-panel access |
| B2 | Release blocker | Backup restore not tested on the production host (local restore verified) | After B1 |
| B3 | Release blocker | Client acceptance of limitations: no browser PSTN calling, no call recording/voicemail, no inbound email, no AI, no campaigns/report builder/self-booking | Agency, in writing |
| B4 | Release blocker | Staff UAT and formal sign-off (`PHASE9_UAT_SIGNOFF.md`) | Agency staff |
| P9-05 | Low (open) | Workflow-builder browser test timed out once in 3 runs (cause undetermined) | Development |
| P8-09 | Low (open) | Per-row lookups in call/task lists (acceptable at 5k rows) | Development |
| P8-16 | Low (open) | Confirm PHPMailer 6.9.1 is current before deployment | Development |
| — | Review | Imported Revision 27 library content is sample material; review/edit/remove before go-live; fill `[Fill in: …]` markers in 10 email templates | Agency |
| — | Manual check | `tel:` handoff opens the phone app on a real device | Agency |
