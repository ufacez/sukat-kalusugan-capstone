<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Sukat Kalusugan 3D Showcase | Group A4Tech OLFU Capstone</title>
    <meta name="description" content="Interactive 3D showcase of Sukat Kalusugan — OLFU Group A4Tech capstone. See how the ESP32 kiosk measures weight & height, classifies WHO growth standards, and syncs to staff and parent dashboards.">
    <link rel="canonical" href="https://sukatkalusugan.app/showcase.php">
    <meta property="og:title" content="Sukat Kalusugan — Interactive 3D Showcase">
    <meta property="og:description" content="Drag to rotate the smart kiosk. Check-in → Measuring → WHO result → Follow-up. OLFU capstone by Group A4Tech.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://sukatkalusugan.app/showcase.php">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/showcase.css?v=2">
    <link rel="icon" type="image/svg+xml" href="assets/img/logo/logo_forlight.svg?v=2">
    <script type="application/ld+json">
    {"@context":"https://schema.org","@type":"Organization","name":"Group A4Tech - Sukat Kalusugan","description":"OLFU student capstone: ESP32 kiosk + WHO growth monitoring. Not an official OLFU system.","url":"https://sukatkalusugan.app/showcase.php","email":"espirituean@gmail.com","telephone":"+63-961-473-0364","address":{"@type":"PostalAddress","addressCountry":"PH"}}
    </script>
</head>
<body class="showcase">
<a class="skip" href="#how">Skip to How it works</a>

<header class="nav">
    <a class="brand" href="./" aria-label="Sukat Kalusugan home">
        <img src="assets/img/logo/logo_forlight.svg?v=2" alt="" width="40" height="40">
        <span><strong>Sukat Kalusugan</strong><small>3D Showcase · Group A4Tech</small></span>
    </a>
    <nav class="nav-links" aria-label="Showcase sections">
        <a href="#viewer">3D Kiosk</a>
        <a href="#how">How it works</a>
        <a href="#roles">Who uses it</a>
        <a href="auth/login.php" class="btn-small">Sign in</a>
    </nav>
</header>

