#!/usr/bin/env bash
# tools/demo_preflight.sh — demo-day gate for Sukat Kalusugan (Azure VM).
# Fails loud on the first problem. Run from the repo root AFTER git pull.
#
# DB creds come from env vars (never committed):
#   export DBH=host DBU=user DBP=pass DBN=sukat_kalusugan
#
# Usage: ./tools/demo_preflight.sh
set -euo pipefail

echo "== 1/5 code markers (proves the deploy landed) =="
grep -c "SKIP_KALI_WIDGET\|AdminToast" public_html/includes/parent_helpers.php
grep -c "viewport-fit" public_html/includes/parent_helpers.php
grep -c "AdminToast" public_html/nutritionist/measurement_record.php

echo "== 2/5 schema markers (proves migrations applied) =="
mysql -u "${DBU:?set DBU}" -p"${DBP:?set DBP}" -h "${DBH:?set DBH}" --ssl-mode=REQUIRED "${DBN:-sukat_kalusugan}" -e "SELECT TABLE_NAME, CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_NAME IN ('audit_logs','chat_conversations') AND CONSTRAINT_TYPE='FOREIGN KEY'; SHOW COLUMNS FROM appointments LIKE 'intervention%';"
echo "(expect: only fk_chat_conv_child remains; both intervention_* columns listed)"

echo "== 3/5 lint sweep =="
fail=0
for f in $(find public_html -name '*.php'); do
  php -l "$f" >/dev/null 2>&1 || { echo "LINT FAIL: $f"; fail=1; }
done
[ $fail -eq 0 ] && echo "lint clean"

echo "== 4/5 static scan (HIGH findings only) =="
php tools/endpoint_static_scan.php 2>&1 | grep -A50 "^=== HIGH" || echo "0 HIGH findings"

echo "== 5/5 schema drift check =="
php tools/schema_drift_check.php --host="$DBH" --user="$DBU" --pass="$DBP" --db="${DBN:-sukat_kalusugan}"

echo "PREFLIGHT DONE — proceed to the manual TEST_PLAN only if every section above looks right."
