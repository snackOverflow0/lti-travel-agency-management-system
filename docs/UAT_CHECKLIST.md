# User acceptance test checklist (synthetic data only)

Mark each Pass / Fail / Not applicable with the tester's initials and date.

**Staff (owner, advisor, finance, operations)**
- [ ] Sign in, sign out, wrong password message, lockout after 5 tries, colleague not locked out
- [ ] Create, edit, archive a contact; add a second phone number
- [ ] Create a trip; build an itinerary (days, activities, internal note); publish
- [ ] Add a supplier and a booking; cancel the booking
- [ ] Create a group trip and a registration
- [ ] Build a form, open the public link, submit, see the response
- [ ] Upload a PDF to a trip; download it; confirm an advisor without access cannot
- [ ] Phone panel: look up a contact by number; Open phone app (check your device dials); record the outcome; log a call; schedule, reschedule and complete a callback
- [ ] Call history filters, export CSV, call analytics
- [ ] Each role sees only what it should (advisor vs advisor, finance, traveler)
- [ ] Pages at phone size (about 390 px): menus, dialogs, softphone usable
**Not yet testable through screens (API only):** quotes, invoices, payments, commissions, finance reports, tasks, email, workflows, notifications.
**Client (traveler)**
- [ ] Sees only their own trips; published itinerary without internal notes; can approve or request changes
- [ ] Cannot open another client's trip or document links