<main>
    <!-- HERO + 3D -->
    <section class="hero" id="viewer">
        <div class="hero-copy">
            <p class="eyebrow">OLFU Capstone · Interactive demo</p>
            <h1>Tamang <span class="hl">Sukat</span>,<br>Gabay sa wastong <span class="hl">Kalusugan</span>.</h1>
            <p class="lede">Drag the smart kiosk to spin it. This is the ESP32 station that weighs and measures children, then classifies growth against WHO standards.</p>
            <div class="hero-cta">
                <a class="btn-primary" href="#how">See how it works</a>
                <a class="btn-ghost" href="about.php">About the project</a>
            </div>
            <ul class="hero-stats" aria-label="At a glance">
                <li><strong>2s</strong><span>heartbeat</span></li>
                <li><strong>180s</strong><span>session timeout</span></li>
                <li><strong>3</strong><span>WHO axes</span></li>
            </ul>
        </div>

        <div class="viewer-wrap" aria-label="Interactive 3D kiosk viewer">
            <div class="viewer-toolbar" role="status" aria-live="polite">
                <span class="dot"></span><span id="viewer-status">Loading 3D…</span>
                <span class="hint">drag to rotate · scroll to zoom</span>
            </div>
            <canvas id="kiosk-canvas" aria-label="Rotatable 3D model of the Sukat Kalusugan kiosk. Drag to rotate."></canvas>
            <div class="viewer-fallback" id="viewer-fallback" hidden>
                <img src="assets/img/logo/logo_forlight.svg?v=2" alt="Sukat Kalusugan logo" width="120" height="120">
                <p><strong>3D unavailable in this browser.</strong><br><span id="viewer-fallback-reason">WebGL may be disabled — enable hardware acceleration and reload.</span><br>Content below still works.</p>
            </div>
            <div class="viewer-controls">
                <button type="button" id="btn-spin" aria-pressed="true" title="Toggle auto-rotate">⏸ Pause spin</button>
                <button type="button" id="btn-reset" title="Reset camera">⟲ Reset view</button>
            </div>
            <p class="model-note" id="model-note">Showing built-in kiosk concept. Drop your <code>assets/models/kiosk.glb</code> to replace it — no code change needed.</p>
        </div>
    </section>

    <!-- HOW IT WORKS -->
    <section class="section" id="how">
        <p class="eyebrow">How it works</p>
        <h2>Check-in → Measuring → WHO result → Follow-up</h2>
        <p class="muted center">Same ordering the real system enforces. Scan this page at the booth, then watch a live measurement.</p>

        <div class="cards" data-tilt-group>
            <article class="card" data-tilt>
                <span class="step">1</span>
                <h3>Check-in</h3>
                <p>Operator finds the child record, kiosk session goes <code>IDLE → START_REQUESTED</code>.</p>
                <span class="tag">kiosk operator</span>
            </article>
            <article class="card" data-tilt>
                <span class="step">2</span>
                <h3>Measuring</h3>
                <p>ESP32 streams weight + height every 2s heartbeat. Operator taps <code>PROCESS</code> → <code>MEASURING</code> locks the reading. 180s timeout guards stalls.</p>
                <span class="tag">ESP32 · HX711 · ultrasonic</span>
            </article>
            <article class="card" data-tilt>
                <span class="step">3</span>
                <h3>WHO result</h3>
                <p>Server classifies 3 independent axes — <strong>WFA · HFA · WFH</strong> — then takes the single most-severe label. No guessing at the booth.</p>
                <span class="tag">eOPT Plus V2</span>
            </article>
            <article class="card" data-tilt>
                <span class="step">4</span>
                <h3>Follow-up</h3>
                <p>Nutritionist reviews on dashboard, parent sees growth curve + AI guidance on their phone. MySQL is source of truth; Firebase is live mirror only.</p>
                <span class="tag">staff + parent</span>
            </article>
        </div>
    </section>

    <!-- ROLES -->
    <section class="section alt" id="roles">
        <p class="eyebrow">Who uses it</p>
        <h2>Authorized accounts only</h2>
        <div class="roles">
            <div class="role">
                <h3>🩺 Staff</h3>
                <p>Activated by invitation code from an admin. Operates kiosk, validates measurements, manages eOPT reports.</p>
            </div>
            <div class="role">
                <h3>👪 Parents</h3>
                <p>Created by staff for their own children. Views growth history, appointments, AI assistant guidance.</p>
            </div>
            <div class="role warn">
                <h3>🚫 No public sign-up</h3>
                <p>No self-registration, downloads, or payments. Accounts are issued by Group A4Tech only.</p>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="cta">
        <h2>At the booth? Ask us for a live weighing.</h2>
        <p>Group A4Tech · Our Lady of Fatima University · <a href="mailto:espirituean@gmail.com">espirituean@gmail.com</a> · 09614730364</p>
        <div class="hero-cta">
            <a class="btn-primary" href="auth/login.php">Sign in (authorized)</a>
            <a class="btn-ghost" href="contact.php">Contact team</a>
        </div>
    </section>
</main>

<footer class="footer">
    <p><strong>Sukat Kalusugan by Group A4Tech</strong> — student capstone, <em>not an official OLFU system</em>.</p>
    <p><a href="./">Home</a> · <a href="about.php">About</a> · <a href="privacy.php">Privacy</a> · <a href="terms.php">Terms</a> · <a href="contact.php">Contact</a></p>
</footer>

<script src="assets/js/vendor/three.min.js?v=128" onerror="(function(){var s=document.createElement('script');s.src='https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';document.head.appendChild(s);})();"></script>
<script src="assets/js/showcase.js?v=4" defer></script>
</body>
</html>
