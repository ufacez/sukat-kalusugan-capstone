<?php
/**
 * Public showcase — standalone, no login, no DB, no internal links.
 * Audience: public only. Do NOT link to auth/kiosk/admin/nutritionist/parent.
 */
declare(strict_types=1);

$teamPhotos = [];
foreach (glob(__DIR__ . '/assets/img/showcase/team-*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
    $teamPhotos[] = 'assets/img/showcase/' . basename($f);
}
sort($teamPhotos);

$kioskShots = [];
foreach (glob(__DIR__ . '/assets/img/showcase/kiosk-*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
    if (strpos(basename($f), 'kiosk-unit') === 0) continue; // unit photo is not a step
    $kioskShots[] = 'assets/img/showcase/' . basename($f);
}
sort($kioskShots);
$kioskShots = array_slice($kioskShots, 0, 4);

function showcase_first(string $pattern): ?string {
    $found = glob(__DIR__ . '/assets/img/showcase/' . $pattern, GLOB_BRACE) ?: [];
    sort($found);
    return $found ? 'assets/img/showcase/' . basename($found[0]) : null;
}
$sensorLidar = showcase_first('sensor-lidar.{jpg,jpeg,png,webp}');
$sensorWeight = showcase_first('sensor-weight.{jpg,jpeg,png,webp}');
$dashDesktop = showcase_first('dash-desktop.{jpg,jpeg,png,webp}');
$dashLaptop = showcase_first('dash-laptop.{jpg,jpeg,png,webp}');
$dashPhone = showcase_first('dash-phone.{jpg,jpeg,png,webp}');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Sukat Kalusugan Showcase | Group A4Tech</title>
    <meta name="description" content="Public showcase of Sukat Kalusugan by Group A4Tech: auto anthropometry kiosk, sensors, dashboards, and team build montage.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/showcase.css?v=10">
    <link rel="icon" type="image/svg+xml" href="assets/img/logo/logo_forlight.svg?v=2">
    <script>
    (function(){
        var t=null;
        try { t=localStorage.getItem("theme"); } catch(e) {}
        if(t==="dark"||(!t&&window.matchMedia("(prefers-color-scheme:dark)").matches)){
            document.documentElement.setAttribute("data-theme","dark");
        }
    })();
    </script>
</head>
<body>
<main class="sk-showcase">

    <header class="sk-hero">
        <img class="sk-hero-logo" src="assets/img/logo/logo_forlight.svg?v=2" alt="Sukat Kalusugan logo">
        <p class="eyebrow">Group A4Tech &middot; OLFU Capstone</p>
        <h1>Sukat Kalusugan</h1>
        <p class="sub">Automatic height + weight kiosk for child growth monitoring, with WHO standards and dashboards for staff and parents.</p>
        <div class="sk-hero-cta">
            <a class="sk-btn" href="#kiosk">See how it works</a>
            <a class="sk-btn ghost" href="#team">Team montage</a>
        </div>
        <div class="sk-badges">
            <span>Public showcase only</span>
            <span>Capstone project</span>
            <span>Not an official OLFU system</span>
        </div>
    </header>

    <section class="sk-section" id="problem">
        <p class="eyebrow">Why we built this</p>
        <h2>The problem</h2>
        <p class="lead">Manual weighing and measuring during growth monitoring is slow and error-prone. Paper records make WHO tracking hard.</p>
        <div class="sk-split">
            <div class="sk-copy">
                <ul>
                    <li><strong>Manual errors</strong> — different staff, different readings for weight and height.</li>
                    <li><strong>Slow records</strong> — paper lists delay reports and follow-ups.</li>
                    <li><strong>No clear history</strong> — parents cannot easily see growth over time.</li>
                    <li><strong>Our answer</strong> — one kiosk that measures automatically, then clean dashboards for admin, nutritionist, and parent.</li>
                </ul>
            </div>
            <div class="sk-side">
                <div class="kiosk-unit-solo" id="kioskUnitSolo">
                    <img src="assets/img/showcase/kiosk-unit.png?v=2" alt="Sukat Kalusugan kiosk unit — height pole, screen, weighing platform" loading="lazy" draggable="false">
                    <div class="device-label">Our kiosk unit — tap to enlarge</div>
                </div>
            </div>
        </div>
    </section>

    <section class="sk-section" id="kiosk">
        <p class="eyebrow">Kiosk process</p>
        <h2>At the kiosk</h2>
        <p class="lead">Swipe the iPad — real kiosk screens. Description changes every slide. Add screenshots as <code>assets/img/showcase/kiosk-01.jpg</code> … <code>kiosk-04.jpg</code>.</p>
        <div class="sk-split">
            <div class="sk-side">
                <div class="device device-tablet" aria-hidden="false">
                    <div class="tablet-screen" id="kioskDeck">
                        <div class="tablet-track" id="kioskTrack">
                            <?php if ($kioskShots): ?>
                                <?php foreach ($kioskShots as $i => $src): ?>
                                    <div class="tablet-slide">
                                        <img src="<?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>" alt="Kiosk screen <?php echo $i + 1; ?>" loading="lazy" draggable="false">
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <div class="tablet-slide is-placeholder">
                                        <b>Step <?php echo $i; ?> — screenshot placeholder</b>
                                        <small>Add <code>kiosk-0<?php echo $i; ?>.jpg</code><br>real kiosk interface</small>
                                    </div>
                                <?php endfor; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="device-label">Kiosk iPad — swipeable</div>
                </div>
            </div>
            <div class="sk-copy">
                <div class="kiosk-desc" id="kioskDesc" aria-live="polite">
                    <span class="sk-step-n" id="kioskStepN">1</span>
                    <div>
                        <strong id="kioskTitle">Welcome — Simulan</strong>
                        <small id="kioskText">Live clock and device online check. Tap Simulan to start.</small>
                    </div>
                </div>
                <div class="sk-deck-ctrl" style="margin-top:.8rem;">
                    <button class="sk-arrow" id="kioskPrev" type="button" aria-label="Previous kiosk step">&#8592;</button>
                    <div class="sk-dots" id="kioskDots"></div>
                    <button class="sk-arrow" id="kioskNext" type="button" aria-label="Next kiosk step">&#8594;</button>
                </div>
                <div class="sk-hint"><span id="kioskPos">1 / <?php echo $kioskShots ? count($kioskShots) : 4; ?></span> &middot; swipe the iPad or tap arrows &middot; tap photo to enlarge</div>
            </div>
        </div>
    </section>

    <section class="sk-section" id="sensors">
        <p class="eyebrow">Inside the kiosk</p>
        <h2>Sensors we use</h2>
        <p class="lead">Two sensors, no manual tape or scale reading.</p>
        <div class="sk-spec-grid">
            <div class="sk-spec">
                <div class="sensor-shot">
                    <?php if ($sensorLidar): ?>
                        <img src="<?php echo htmlspecialchars($sensorLidar, ENT_QUOTES, 'UTF-8'); ?>" alt="TF-Luna LiDAR height sensor" loading="lazy" draggable="false">
                    <?php else: ?>
                        <b>LiDAR photo placeholder</b>
                        <small>Add <code>sensor-lidar.jpg</code></small>
                    <?php endif; ?>
                </div>
                <h3>Height — TF-Luna LiDAR</h3>
                <p>Laser distance sensor above the child.<br><code>height = mounting (182.88 cm) &minus; distance + offset</code><br>Calibrated on empty platform, tuned without reflash.</p>
            </div>
            <div class="sk-spec">
                <div class="sensor-shot">
                    <?php if ($sensorWeight): ?>
                        <img src="<?php echo htmlspecialchars($sensorWeight, ENT_QUOTES, 'UTF-8'); ?>" alt="Load cells and HX711 weight sensor" loading="lazy" draggable="false">
                    <?php else: ?>
                        <b>Load cell photo placeholder</b>
                        <small>Add <code>sensor-weight.jpg</code></small>
                    <?php endif; ?>
                </div>
                <h3>Weight — HX711 + load cell</h3>
                <p>Platform scale sensor under the feet.<br><code>auto-tare, live calibration factor</code><br>Stable-weight check before processing.</p>
            </div>
        </div>
    </section>

    <section class="sk-section" id="roles">
        <p class="eyebrow">Who sees what</p>
        <h2>Dashboards</h2>
        <p class="lead">Three views. Same data, different needs.</p>

        <div class="sk-split">
            <div class="sk-copy">
                <h3 style="margin:0 0 .3rem;">Admin — desktop</h3>
                <ul>
                    <li>Fleet status — which kiosks are online</li>
                    <li>Families and children counts per barangay</li>
                    <li>Staff accounts and activity logs</li>
                </ul>
                <h3 style="margin:.9rem 0 .3rem;">Nutritionist — laptop</h3>
                <ul>
                    <li>WHO charts — WFA / HFA / WFH trends</li>
                    <li>Monitoring lists and eOPT reports</li>
                    <li>Record and verify measurements</li>
                </ul>
                <h3 style="margin:.9rem 0 .3rem;">Parent — phone</h3>
                <ul>
                    <li>Child growth history in simple charts</li>
                    <li>Book and track appointments</li>
                    <li>Ask Kali AI about growth</li>
                </ul>
            </div>
            <div class="sk-side">
                <div class="device-cluster" aria-hidden="true">
                    <div class="device device-desktop cluster-desktop">
                        <div class="screen-slot">
                            <?php if ($dashDesktop): ?>
                                <img src="<?php echo htmlspecialchars($dashDesktop, ENT_QUOTES, 'UTF-8'); ?>" alt="Admin dashboard screenshot" loading="lazy" draggable="false">
                            <?php else: ?>
                                <b>Desktop placeholder</b>
                                <small>Admin screenshot<br>Add <code>dash-desktop.jpg</code></small>
                            <?php endif; ?>
                        </div>
                        <div class="stand"></div>
                        <div class="base"></div>
                    </div>
                    <div class="device device-laptop cluster-laptop">
                        <div class="screen-slot">
                            <?php if ($dashLaptop): ?>
                                <img src="<?php echo htmlspecialchars($dashLaptop, ENT_QUOTES, 'UTF-8'); ?>" alt="Nutritionist dashboard screenshot" loading="lazy" draggable="false">
                            <?php else: ?>
                                <b>Laptop placeholder</b>
                                <small>Nutritionist screenshot<br>Add <code>dash-laptop.jpg</code></small>
                            <?php endif; ?>
                        </div>
                        <div class="keyboard"></div>
                    </div>
                    <div class="device device-phone cluster-phone">
                        <div class="screen-slot">
                            <?php if ($dashPhone): ?>
                                <img src="<?php echo htmlspecialchars($dashPhone, ENT_QUOTES, 'UTF-8'); ?>" alt="Parent dashboard screenshot" loading="lazy" draggable="false">
                            <?php else: ?>
                                <b>Phone placeholder</b>
                                <small>Parent screenshot<br>Add <code>dash-phone.jpg</code></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="device-label cluster-label">Admin &middot; Nutritionist &middot; Parent — placeholders</div>
                </div>
            </div>
        </div>
    </section>

    <section class="sk-section" id="hardware">
        <p class="eyebrow">Under the hood</p>
        <h2>Hardware &amp; software</h2>
        <p class="lead">What the kiosk is made of and where the system runs.</p>
        <div class="sk-spec-grid">
            <div class="sk-spec">
                <h3>Hardware</h3>
                <dl class="hw-table">
                    <div><dt>Microcontroller</dt><dd>ESP32</dd></div>
                    <div><dt>Height sensor</dt><dd>TF-Luna LiDAR</dd></div>
                    <div><dt>Weight sensors</dt><dd>4&times; 50kg load cells + HX711 amp</dd></div>
                    <div><dt>Reading method</dt><dd>10 raw samples, stable snapshot</dd></div>
                    <div><dt>Transport</dt><dd>HTTPS &rarr; Firebase Realtime DB</dd></div>
                </dl>
            </div>
            <div class="sk-spec">
                <h3>Software &amp; hosting</h3>
                <dl class="hw-table">
                    <div><dt>Backend</dt><dd>PHP + MySQL</dd></div>
                    <div><dt>Frontend</dt><dd>Vanilla HTML / CSS / JS</dd></div>
                    <div><dt>Live bridge</dt><dd>Firebase (transient only)</dd></div>
                    <div><dt>Hosting</dt><dd>Azure Virtual Machine</dd></div>
                    <div><dt>Auth</dt><dd>SHA-256 hashing, prepared statements</dd></div>
                </dl>
            </div>
        </div>
    </section>

    <section class="sk-section" id="team">
        <p class="eyebrow">Build montage</p>
        <h2>Team &amp; how we made it</h2>
        <p class="lead">Swipe the cards like a deck. Add photos to <code>assets/img/showcase/team-*.jpg</code>.</p>
        <div class="sk-deck-wrap">
            <div class="sk-deck" id="teamDeck">
                <div class="sk-deck-track">
                    <?php if ($teamPhotos): ?>
                        <?php foreach ($teamPhotos as $i => $src): ?>
                            <div class="sk-card">
                                <img src="<?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>" alt="Team build photo <?php echo $i + 1; ?>" loading="lazy">
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <div class="sk-card">
                                <b>Photo <?php echo $i; ?> — placeholder</b>
                                <small>Add <code>assets/img/showcase/team-0<?php echo $i; ?>.jpg</code> to show the team and build montage here.</small>
                            </div>
                        <?php endfor; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sk-deck-ctrl">
                <button class="sk-arrow" id="teamPrev" type="button" aria-label="Previous photo">&#8592;</button>
                <div class="sk-dots" id="teamDots"></div>
                <button class="sk-arrow" id="teamNext" type="button" aria-label="Next photo">&#8594;</button>
            </div>
            <div class="sk-hint"><span id="teamPos">1 / <?php echo $teamPhotos ? count($teamPhotos) : 5; ?></span> &middot; swipe or tap arrows &middot; tap photo to enlarge</div>
        </div>
    </section>

    <footer class="sk-footer">
        Sukat Kalusugan by Group A4Tech &middot; OLFU Capstone<br>
        Public showcase only — capstone project, not an official OLFU system.
    </footer>

</main>

<div class="sk-lightbox" id="skLightbox" hidden>
    <div class="sk-lightbox-backdrop" id="skLbBackdrop"></div>
    <figure class="sk-lightbox-fig" role="dialog" aria-modal="true" aria-label="Enlarged photo">
        <img id="skLbImg" src="" alt="Enlarged showcase photo">
        <figcaption id="skLbCap"></figcaption>
    </figure>
    <button class="sk-lb-btn sk-lb-close" id="skLbClose" type="button" aria-label="Close enlarged view">&times;</button>
    <button class="sk-lb-btn sk-lb-prev" id="skLbPrev" type="button" aria-label="Previous photo">&#8592;</button>
    <button class="sk-lb-btn sk-lb-next" id="skLbNext" type="button" aria-label="Next photo">&#8594;</button>
</div>

<script src="assets/js/showcase.js?v=7" defer></script>
</body>
</html>
