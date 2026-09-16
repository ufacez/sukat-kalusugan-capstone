#!/usr/bin/env bash
# tools/codespaces_setup.sh — one-command Codespaces staging bootstrap.
# Runs automatically via .devcontainer postCreateCommand. TEST DATA ONLY.
# NEVER point this at production: it creates staging creds and fake rows.
#
# Prerequisites (provided by .devcontainer/docker-compose.yml):
#   - MySQL 8 service on host "db" (override with DB_HOST)
#   - .env present (copied from .env.codespaces.example, secrets filled in)
set -euo pipefail

DBH="${DB_HOST:-db}"
DBN="${DB_NAME:-sukat_staging}"
DBU="${DB_USER:-sukat}"
DBP="${DB_PASS:-}"

if [ -z "$DBP" ]; then
  echo "ERROR: DB_PASS is empty. Set it in .env (Codespaces Secret, staging-only)."
  exit 1
fi

if [ ! -f .env ]; then
  echo "ERROR: .env missing. Run: cp .env.codespaces.example .env  (then fill secrets)"
  exit 1
fi

echo "== 1/5 waiting for MySQL on $DBH =="
for i in $(seq 1 30); do
  if mysql -h "$DBH" -u "$DBU" -p"$DBP" -e "SELECT 1" >/dev/null 2>&1; then
    echo "mysql up"
    break
  fi
  if [ "$i" -eq 30 ]; then echo "ERROR: mysql never came up"; exit 1; fi
  sleep 2
done

echo "== 2/5 importing db/schema.sql into $DBN =="
mysql -h "$DBH" -u "$DBU" -p"$DBP" -e "CREATE DATABASE IF NOT EXISTS \`$DBN\` CHARACTER SET utf8mb4;"
mysql -h "$DBH" -u "$DBU" -p"$DBP" "$DBN" < db/schema.sql
echo "schema ok"

echo "== 3/5 applying timestamped migrations (tolerant: logs, continues) =="
> /tmp/staging_migrations.log
for f in $(ls db/20*.sql db/*_migration.sql 2>/dev/null | sort -u); do
  if mysql --force -h "$DBH" -u "$DBU" -p"$DBP" "$DBN" < "$f" >>/tmp/staging_migrations.log 2>&1; then
    echo "  applied: $f"
  else
    echo "  NOTE (already applied or skipped, see log): $f"
  fi
done

echo "== 4/5 seeding TEST data only =="
php db/seeders/seed_staging.php

echo "== 5/5 verifying =="
mysql -h "$DBH" -u "$DBU" -p"$DBP" "$DBN" -e "SELECT (SELECT COUNT(*) FROM users) AS users, (SELECT COUNT(*) FROM parents) AS parents, (SELECT COUNT(*) FROM children) AS children;"
php -l public_html/index.php >/dev/null && echo "lint index ok"
php -l public_html/auth/login.php >/dev/null && echo "lint login ok"

echo "STAGING READY — open the forwarded port 8080 URL. See docs/qa-staging.md"
