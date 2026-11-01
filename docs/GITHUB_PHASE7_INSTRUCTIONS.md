# Committing Phase 7 manually (Windows Command Prompt)

No GitHub access was available; nothing was pushed. The ZIP excludes `.git`. Commands below are written for **cmd.exe**.

```
git checkout develop
git pull
git checkout -b phase/7-softphone-call-management
```
If there is no `develop` branch, start from your latest approved branch instead, then run only the last line. If the branch already exists, use `git checkout phase/7-softphone-call-management`.

Copy the ZIP’s `lti` folder contents over your project (keep your own `server\app\config\config.php`), then:
```
git rm --cached server/app/config/config.dev.php server/app/config/config.test.php 2>nul
git add server/app/migrations/008_phase7.sql server/app/src/Controllers/CallController.php
git commit -m "feat(calls): implement persistent call records"
git add server/app/src/Phone.php server/app/src/Controllers/ContactController.php server/app/bin/seed_dev.php
git commit -m "feat(softphone): phone normalisation, contact numbers and lookup"
git add server/app/src/Controllers/CallbackController.php server/app/src/Worker.php server/app/src/Workflow.php
git commit -m "feat(callbacks): integrate follow-up tasks"
git add web server/public
git commit -m "feat(telephony): connect restored softphone with external dialing and manual logging"
git add server/public/api/index.php server/tests tests run-tests.sh
git commit -m "test(phase7): verify call management and permissions"
git add -A
git status
git commit -m "docs(phase7): capability audit, reports, security, test results"
git push -u origin phase/7-softphone-call-management
```
Before the last commit, check `git status` lists no `config.php`, `config.dev.php`, `config.test.php`, `node_modules` or uploaded files. If `git push` says the branch does not match, run `git branch` and `git log --oneline -3` and send me the output.

Open a **draft** pull request into `develop`; do not merge until you approve.
