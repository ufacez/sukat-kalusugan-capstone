<?php
/**
 * public landing (trust fix for Safe Browsing "Deceptive pages" flag).
 * Public page: no login form here. Logged-in users bounce to their dashboard.
 * Identity: Sukat Kalusugan by Group A4Tech (OLFU capstone, NOT official OLFU system).
 */

require_once __DIR__ . '/includes/auth_middleware.php';

start_secure_session();

$user = current_user();

if ($user !== null) {
    header('Location: ' . redirect_for_current_user($user));
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Sukat Kalusugan by Group A4Tech | Child Growth Monitoring (OLFU Capstone)</title>
    <meta name="description" content="Sukat Kalusugan is a student capstone project by Group A4Tech (Our Lady of Fatima University) for authorized staff and parent accounts to monitor child growth. No public registration. Contact espirituean@gmail.com / 09614730364, Philippines.">
    <link rel="canonical" href="https://sukatkalusugan.app/">
    <meta property="og:title" content="Sukat Kalusugan by Group A4Tech">
    <meta property="og:description" content="OLFU student capstone for authorized child growth monitoring. No public sign-up.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://sukatkalusugan.app/">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/auth.css?v=7">
    <link rel="icon" type="image/svg+xml" href="assets/img/logo/logo_forlight.svg?v=2">
    <script type="application/ld+json">
    {"@context":"https://schema.org","@type":"Organization","name":"Group A4Tech - Sukat Kalusugan","description":"OLFU student capstone project for child growth monitoring. Not an official OLFU system.","url":"https://sukatkalusugan.app/","email":"espirituean@gmail.com","telephone":"+63-961-473-0364","address":{"@type":"PostalAddress","addressCountry":"PH"}}
    </script>
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
        <section class="auth-hero">
            <div class="auth-logo-group">
                <div class="mark-standalone-icon" aria-hidden="true">
                    <img src="assets/img/logo/logo_forlight.svg?v=2" alt="Sukat Kalusugan logo" class="mark-icon-img" data-logo-light="assets/img/logo/logo_forlight.svg?v=2" data-logo-dark="assets/img/logo/logo_fordark.svg?v=2">
                </div>
                <div class="mark-standalone" aria-hidden="true">
                    <img src="assets/img/logo/logotext_forlight.svg?v=2" alt="Sukat Kalusugan" class="mark-standalone-img" data-logo-light="assets/img/logo/logotext_forlight.svg?v=2" data-logo-dark="assets/img/logo/logotext_fordark.svg?v=2">
                </div>
                <p class="auth-tagline">Tamang <span class="hl">Sukat</span>, Gabay sa wastong <span class="hl">Kalusugan</span>.</p>
            </div>
            <div class="auth-footer-row">
                <span class="auth-partner-badge"><span class="badge-dot badge-dot-alt"></span><span>Student capstone by <strong>&nbsp;Group A4Tech</strong>&nbsp;(Our Lady of Fatima University)</span></span>
                <span class="auth-partner-badge"><span class="badge-dot"></span><span>Capstone project — not an official OLFU system. No public registration.</span></span>
            </div>
        </section>
        <section class="auth-card" aria-labelledby="landing-title">
            <div class="auth-card-header">
                <p class="eyebrow">Sukat Kalusugan</p>
                <h2 id="landing-title">Child growth monitoring for authorized users</h2>
                <p class="muted">Built by Group A4Tech as an OLFU capstone. Staff and parents with accounts issued by the project team can sign in to record and review growth measurements.</p>
            </div>
            <div class="flash flash-notice" role="status">Authorized accounts only. Accounts are issued by Group A4Tech — there is no public sign-up. If you arrived via an unknown link, do not enter credentials. Contact us first.</div>
            <ul style="margin:0 0 8px 18px;line-height:1.6;font-size:.92rem;">
                <li>Record weight/height and track WHO growth standards</li>
                <li>Separate staff and parent access with role checks</li>
                <li>Philippines Data Privacy Act (RA 10173) notice in <a class="link" href="privacy.php">Privacy</a></li>
            </ul>
            <p style="display:flex;gap:.6rem;flex-wrap:wrap;margin:12px 0;justify-content:center;">
                <a class="auth-submit" style="text-decoration:none;" href="auth/login.php"><span class="button-label">Sign in</span></a>
                <a class="link" style="align-self:center;" href="about.php">Learn more</a>
            </p>
            <p class="muted" style="font-size:.85rem;">Questions about an account? <a class="link" href="contact.php">Contact Group A4Tech</a> — espirituean@gmail.com / 09614730364, Philippines.</p>
            <p class="auth-card-footer">Group A4Tech &middot; <a class="link" href="about.php">About</a> &middot; <a class="link" href="privacy.php">Privacy</a> &middot; <a class="link" href="terms.php">Terms</a> &middot; <a class="link" href="contact.php">Contact</a><br><span class="muted" style="font-size:.78rem;">Capstone project — not an official OLFU system.</span></p>
        </section>
    </main>
    <script>
    (function(){
        function swapLogos(isDark){
            document.querySelectorAll('[data-logo-light]').forEach(function(img){
                img.src = isDark ? img.getAttribute('data-logo-dark') : img.getAttribute('data-logo-light');
            });
        }
        swapLogos(document.documentElement.getAttribute('data-theme') === 'dark');
    })();
    </script>
</body>
</html>
