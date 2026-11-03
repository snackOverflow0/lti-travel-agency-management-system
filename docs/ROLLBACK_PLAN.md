# Rollback plan

**Before every deployment:** database export, documents archive, the previous release ZIP, and a note of the exact time.

| Failure | Action |
|---|---|
| Frontend broken | Re-upload the previous release's `public/` files. No data impact. |
| API errors | Re-upload the previous `app/` code. Migrations in this project are additive, so older code runs on the newer schema. |
| Migration failed partway | Stop. Do not re-run blindly. Restore the pre-deployment export into a new database, fix, and repeat on a copy first. All migrations are additive (no drops), so a forward fix is usually safer than a restore. |
| Sign-in failure | Check `config.php`, HTTPS settings (`require_https`), and the error log; roll back code if not fixed in 15 minutes. |
| Financial reconciliation error | Freeze finance actions (remove `finance.*` permissions temporarily), run `GET /api/finance/audit/verify`, correct with void/reversal entries — never edit rows directly. |
| Upload failures | Check `storage/` permissions and PHP upload limits; uploads fail closed (nothing stored). |
| Scheduler errors | Disable the cron entry; queued jobs wait safely; review `GET /api/jobs?status=failed`. |

**Protecting new data:** never restore an old snapshot over a database that has taken new transactions. Instead: export the current database first, restore the old snapshot to a separate database, and re-enter or migrate the records created after deployment (identify them by `created_at` after the deployment time). Rollback is triggered by: data corruption, authentication failure for all users, financial audit chain failure, or a security exposure.
