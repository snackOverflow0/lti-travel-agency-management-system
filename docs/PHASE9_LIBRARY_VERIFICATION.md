# Library migration verification (final run)

| Check | Result |
|---|---|
| L01 Only administrators can run the import | advisor 403 |
| L02 Dry run reports the import and writes nothing | 71 folders, 15 activities… reported; database unchanged (0/0/0) |
| L03 Import creates every record with origin and matching counts | {"activity":15,"guide":4,"checklist":3,"task":3,"supplier_info":1,"workflow_reference":52}; 10 email templates; 71 folders |
| L04 Re-running the import creates no duplicates | 0 new records; totals unchanged (78/71/10) |
| L05 Records stay editable; a re-run never overwrites staff edits | edited title survived a re-run |
| L06 Unsupported placeholders become visible markers and block sending until replaced | queue refused: Replace [Fill in: form link] with real text before sending |
| L07 New variables render real data (trip destination, dates, portal link) | Amalfi Coast, Italy / December 3, 2026 to December 11, 2026 http://127.0.0.1:8000/ |
| L08 Folder hierarchy and text preserved exactly | no orphans; Alaska… under Ocean; text byte-identical |

Itinerary templates: **none existed** in the prototype (two empty folders migrated). Media: **no image data existed** (102 file names only; not migrated). Workflow/automation entries are **read-only references**, not runnable automations. All imported records carry `origin='revision27'` and are labelled in the app as prototype sample content for the agency to review.
