# Security audit and remediation (OWASP ASVS 5.0.0 used as a reference, not a certification)

| Area | Finding | Status | Evidence |
|---|---|---|---|
| Authentication on every route | — | Pass | SEC-T01: 210 protected routes refuse without a session |
| CSRF | — | Pass | SEC-T02 |
| Session handling | HttpOnly, SameSite=Lax, Secure under HTTPS, rotation on login, idle 30 min / absolute 12 h | Pass | SEC-T03 |
| Password storage and reset | bcrypt (PASSWORD_DEFAULT); hashed single-use reset tokens, 60 min | Pass | SEC-T10 |
| Brute force | **P8-06 (Medium): per-IP+email combined limit locked out offices behind one IP** | Fixed: separate per-account (5) and per-IP (30) limits | SEC-T09, SEC-T09b |
| SQL injection | Prepared statements throughout | Pass | SEC-T04 (35 payloads) |
| XSS | Output escaped; templates allowlisted | Pass | Browser XSS test; unit template tests |
| Access control / IDOR | Record-level scopes for staff and clients | Pass | SEC-T05, SEC-T06 with positive controls; SEC-T07 escalation |
| Uploads | Extension + content checks; stored outside web root with random names | Pass | SEC-T08 (6 disguised files refused) |
| Security headers | **P8-01 (Medium): none on app pages; minimal on API** | Fixed: CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy, nosniff; HSTS under HTTPS | SEC-T11; 0 CSP violations across 73 page routes × 5 widths |
| Error disclosure | **P8-02 (Medium): display_errors not forced off** | Fixed: production hides errors, logs privately, generic 500 | SEC-T11 (308 responses scanned) |
| Version disclosure | X-Powered-By | Fixed in app; set `expose_php=Off` on host | SEC-T11 |
| HTTPS | Optional HTTPS-only sign-in; redirect ready in `.htaccess` | Ready, **unverified on host** | SEC-T12 |
| Dependencies | npm production deps: 0 known vulnerabilities; dev tooling has advisories (not deployed); PHPMailer 6.9.1 — check current release before deployment | Open (Low) | `npm audit --omit=dev` |
| CSP strength | `'unsafe-inline'` required by the approved Revision 27 inline handlers | Accepted limitation | — |
| Secrets | Config outside web root; no secrets in the bundle; credentials redacted from logs | Pass | Phase 6 TC38 |
| Financial integrity | Server totals, idempotency, append-only hash chain | Pass | F05–F07; restored chain verified |
