<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sukat Kalusugan | 3D Kiosk Showcase</title>
    <meta name="description" content="Sukat Kalusugan 3D Kiosk Showcase - Child Growth Monitoring ESP32 Kiosk">
    <link rel="canonical" href="https://sukatkalusugan.app/3d/">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/site3d.css">
    <link rel="icon" type="image/svg+xml" href="../assets/img/logo/logo_forlight.svg?v=2">
</head>
<body>

    <!-- Navigation -->
    <nav class="nav">
        <div class="nav-logo"><img src="../assets/img/logo/logo_forlight.svg" alt="Sukat Kalusugan"></div>
        <ul class="nav-links">
            <li><a href="#features">Features</a></li>
            <li><a href="#screenshots">Screenshots</a></li>
            <li><a href="#how-it-works">How It Works</a></li>
            <li><a href="#details">Details</a></li>
        </ul>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div id="hero-canvas"></div>
        <div class="hero-overlay">
            <h1>Sukat Kalusugan</h1>
            <p>Child Growth Monitoring — 3D Kiosk Showcase</p>
            <div class="hero-badges">
                <div class="hero-badge">WHO Growth Standards</div>
                <div class="hero-badge">ESP32 Kiosk</div>
                <div class="hero-badge">Real-time Results</div>
            </div>
        </div>
        <div class="scroll-hint">
            <svg viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>
            <span>Scroll to explore</span>
        </div>
    </section>

    <!-- Features Section -->
    <section class="section" id="features">
        <div class="section-head">
            <h2>Features</h2>
            <p>Everything the Sukat Kalusugan kiosk can do, in one view</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h3>WHO Growth Standards</h3>
                <p>Weight-for-Age, Height-for-Age, and Weight-for-Height/Length z-scores based on WHO Child Growth Standards.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📏</div>
                <h3>ESP32 Kiosk</h3>
                <p>HX711 load cell + TF-Luna LiDAR sensor integration for simultaneous height & weight measurement.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📈</div>
                <h3>Real-time Results</h3>
                <p>Instant WHO z-score classification with nutritional status badge (Normal/Underweight/Stunted/Wasted/Overweight/Obese).</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">👥</div>
                <h3>Multi-Role Access</h3>
                <p>Role-based access: Admin, Nutritionist, and Parent — each with their own dashboard and view.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔥</div>
                <h3>Firebase Sync</h3>
                <p>Live cloud mirror of all measurements with device online/offline status sync every 5 seconds.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🛡️</div>
                <h3>Data Privacy</h3>
                <p>Compliant with Philippines Data Privacy Act (RA 10173). All data stored in MySQL with encrypted auth.</p>
            </div>
        </div>
    </section>

    <!-- Screenshots Section -->
    <section class="section" id="screenshots">
        <div class="section-head">
            <h2>Screenshots</h2>
            <p>Your app in action — browser mockups</p>
        </div>
        <div class="screens-grid">
            <!-- Parent Dashboard -->
            <div class="screen-card fade-in">
                <div class="screen-mock">
                    <div class="screen-mockbar">
                        <div class="screen-mockdot r"></div>
                        <div class="screen-mockdot y"></div>
                        <div class="screen-mockdot g"></div>
                    </div>
                    <div class="screen-mock-content">
                        <div class="screen-mock-card"><div class="screen-mock-line"></div><div class="screen-mock-line short"></div><div class="screen-mock-line"></div></div>
                        <div class="screen-mock-card"><div class="screen-mock-line"></div><div class="screen-mock-line short"></div><div class="screen-mock-line"></div></div>
                    </div>
                    <div class="screen-body">
                        <h3>Parent Dashboard</h3>
                        <p>Track your child's growth with WHO charts, view latest measurements, and follow-up alerts.</p>
                    </div>
                </div>
            </div>

            <!-- Nutritionist Dashboard -->
            <div class="screen-card fade-in">
                <div class="screen-mock">
                    <div class="screen-mockbar">
                        <div class="screen-mockdot r"></div>
                        <div class="screen-mockdot y"></div>
                        <div class="screen-mockdot g"></div>
                    </div>
                    <div class="screen-mock-content">
                        <div class="screen-mock-card"><div class="screen-mock-line"></div><div class="screen-mock-line short"></div><div class="screen-mock-line"></div></div>
                        <div class="screen-mock-card"><div class="screen-mock-line"></div><div class="screen-mock-line short"></div><div class="screen-mock-line"></div></div>
                    </div>
                    <div class="screen-body">
                        <h3>Nutritionist Dashboard</h3>
                        <p>WHO growth indicator overview, AI insights, and appointment calendar management.</p>
                    </div>
                </div>
            </div>

            <!-- Admin Dashboard -->
            <div class="screen-card fade-in">
                <div class="screen-mock">
                    <div class="screen-mockbar">
                        <div class="screen-mockdot r"></div>
                        <div class="screen-mockdot y"></div>
                        <div class="screen-mockdot g"></div>
                    </div>
                    <div class="screen-mock-content">
                        <div class="screen-mock-card"><div class="screen-mock-line"></div><div class="screen-mock-line short"></div><div class="screen-mock-line"></div></div>
                        <div class="screen-mock-card"><div class="screen-mock-line"></div><div class="screen-mock-line short"></div><div class="screen-mock-line"></div></div>
                    </div>
                    <div class="screen-body">
                        <h3>Admin Dashboard</h3>
                        <p>User management, device monitoring, and city-wide growth analytics with interactive map.</p>
                    </div>
                </div>
            </div>

            <!-- Kiosk Flow -->
            <div class="screen-card fade-in">
                <div class="screen-mock">
                    <div class="screen-mockbar">
                        <div class="screen-mockdot r"></div>
                        <div class="screen-mockdot y"></div>
                        <div class="screen-mockdot g"></div>
                    </div>
                    <div class="screen-mock-content">
                        <div class="screen-mock-card"><div class="screen-mock-line"></div><div class="screen-mock-line short"></div><div class="screen-mock-line"></div></div>
                        <div class="screen-mock-card"><div class="screen-mock-line"></div><div class="screen-mock-line short"></div><div class="screen-mock-line"></div></div>
                    </div>
                    <div class="screen-body">
                        <h3>Kiosk Measurement Flow</h3>
                        <p>Welcome → Privacy → Child Lookup → Height + Weight → Processing → Results Summary.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="section" id="how-it-works">
        <div class="section-head">
            <h2>How It Works</h2>
            <p>Four simple steps from registration to monitoring</p>
        </div>
        <div class="steps">
            <div class="step">
                <div class="step-number">1</div>
                <h3>Register Child</h3>
                <p>Enter child details: name, birthdate, sex, and barangay. System validates age (0–59 months).</p>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <h3>Measure</h3>
                <p>Place child on platform. Kiosk captures height (TF-LiDAR) and weight (HX711 load cell) simultaneously.</p>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <h3>WHO Analysis</h3>
                <p>System computes z-scores using WHO Child Growth Standards. Nutritional status auto-classified.</p>
            </div>
            <div class="step">
                <div class="step-number">4</div>
                <h3>Monitor & Refer</h3>
                <p>Results saved to Firebase & MySQL. Follow-up cadence set (14 days severe, 30 days moderate). Referral to nutritionist.</p>
            </div>
        </div>
    </section>

    <!-- Details Section -->
    <section class="section" id="details">
        <div class="section-head">
            <h2>Details</h2>
            <p>About Sukat Kalusugan — tech stack & team</p>
        </div>
        <div class="details-grid">
            <div class="detail-card fade-in">
                <h3>Project Info</h3>
                <ul>
                    <li>Capstone project — Our Lady of Fatima University</li>
                    <li>Group A4Tech</li>
                    <li>NOT an official OLFU system</li>
                </ul>
            </div>
            <div class="detail-card fade-in">
                <h3>Tech Stack</h3>
                <ul class="stack-list">
                    <li class="stack-tag">PHP 8</li>
                    <li class="stack-tag">MySQL</li>
                    <li class="stack-tag">ESP32 (HX711 + TF-Luna)</li>
                    <li class="stack-tag">Firebase Realtime</li>
                    <li class="stack-tag">Three.js (web showcase)</li>
                    <li class="stack-tag">Bootstrap-inspired CSS</li>
                </ul>
            </div>
            <div class="detail-card fade-in">
                <h3>Contact</h3>
                <ul>
                    <li>Email: <a href="mailto:espirituean@gmail.com">espirituean@gmail.com</a></li>
                    <li>Phone: 0961-473-0364</li>
                    <li>Location: Philippines</li>
                </ul>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-inner">
            <div>
                <svg viewBox="0 0 24 24" style="width:28px;height:28px;fill:var(--sk-green);margin-bottom:4px;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 2c-4.41 0-8 3.59-8 8s3.59 8 8 8 8-3.59 8-8-3.59-8-8-8zm1.5 8c-.83 0-1.5-.67-1.5-1.5v-2c0-.83.67-1.5 1.5-1.5h6c.83 0 1.5.67 1.5 1.5v2c0 .83-.67 1.5-1.5 1.5h-6z"/></svg>
                <strong>Sukat Kalusugan</strong> — Child Growth Monitoring<br>
                <small>Group A4Tech &bull; OLFU Capstone</small>
            </div>
            <ul class="footer-links">
                <li><a href="#features">Features</a></li>
                <li><a href="#screenshots">Screenshots</a></li>
                <li><a href="#how-it-works">How It Works</a></li>
                <li><a href="#details">Details</a></li>
            </ul>
            <div class="footer-copy">© 2026 Group A4Tech. All rights reserved.</div>
        </div>
    </footer>

    <!-- 3D Kiosk Scene -->
    <script type="module" src="assets/js/site3d.js"></script>

</body>
</html>