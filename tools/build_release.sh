#!/bin/sh
# Builds the production release package. Usage: VERSION=1.0.0-rc1 sh tools/build_release.sh
# Output: release/lti-$VERSION/{public_html/lti, lti_app, tools} + VERSION + MANIFEST.sha256 + DEPLOY_README.md, and a ZIP.
set -eu
ROOT=$(cd "$(dirname "$0")/.." && pwd); V=${VERSION:-1.0.0-rc1}; OUT="$ROOT/release/lti-$V"; rm -rf "$OUT"; mkdir -p "$OUT/public_html/lti" "$OUT/lti_app" "$OUT/tools"
echo "1. Frontend production build"; (cd "$ROOT/web" && npx vite build --outDir "$OUT/public_html/lti" --emptyOutDir >/dev/null)
echo "2. Web root files"; cp -r "$ROOT/web/public/prototype" "$OUT/public_html/lti/" 2>/dev/null || true; cp "$ROOT/server/public/.htaccess" "$OUT/public_html/lti/.htaccess"
mkdir -p "$OUT/public_html/lti/api"; cp "$ROOT/server/public/api/index.php" "$ROOT/server/public/api/.htaccess" "$OUT/public_html/lti/api/"
printf "<?php\n// Absolute path of the private lti_app folder (outside the web root). Edit on the server.\nreturn '/home/ACCOUNT/lti_app';\n" > "$OUT/public_html/lti/api/app_path.php.example"
echo "3. Private application (outside the web root)"; cd "$ROOT/server/app"
cp -r src vendor migrations data bootstrap.php "$OUT/lti_app/"; mkdir -p "$OUT/lti_app/bin" "$OUT/lti_app/config" "$OUT/lti_app/storage/documents" "$OUT/lti_app/storage/logs"
cp bin/migrate.php bin/worker.php bin/create_user.php bin/import_revision27_library.php "$OUT/lti_app/bin/"; cp config/config.production.example.php "$OUT/lti_app/config/"
printf "# Deny all web access (defence in depth if this folder is ever under the web root).\nRequire all denied\n" | tee "$OUT/lti_app/.htaccess" "$OUT/lti_app/storage/.htaccess" > /dev/null
touch "$OUT/lti_app/storage/documents/.keep" "$OUT/lti_app/storage/logs/.keep"
cp "$ROOT/tools/probe.php" "$ROOT/tools/hash_password.php" "$OUT/tools/"
echo "$V" > "$OUT/VERSION"; echo "built $(date -u +%Y-%m-%dT%H:%MZ) from $(cd "$ROOT" && git rev-parse --short HEAD)" >> "$OUT/VERSION"
cat > "$OUT/DEPLOY_README.md" <<MD
# LTI release $V
- public_html/lti/  -> upload into the web root (keeps .htaccess files).
- lti_app/          -> upload ABOVE the web root (e.g. /home/ACCOUNT/lti_app). Copy config/config.production.example.php to config/config.php and fill it in.
- public_html/lti/api/app_path.php.example -> rename to app_path.php and set the absolute path of lti_app.
- Database: run lti_app/migrations 001 -> 009 in order (phpMyAdmin or php lti_app/bin/migrate.php). Never run development seed data.
- First owner: LTI_NEW_PASSWORD='...' php lti_app/bin/create_user.php you@agency.com "Full Name" owner (or tools/hash_password.php + phpMyAdmin).
- Health check: GET https://DOMAIN/lti/api/health -> {"ok":true}
- tools/ -> upload only temporarily (probe.php) and delete afterwards.
Full guide: docs/DEPLOYMENT_GUIDE.md in the source ZIP.
MD
for f in data/revision27_library.json data/revision27_settings.json bin/import_revision27_library.php migrations/010_library_import.sql migrations/011_admin_settings.sql; do [ -f "$OUT/lti_app/$f" ] || { echo "MISSING required file lti_app/$f"; exit 1; }; done
for a in $(grep -o 'assets/[^"]*' "$OUT/public_html/lti/index.html"); do [ -f "$OUT/public_html/lti/$a" ] || { echo "MISSING referenced asset $a"; exit 1; }; done
echo "4. Manifest"; (cd "$OUT" && find . -type f ! -name MANIFEST.sha256 | sort | xargs sha256sum > MANIFEST.sha256)
echo "5. Safety checks"; fail=0
for pat in 'Dev-Password-2026' 'lti_local_only' 'lti_test' 'Sup3r-Secret'; do if grep -rIl "$pat" "$OUT" >/dev/null 2>&1; then echo "   FOUND development secret/marker: $pat in $(grep -rIl "$pat" "$OUT" | head -3 | tr '\n' ' ')"; fail=1; fi; done
for f in config/config.php config/config.test.php config/config.dev.php bin/seed_dev.php; do [ -e "$OUT/lti_app/$f" ] && { echo "   FOUND forbidden file $f"; fail=1; }; done
[ -e "$OUT/public_html/lti/dev-router.php" ] && { echo "   FOUND dev router"; fail=1; }
find "$OUT" -name '*.map' -o -name node_modules -o -name .git | grep -q . && { echo "   FOUND source maps / node_modules / .git"; fail=1; } || true
[ $fail = 0 ] || { echo "RELEASE CHECK FAILED"; exit 1; }
(cd "$ROOT/release" && rm -f "lti-$V.zip" && zip -qr "lti-$V.zip" "lti-$V")
echo "RELEASE OK: $(cd "$OUT" && find . -type f | wc -l) files, $(du -sh "$ROOT/release/lti-$V.zip" | cut -f1) zip"
