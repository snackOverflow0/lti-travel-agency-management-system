# Phase 4 schema and security changes

## Migration `005_phase4.sql` (additive; no data is changed or dropped)
- **Permissions:** `groups.manage`, `groups.override_capacity` (owner, admin), `forms.manage`, `documents.view`, `documents.manage`, `library.view`, `library.manage`, with role grants.
- **trips:** `coordinator_id` (FK users), `group_capacity`, `registration_deadline`, `group_details_json`, index on type/parent.
- **itinerary_days / itinerary_activities:** `origin_day_id`, `origin_activity_id`, `is_customized` for group-to-sub-trip sync.
- **group_registrations:** contact, status, room preference, special requests, override author, creator, cancellation fields; new **group_registration_travelers** (composite PK, FKs).
- **suppliers:** status, destination, website, address, contract reference/notes, archived time, creator, version; new **supplier_contacts**.
- **bookings:** end date, notes, confirmation details, cancellation fields, creator, version; new **booking_travelers**.
- **forms:** status, category, steps, settings, published time, creator, version; new **form_fields** (unique key per form) and **form_answers**; submissions gain submitter, IP and source.
- **documents:** supplier link, title, SHA-256, updated time, indexes, uploader FK; `stored_name` widened to 100.
- **content_folders** (self-referencing, cycle prevention in code), **content_templates**, **content_template_usage**.

## Security
- Every new endpoint requires a session and a named permission; row scoping reuses trip/contact scoping, so out-of-scope records return 404.
- CSRF required on all state-changing requests except login, token reset and public form submission (which is unauthenticated, rate-limited to 20 per IP per hour and has a honeypot field).
- Group registrations run in a transaction with a row lock on the group to prevent overbooking from concurrent requests; capacity, room and deadline overrides require `groups.override_capacity` and are recorded.
- Uploads: extension allow-list plus server-side content-type detection (`fileinfo`), size limit (default 10 MB, `upload_max_bytes`), random 64-hex stored names in `app/storage/documents/YYYY/MM/` outside the web root, SHA-256 recorded.
- Downloads only through PHP after permission checks, `nosniff`, `no-store`, a sandboxing Content-Security-Policy, and inline display limited to images and PDF. Travelers can download only client-visible documents on their published trips.
- CSV export neutralizes spreadsheet formula injection.
- All mutations are written to `audit_log`.

## Added in the final increment
- No further migration. `TripController` accepts the group statuses Open and Closed.
- Public form page (`#/f/<token>`) uses only the existing rate-limited, honeypot-protected public endpoints; drafts and archived forms return 404.
- **Storage guard:** every write of the prototype’s browser sample-data store is intercepted and scrubbed. Entries flagged as database records, database-keyed entries, and values matching live booking references, confirmation numbers, supplier names or document names are removed before saving. If the payload cannot be processed, nothing is written.
- Prototype source change (minimal, functional): multiple-choice fields get an options box, can drive conditional fields and appear in the builder preview.
