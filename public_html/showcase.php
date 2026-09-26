<?php
declare(strict_types=1);
/**
 * Public showcase page — highlights only: full kiosk, three dashboards
 * (nutritionist / parent / admin), and kiosk sensors.
 * No login required. Hand-drawn SVG + photo placeholders only.
 * Standalone: no links into the web app (login, home, contact, etc.).
 */
require_once __DIR__ . '/includes/auth_middleware.php';
start_secure_session();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>The Project | Sukat Kalusugan by Group A4Tech</title>
    <meta name="description" content="Meet Sukat Kalusugan: a Group A4Tech (OLFU) capstone — ESP32 kiosk and web dashboards for Nutritionist, Parent, and Admin. Based on WHO Child Growth Standards.">
    <link rel="canonical" href="https://sukatkalusugan.app/showcase.php">
    <meta property="og:title" content="Sukat Kalusugan — The Project">
    <meta property="og:description" content="Kiosk + web dashboards for child growth monitoring. A Group A4Tech (OLFU) capstone.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://sukatkalusugan.app/showcase.php">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/auth.css?v=7">
    <link rel="stylesheet" href="assets/css/showcase.css?v=2">
    <link rel="icon" type="image/svg+xml" href="assets/img/logo/logo_forlight.svg?v=2">
    <script>
    (function(){
    var t=localStorage.getItem("theme");
    if(t==="dark"||(!t&&window.matchMedia("(prefers-color-scheme:dark)").matches)){
    document.documentElement.setAttribute("data-theme","dark");
    }
    })();
    </script>
