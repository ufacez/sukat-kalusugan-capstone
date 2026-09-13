<?php
/**
 * config.php
 *
 * Loads environment variables from the project-root .env file (via
 * vlucas/phpdotenv) and exposes them as PHP constants so the rest of
 * the codebase can keep using define()-based constants unchanged.
 *
 * Setup:
 *   1. composer install          (installs phpdotenv)
 *   2. cp .env.example .env      (create your local .env)
 *   3. Edit .env with real values
 *
 * .env itself is gitignored — never commit secrets.
 */

// ── Composer autoloader ──────────────────────────────────────────────────────
$composerAutoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($composerAutoload)) {
    die('Composer autoloader not found. Run <code>composer install</code> in the project root.');
}
require_once $composerAutoload;

// ── Load .env ────────────────────────────────────────────────────────────────
$envPath = __DIR__ . '/../../.env';
if (file_exists($envPath)) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname($envPath));
    $dotenv->safeLoad();           // safeLoad won't throw on missing .env
    $dotenv->required([
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
    ]);
} else {
    // Fallback: if no .env exists, try to keep things working (dev convenience).
    // You'll hit this if someone clones the repo but forgets to create .env.
    error_log('config.php: .env file not found at ' . $envPath);
}

// ── Helper: read from $_ENV with a fallback default ──────────────────────────
// phpcs:ignore MediaWiki.NamingConventions.ValidGlobalName
function env(string $key, $default = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return (string) $default;
    }
    return (string) $value;
}

// ── Database ─────────────────────────────────────────────────────────────────
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'sukat_kalusugan'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// ── Application Environment ──────────────────────────────────────────────────
define('APP_ENV', env('APP_ENV', 'development'));

// ── Canonical public base URL (used for links inside emails) ─────────────────
// Set APP_URL=https://sukatkalusugan.app on live (Azure App Settings).
// Email links must never depend on the request host: a reset requested
// from localhost/XAMPP must still point at the live site, and Azure sits
// behind a proxy where $_SERVER['HTTPS'] sniffing is unreliable.
define('APP_URL', rtrim(env('APP_URL', ''), '/'));

// ── ESP32 Kiosk Device Auth ──────────────────────────────────────────────────
define('ESP32_DEVICE_KEY', env('ESP32_DEVICE_KEY', ''));

// ── Firebase Realtime Database ───────────────────────────────────────────────
define('FIREBASE_DATABASE_URL', env('FIREBASE_DATABASE_URL', ''));
define('FIREBASE_AUTH_TOKEN', env('FIREBASE_AUTH_TOKEN', ''));

// ── SMTP / Email ─────────────────────────────────────────────────────────────
define('SMTP_HOST', env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', env('SMTP_PORT', '587'));
define('SMTP_ENCRYPTION', env('SMTP_ENCRYPTION', 'tls'));
define('SMTP_USER', env('SMTP_USER', ''));
define('SMTP_PASS', env('SMTP_PASS', ''));
define('MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', ''));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'Sukat Kalusugan'));

// ── AI Chatbot (Kali AI) ─────────────────────────────────────────────────
define('CHATBOT_PROVIDER', env('CHATBOT_PROVIDER', 'gemini'));
define('CHATBOT_API_KEY', env('CHATBOT_API_KEY', ''));
define('CHATBOT_MODEL', env('CHATBOT_MODEL', 'gemini-3.5-flash-lite'));
define('CHATBOT_API_URL', env('CHATBOT_API_URL', ''));

// ── Nutritionist Dashboard AI (optional overrides) ───────────────────────────
// Leave empty to fall back to the shared CHATBOT_* values above.
define('NUTRITIONIST_AI_PROVIDER', env('NUTRITIONIST_AI_PROVIDER', ''));
define('NUTRITIONIST_AI_KEY', env('NUTRITIONIST_AI_KEY', ''));
define('NUTRITIONIST_AI_MODEL', env('NUTRITIONIST_AI_MODEL', ''));
