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
