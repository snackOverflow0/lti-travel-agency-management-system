# Performance report

Measured on this sandbox (not the hosting server), PHP built-in server, MariaDB 10.11, advisor account, median of 5 requests, with 5,006 contacts, 5,001 calls and 5,002 tasks (synthetic). Absolute times on Network Solutions will differ.

## Frontend
| Asset | Size | gzip |
|---|---|---|
| React bundle (`index-*.js`) | 261 KB | 83 KB |
| Stylesheet | 32 KB | — |
| Revision 27 prototype script (approved, unmodified) | 882 KB | 280 KB |

Approximately 370 KB compressed on first load. Added: long-term caching for hashed assets, `no-cache` (revalidation) for HTML and the prototype script, compression via `mod_deflate` (effect not measurable without Apache). Not done: splitting the prototype script (would modify the approved frontend).

## API — before migration 009
```
Volume: contacts=5006 calls=5001 tasks=5002
/contacts?page=1&perPage=50      HTTP 200  median    4.0 ms  max    4.5 ms
/contacts?q=garcia               HTTP 200  median    9.1 ms  max    9.3 ms
/calls?perPage=25                HTTP 200  median   21.6 ms  max   22.0 ms
/calls?status=missed             HTTP 200  median   24.7 ms  max   25.2 ms
/calls?q=4155550000              HTTP 200  median   31.6 ms  max   32.3 ms
/calls/report                    HTTP 200  median   47.8 ms  max   48.3 ms
/tasks?overdue=1                 HTTP 200  median   27.7 ms  max   28.6 ms
/tasks?upcomingDays=7            HTTP 200  median   23.6 ms  max   24.9 ms
/notifications                   HTTP 200  median    2.4 ms  max    2.6 ms
/trips?type=all&perPage=50       HTTP 200  median    3.4 ms  max    3.6 ms
EXPLAIN calls list: cl type=range key=idx_calls_staff rows=4971
EXPLAIN overdue tasks: k type=ALL key=none rows=4956
EXPLAIN contact by phone: contact_phones type=ref key=idx_cp_e164 rows=1
```
## API — after migration 009
```
Volume: contacts=5006 calls=5001 tasks=5002
/contacts?page=1&perPage=50      HTTP 200  median    4.2 ms  max    4.9 ms
/contacts?q=garcia               HTTP 200  median    9.4 ms  max    9.5 ms
/calls?perPage=25                HTTP 200  median   20.8 ms  max   21.4 ms
/calls?status=missed             HTTP 200  median   24.3 ms  max   24.8 ms
/calls?q=4155550000              HTTP 200  median   31.7 ms  max   32.5 ms
/calls/report                    HTTP 200  median   48.2 ms  max   48.4 ms
/tasks?overdue=1                 HTTP 200  median   27.8 ms  max   28.8 ms
/tasks?upcomingDays=7            HTTP 200  median   24.4 ms  max   26.0 ms
/notifications                   HTTP 200  median    2.6 ms  max    2.8 ms
/trips?type=all&perPage=50       HTTP 200  median    3.9 ms  max    4.1 ms
EXPLAIN calls list: cl type=range key=idx_calls_staff rows=4971
EXPLAIN overdue tasks: k type=ref key=idx_tasks_assignee_status_due rows=2478
EXPLAIN contact by phone: contact_phones type=ref key=idx_cp_e164 rows=1
```
**Result:** the overdue-task plan changed from a full scan (4,956 rows) to an index range (2,478); response times did not change measurably at this volume, so no speed-up is claimed. All measured endpoints answered in under 50 ms median. Remaining cost is per-row PHP work (P8-09). No caching of private or financial data was added.
