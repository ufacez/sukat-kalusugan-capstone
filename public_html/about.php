<?php
require_once __DIR__ . '/includes/auth_middleware.php';
start_secure_session();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>About | Sukat Kalusugan by Group A4Tech</title>
    <meta name="description" content="About Sukat Kalusugan: OLFU student capstone by Group A4Tech for authorized child growth monitoring. Not an official OLFU system.">
    <link rel="canonical" href="https://sukatkalusugan.app/about.php">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/auth.css?v=7">
    <link rel="icon" type="image/svg+xml" href="assets/img/logo/logo_forlight.svg?v=2">
</head>
<body class="auth-page info-page">
<main class="auth-shell">
<section class="auth-hero"><div class="auth-logo-group">
<img src="assets/img/logo/logo_forlight.svg?v=2" alt="Sukat Kalusugan logo" class="mark-icon-img" style="width:180px;height:180px;object-fit:contain;">
<p class="auth-tagline">Tamang <span class="hl">Sukat</span>, Gabay sa wastong <span class="hl">Kalusugan</span>.</p>
</div>
<div class="auth-footer-row"><span class="auth-partner-badge"><span class="badge-dot badge-dot-alt"></span><span>Group A4Tech &middot; OLFU Capstone</span></span></div>
</section>
<section class="auth-card" aria-labelledby="about-title">
<p class="eyebrow">About</p>
<h2 id="about-title">Sukat Kalusugan by Group A4Tech</h2>
<p class="muted">Sukat Kalusugan is a student capstone project by <strong>Group A4Tech</strong> (Our Lady of Fatima University) for tracking child weight, height, and WHO growth standards.</p>
<div class="flash flash-notice" role="status">Capstone project — not an official OLFU system. No public registration. Accounts are issued by the project team to authorized staff and parents only.</div>
<h3>Who may use it</h3>
<ul style="line-height:1.6;font-size:.92rem;margin:0 0 8px 18px;">
<li>Staff accounts activated by invitation code from an administrator</li>
<li>Parent accounts created by staff for their own children</li>
<li>No self sign-up, no downloads, no payments</li>
</ul>
<h3>Contact</h3>
<p style="font-size:.92rem;">Group A4Tech<br>Email: <a class="link" href="mailto:espirituean@gmail.com">espirituean@gmail.com</a><br>Phone: 09614730364<br>Philippines</p>
<p class="auth-card-footer"><a class="link" href="./">Home</a> &middot; <a class="link" href="privacy.php">Privacy</a> &middot; <a class="link" href="terms.php">Terms</a> &middot; <a class="link" href="contact.php">Contact</a></p>
</section>
</main>
</body>
</html>
