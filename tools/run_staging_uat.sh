#!/bin/sh
# Runs the acceptance suites against the Apache STAGING site (release package, separate database, synthetic data).
cd /home/claude/lti; O=release/uat-results; rm -rf $O; mkdir -p $O/screenshots
export BASE=http://127.0.0.1:8080/lti/ DB=lti_staging LTI_CONFIG=/srv/lti-staging/lti_app/config/config.php SHOTS=$PWD/$O/screenshots PW=/home/claude/.npm-global/lib/node_modules/playwright CHROME=/opt/pw-browsers/chromium-1194/chrome-linux/chrome
(setsid nohup python3 tests/smtp_sink.py 2525 /tmp/lti-smtp-sink </dev/null >/dev/null 2>&1 &); sleep 1
for s in restored phase3 phase4 phase4b phase7 phase81 phase81_ops phase81_comms phase8; do sh tools/staging_reset.sh; SUITE=$s node tests/e2e/$s.spec.js > $O/e2e_$s.log 2>&1; done
sh tools/staging_reset.sh; SUITE=uatC node tests/uat/scenario_c.spec.js > $O/uat_scenario_c.log 2>&1
for t in api_test api_phase3_test api_phase4_test api_phase5_test api_phase6_test api_phase7_test api_security_test api_system_flow_test; do sh tools/staging_reset.sh; php server/tests/$t.php http://127.0.0.1:8080/lti > $O/$t.log 2>&1; done
echo done > $O/DONE
