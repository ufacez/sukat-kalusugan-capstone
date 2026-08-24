<?php
/**
 * config.example.php
 * Template for config.php — copy this file to config.php and fill in
 * your real local values. config.php itself is gitignored and should
 * never be committed.
 *
 *   cp public_html/includes/config.example.php public_html/includes/config.php
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'sukat_kalusugan');
define('DB_USER', 'root');
define('DB_PASS', '');   // XAMPP default root password is usually empty

define('APP_ENV', 'development'); // 'development' | 'production'

// Shared secret the ESP32 kiosk sends in an X-Device-Key header on every
// request to public_html/api/esp32/*. Without this, anyone who can reach
// the server (real risk now that it's public, not LAN-only) could submit
// fake measurements just by knowing the device_id string, which is not a
// secret. Generate a real random value for production, e.g. in a
// terminal: php -r "echo bin2hex(random_bytes(32));"
// Must match DEVICE_KEY in the ESP32 firmware (.ino) exactly.
define('ESP32_DEVICE_KEY', '');

// Firebase Realtime Database setup (leave empty until you create the project)
define('FIREBASE_DATABASE_URL', '');
// Example: define('FIREBASE_DATABASE_URL', 'https://your-project-default-rtdb.firebaseio.com');

// Optional: Firebase Realtime Database auth token for server-side REST writes.
// Leave empty if you are using test-mode rules during development.
define('FIREBASE_AUTH_TOKEN', '');

// Outgoing email (password reset links, etc.) via Gmail SMTP + PHPMailer.
// Leave SMTP_USER/SMTP_PASS empty to fall back to PHP's mail() and, if that
// also fails, to logging the link — fine for local dev, not for production.
//
// Setup for Gmail:
//   1. Turn on 2-Step Verification on the Gmail account you're sending from:
//      https://myaccount.google.com/security
//   2. Create an "App Password" (Google Account > Security > 2-Step
//      Verification > App passwords). Gmail will not accept your normal
//      login password for SMTP once 2FA is on.
//   3. Put that 16-character app password below as SMTP_PASS (spaces don't
//      matter, Google shows them in groups of 4 for readability).
//   4. Run `composer install` in the project root so PHPMailer is available
//      (see composer.json) — vendor/autoload.php must exist.
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', '587');           // 587 = STARTTLS (recommended), 465 = SMTPS
define('SMTP_ENCRYPTION', 'tls');     // 'tls' for port 587, 'ssl' for port 465
define('SMTP_USER', '');              // your full Gmail address, e.g. 'yourclinic@gmail.com'
define('SMTP_PASS', '');              // the 16-character Gmail App Password, NOT your Gmail login password
define('MAIL_FROM_ADDRESS', '');      // usually the same as SMTP_USER
define('MAIL_FROM_NAME', 'Sukat Kalusugan');

// Growth Result Assistant (AI chatbot on the nutritionist & parent portals).
// Leave CHATBOT_API_KEY empty to keep the chatbot disabled — the widget
// will still render but will show a "not configured" message instead of
// calling out to an external API.
define('CHATBOT_PROVIDER', 'gemini'); // 'openai' | 'gemini'
define('CHATBOT_API_KEY', '');        // your OpenAI or Gemini API key
define('CHATBOT_MODEL', 'gemini-3.5-flash-lite'); // e.g. 'gpt-4o-mini' or 'gemini-1.5-flash'
// Only needed if you use an OpenAI-compatible proxy instead of api.openai.com.
define('CHATBOT_API_URL', '');