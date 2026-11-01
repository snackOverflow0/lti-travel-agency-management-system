# Committing Phase 5 (increment 1) manually

No GitHub access was available, so nothing was pushed and no pull request exists. The commits below exist only in my working copy; the ZIP excludes `.git`.

```bash
git checkout develop && git pull
git checkout -b phase/5-financial-management
# copy the ZIP's lti/ contents over your working tree (keep your own server/app/config/config.php)
git add server/app/migrations/006_phase5.sql server/app/src/Money.php server/app/src/FinAudit.php server/app/src/Idempotency.php server/app/src/FinDoc.php server/public/api/index.php server/app/src/Controllers/ContactController.php
git commit -m "feat(finance): schema, exact money arithmetic, audit chain and idempotency"
git add server/app/src/Controllers/QuoteController.php && git commit -m "feat(quotes): implement persistent quote management"
git add server/app/src/Controllers/InvoiceController.php && git commit -m "feat(invoices): add database-backed invoicing"
git add server/app/src/Controllers/PaymentController.php && git commit -m "feat(payments): support payment allocations and schedules"
git add server/app/src/Controllers/PayableController.php && git commit -m "feat(payables): implement supplier financial tracking"
git add server/app/src/Controllers/CommissionController.php server/app/src/Controllers/BookingController.php && git commit -m "feat(commissions): add advisor commission management"
git add server/app/src/Controllers/FinanceController.php && git commit -m "feat(finance): dashboard metrics, reports, rates and audit endpoints"
git add server/tests tests run-tests.sh && git commit -m "test(phase5): validate financial persistence and reconciliation"
git add docs README.md && git commit -m "docs(phase5): audit, data model, calculations, security, test report"
git push -u origin phase/5-financial-management
```
Open a **draft** pull request into `develop`: Phase 5 is not complete until increment 2 connects the financial screens. Do not merge without your approval.
