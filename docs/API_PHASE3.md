# Phase 3 API reference

All staff endpoints need a signed-in session; non-GET requests need the `X-CSRF-Token` header returned by `/auth/me` or `/auth/login`.

| Method | Path | Permission | Purpose |
|---|---|---|---|
| GET | /api/trips?q&status&contactId&archived=0/1/all&page&perPage | trips.view (scoped) | List trips with travelers and collaborators |
| POST | /api/trips | trips.edit | Create (name, destination, startDate, endDate, status, ownerId, primaryContactId, tags) |
| GET | /api/trips/{id} | trips.view (scoped) | Trip with travelers, collaborators, approvals and comments |
| PUT | /api/trips/{id} | trips.edit (+ trips.delete to archive/cancel) | Update fields; `version` for conflict detection |
| POST | /api/trips/{id}/archive, /restore | trips.delete | Archive keeps all related records |
| PUT | /api/trips/{id}/travelers | trips.edit | Replace travelers `[{contactId, permission: primary/full/limited}]`, one primary |
| GET | /api/trips/{id}/itinerary | trips.view | Draft, metadata, published info, version list |
| PUT | /api/trips/{id}/itinerary | trips.edit | Save whole draft `{rev, options, meta}`; returns `idMap` for new items; 409 on stale `rev` |
| POST | /api/trips/{id}/publish, /unpublish | trips.publish | Freeze sanitized snapshot / hide from portal |
| GET | /api/trips/{id}/versions/{vid} | trips.view | Snapshot of a published version |
| POST | /api/trips/{id}/comments | trips.edit | Advisor reply visible on the portal |
| POST | /api/contacts/{id}/portal-account | staff.manage | Create/re-issue a traveler login; returns one-time password link |
| GET | /api/portal/trips | portal.access | The traveler’s published trips |
| GET | /api/portal/trips/{id} | portal.access | Published snapshot (prices removed for Limited access) |
| POST | /api/portal/trips/{id}/select, /approve | portal.access, Primary only | Choose option / approve |
| POST | /api/portal/trips/{id}/request-changes | Primary or Full | Change request |
| POST | /api/portal/trips/{id}/comments | any traveler on the trip | Comment |

Records a user may not see always return 404 (no difference between “missing” and “not yours”).
