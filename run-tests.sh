#!/bin/sh
# Runs every automated suite against a fresh test database (each suite reseeds). Output in test-output/.
cd "$(dirname "$0")"; mkdir -p test-output
export PW=${PW:-playwright} CHROME=${CHROME:-}
CFG="$PWD/server/app/config/config.test.php"
(cd web && npm run build) > test-output/build.log 2>&1; echo "build exit $?" >> test-output/build.log
( for f in $(find server -name '*.php' -not -path '*/storage/*'); do php -l "$f" | grep -v '^No syntax errors' ; done; echo 'php lint done' ) > test-output/php_lint.log 2>&1
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_test.php http://127.0.0.1:8000 > test-output/api_phase2.log 2>&1
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_phase3_test.php http://127.0.0.1:8000 > test-output/api_phase3.log 2>&1
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_phase4_test.php http://127.0.0.1:8000 > test-output/api_phase4.log 2>&1
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_phase5_test.php http://127.0.0.1:8000 > test-output/api_phase5.log 2>&1
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_phase6_test.php http://127.0.0.1:8000 > test-output/api_phase6.log 2>&1
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_phase7_test.php http://127.0.0.1:8000 > test-output/api_phase7.log 2>&1
./dev-up.sh >/dev/null 2>&1; node tests/e2e/phase7.spec.js > test-output/e2e_phase7.log 2>&1
php server/tests/unit_test.php > test-output/unit.log 2>&1
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_security_test.php http://127.0.0.1:8000 > test-output/api_security.log 2>&1
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_system_flow_test.php http://127.0.0.1:8000 > test-output/api_system_flow.log 2>&1
./dev-up.sh >/dev/null 2>&1; node tests/e2e/phase8.spec.js > test-output/e2e_phase8.log 2>&1
./dev-up.sh >/dev/null 2>&1; node tests/e2e/phase81.spec.js > test-output/e2e_phase81.log 2>&1
./dev-up.sh >/dev/null 2>&1; node tests/e2e/phase81_ops.spec.js > test-output/e2e_phase81_ops.log 2>&1
./dev-up.sh >/dev/null 2>&1; node tests/e2e/phase81_comms.spec.js > test-output/e2e_phase81_comms.log 2>&1
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_library_import_test.php http://127.0.0.1:8000 > test-output/api_library_import.log 2>&1
./dev-up.sh >/dev/null 2>&1; node tests/e2e/library.spec.js > test-output/e2e_library.log 2>&1
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_admin_test.php http://127.0.0.1:8000 > test-output/api_admin.log 2>&1
./dev-up.sh >/dev/null 2>&1; node tests/e2e/phase9.spec.js > test-output/e2e_phase9.log 2>&1
for i in 1 2 3; do ./dev-up.sh >/dev/null 2>&1; node tests/e2e/phase4.spec.js > test-output/e2e_phase4_run$i.log 2>&1; done; cp test-output/e2e_phase4_run3.log test-output/e2e_phase4.log
./dev-up.sh >/dev/null 2>&1; node tests/e2e/phase4b.spec.js > test-output/e2e_phase4b.log 2>&1
./dev-up.sh >/dev/null 2>&1; node tests/e2e/phase3.spec.js > test-output/e2e_phase3.log 2>&1
./dev-up.sh >/dev/null 2>&1; node tests/e2e/restored.spec.js > test-output/e2e_restored.log 2>&1
echo done > test-output/DONE
