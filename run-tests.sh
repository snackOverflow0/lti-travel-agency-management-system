#!/bin/sh
# Runs every automated suite against a fresh test database (each suite reseeds). Output in test-output/.
cd "$(dirname "$0")"; mkdir -p test-output
export PW=${PW:-playwright} CHROME=${CHROME:-}
CFG="$PWD/server/app/config/config.test.php"
(cd web && npm run build) > test-output/build.log 2>&1; echo "build exit $?" >> test-output/build.log
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_test.php http://127.0.0.1:8000 > test-output/api_phase2.log 2>&1
./dev-up.sh >/dev/null 2>&1; LTI_CONFIG=$CFG php server/tests/api_phase3_test.php http://127.0.0.1:8000 > test-output/api_phase3.log 2>&1
./dev-up.sh >/dev/null 2>&1; node tests/e2e/phase3.spec.js > test-output/e2e_phase3.log 2>&1
./dev-up.sh >/dev/null 2>&1; node tests/e2e/restored.spec.js > test-output/e2e_restored.log 2>&1
echo done > test-output/DONE
