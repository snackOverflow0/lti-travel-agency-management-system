# Committing Phase 6 (increment 1) manually

No GitHub access was available; nothing was pushed and no pull request exists. The commits below exist only in my working copy; the ZIP excludes `.git`.

```bash
git checkout develop && git pull
git checkout -b phase/6-workflows-automation
# copy the ZIP's lti/ contents over your working tree (keep your own server/app/config/config.php)
git rm --cached server/app/config/config.dev.php server/app/config/config.test.php 2>/dev/null   # local-only configs must not be tracked
git add server/app/migrations/007_phase6.sql server/app/src/Events.php server/app/src/Controllers/TaskController.php
git commit -m "feat(tasks): implement persistent staff task management"
git add server/app/vendor/phpmailer server/app/src/Mailer.php server/app/src/Controllers/EmailController.php && git commit -m "feat(email): add email composer and communication logs"
git add server/app/src/Template.php server/app/src/Controllers/EmailTemplateController.php && git commit -m "feat(templates): implement personalized email templates"
git add server/app/src/Workflow.php server/app/src/Controllers/WorkflowController.php && git commit -m "feat(workflows): implement database-backed workflow engine"
git add server/app/src/Jobs.php server/app/src/Worker.php server/app/bin/worker.php && git commit -m "feat(queue): add PHP MySQL background job processing"
git add server/app/src/Controllers/OpsController.php server/public/api/index.php && git commit -m "feat(notifications): notifications, follow-ups, scheduler endpoint"
git add server/app/src/Controllers/*.php && git commit -m "feat(timeline): persist CRM activity history from real events"
git add server/tests tests run-tests.sh server/app/config/*.example.php && git commit -m "test(phase6): verify tasks email and workflow execution"
git add docs README.md && git commit -m "docs(phase6): audit, scheduler, email, security, test report"
git push -u origin phase/6-workflows-automation
```
Open a **draft** pull request into `develop`. Do not merge until you approve; Phase 5 and Phase 6 screen integration are still outstanding.
