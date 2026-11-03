#!/bin/sh
# Backup and restore verification (local/test). Dumps a database and the private documents, restores both into
# isolated targets, and proves the copy is identical and usable. Usage: SRC_DB=lti_test sh tools/backup_restore_verify.sh
set -eu
ROOT=$(cd "$(dirname "$0")/.." && pwd); SRC_DB=${SRC_DB:-lti_test}; DST_DB=${DST_DB:-lti_restore_check}; OUT=${OUT:-/tmp/lti-backup-$(date -u +%Y%m%dT%H%M%SZ)}; MY="mysql -uroot"
mkdir -p "$OUT"; echo "1. Backup database $SRC_DB"; mysqldump -uroot --single-transaction --routines --triggers --hex-blob --default-character-set=utf8mb4 "$SRC_DB" > "$OUT/db.sql"
echo "2. Backup private documents"; tar -C "$ROOT/server/app/storage" -czf "$OUT/documents.tar.gz" documents; (cd "$OUT" && sha256sum db.sql documents.tar.gz > SHA256SUMS)
echo "3. Restore into isolated database $DST_DB"; $MY -e "DROP DATABASE IF EXISTS \`$DST_DB\`; CREATE DATABASE \`$DST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`$DST_DB\`.* TO 'lti'@'localhost'"; $MY "$DST_DB" < "$OUT/db.sql"
echo "4. Compare every table (row count + CHECKSUM TABLE)"; fail=0; n=0
for t in $($MY -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$SRC_DB' ORDER BY table_name"); do n=$((n+1))
  a=$($MY -N -e "SELECT COUNT(*) FROM \`$SRC_DB\`.\`$t\`"); b=$($MY -N -e "SELECT COUNT(*) FROM \`$DST_DB\`.\`$t\`")
  ca=$($MY -N -e "CHECKSUM TABLE \`$SRC_DB\`.\`$t\`" | awk '{print $2}'); cb=$($MY -N -e "CHECKSUM TABLE \`$DST_DB\`.\`$t\`" | awk '{print $2}')
  [ "$a" = "$b" ] && [ "$ca" = "$cb" ] || { echo "   MISMATCH $t rows $a/$b checksum $ca/$cb"; fail=1; }; done
fk=$($MY -N -e "SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema='$DST_DB'"); echo "   $n tables compared; foreign keys in restored copy: $fk"
echo "5. Restore documents into a temporary folder and compare file hashes"; T=$(mktemp -d); tar -C "$T" -xzf "$OUT/documents.tar.gz"
(cd "$ROOT/server/app/storage" && find documents -type f -exec sha256sum {} + | sort) > "$T/src.txt"; (cd "$T" && find documents -type f -exec sha256sum {} + | sort) > "$T/dst.txt"
cmp -s "$T/src.txt" "$T/dst.txt" && echo "   $(wc -l < "$T/src.txt") files identical" || { echo "   FILE MISMATCH"; fail=1; }
echo "6. Run the application against the restored database"; CFG=$(mktemp --suffix=.php); php -r '$c=require getenv("SRC_CFG"); $c["db"]["name"]=getenv("DST_DB"); file_put_contents(getenv("OUT_CFG"), "<?php return ".var_export($c,true).";");' SRC_CFG="$ROOT/server/app/config/config.test.php" DST_DB="$DST_DB" OUT_CFG="$CFG" 2>/dev/null || \
  SRC_CFG="$ROOT/server/app/config/config.test.php" DST_DB="$DST_DB" OUT_CFG="$CFG" php -r '$c=require getenv("SRC_CFG"); $c["db"]["name"]=getenv("DST_DB"); file_put_contents(getenv("OUT_CFG"), "<?php return ".var_export($c,true).";");'
(LTI_CONFIG="$CFG" setsid nohup php -S 127.0.0.1:8003 -t "$ROOT/server/public" "$ROOT/server/dev-router.php" </dev/null >/dev/null 2>&1 &); sleep 1
J=$(mktemp); csrf=$(curl -s -c "$J" -b "$J" -H 'Content-Type: application/json' -d '{"email":"owner@lti.test","password":"Dev-Password-2026"}' http://127.0.0.1:8003/api/auth/login | php -r 'echo json_decode(stream_get_contents(STDIN),true)["csrf"] ?? "";')
api_contacts=$(curl -s -b "$J" "http://127.0.0.1:8003/api/contacts?perPage=1" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["total"] ?? "x";'); db_contacts=$($MY -N -e "SELECT COUNT(*) FROM \`$DST_DB\`.contacts WHERE deleted_at IS NULL")
audit=$(curl -s -b "$J" http://127.0.0.1:8003/api/finance/audit/verify); pkill -f "[1]27.0.0.1:8003" || true
echo "   sign-in: $([ -n "$csrf" ] && echo ok || echo FAILED); contacts via API on restored copy: $api_contacts (database: $db_contacts); financial audit chain: $audit"
[ -n "$csrf" ] && [ "$api_contacts" = "$db_contacts" ] && echo "$audit" | grep -q '"valid":true' || fail=1
echo "Backup set: $OUT"; [ $fail = 0 ] && echo "RESTORE VERIFIED" || { echo "RESTORE VERIFICATION FAILED"; exit 1; }
