# GitHub release instructions (Windows Command Prompt)

Nothing has been pushed. Earlier phase branches may or may not be in your repository; check first:
```
git fetch
git branch -a
```
Create the release branch only from the latest **approved and integrated** code. If Phase 8.1 is not yet merged into `develop`, merge it (with your approval) first, or base the release branch on your Phase 8.1 branch and say so in the pull request.
```
git checkout develop
git pull
git checkout -b release/phase-9-uat-go-live
```
Copy the source ZIP's `lti` folder over your project (keep your own `config.php`), then:
```
git rm --cached server/app/config/config.dev.php server/app/config/config.test.php 2>nul
git add tests/uat tests/e2e run-tests.sh
git commit -m "test(uat): add client acceptance scenarios"
git add web server/public server/app/src
git commit -m "fix(release): resolve UAT defects"
git add tools server/app/config/config.production.example.php .gitignore
git commit -m "chore(deploy): prepare Network Solutions release package"
git add docs
git commit -m "docs(release): add deployment, rollback, UAT sign-off and evidence"
git status
git push -u origin release/phase-9-uat-go-live
git tag v1.0.0-rc3
git push origin v1.0.0-rc3
```
Open a **draft** pull request. Do not merge into `main`, deploy, or change DNS without written approval.
