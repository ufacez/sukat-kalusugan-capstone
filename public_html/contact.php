<?php
require_once __DIR__ . '/includes/auth_middleware.php';
start_secure_session();
$user = current_user();
if ($user !== null) { header('Location: ' . redirect_for_current_user($user)); exit; }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Contact | Sukat Kalusugan by Group A4Tech</title>
    <meta name="description" content="Contact Group A4Tech about Sukat Kalusugan accounts, privacy requests, or issues.">
    <link rel="canonical" href="https://sukatkalusugan.app/contact.php">
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
<section class="auth-card" aria-labelledby="contact-title">
<p class="eyebrow">Contact</p>
<h2 id="contact-title">Contact Group A4Tech</h2>
<p class="muted">For account help, privacy requests (access/correction/deletion under RA 10173), or reporting a problem.</p>
<p style="font-size:.95rem;line-height:1.7;">Email: <a class="link" href="mailto:espirituean@gmail.com">espirituean@gmail.com</a><br>Phone: 09614730364<br>Location: Philippines<br>Project: Sukat Kalusugan — OLFU student capstone (not an official OLFU system)</p>
<div class="flash flash-notice" role="status">Do not send passwords. We will never ask for your password by email or phone.</div>
<p><a class="auth-submit" style="text-decoration:none;" href="auth/login.php"><span class="button-label">Sign in</span></a></p>
<p class="auth-card-footer"><a class="link" href="./">Home</a> &middot; <a class="link" href="about.php">About</a> &middot; <a class="link" href="privacy.php">Privacy</a> &middot; <a class="link" href="terms.php">Terms</a></p>
</section>
</main>
</body>
</html>
