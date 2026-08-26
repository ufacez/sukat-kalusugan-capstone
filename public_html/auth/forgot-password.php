<?php

/**
 * auth/forgot-password.php
 * Public "enter your email" page. Submits to api/auth/forgot_password.php.
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
    <title>Sukat Kalusugan | Forgot Password</title>
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
                    <h1>Locked out happens. Let's get you back in.</h1>
                </div>
            </div>
            <p class="auth-copy">
                Enter the email on your account and we'll send a link to reset your password.
                The link works for both staff and parent accounts.
            </p>
            <ul class="auth-highlights">
                <li>Reset links expire after 30 minutes</li>
                <li>Each link can only be used once</li>
                <li>We never say out loud whether an email has an account</li>
            </ul>
        </section>

        <section class="auth-card" aria-labelledby="forgot-password-title">
            <div class="auth-card-header">
                <p class="eyebrow">Reset your password</p>
                <h2 id="forgot-password-title">Forgot your password?</h2>
                <p class="muted">We'll email you a link to choose a new one.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="flash flash-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if ($notice !== ''): ?>
                <div class="flash flash-notice" role="status"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form class="auth-form" id="forgotPasswordForm" action="../api/auth/forgot_password.php" method="post" novalidate>
                <label class="field" for="email">
                    <span>Email address</span>
                    <input id="email" name="email" type="email" autocomplete="email" placeholder="you@example.com" required>
                </label>

                <div class="form-message" id="formMessage" aria-live="polite"></div>

                <button class="auth-submit" type="submit">
                    <span class="button-label">Send reset link</span>
                    <span class="button-spinner" aria-hidden="true"></span>
                </button>

                <div class="auth-row">
                    <a class="link" href="login.php">&larr; Back to sign in</a>
                </div>
            </form>
        </section>
    </main>

    <script src="../assets/js/auth-forgot-password.js"></script>
</body>

</html>
