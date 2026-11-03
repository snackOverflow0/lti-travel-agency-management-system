# Committing Phase 8 manually (Windows Command Prompt)

Nothing was pushed. The ZIP excludes `.git`.
```
git checkout develop
git pull
git checkout -b phase/8-final-qa-deployment
```
(No `develop`? Start from your latest approved branch. Branch exists? `git checkout phase/8-final-qa-deployment`.) Copy the ZIP's `lti` folder over your project (keep your own `config.php`), then:
```
git rm --cached server/app/config/config.dev.php server/app/config/config.test.php 2>nul
git add server/tests/unit_test.php server/tests/api_security_test.php server/tests/api_system_flow_test.php server/tests/routes_snapshot.json tests/e2e/phase8.spec.js run-tests.sh
git commit -m "test(phase8): add system-wide integration coverage"
git add server/app/src/Auth.php server/app/src/Http.php server/app/bootstrap.php server/public/.htaccess server/dev-router.php server/app/src/Controllers/AuthController.php server/public/api/index.php
git commit -m "fix(security): harden authentication, headers and error handling"
git add web
git commit -m "fix(ui): honest unavailable pages and mobile overflow"
git add server/app/migrations/009_phase8.sql tools/perf_measure.php
git commit -m "perf: add indexes and performance measurement"
git add server/app/config/config.production.example.php server/app/config/config.example.php server/app/config/config.local.example.php tools/backup_restore_verify.sh
git commit -m "chore(deploy): add production configuration and backup verification"
git add -A
git status
git commit -m "docs: add backup, rollback, release guides and scorecard"
git push -u origin phase/8-final-qa-deployment
```
Check `git status` before the last commit (no config.php, node_modules or uploads). Open a **draft** pull request into `develop`. Do not merge into `develop` or `main`, deploy, or change DNS without approval.
