# Zero-cost compliance audit

Rule: **$0 new recurring or usage-based cost.** Free trials, free tiers that convert to paid, and anything needing a payment method on file are rejected. Items marked *Unverified* must be confirmed against the client's Network Solutions account before production.

## Components in use now
| Name | Purpose | License | Free for commercial use | Monthly cost | Usage charges | API restrictions | Hosting resources | Included with Network Solutions | Unverified items | Free alternative | Production ready |
|---|---|---|---|---|---|---|---|---|---|---|---|
| React 18.3.1 / React DOM | UI | MIT | Yes | $0 | None | None | Static JS in browser | n/a (static files) | — | — | Yes |
| TypeScript 5.6.3 | Type checking (build only) | Apache-2.0 | Yes | $0 | None | None | Local only | n/a | — | — | Yes |
| Vite 5.4.10 + @vitejs/plugin-react 4.3.3 | Build to static files | MIT | Yes | $0 | None | None | Local only; nothing runs on the server | n/a | — | — | Yes |
| @types/react, @types/react-dom | Types (build only) | MIT | Yes | $0 | None | None | Local only | n/a | — | — | Yes |
| PHP (7.4+ compatible code) | API runtime | PHP License 3.01 | Yes | $0 | None | None | Per-request | Expected on shared hosting | **Version and extensions (pdo_mysql, mbstring, openssl, fileinfo) unverified** — run `tools/probe.php` | — | Pending Phase 0 |
| MySQL (InnoDB, utf8mb4) | Database | Provided by host | Yes | $0 | None stated | Connection limits unknown | ~48 tables, small at start | Client states MySQL is included | **Version, storage quota, max_user_connections unverified** | — | Pending Phase 0 |
| Apache .htaccess + mod_rewrite | Route `/api/*` to `index.php` | Host-provided | Yes | $0 | None | — | — | Typical on shared hosting | **Unverified** | Call `api/index.php?r=/path` directly (front controller already supports it) | Pending Phase 0 |
| PHP native sessions | Staff sign-in | Built into PHP | Yes | $0 | None | — | Session files | Yes (part of PHP) | Session save path writability (probe checks) | — | Yes |
| HTTPS certificate | Encryption | Host / CA | — | Must be existing | — | — | — | **Unverified whether included on this plan** | Free CA certificates only if the host allows installing them | Pending |

## Development-only tools (never deployed, no cost)
| Name | Purpose | License | Cost |
|---|---|---|---|
| Node.js 22 / npm | Build frontend | MIT / Artistic-2.0 | $0 |
| Playwright 1.56 + Chromium | Browser tests | Apache-2.0 | $0 |
| MariaDB 10.11 (local) | Local database | GPL-2.0 | $0 |
| Custom PHP API test runner (`server/tests/api_test.php`) | Integration tests (PHPUnit equivalent) | Project code | $0 |
| Git | Version control | GPL-2.0 | $0 |

## Planned for later phases (evaluated, not yet added)
| Name | Purpose | License | Cost / risk | Decision |
|---|---|---|---|---|
| PHPMailer | SMTP email | LGPL-2.1 | $0 library; **depends on an SMTP mailbox included in the account (unverified)** and provider sending limits | Adopt if SMTP verified; otherwise keep a clearly labelled draft/outbox |
| PHP IMAP extension | Inbox sync | PHP License | $0; **extension availability unverified** (probe checks `imap`) | Adopt if available |
| Hosting cron | Automations, scheduled email | Host feature | **Availability on this plan unverified** | Required for reliable scheduling |
| External free scheduler (e.g. cron-job.org, GitHub Actions schedule) | Fallback trigger for `cron.php` | Service terms | Terms, limits and payment requirements **not verified in this session**; GitHub Actions has monthly minute limits on private repos | Only after written review confirms no payment method and acceptable reliability |
| Browser WebRTC | Internet calling | Built into browsers | $0 | Adopt (Phase 7) |
| Public STUN servers | NAT discovery | Third-party | No fee, but **no service agreement**; may change | Use with disclosure; calls may fail on restrictive networks |
| TURN relay | Calls through strict NAT/firewalls | — | No reliable zero-cost hosted option identified; self-hosting needs a continuously running server | **Not provided** — limitation documented |
| `tel:` links | Hand off to the user's own phone | Standard URI | $0 to LTI; the user's carrier plan may charge | Adopt with disclosure |
| PDF: browser print-to-PDF, or a free PHP library (e.g. dompdf, LGPL-2.1) | Invoice PDF | Free | Memory/time limits on shared hosting unverified | Evaluate in Phase 5 |
| Chart library (e.g. Chart.js, MIT) | Reports | MIT | $0 | Evaluate in Phase 8 |

## Rejected (cost or billing risk)
Twilio, Vonage, paid SIP trunks and phone numbers; SMS gateways; SendGrid/Mailgun/other paid or metered email APIs; Stripe/PayPal/Square processing (transaction fees); OpenAI, Anthropic, Gemini or any metered AI API; AWS/GCP/Azure storage or databases; Firebase/Supabase pay-as-you-go; paid TURN; any "free trial" service.

**Total new monthly cost of the current build: $0.** No paid service is configured or called anywhere in the code.

## Phase 4 additions
No new libraries, services or accounts. File storage uses the hosting account’s disk; the PHP `fileinfo` extension (bundled with PHP, normally enabled) is now required for upload validation and must be confirmed in Phase 0. Upload sizes are also limited by the host’s `upload_max_filesize` and `post_max_size` (unverified). **New monthly cost: $0.**

## Phase 5 additions
No new libraries, services or accounts. No payment processor, exchange-rate service or e-signature service: payments are recorded manually, exchange rates are entered manually with a source note, and quote acceptance is recorded by staff. Printable invoices use the browser's Print / Save as PDF. **New monthly cost: $0.**

## Phase 6 additions
| Name | Purpose | License | Cost | Notes |
|---|---|---|---|---|
| PHPMailer 6.9.1 (vendored in `server/app/vendor/phpmailer`) | SMTP sending | LGPL-2.1 | $0 | No Composer needed on the host |
| Hosting SMTP mailbox | Outbound email | Host-provided | Existing plan (unverified) | Sending limits unverified |
| Hosting cron | Scheduler | Host-provided | Existing plan (**unverified — TC33 BLOCKED**) | Fallback: token-protected URL endpoint |
| aiosmtpd (development only) | Local SMTP test server | Apache-2.0 | $0 | Never deployed |
No queue service, automation platform, SMS or email API added. **New monthly cost: $0.**
