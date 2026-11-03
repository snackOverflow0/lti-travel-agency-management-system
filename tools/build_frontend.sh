#!/bin/sh
# Build the React frontend and deploy it into server/public (the PHP-served web root), replacing the previous build completely,
# then verify the served files are exactly the new build and nothing stale remains. Never edit compiled JavaScript by hand.
set -eu
ROOT=$(cd "$(dirname "$0")/.." && pwd); WEB="$ROOT/web"; PUB="$ROOT/server/public"
cd "$WEB" && npm run build >/tmp/lti-build.log 2>&1 || { cat /tmp/lti-build.log; echo "BUILD FAILED"; exit 1; }
rm -rf "$PUB/assets" "$PUB/index.html" "$PUB/logo.png" "$PUB/prototype"; cp -r "$WEB/dist/"* "$PUB/"
fail=0
for f in $(grep -o 'assets/[^"]*' "$PUB/index.html"); do [ -f "$PUB/$f" ] || { echo "MISSING referenced file $f"; fail=1; }; done
for f in "$PUB"/assets/*; do b="assets/$(basename "$f")"; grep -q "$b" "$PUB/index.html" || { echo "STALE unreferenced file $b"; fail=1; }; done
(cd "$WEB/dist" && find . -type f -exec sha256sum {} + | sort -k2) > /tmp/lti-dist.sha; (cd "$PUB" && for f in $(cd "$WEB/dist" && find . -type f); do sha256sum "$f"; done | sort -k2) > /tmp/lti-pub.sha
cmp -s /tmp/lti-dist.sha /tmp/lti-pub.sha || { echo "SERVED FILES DIFFER FROM BUILD"; fail=1; }
JS=$(grep -o 'assets/index-[^"]*\.js' "$PUB/index.html"); PV=$(grep -o "lti-prototype.js?v=[a-f0-9]*" "$PUB/$JS" | head -1)
echo "Served bundle: $JS ($(wc -c < "$PUB/$JS") bytes); prototype URL: $PV"
[ -n "$PV" ] || { echo "Prototype script is not versioned"; fail=1; }
[ $fail = 0 ] && echo "FRONTEND DEPLOY VERIFIED" || { echo "FRONTEND DEPLOY FAILED"; exit 1; }
