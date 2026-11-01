# Phase 4 feature-parity audit and checklist

## Audit before implementation (Phase 3 baseline, verified by tests)
| Module | Database tables (from 003) | API | Restored screen | Status before Phase 4 |
|---|---|---|---|---|
| Group trips, registrations, sub-trips | `trips` (group/sub types), `group_rooms`, `group_registrations` (minimal) | None | Prototype (sample data) | Prototype-only; schema partial |
| Suppliers | `suppliers` (basic) | None | Prototype | Prototype-only |
| Bookings | `bookings` (basic) | None | Prototype | Prototype-only |
| Forms | `forms`, `form_submissions` (JSON only) | None | Prototype builder | Prototype-only; no field table |
| Documents | `documents` (metadata) | None | Prototype (files in browser memory) | Prototype-only; no storage |
| Content library | None | None | Prototype | Missing in database |
| Broken | — | — | — | Nothing broken found |

## Status at this checkpoint
| Module | Database | API (tested) | Restored screen (tested) | Status |
|---|---|---|---|---|
| Group trips | Done | Done | Done — create, edit, coordinator, dates, capacity, deadline, status, archive/restore, landing page, room categories | Database-connected |
| Registrations | Done | Done | Done — existing or new contacts, room, preferences, special requests, edit, cancel, duplicate and capacity rules, administrator override | Database-connected |
| Sub-trips and sync | Done | Done | Done — per registration or manual, own dates and travelers, preview and apply sync, custom activities preserved | Database-connected |
| Suppliers | Done | Done | Done | Database-connected |
| Bookings | Done | Done | Done | Database-connected |
| Forms | Done | Done | Done — builder, field types (short/long text, email, phone, date, number, dropdown, multiple choice, checkbox), conditional fields, publish, duplicate, archive, public link page, responses, linking, CSV | Database-connected (file-upload fields not offered) |
| Documents | Done | Done | Done | Database-connected |
| Content library | Done | Done | Done — folders, activity templates, packing lists, use on trips | Database-connected (destination guides, task templates, more libraries remain prototype) |

## Known limitations
- File-upload form fields are not offered yet.
- Destination guides, task templates and “More libraries” remain prototype until Phase 6.
- Payments, invoices and commission statements remain Phase 5.
- Public group-landing self-registration is staff-operated in this phase (the public link is for forms).
- Archived documents keep their file on disk for recovery; no purge tool yet.
