# Every previously prototype-only page and its resolution

Found by rendering every route while signed in (73 routes). Final sweep: **0 routes** show the old warning or any “browser only” / demo claim anywhere on the page.

| Page | Previous state | Resolution | Status |
|---|---|---|---|
| agency, settings | Browser sample settings | New Settings API (agency, regional, email signature, booking controls, terms); admin-only edits; audited | Live |
| tags | Browser sample tags | Tags API with usage counts; in-use tags protected; 8 trip tags migrated | Live |
| controls | Browser sample booking rules | Required fields stored and **enforced by the server** when confirming | Live |
| roles, team | Role-switching demo | Real permission matrix; owner-only edits; owner role locked; clients portal-only; audited; immediate effect | Live |
| terms | Sample terms | Draft/publish; clients see only published text (sample legal text not migrated) | Live |
| trav | Sample travelers | Travelers on visible trips; passport country/expiry only (numbers not stored) | Live |
| hsup | Local support form | Creates a real task + notification for an administrator | Live |
| integ | Sample connections | Real status of SMTP, scheduler, telephony, IMAP, calendar | Live (status) |
| rpc, rpview | Live reports, mislabelled | Verified live; label removed | Live |
| library, libem, libfo, libsu, comm, client, preview | Duplicates of live modules | Route to Activities, Email templates, Forms, Suppliers, Commission, Contacts, Client portal | Routed |
| ai | Scripted demo assistant | No AI service at zero cost | Not available |
| scheduling | Simulated booking link | Public self-booking not implemented | Not available |
| libca, libch | Empty demo lists | Campaigns / chat prompts not implemented | Not available |
| rpcl, rpq, Reporting › Report builder / AI assist | Report-builder demo | Not implemented; Reports tab is live | Not available |
| tpl, tact | Itinerary-template editor | No templates existed; not implemented | Not available |
| help | Static articles | Labelled as static guidance (no business data) | Static |
| All pages | Footer “Demo prototype · sample data saved in this browser only…” | Replaced with an accurate statement after every render | Fixed |
| docs | “Files you upload are kept in this browser only” | Replaced: files are stored privately on the server | Fixed |
