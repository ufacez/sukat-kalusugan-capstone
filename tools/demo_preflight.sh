#!/usr/bin/env bash
# tools/demo_preflight.sh — demo-day readiness gate for Sukat Kalusugan.
# Answers one question before the final presentation:
#   "Is the website OK, with no errors?"  Exit 0 = GO, nonzero = NO-GO.
#
# ALL CHECKS ARE READ-ONLY: SELECT queries, php -l, static analysis, and
# unauthenticated HTTP GETs. No writes, no logins, no emails, no DB changes.
# Safe to run against the live VM any time (it changes nothing).
#
# Usage (from the repo root):
#   ./tools/demo_preflight.sh
#   BASE=https://sukatkalusugan.app ./tools/demo_preflight.sh   # override site
#
# DB creds: exported env vars (preferred), with .env fallback:
#   export DBH=host DBU=user DBP=pass DBN=sukat_kalusugan
#
set -euo pipefail

cd "$(dirname "$0")/.."

fail() { echo "PREFLIGHT FAIL: $1" >&2; exit 1; }
pass() { echo "  ok: $1"; }

# ── DB credentials: env vars first, project .env fallback (read-only) ─────────
if [ -z "${DBU:-}" ] && [ -f .env ]; then
    DBH="${DBH:-$(grep -m1 '^DB_HOST=' .env | cut -d= -f2-)}"
    DBU="${DBU:-$(grep -m1 '^DB_USER=' .env | cut -d= -f2-)}"
    DBP="${DBP:-$(grep -m1 '^DB_PASS=' .env | cut -d= -f2-)}"
    DBN="${DBN:-$(grep -m1 '^DB_NAME=' .env | cut -d= -f2-)}"
fi
DBH="${DBH:-localhost}"
DBN="${DBN:-sukat_kalusugan}"

echo "== 1/6 code markers (proves the deployed code is current) =="
[ "$(grep -c 'function inlineMd' public_html/assets/js/kali_widget.js)" = "1" ] \
    || fail "kali_widget.js marker (Kali AI markdown renderer) missing"
pass "kali_widget.js marker present"
[ "$(grep -c 'Kali AI for barangay nutritionists' public_html/includes/chatbot_helper.php)" = "1" ] \
    || fail "chatbot_helper.php marker (Kali AI prompt) missing"
pass "chatbot_helper.php marker present"
for page in public_html/nutritionist/ai_assistant.php public_html/parent/ai_assistant.php public_html/kiosk/kiosk_index.php public_html/auth/login.php; do
    [ -f "$page" ] || fail "expected page missing: $page"
done
pass "demo pages present (ai_assistant x2, kiosk, login)"

echo "== 2/6 demo-data sanity (read-only SELECTs, live data untouched) =="
[ -n "${DBU:-}" ] || fail "DB creds missing (export DBU/DBP or provide .env)"
Q() { mysql -u "$DBU" -p"$DBP" -h "$DBH" -N -B "$DBN" -e "$1" 2>/dev/null; }
[ "$(Q "SELECT COUNT(*) FROM users WHERE email='admin@sukat.local' AND status='active'")" = "1" ] \
    || fail "active admin@sukat.local account not found"
pass "active admin account present"
[ "$(Q "SELECT COUNT(*) FROM roles")" -ge 2 ] \
    || fail "roles reference data missing"
pass "roles present"
[ "$(Q "SELECT COUNT(*) FROM barangays")" -ge 1 ] \
    || fail "barangays reference data missing"
pass "barangays present"
[ "$(Q "SELECT COUNT(*) FROM who_weight_for_age")" -gt 0 ] \
    || fail "WHO reference data empty"
pass "WHO reference data present"

echo "== 3/6 lint sweep =="
lint_fail=0
for f in $(find public_html -name '*.php'); do
    php -l "$f" >/dev/null 2>&1 || { echo "LINT FAIL: $f"; lint_fail=1; }
done
[ "$lint_fail" -eq 0 ] || fail "php -l errors above"
pass "lint clean"

echo "== 4/6 static scan (HIGH findings only) =="
if php tools/endpoint_static_scan.php > /tmp/preflight_scan.txt 2>&1; then
    pass "0 HIGH findings"
else
    grep -A50 '^=== HIGH' /tmp/preflight_scan.txt || true
    fail "static scan reported HIGH findings (see above)"
fi

echo "== 5/6 schema drift check =="
php tools/schema_drift_check.php --host="$DBH" --user="$DBU" --pass="$DBP" --db="$DBN" \
    || fail "schema drift detected (see above)"
pass "no schema drift"

echo "== 6/6 live demo-path probe (unauthenticated GETs only) =="
BASE="${BASE:-https://sukatkalusugan.app}"
HTTP_BASE="$(printf '%s' "$BASE" | sed 's|^https://|http://|')"
code_of() { curl -sS -o /dev/null --max-time 15 -w '%{http_code}' "$1"; }
body_of() { curl -sS --max-time 15 "$1"; }

# http must redirect to https (never serve the login form over plain HTTP).
# Skipped for plain-http BASE values (local XAMPP rehearsal).
if [[ "$BASE" == https://* ]]; then
    [ "$(code_of "$HTTP_BASE/auth/login.php")" = "301" ] \
        || fail "http:// login did not 301-redirect to https"
    pass "http -> https redirect active"
fi

# login page: reachable, branded, no fatal
login_body="$(body_of "$BASE/auth/login.php")"
[ "$(code_of "$BASE/auth/login.php")" = "200" ] || fail "login page not 200"
printf '%s' "$login_body" | grep -q 'Sukat Kalusugan' || fail "login page branding missing"
printf '%s' "$login_body" | grep -qi 'fatal error' && fail "login page shows a PHP fatal" || true
pass "login page 200, branded, no fatal"

# public trust pages (privacy/terms were part of the Safe Browsing remediation)
for p in '' '/about.php' '/privacy.php' '/terms.php' '/contact.php' '/kiosk/kiosk_index.php' '/auth/forgot-password.php'; do
    url="$BASE$p"
    [ "$(code_of "$url")" = "200" ] || fail "expected 200: $url"
    body_of "$url" | grep -qi 'fatal error' && fail "fatal on $url" || true
done
pass "public pages + kiosk + forgot-password return 200, no fatals"

# protected dashboard while logged out must bounce to login (auth alive, not broken)
dash_headers="$(curl -sS -o /dev/null -D - --max-time 15 "$BASE/admin/dashboard.php")"
printf '%s' "$dash_headers" | head -n1 | grep -q '302' \
    || fail "logged-out dashboard did not 302 (auth middleware may be broken)"
printf '%s' "$dash_headers" | grep -qi 'location:.*/auth/login.php' \
    || fail "dashboard bounce does not point at login"
pass "logged-out dashboard bounces to login"

# HSTS must be present on the https login response
curl -sS -o /dev/null -D - --max-time 15 "$BASE/auth/login.php" \
    | grep -qi 'strict-transport-security' \
    || fail "HSTS header missing on https login"
pass "HSTS present"

echo "PREFLIGHT GO — website OK, no errors. Safe to present."
