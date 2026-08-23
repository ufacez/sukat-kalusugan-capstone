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
            <svg class="hero-pattern" viewBox="0 0 400 400" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0,320 C80,300 120,340 200,260 C260,200 300,220 400,140" />
                <path d="M0,360 C90,330 140,370 220,300 C280,250 330,270 400,190" />
                <path d="M0,280 C70,260 110,300 190,220 C250,160 290,180 400,100" />
            </svg>

            <div class="mark-standalone" aria-hidden="true">
                <svg viewBox="0 0 48 48" width="58" height="58" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M6 30 L16 34 L24 18 L32 24 L42 10" stroke="var(--primary-strong)" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" />
                    <circle cx="6" cy="30" r="2.6" fill="var(--primary-strong)" />
                    <circle cx="16" cy="34" r="2.6" fill="var(--primary-strong)" />
                    <circle cx="24" cy="18" r="2.6" fill="var(--primary-strong)" />
                    <circle cx="32" cy="24" r="2.6" fill="var(--primary-strong)" />
                    <circle cx="42" cy="10" r="2.6" fill="var(--primary-strong)" />
                </svg>
                <p class="collage-kicker">Sukat<br>Kalusugan</p>
            </div>

            <h1>Sukat Kalusugan</h1>

            <p class="auth-tagline">
               Tamang <span class="hl">Sukat</span>, Eksaktong<span class="hl"> Kalidad</span>.
            </p>

            <div class="auth-footer-row">
                <div class="partner-logos">
                    <!-- Drop the real logo files in here once you have permission to
                         use them -- these are empty, aligned slots until then. -->
                    <div class="logo-slot" title="City Government of San Fernando seal"></div>
                    <div class="logo-slot" title="City Health Office logo"></div>
                </div>
            </div>
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
                    <input id="identifier" name="identifier" type="text" autocomplete="username" placeholder="Enter your email or username" required>
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
                    <a class="link" href="forgot-password.php">Forgot password?</a>
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