</head>
<body class="auth-page info-page">
<main class="auth-shell">

    <!-- ================= HERO ================= -->
    <section class="auth-hero">
        <div class="auth-logo-group">
            <div class="mark-standalone-icon" aria-hidden="true">
                <img src="assets/img/logo/logo_forlight.svg?v=2" alt="Sukat Kalusugan logo" class="mark-icon-img" data-logo-light="assets/img/logo/logo_forlight.svg?v=2" data-logo-dark="assets/img/logo/logo_fordark.svg?v=2">
            </div>
            <div class="mark-standalone" aria-hidden="true">
                <img src="assets/img/logo/logotext_forlight.svg?v=2" alt="Sukat Kalusugan" class="mark-standalone-img" data-logo-light="assets/img/logo/logotext_forlight.svg?v=2" data-logo-dark="assets/img/logo/logotext_fordark.svg?v=2">
            </div>
            <p class="auth-tagline">Tamang <span class="hl">Sukat</span>, Gabay sa wastong <span class="hl">Kalusugan</span>.</p>
            <p class="muted" style="font-size:.88rem;max-width:44ch;text-align:center;margin-top:.8rem;">A Group A4Tech (OLFU) capstone for child growth monitoring — an ESP32 kiosk plus web dashboards, based on WHO Child Growth Standards.</p>
            <p class="sk-cta-row">
                <a class="sk-cta-ghost" href="#sk-kiosk">See the project</a>
            </p>
        </div>
        <div class="auth-footer-row">
            <span class="auth-partner-badge"><span class="badge-dot badge-dot-alt"></span><span>Group A4Tech &middot; OLFU Capstone</span></span>
        </div>
    </section>

    <!-- ================= KIOSK ================= -->
    <section class="auth-card sk-panel" aria-labelledby="sk-kiosk">
        <p class="eyebrow">The Kiosk</p>
        <h2 id="sk-kiosk">Full kiosk unit</h2>
        <p class="muted">Weighing platform, height pole, and touch screen in one stand. The child steps on, stands still, and the result is classified and saved automatically.</p>
        <!-- REPLACE: <img src="assets/img/showcase/kiosk-full.jpg" alt="Full view of the Sukat Kalusugan kiosk"> -->
        <div class="sk-photo-slot is-wide" role="img" aria-label="Photo placeholder: full kiosk view">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="14" r="3.5"/></svg>
            <strong>Photo goes here: full kiosk view</strong>
            <span>Front-facing, well-lit, 16:9. Blur children's faces if any are included.</span>
        </div>
    </section>

    <!-- ================= KIOSK PROCESS ON TABLET ================= -->
    <section class="auth-card sk-panel" aria-labelledby="sk-flow">
        <p class="eyebrow">On the Tablet</p>
        <h2 id="sk-flow">The kiosk process</h2>
        <p class="muted">The touch flow on the kiosk screen — from welcome to result.</p>
        <div class="sk-ipad" aria-hidden="true">
            <div class="sk-ipad-cam"></div>
            <!-- REPLACE with screenshot: kiosk touch screen UI -->
            <div class="sk-screen-slot" role="img" aria-label="Screenshot placeholder: kiosk touch screen">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="14" r="3.5"/></svg>
                <strong>Tablet screenshot</strong>
                <span>Kiosk touch screen</span>
            </div>
            <div class="sk-ipad-home"></div>
        </div>
        <ol class="sk-steps">
            <li class="sk-step"><span class="sk-step-num" aria-hidden="true">1</span><div><strong>Tap START</strong><span>Big button on the welcome screen.</span></div></li>
            <li class="sk-step"><span class="sk-step-num" aria-hidden="true">2</span><div><strong>Privacy + find child</strong><span>Consent notice, then Child ID or name.</span></div></li>
            <li class="sk-step"><span class="sk-step-num" aria-hidden="true">3</span><div><strong>Stand still</strong><span>Height and weight measured live, together.</span></div></li>
            <li class="sk-step"><span class="sk-step-num" aria-hidden="true">4</span><div><strong>See the result</strong><span>WFA, HFA, WFH — auto-saved to the system.</span></div></li>
        </ol>
    </section>

    <!-- ================= DASHBOARDS ================= -->
    <section class="auth-card sk-panel" aria-labelledby="sk-dash">
        <p class="eyebrow">Dashboards</p>
        <h2 id="sk-dash">One system, three screens</h2>
        <p class="muted">Every measurement flows to the dashboard of each role.</p>

        <div class="sk-devices">
            <article class="sk-device-card">
                <div class="sk-laptop-screen" aria-hidden="true">
                    <!-- REPLACE with screenshot: nutritionist dashboard on a laptop -->
                    <div class="sk-screen-slot" role="img" aria-label="Screenshot placeholder: nutritionist dashboard">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="14" r="3.5"/></svg>
                        <strong>Laptop screenshot</strong>
                        <span>Nutritionist dashboard</span>
                    </div>
                </div>
                <div class="sk-laptop-base" aria-hidden="true"></div>
                <span class="sk-role-tag">Laptop &middot; Staff</span>
                <h4>Nutritionist Dashboard</h4>
                <p class="muted">WHO analysis, eOPT reports, monitoring, and appointments.</p>
            </article>

            <article class="sk-device-card">
                <div class="sk-phone" aria-hidden="true">
                    <!-- REPLACE with screenshot: parent dashboard on a phone -->
                    <div class="sk-screen-slot" role="img" aria-label="Screenshot placeholder: parent dashboard">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="14" r="3.5"/></svg>
                        <strong>Phone screenshot</strong>
                        <span>Parent dashboard</span>
                    </div>
                </div>
                <span class="sk-role-tag">Phone &middot; Parent</span>
                <h4>Parent Dashboard</h4>
                <p class="muted">Each child's growth history, status, and appointments.</p>
            </article>

            <article class="sk-device-card">
                <div class="sk-monitor-screen" aria-hidden="true">
                    <!-- REPLACE with screenshot: admin dashboard on a PC -->
                    <div class="sk-screen-slot" role="img" aria-label="Screenshot placeholder: admin dashboard">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1 1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="14" r="3.5"/></svg>
                        <strong>PC screenshot</strong>
                        <span>Admin dashboard</span>
                    </div>
                </div>
                <div class="sk-monitor-stand" aria-hidden="true"></div>
                <div class="sk-monitor-foot" aria-hidden="true"></div>
                <span class="sk-role-tag">PC &middot; Admin</span>
                <h4>Admin Dashboard</h4>
                <p class="muted">Family map, live kiosk fleet status, and users.</p>
            </article>
        </div>
    </section>

    <!-- ================= SENSORS ================= -->
    <section class="auth-card sk-panel" aria-labelledby="sk-sensors">
        <p class="eyebrow">Sensors</p>
        <h2 id="sk-sensors">Inside the kiosk</h2>
        <p class="muted">Three parts do the measuring — all calibrated and monitored.</p>

        <div class="sk-sensors">
            <article class="sk-sensor">
                <!-- REPLACE: <img src="assets/img/showcase/sensor-weight.jpg" alt="Load cell and HX711 board"> -->
                <div class="sk-photo-slot is-square" role="img" aria-label="Photo placeholder: load cell and HX711">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="14" r="3.5"/></svg>
                    <strong>Photo: load cell</strong>
                    <span>HX711 weighing platform</span>
                </div>
                <h4>HX711 + Load Cell</h4>
                <span class="sk-spec">Weight</span>
            </article>

            <article class="sk-sensor">
                <!-- REPLACE: <img src="assets/img/showcase/sensor-lidar.jpg" alt="TF-Luna LiDAR module on the pole"> -->
                <div class="sk-photo-slot is-square" role="img" aria-label="Photo placeholder: TF-Luna LiDAR">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="14" r="3.5"/></svg>
                    <strong>Photo: TF-Luna</strong>
                    <span>LiDAR height sensor</span>
                </div>
                <h4>TF-Luna LiDAR</h4>
                <span class="sk-spec">Height &middot; non-contact</span>
            </article>

            <article class="sk-sensor">
                <!-- REPLACE: <img src="assets/img/showcase/sensor-esp32.jpg" alt="ESP32 board and wiring"> -->
                <div class="sk-photo-slot is-square" role="img" aria-label="Photo placeholder: ESP32 board">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="14" r="3.5"/></svg>
                    <strong>Photo: ESP32</strong>
                    <span>Board and wiring</span>
                </div>
                <h4>ESP32</h4>
                <span class="sk-spec">Brain + WiFi</span>
            </article>
        </div>
    </section>

    <!-- ================= TEAM ================= -->
    <section class="auth-card sk-panel" aria-labelledby="sk-team">
        <p class="eyebrow">Team</p>
        <h2 id="sk-team">Group A4Tech</h2>
        <p style="font-size:.92rem;"><span class="muted">Our Lady of Fatima University — student capstone<br>Email: espirituean@gmail.com<br>Phone: 09614730364 &middot; Philippines</span></p>
        <p class="auth-card-footer"><span class="muted" style="font-size:.78rem;">Capstone project — not an official OLFU system.</span></p>
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
