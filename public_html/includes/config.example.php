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

// Firebase Realtime Database setup (leave empty until you create the project)
define('FIREBASE_DATABASE_URL', '');
// Example: define('FIREBASE_DATABASE_URL', 'https://your-project-default-rtdb.firebaseio.com');

// Optional: Firebase Realtime Database auth token for server-side REST writes.
// Leave empty if you are using test-mode rules during development.
define('FIREBASE_AUTH_TOKEN', '');

// Growth Result Assistant (AI chatbot on the nutritionist & parent portals).
// Leave CHATBOT_API_KEY empty to keep the chatbot disabled — the widget
// will still render but will show a "not configured" message instead of
// calling out to an external API.
define('CHATBOT_PROVIDER', 'gemini'); // 'openai' | 'gemini'
define('CHATBOT_API_KEY', '');        // your OpenAI or Gemini API key
define('CHATBOT_MODEL', 'gemini-3.5-flash-lite'); // e.g. 'gpt-4o-mini' or 'gemini-1.5-flash'
// Only needed if you use an OpenAI-compatible proxy instead of api.openai.com.
define('CHATBOT_API_URL', '');
