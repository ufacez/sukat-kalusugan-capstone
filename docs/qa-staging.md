# QA + Pen-test Staging (Codespaces) — Group A4Tech

Staging exists so pen-testing NEVER touches production again (the Safe
Browsing "Deceptive pages" flag on `sukatkalusugan.app` came from testing on
prod). Azure/prod is out of scope here — everything below is Codespaces only.

## 1. Start staging (owner)

1. Open this repo → branch **`codespace`** → `Code ▸ Codespaces ▸ Create codespace`.
2. Wait for `tools/codespaces_setup.sh` (postCreate): `composer install`,
   `db/schema.sql` + migrations, `db/seeders/seed_staging.php`.
3. Create `.env` once per Codespace:
   `cp env.codespaces.example .env` then fill `DB_PASS`, `STAGING_PASSWORD`
   (Codespaces Secrets, staging-only — never prod values) and `APP_URL`
   (the forwarded 8080 URL, set after step 4).
4. Ports panel → port `8080` → Visibility **Public** (only for the test
   window) → copy the `https://…-8080.app.github.dev` URL.
5. Share privately with the tester: staging URL + `STAGING_PASSWORD` +
   seed logins below. After the window: stop the Codespace + rotate the
   password.

## 2. Seed accounts (test data ONLY)

Password for all three: `Stag1ng!Pass`

| Role         | Login                      |
| ------------ | -------------------------- |
| Admin        | `staging_admin@test.local` |
| Nutritionist | `staging_nutri@test.local` |
| Parent       | `staging_parent@test.local` |

Fake child `STAGING-0001` belongs to the staging parent. There is no real
parent/child health data here — never import prod dumps (`*_backup.sql`).

API/curl testing must send header `X-Staging-Key: <STAGING_PASSWORD>`
(to pass the gate without a browser session):
`curl -H "X-Staging-Key: $STAGING_PASSWORD" https://…/api/auth/login.php`

## 3. Rules of engagement (teacher/tester)

- Scope: the staging URL ONLY. Never `sukatkalusugan.app` (production).
- No DoS/load testing (Codespaces is a small shared box).
- No persistent phishing-looking pages; every finding must list exact URL +
  payload + screenshot so it can be cleaned and verified.
- No real personal data — use the seed accounts and fake names only.
- Report format: severity, URL, steps to reproduce, request/response
  excerpt, suggested fix. Send to `espirituean@gmail.com`.

## 4. Protections built into staging

- Shared-password gate (fail-closed when unset) + `X-Staging-Key` header.
- `X-Robots-Tag: noindex, nofollow` on every staging response + `STAGING`
  banner, so crawler-indexed payloads can't re-trigger a Safe Browsing flag.
- `google*.html` verification files, SMTP, Firebase, and AI keys are absent
  in staging by design.

## 5. Cleanup after the window

1. Ports panel → `8080` back to **Private**. Stop the Codespace.
2. Rotate `STAGING_PASSWORD`. Record kept findings in theQA log; the box
   itself is disposable (delete the Codespace if it held anything sensitive).
3. Fixes go to the `codespace` branch → PR → `main` → prod deploy via the
   normal release path (never direct prod edits).
