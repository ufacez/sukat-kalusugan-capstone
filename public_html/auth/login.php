<?php

/**
 * auth/login.php
 * The public login page (staff + parent share this form; role determines redirect).
 * Renders HTML form, submits to api/auth/login.php via fetch().
 */

require_once __DIR__ . '/../includes/auth_middleware.php';

start_secure_session();

$currentUser = current_user();

if ($currentUser !== null) {
    header('Location: ' . redirect_for_current_user($currentUser));
    exit;
}

$error = trim((string)($_GET['error'] ?? ''));
$notice = trim((string)($_GET['notice'] ?? ''));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sukat Kalusugan | Sign In</title>
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>

<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-hero" aria-hidden="true">
            <div class="auth-brand">
                <div class="auth-mark">SK</div>
                <div>
                    <p class="auth-kicker">Sukat Kalusugan</p>
                    <h1>Monitor growth with a cleaner clinical workflow.</h1>
                </div>
            </div>
            <p class="auth-copy">
                Access the child nutrition monitoring system for staff and parents.
                Manage appointments, measurements, and reports from one secure portal.
            </p>
            <ul class="auth-highlights">
                <li>Role-aware redirect for admin, nutritionist, and parent accounts</li>
                <li>Secure PHP session handling with reusable API endpoints</li>
                <li>Designed to fit the existing wireframe direction</li>
            </ul>
        </section>

        <section class="auth-card" aria-labelledby="sign-in-title">
            <div class="auth-card-header">
                <p class="eyebrow">Welcome back</p>
                <h2 id="sign-in-title">Sign in to continue</h2>
                <p class="muted">Use your staff username/email or parent email and password.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="flash flash-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($notice !== ''): ?>
                <div class="flash flash-notice" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form class="auth-form" id="loginForm" action="../api/auth/login.php" method="post" novalidate>
                <label class="field" for="identifier">
                    <span>Email or username</span>
                    <input id="identifier" name="identifier" type="text" autocomplete="username" placeholder="admin@sukat.ph or johndoe12" required>
                </label>

                <label class="field" for="password">
                    <span>Password</span>
                    <div class="password-field">
                        <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required>
                        <button class="toggle-password" type="button" data-toggle-password aria-label="Show password">Show</button>
                    </div>
                </label>

                <div class="auth-row">
                    <label class="checkbox">
                        <input type="checkbox" name="remember" value="1" disabled>
                        <span>Remember me</span>
                    </label>
                    <a class="link" href="#" aria-disabled="true">Forgot password?</a>
                </div>

                <div class="form-message" id="formMessage" aria-live="polite"></div>

                <button class="auth-submit" type="submit">
                    <span class="button-label">Sign in</span>
                    <span class="button-spinner" aria-hidden="true"></span>
                </button>
            </form>
        </section>
    </main>

    <script src="../assets/js/auth-login.js"></script>
</body>

</html>