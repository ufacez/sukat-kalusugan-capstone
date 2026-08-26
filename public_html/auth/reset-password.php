<?php

/**
 * auth/reset-password.php
 * Landing page for the emailed reset link (?token=...). Renders a new
 * password form that submits to api/auth/reset_password.php.
 *
 * The token itself isn't checked against the database here — that only
 * happens on submit, in api/auth/reset_password.php — so this page can't be
 * used to probe which tokens are valid just by loading it.
 */

require_once __DIR__ . '/../includes/auth_middleware.php';

start_secure_session();

$currentUser = current_user();

if ($currentUser !== null) {
    header('Location: ' . redirect_for_current_user($currentUser));
    exit;
}

$token = trim((string)($_GET['token'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

if ($token === '') {
    header('Location: ' . app_url('/auth/forgot-password.php?error=' . urlencode('That reset link is missing its token.')));
    exit;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sukat Kalusugan | Reset Password</title>
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
            <div class="auth-brand">
                <div class="auth-mark">SK</div>
                <div>
                    <p class="auth-kicker">Sukat Kalusugan</p>
                    <h1>Choose a new password.</h1>
                </div>
            </div>
            <p class="auth-copy">
                Pick something you haven't used before. Once it's saved, this link
                stops working and you'll sign in with your new password.
            </p>
            <ul class="auth-highlights">
                <li>At least 8 characters</li>
                <li>This link can only be used once</li>
            </ul>
        </section>

        <section class="auth-card" aria-labelledby="reset-password-title">
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
                <p class="eyebrow">Almost done</p>
                <h2 id="reset-password-title">Set a new password</h2>
                <p class="muted">Enter and confirm your new password below.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="flash flash-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form class="auth-form" id="resetPasswordForm" action="../api/auth/reset_password.php" method="post" novalidate>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">

                <label class="field" for="password">
                    <span>New password</span>
                    <div class="password-field">
                        <input id="password" name="password" type="password" autocomplete="new-password" placeholder="At least 8 characters" minlength="8" required>
                        <button class="toggle-password" type="button" data-toggle-password aria-label="Show password">Show</button>
                    </div>
                </label>

                <label class="field" for="confirm_password">
                    <span>Confirm new password</span>
                    <div class="password-field">
                        <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" placeholder="Re-enter your new password" minlength="8" required>
                        <button class="toggle-password" type="button" data-toggle-password aria-label="Show password">Show</button>
                    </div>
                </label>

                <div class="form-message" id="formMessage" aria-live="polite"></div>

                <button class="auth-submit" type="submit">
                    <span class="button-label">Reset password</span>
                    <span class="button-spinner" aria-hidden="true"></span>
                </button>

                <div class="auth-row">
                    <a class="link" href="login.php">&larr; Back to sign in</a>
                </div>
            </form>
        </section>
    </main>

    <script src="../assets/js/auth-reset-password.js"></script>
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
