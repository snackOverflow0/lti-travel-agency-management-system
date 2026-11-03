# Defect register (Phase 8)

| ID | Module | Description | Severity | Root cause | Fix | Retest |
|---|---|---|---|---|---|---|
| P8-01 | Security | No security headers on app pages | Medium | No web-root `.htaccess`; API only set nosniff | Headers in `.htaccess`, API and dev router | PASS (SEC-T11, CSP sweep) |
| P8-02 | Security | PHP errors could be displayed in production | Medium | display_errors not set | Forced off in production; private log; generic 500 | PASS (SEC-T11) |
| P8-03 | Calls | Recordings page showed fabricated recordings | Medium | Prototype page | Honest "unavailable" page | PASS (restoration 12/12) |
| P8-04 | Email | Smart inbox showed fabricated received emails | Medium | Prototype page | Honest "unavailable" page | PASS |
| P8-05 | UI | Team and Roles pages ~2× phone width at 360/390 px | Low | `.seg` tab strip cannot wrap or scroll | Mobile-only scroll rule | PASS (responsive 360/390) |
| P8-06 | Auth | 5 failures from one IP locked out every account on that IP for 15 min | Medium | `email OR ip` combined limit | Separate per-account and per-IP limits | PASS (SEC-T09, T09b) |
| P8-07 | Security | X-Powered-By on static files (local server) | Low | Dev router | Removed; production: `expose_php=Off` | PASS |
| P8-08 | Performance | Overdue-task query scanned the whole table | Low | Missing composite index | Migration 009 | Plan improved (ALL→ref); latency unchanged at 5k rows |
| P8-09 | Performance | Per-row visibility lookups in call/task lists (N+1) | Low | Label resolution per row | **Open** — ~21 ms per 25-row page at 5k rows | — |
| P8-10 | Finance | Finance screens not connected (no quote screen) | **High** | Phase 5 increment 2 not done | **Open — release blocker** | — |
| P8-11 | Operations | Tasks, email, workflow, notification screens not connected | **High** | Phase 6 increment 2 not done | **Open — release blocker** | — |
| P8-12 | Hosting | Network Solutions capabilities unverified | Blocker | No account access | **BLOCKED** | — |
| P8-13 | Email | SMTP delivery unverified | Blocker | No mailbox details | **BLOCKED** | — |
| P8-14 | Jobs | Production scheduler unverified | Blocker | No account access | **BLOCKED** | — |
| P8-15 | Tests | Phase 3 browser tests failed once (Phase 7 run) | Low | Undetermined | Not reproduced in 3 later full runs | Monitoring |
| P8-16 | Dependencies | PHPMailer 6.9.1 currency | Low | — | Check before deployment | — |
| P8-17 | API | Quote conversion returns 200 (not 201) | Info | Established Phase 5 contract | None (documented) | — |

Test-harness defects found and fixed during QA (not application defects): fixtures that silently did not exist (SEC-T05/T06), wrong booking category, wrong expected status codes/columns in the flow test, backup script checking the wrong field, cookie jar not written on PHP 8, lockout leaking across suites.
