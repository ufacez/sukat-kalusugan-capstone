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
    <script>
    (function(){
    var t=localStorage.getItem("theme");
    if(t==="dark"||(!t&&window.matchMedia("(prefers-color-scheme:dark)").matches)){
    document.documentElement.setAttribute("data-theme","dark");
    }
    })();
    </script>
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
                <img src="../assets/images/logo.jpg" alt="Sukat Kalusugan" class="mark-standalone-img">
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
            <button class="auth-theme-toggle" type="button" data-auth-theme-toggle aria-label="Toggle dark mode">
                <span class="auth-theme-toggle-track">
                    <svg class="auth-theme-icon auth-theme-icon--sun" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
                    <svg class="auth-theme-icon auth-theme-icon--moon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/></svg>
                    <span class="auth-theme-toggle-thumb">
                        <svg class="thumb-icon--sun" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><circle cx="12" cy="12" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
                        <svg class="thumb-icon--moon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z"/></svg>
                    </span>
                </span>
            </button>
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
                        <button class="toggle-password" type="button" data-toggle-password aria-label="Show password">
                            <svg class="eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>
                            <svg class="eye-closed" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                        </button>
                    </div>
                </label>

                <div class="auth-row">
                    <label class="checkbox">
                        <input type="checkbox" name="remember" value="1">
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
    <script>
    (function(){
        var toggle = document.querySelector('[data-auth-theme-toggle]');
        if (!toggle) return;

        var current = document.documentElement.getAttribute('data-theme') || 'light';
        var isDark = current === 'dark';

        toggle.addEventListener('click', function(){
            isDark = !isDark;
            document.documentElement.setAttribute('data-theme', isDark ? 'dark' : 'light');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    })();
    </script>
</body>

</html>