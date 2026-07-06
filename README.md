# Project Folder Structure — Child Nutrition Monitoring System

Procedural PHP 8. No framework. No classes required — every file exposes
plain functions and is `require_once`'d where needed.

## Layout

- `public_html/` — the cPanel webroot. Everything here is publicly reachable.
  - `api/` — JSON endpoints, one file per action, grouped by resource.
    Every file here should: require db.php + auth_middleware.php,
    validate $_POST/$_GET input, run the query, `echo json_encode(...)`.
  - `admin/`, `nutritionist/`, `parent/` — role-specific dashboard pages.
    Each page requires login + calls the matching api/ endpoints via JS fetch().
  - `kiosk/` — the single unauthenticated public kiosk page (tablet-facing).
  - `auth/` — the shared login page.
  - `includes/` — shared procedural function files (the "backend logic layer"):
    - `config.php` — DB/Firebase credentials as constants.
    - `db.php` — one function returning a shared PDO connection.
    - `auth_middleware.php` — session + permission-check functions.
    - `who_calculator.php` — WHO LMS z-score functions.
    - `audit_logger.php` — writes to audit_logs table.
    - `firebase_sync.php` — pushes latest reading to Firebase RTDB.
  - `assets/` — css/js/img, plain files, no build step.
- `db/schema.sql` — the tested, working MySQL schema (already validated against
  a real MariaDB instance — see Phase 1 conversation).
- `docs/` — ERD exports, notes, thesis diagrams.

## Naming convention (procedural style)

- Files = verbs/nouns describing the single action they perform
  (`create.php`, `list.php`, `submit_measurement.php`).
- Functions inside `includes/*.php` = `snake_case`, one clear responsibility
  each (e.g. `calculate_waz()`, `require_permission()`).
- No classes, no namespaces — every file's functions are just available
  after `require_once`.
