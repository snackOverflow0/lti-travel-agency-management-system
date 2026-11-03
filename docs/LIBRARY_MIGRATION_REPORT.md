# Revision 27 content library migration

**Source:** the approved prototype file (`rev27.html`), extracted unchanged into `server/app/data/revision27_library.json` (SHA-256 recorded in the file and in every import run). The prototype and the snapshot are **not modified or deleted**.

**Important:** this is the prototype's sample content (fictional suppliers and examples). Every imported record is tagged `origin = 'revision27'` and labelled "Imported from Revision 27" in the app so it can be reviewed, edited or removed before go-live.

## Result (verified in MySQL; test database)
| Content | In prototype | Migrated | Where |
|---|---:|---:|---|
| Activity templates | 15 | 15 | `content_templates` kind `activity` (Content hub › Activities) |
| Destination guides (with sections) | 4 | 4 | kind `guide` (Content hub › Destination guides) |
| Packing lists | 3 | 3 | kind `checklist` |
| Task templates | 3 | 3 | kind `task` (Content hub › Task templates; “Create task”) |
| Cruise library | 1 | 1 | kind `supplier_info` (Cruises) |
| Folders (itinerary 2, photos 66 incl. nested tree, tasks 1, workflows 2) | 71 | 71 | `content_folders` with `section` and parent links |
| Email templates | 10 | 10 | `email_templates` (see placeholders below) |
| Workflow templates | 20 | 20 | kind `workflow_reference` (read-only) |
| Automation templates | 26 | 26 | kind `workflow_reference` (read-only) |
| Workflow library entries (names only) | 7 | 6 | 1 skipped as a duplicate of an imported workflow |

## Not migrated, and why
- **Media:** no image data exists in the prototype. The photo library holds 102 file names with no file content, and all 15 activity images are empty. Folders were migrated; nothing was invented.
- **Itinerary templates:** none exist in the prototype — only two empty folders (“Ocean”, “River”), which were migrated.
- **Workflow/automation templates as runnable workflows:** they were written for the prototype’s simulated engine; converting them automatically would create automations that behave incorrectly. They are preserved verbatim as read-only references to rebuild in the workflow builder.

## Email template placeholders
Four placeholders were added because LTI has real data for them: `{{trip.destination}}`, `{{trip.start_date}}`, `{{trip.end_date}}`, `{{trip.link}}` (client portal address). Six have no data source in LTI (`form.link`, `advisor.signature`, `payment.amount`, `payment.due_date`, `trip.packing_list`, `client.passport_expiration`); they were replaced with visible markers such as `[Fill in: form link]`. **The server refuses to queue any email that still contains a marker.** The original wording is preserved in the snapshot.

## Guarantees (tested)
Idempotent (re-running imports nothing new; no duplicate source keys); staff edits are never overwritten by a re-run; folder hierarchy has no orphans; text is byte-identical (e.g. accents); records are editable and persist after refresh and re-login; only administrators can run the import; dry run writes nothing and reports the same counts as the real run.

## Running it on the server (no SSH needed)
1. Back up the database. 2. Import migration `010_library_import.sql` in phpMyAdmin. 3. Upload `server/app/data/revision27_library.json` with the application. 4. Signed in as the owner, run a dry run, then the import — from the browser console on the app:
```
const me = await (await fetch('./api/auth/me')).json(); const H = {'Content-Type':'application/json','X-CSRF-Token':me.csrf};
await (await fetch('./api/library/import-revision27',{method:'POST',headers:H,body:JSON.stringify({dryRun:true})})).json();
await (await fetch('./api/library/import-revision27',{method:'POST',headers:H,body:'{}'})).json();
```
With SSH: `php server/app/bin/import_revision27_library.php --dry-run`, then without `--dry-run`.

## phpMyAdmin verification queries
```sql
SELECT kind, COUNT(*) records, SUM(origin='revision27') imported FROM content_templates GROUP BY kind;
SELECT section, COUNT(*) folders FROM content_folders GROUP BY section;
SELECT COUNT(*) orphans FROM content_folders c LEFT JOIN content_folders p ON p.id=c.parent_id WHERE c.parent_id IS NOT NULL AND (p.id IS NULL OR p.section<>c.section);
SELECT name, body LIKE '%[Fill in:%' AS needs_editing FROM email_templates WHERE origin='revision27';
SELECT created_at, dry_run, summary_json FROM library_import_runs ORDER BY id DESC;
```

## Removing the imported sample content later
Only after review and a backup, and only for records nobody has started using:
```sql
DELETE FROM content_templates WHERE origin='revision27' AND id NOT IN (SELECT template_id FROM content_template_usage);
DELETE FROM email_templates WHERE origin='revision27' AND id NOT IN (SELECT template_id FROM emails WHERE template_id IS NOT NULL);
-- folders: delete children before parents (or archive them in the app)
```
