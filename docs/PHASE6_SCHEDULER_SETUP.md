# Scheduler setup

Queued work (emails, delayed workflow actions, follow-ups, date-based triggers) runs only when the scheduler runs. Nothing depends on a browser being open. Administrators can see pending/failed jobs and the last scheduler run at `GET /api/jobs/status`.

## Windows / XAMPP (development)
1. Test once: `C:\xampp\php\php.exe C:\path\to\lti\server\app\bin\worker.php --once`
2. Task Scheduler › Create Task: trigger Daily, repeat every 1 minute for 1 day, indefinitely; action: Program `C:\xampp\php\php.exe`, arguments `C:\path\to\lti\server\app\bin\worker.php --once`, start in the `lti` folder. If your config is not at `server\app\config\config.php`, set the `LTI_CONFIG` environment variable for the task.
3. Alternatively keep a console open with `php server\app\bin\worker.php --loop=60` (stops when the window closes).

## Network Solutions (production) — **BLOCKED until verified**
I could not access the hosting account, so cron support is **unconfirmed**. In the control panel:
- **If cron jobs are available:** run every 1–5 minutes: `php /home/ACCOUNT/lti_app/bin/worker.php --once` (use the PHP path the host documents). This is the preferred option.
- **If only URL-based cron is available:** set a random `cron_token` (32+ characters) in `config.php` and have the cron call `POST https://YOUR-DOMAIN/lti/api/cron/run` with header `X-Cron-Token: <token>`. The endpoint is disabled when no token is set, rejects wrong tokens (logged), and runs at most once per 30 seconds.
- **If neither exists:** unattended automation cannot run on this plan. Do not add a paid service; jobs stay visible as pending, and an administrator can run the worker from any machine with PHP that can reach the database, or the plan can be reviewed. Free external URL schedulers must be reviewed for payment requirements and reliability before use (not verified here).

Never expose the worker without the token; there is no unauthenticated job-execution endpoint.
