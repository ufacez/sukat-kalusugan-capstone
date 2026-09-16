#!/usr/bin/env bash
# tools/codespaces_setup.sh — one-command Codespaces staging bootstrap.
# Runs automatically via .devcontainer postCreateCommand. TEST DATA ONLY.
# NEVER point this at production: it creates staging creds and fake rows.
#
# Prerequisites (provided by .devcontainer/docker-compose.yml):
#   - MySQL 8 service on host "db" (override with DB_HOST)
#   - .env present (copied from env.codespaces.example, secrets filled in)
#
# Notes:
#   - Base dump is db/sukat_kalusugan_clean_baseline.sql (PII-free: only
#     @sukat.local rows). NEVER use db/schema.sql here (stale demo INSERT that
#     fails + carries a real name/address) nor db/baseline.sql (real gmails).
#   - --skip-ssl: the bundled MariaDB client rejects MySQL 8's self-signed
#     cert; the compose network is private to this box, so plain is fine.
set -euo pipefail

MYSQL="mysql --skip-ssl"

echo "== 0/5 composer dependencies =="
if [ ! -x /usr/local/bin/composer ]; then
  # apt's composer conflicts with this image's PHP; use the official installer.
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi
if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
else
  echo "vendor present"
fi

DBH="${DB_HOST:-db}"
DBN="${DB_NAME:-sukat_staging}"
DBU="${DB_USER:-sukat}"
DBP="${DB_PASS:-}"

if [ ! -f .env ]; then
  echo "ERROR: .env missing. Run: cp env.codespaces.example .env  (then fill secrets)"
  exit 1
fi

# Single source of truth: fill any still-empty DB_* from .env (explicitly
# exported values keep precedence). postCreate runs unattended with no
# DBP prefix, so without this the script would abort on a fresh rebuild.
env_from_file() {
  grep -E "^$1=" .env 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d "\"'" | xargs
}
[ -z "$DBH" ] && DBH="$(env_from_file DB_HOST)"; DBH="${DBH:-db}"
[ -z "$DBN" ] && DBN="$(env_from_file DB_NAME)"; DBN="${DBN:-sukat_staging}"
[ -z "$DBU" ] && DBU="$(env_from_file DB_USER)"; DBU="${DBU:-sukat}"
[ -z "$DBP" ] && DBP="$(env_from_file DB_PASS)"

if [ -z "$DBP" ]; then
  echo "ERROR: DB_PASS is empty. Set it in .env (Codespaces Secret, staging-only)."
  exit 1
fi

echo "== 1/5 waiting for MySQL on $DBH =="
for i in $(seq 1 30); do
  if $MYSQL -h "$DBH" -u "$DBU" -p"$DBP" -e "SELECT 1" >/dev/null 2>&1; then
    echo "mysql up"
    break
  fi
  if [ "$i" -eq 30 ]; then echo "ERROR: mysql never came up"; exit 1; fi
  sleep 2
done

echo "== 2/5 importing PII-free clean baseline into $DBN (fresh rebuild) =="
$MYSQL -h "$DBH" -u "$DBU" -p"$DBP" -e "DROP DATABASE IF EXISTS \`$DBN\`; CREATE DATABASE \`$DBN\` CHARACTER SET utf8mb4;"
$MYSQL -h "$DBH" -u "$DBU" -p"$DBP" "$DBN" < db/sukat_kalusugan_clean_baseline.sql
echo "baseline ok"

echo "== 3/5 applying timestamped migrations (tolerant: logs, continues) =="
> /tmp/staging_migrations.log
for f in $(ls db/20*.sql db/*_migration.sql 2>/dev/null | sort -u); do
  if $MYSQL --force -h "$DBH" -u "$DBU" -p"$DBP" "$DBN" < "$f" >>/tmp/staging_migrations.log 2>&1; then
    echo "  applied: $f"
  else
    echo "  NOTE (already applied or skipped, see log): $f"
  fi
done

echo "== 4/5 seeding TEST data only =="
php db/seeders/seed_staging.php

echo "== 5/5 verifying =="
$MYSQL -h "$DBH" -u "$DBU" -p"$DBP" "$DBN" -e "SELECT (SELECT COUNT(*) FROM users) AS users, (SELECT COUNT(*) FROM parents) AS parents, (SELECT COUNT(*) FROM children) AS children;"
php -l public_html/index.php >/dev/null && echo "lint index ok"
php -l public_html/auth/login.php >/dev/null && echo "lint login ok"

echo "STAGING READY — open the forwarded port 8080 URL. See docs/qa-staging.md"
