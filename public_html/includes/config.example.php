<?php
/**
 * config.example.php
 *
 * This file is kept for backward compatibility, but the recommended way to
 * configure the application is via the .env file in the project root.
 *
 * Quick start:
 *   1. composer install
 *   2. cp .env.example .env
 *   3. Edit .env with your local values
 *
 * config.php (loaded automatically) reads .env and defines all constants
 * below. You should NOT need to edit this file or config.php — just .env.
 *
 * See .env.example for the full list of configuration keys and descriptions.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'sukat_kalusugan');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_ENV', 'development'); // 'development' | 'staging' | 'production'

define('ESP32_DEVICE_KEY', '');

define('FIREBASE_DATABASE_URL', '');
define('FIREBASE_AUTH_TOKEN', '');

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', '587');
define('SMTP_ENCRYPTION', 'tls');
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('MAIL_FROM_ADDRESS', '');
define('MAIL_FROM_NAME', 'Sukat Kalusugan');

define('CHATBOT_PROVIDER', 'gemini');
define('CHATBOT_API_KEY', '');
define('CHATBOT_MODEL', 'gemini-3.5-flash-lite');
define('CHATBOT_API_URL', '');

define('NUTRITIONIST_AI_PROVIDER', '');
define('NUTRITIONIST_AI_KEY', '');
define('NUTRITIONIST_AI_MODEL', '');
