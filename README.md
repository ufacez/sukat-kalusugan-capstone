# Sukat Kalusugan — Child Nutrition Monitoring System

A procedural PHP 8 application for capturing and monitoring child nutrition measurements. The app is intentionally framework-free and organized as small, single-responsibility PHP files and JSON API endpoints. It includes a tablet-friendly kiosk interface that integrates with an ESP32 device and mirrors live results to Firebase Realtime Database.

Key features

- Role-based dashboards: admin, nutritionist, parent
- Kiosk workflow for quick measurements (ESP32 → PHP → MySQL → Firebase)
- WHO growth score calculations (z-scores) implemented in PHP
- Audit logging for important actions
- Simple, file-based API endpoints under public_html/api/

Tech stack

- PHP 8 (procedural style)
- MySQL / MariaDB
- Firebase Realtime Database (optional live mirror)
- ESP32 firmware (example in docs/firmware)

Repository layout

- [public_html/](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/) — webroot and application UI
  - [public_html/api/](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/api/) — JSON endpoints grouped by resource (children, measurements, auth, esp32, kiosk, reports, admin, etc.)
  - [public_html/kiosk/](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/kiosk/) — tablet-facing kiosk UI (`kiosk_index.php`)
  - [public_html/auth/](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/auth/login.php) — login UI
  - [public_html/includes/](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/) — backend helpers and core logic
    - [includes/config.example.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/config.example.php) — example config (copy to `config.php`)
    - [includes/config.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/config.php) — runtime config (DB / Firebase constants)
    - [includes/db.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/db.php) — PDO connection helper
    - [includes/auth_middleware.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/auth_middleware.php) — session/permission helpers
    - [includes/who_calculator.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/who_calculator.php) — WHO LMS z-score functions
    - [includes/audit_logger.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/audit_logger.php) — audit logging
    - [includes/firebase_sync.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/firebase_sync.php) — optional Firebase mirror logic
- [db/](C:/xampp/htdocs/sukat-kalusugan-capstone/db/) — SQL schema and migration files
  - [db/schema.sql](C:/xampp/htdocs/sukat-kalusugan-capstone/db/schema.sql) — baseline schema
  - migration and seed files (timestamped) for recent changes
- [docs/](C:/xampp/htdocs/sukat-kalusugan-capstone/docs/) — developer notes and asset examples
  - [docs/firebase_setup.md](C:/xampp/htdocs/sukat-kalusugan-capstone/docs/firebase_setup.md) — instructions for enabling Firebase Realtime Database mirror
  - [docs/firmware/esp32_kios_arduino_code.ino](C:/xampp/htdocs/sukat-kalusugan-capstone/docs/firmware/esp32_kios_arduino_code.ino) — example ESP32 firmware sketch

Requirements

- PHP 8 with PDO and pdo_mysql extension enabled
- MySQL / MariaDB
- A web server (XAMPP, WAMP, or similar). The project is arranged so `public_html/` can be used as the webroot.
- (Optional) Firebase project with Realtime Database for live kiosk mirror

Quick start (development)

1. Place the repository in your web server folder. With XAMPP, for example, the current layout already fits under `C:\xampp\htdocs\sukat-kalusugan-capstone`.
2. Ensure [public_html/](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/) is served as the webroot (or point your VirtualHost to that folder). Access the app in a browser at:
   - http://localhost/sukat-kalusugan-capstone/public_html/
3. Create a database for the project and import the schema:
   - Using mysql CLI: mysql -u root -p your_db_name < db/schema.sql
   - Or import `db/schema.sql` in phpMyAdmin.
4. Copy the example config and update database / firebase settings:
   - Copy [public_html/includes/config.example.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/config.example.php) to [public_html/includes/config.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/config.php)
   - Edit DB_HOST, DB_NAME, DB_USER, DB_PASS and (optionally) FIREBASE_DATABASE_URL and FIREBASE_AUTH_TOKEN
5. (Optional) Follow [docs/firebase_setup.md](C:/xampp/htdocs/sukat-kalusugan-capstone/docs/firebase_setup.md) to enable Firebase Realtime Database if you want live kiosk results mirrored
6. Seed any initial data if needed using SQL files in [db/](C:/xampp/htdocs/sukat-kalusugan-capstone/db/)

ESP32 / Kiosk integration

- ESP32 devices post measurements to the PHP backend via endpoints in [public_html/api/esp32/](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/api/esp32/), for example `submit_measurement.php`.
- After MySQL saves a successful measurement, the server optionally writes the latest result to Firebase under `/latest_measurements/<device_id>.json` (see [includes/firebase_sync.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/firebase_sync.php) and [docs/firebase_setup.md](C:/xampp/htdocs/sukat-kalusugan-capstone/docs/firebase_setup.md)).
- The kiosk UI reads Firebase to display live results on the tablet (`public_html/kiosk/kiosk_index.php`).

Developer notes

- Coding style: procedural, small functions in `public_html/includes/`.
- API conventions: endpoints under `public_html/api/` expect to `require_once '../includes/db.php'` and `auth_middleware.php` where appropriate, validate input, perform DB operations and `echo json_encode(...)` results.
- Important helpers:
  - WHO z-score utilities: [public_html/includes/who_calculator.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/who_calculator.php)
  - Audit logging: [public_html/includes/audit_logger.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/audit_logger.php)
  - Measurement session helpers: [public_html/includes/measurement_sessions.php](C:/xampp/htdocs/sukat-kalusugan-capstone/public_html/includes/measurement_sessions.php)

Common tasks

- Reset DB: re-import [db/schema.sql](C:/xampp/htdocs/sukat-kalusugan-capstone/db/schema.sql) and any desired seed files in `db/`.
- Add a new API endpoint: create a single-purpose PHP file under `public_html/api/<resource>/` that returns JSON.

Where to look next

- UI: `public_html/admin/`, `public_html/nutritionist/`, `public_html/parent/`, `public_html/kiosk/` for interface pages.
- API: `public_html/api/` organized by resource and role.
- Database migrations and seeds: `db/` (many timestamped SQL files are included).
- Firebase setup: [docs/firebase_setup.md](C:/xampp/htdocs/sukat-kalusugan-capstone/docs/firebase_setup.md)

If anything in this README is incorrect or missing for your environment, open an issue or submit a PR with the needed corrections.
