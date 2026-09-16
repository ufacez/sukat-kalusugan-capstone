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
    <title>Privacy | Sukat Kalusugan by Group A4Tech</title>
    <meta name="description" content="Privacy notice for Sukat Kalusugan (Group A4Tech capstone): what parent and child health data is collected, why, who can access, and RA 10173 rights.">
    <link rel="canonical" href="https://sukatkalusugan.app/privacy.php">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/auth.css?v=6">
    <link rel="icon" type="image/svg+xml" href="assets/img/logo/logo_forlight.svg?v=2">
</head>
<body class="auth-page">
<main class="auth-shell">
<section class="auth-hero"><div class="auth-logo-group">
<img src="assets/img/logo/logo_forlight.svg?v=2" alt="Sukat Kalusugan logo" class="mark-icon-img" style="width:140px;height:140px;object-fit:contain;">
<p class="auth-tagline">Tamang <span class="hl">Sukat</span>, Gabay sa wastong <span class="hl">Kalusugan</span>.</p>
</div>
<div class="auth-footer-row"><span class="auth-partner-badge"><span class="badge-dot badge-dot-alt"></span><span>Group A4Tech &middot; OLFU Capstone</span></span></div>
</section>
<section class="auth-card" aria-labelledby="privacy-title">
<p class="eyebrow">Privacy notice</p>
<h2 id="privacy-title">How we handle your data</h2>
<p class="muted">Effective 2026. Sukat Kalusugan is a student capstone by <strong>Group A4Tech</strong>. This notice follows the Philippines Data Privacy Act (RA 10173).</p>
<h3>What we collect</h3>
<ul style="line-height:1.6;font-size:.92rem;margin:0 0 8px 18px;">
<li>Account: name, email/username, phone, role, barangay</li>
<li>Parent/child: parent name and contact, child name, birthdate, sex, barangay</li>
<li>Health measurements: weight, height, date, WHO z-scores, appointments</li>
<li>Technical: login timestamps, audit logs for security</li>
</ul>
<h3>Why</h3>
<p style="font-size:.92rem;">To let authorized staff record growth measurements and let parents view their own children's records. No advertising, no selling, no sharing outside the project team and authorized staff.</p>
<h3>Who can see what</h3>
<ul style="line-height:1.6;font-size:.92rem;margin:0 0 8px 18px;">
<li>Parents: only their own children</li>
<li>Staff: children assigned to their barangay/role, enforced by server-side permission checks</li>
<li>No public profiles, no public registration</li>
</ul>
<h3>Security</h3>
<p style="font-size:.92rem;">HTTPS everywhere (except constrained ESP32 device endpoints), hashed passwords, HttpOnly Secure session cookies, role checks on every page/API, audit logging.</p>
<h3>Your rights (RA 10173)</h3>
<p style="font-size:.92rem;">You may request access, correction, or deletion of your and your child's data via <a class="link" href="mailto:espirituean@gmail.com">espirituean@gmail.com</a> / 09614730364. We respond within a reasonable time and delete test data on request.</p>
<h3>Retention</h3>
<p style="font-size:.92rem;">Account and measurement data is kept while your account is active for the capstone evaluation, then archived or deleted on request.</p>
<p class="auth-card-footer"><a class="link" href="./">Home</a> &middot; <a class="link" href="about.php">About</a> &middot; <a class="link" href="terms.php">Terms</a> &middot; <a class="link" href="contact.php">Contact</a><br><span class="muted" style="font-size:.78rem;">Questions: espirituean@gmail.com / 09614730364, Philippines.</span></p>
</section>
</main>
</body>
</html>
