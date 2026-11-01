# Committing Phase 4 (increment 1) manually

I could not access GitHub from this session, so nothing was pushed and no pull request exists. The ZIP excludes `.git`. Local commits were made in my working copy only, using these messages.

```bash
git checkout develop && git pull
git checkout -b phase/4-groups-bookings-documents
# copy the ZIP's lti/ contents over your working tree, keeping your own server/app/config/config.php
git add server/app/migrations/005_phase4.sql server/app/src/Router.php server/app/src/Controllers/TripController.php server/app/src/Controllers/ItineraryController.php server/app/src/Controllers/GroupController.php
git commit -m "feat(groups): implement database-backed group trips, registrations and sub-trip sync"
git add server/app/src/Controllers/SupplierController.php && git commit -m "feat(suppliers): connect suppliers to MySQL"
git add server/app/src/Controllers/BookingController.php && git commit -m "feat(bookings): implement persistent bookings"
git add server/app/src/Controllers/FormController.php && git commit -m "feat(forms): forms, fields, publishing, submissions and export API"
git add server/app/src/Controllers/DocumentController.php && git commit -m "feat(documents): add secure file management"
git add server/app/src/Controllers/LibraryController.php && git commit -m "feat(library): implement reusable content library API"
git add server/public web && git commit -m "feat(frontend): connect restored Suppliers, Bookings and Documents screens"
git add server/tests tests run-tests.sh && git commit -m "test(phase4): verify travel operations and persistence"
git add docs README.md server/app/config/*.example.php .gitignore && git commit -m "docs(phase4): audit, test report, schema, security, cost"
git push -u origin phase/4-groups-bookings-documents
```
Then add the final-increment commits:
```bash
git add web/src/prototype/phase4b.ts web/src/pages/PublicForm.tsx web/src/App.tsx web/src/prototype/adapter.ts web/src/prototype/globals.d.ts web/src/prototype/trips.ts web/public/prototype/lti-prototype.js server/app/src/Controllers/TripController.php server/public
git commit -m "feat(groups,forms,library): connect restored Group trips, Forms and Content library screens"
git add web/src/prototype/storageGuard.ts web/src/prototype/phase4.ts && git commit -m "fix(privacy): keep database records out of browser storage"
git add tests run-tests.sh && git commit -m "test(phase4): screen tests for groups, forms and library"
git add docs && git commit -m "docs(phase4): final checkpoint report"
git push
```
Then open a pull request into `develop` titled “Phase 4 — groups, registrations, sub-trips, suppliers, bookings, forms, documents and content library”. Review the test report and known limitations; do not merge until you approve.
