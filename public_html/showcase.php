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

$kioskDemo = is_file(__DIR__ . '/assets/video/kiosk-demo.mp4') ? 'assets/video/kiosk-demo.mp4' : null;
$kioskSteps = [
    ['title' => 'Simulan', 'text' => 'Checks the clock and connection, then waits for a tap on Simulan.'],
    ['title' => 'Find the child', 'text' => 'Search by ID or name and confirm the record scoped to the kiosk\'s barangay.'],
    ['title' => 'Live height + weight', 'text' => 'TF-Luna reads height, the load cell reads weight. Stability bars turn green when it\'s ready to process.'],
    ['title' => 'Resulta', 'text' => 'Shows weight, height, and WHO status, then syncs straight to the dashboards.'],
];

function showcase_first(string $pattern): ?string {
    $found = glob(__DIR__ . '/assets/img/showcase/' . $pattern, GLOB_BRACE) ?: [];
    sort($found);
    return $found ? 'assets/img/showcase/' . basename($found[0]) : null;
}

/**
 * Reusable two-column feature section (problem / solution / …).
 * Same structure every time — only the data array changes:
 *   id, label, heading, paragraph, points, images, placeholders, flip.
 * - points: list of ['title' => ..., 'text' => ...] (optional, rendered as-is when empty)
 * - images: list of ['src' => ..., 'alt' => ...] (auto-loaded via glob at top)
 * - placeholders: list of ['file' => ..., 'caption' => ...] filling empty image
 *   slots, so dropping files into assets/img/showcase/ needs no layout edits.
 * - flip: mirror columns (visuals left, text right).
 * - follow: compact closing paragraph (string) or ['label' => ..., 'heading' => ...,
 *   'paragraph' => ...] rendered below the split, no visuals — e.g. the
 *   solution bridge inside the problem container.
 */
function showcase_feature_section(array $s): void {
    $id = (string)($s['id'] ?? 'feature');
    $flip = !empty($s['flip']) ? ' sk-feature--flip' : '';
    $points = $s['points'] ?? [];
    $images = array_slice(array_values($s['images'] ?? []), 0, 3);
    $slots = $images;
    foreach (($s['placeholders'] ?? []) as $ph) {
        if (count($slots) >= 3) break;
        $slots[] = ['placeholder' => true, 'file' => (string)($ph['file'] ?? ''), 'caption' => (string)($ph['caption'] ?? '')];
    }
    ?>
    <section class="sk-section sk-feature<?php echo $flip; ?>" id="<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="sk-split">
            <div class="sk-copy">
                <p class="eyebrow"><?php echo htmlspecialchars((string)($s['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <h2><?php echo htmlspecialchars((string)($s['heading'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="lead"><?php echo htmlspecialchars((string)($s['paragraph'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <?php if ($points): ?>
                    <ul>
                        <?php foreach ($points as $pt): ?>
                            <li><strong><?php echo htmlspecialchars((string)($pt['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong> &mdash; <?php echo htmlspecialchars((string)($pt['text'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <div class="sk-side">
                <div class="sk-feature-visuals">
                    <?php foreach ($slots as $cell): ?>
                        <?php if (!empty($cell['placeholder'])): ?>
                            <div class="sk-feature-ph">
                                <b><?php echo htmlspecialchars($cell['caption'], ENT_QUOTES, 'UTF-8'); ?></b>
                                <small>Add <code><?php echo htmlspecialchars($cell['file'], ENT_QUOTES, 'UTF-8'); ?></code></small>
                            </div>
                        <?php else: ?>
                            <div class="sk-feature-cell">
                                <img src="<?php echo htmlspecialchars($cell['src'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($cell['alt'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" draggable="false">
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        $follow = $s['follow'] ?? null;
        $followLabel = '';
        $followHeading = '';
        $followPara = '';
        if (is_string($follow)) {
            $followPara = $follow;
        } elseif (is_array($follow)) {
            $followLabel = (string)($follow['label'] ?? '');
            $followHeading = (string)($follow['heading'] ?? '');
            $followPara = (string)($follow['paragraph'] ?? '');
        }
        ?>
        <?php if ($followPara !== ''): ?>
            <div class="sk-feature-follow">
                <?php if ($followLabel !== ''): ?>
                    <p class="eyebrow"><?php echo htmlspecialchars($followLabel, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <?php if ($followHeading !== ''): ?>
                    <h3><?php echo htmlspecialchars($followHeading, ENT_QUOTES, 'UTF-8'); ?></h3>
                <?php endif; ?>
                <p><?php echo htmlspecialchars($followPara, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>
    </section>
    <?php
}
$problemShots = [];
foreach (glob(__DIR__ . '/assets/img/showcase/problem-*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
    $problemShots[] = 'assets/img/showcase/' . basename($f);
}
sort($problemShots);
$problemAlts = [
    'Paper OPT form used during manual growth monitoring',
    'Manual weighing of a child on a platform scale',
    'Manual height measuring with a tape or stadiometer',
];
$problemImages = [];
foreach (array_slice($problemShots, 0, 3) as $i => $src) {
    $problemImages[] = ['src' => $src, 'alt' => $problemAlts[$i] ?? ('Problem photo ' . ($i + 1))];
}
$sensorLidar = showcase_first('sensor-lidar.{jpg,jpeg,png,webp}');
$sensorWeight = showcase_first('sensor-weight.{jpg,jpeg,png,webp}');
$dashDesktop = showcase_first('dash-desktop.{jpg,jpeg,png,webp}');
$dashLaptop = showcase_first('dash-laptop.{jpg,jpeg,png,webp}');
$dashPhone = showcase_first('dash-phone.{jpg,jpeg,png,webp}');

/**
 * Feature cards ("What it does") — a swipeable row, one card per feature
 * (screenshot on top, role chips + title + one line below). Mobile first.
 * Add/edit features here only.
 *   slug     screenshot file: assets/img/showcase/feature-<slug>.jpg (png/webp ok)
 *   device   'phone' (portrait shot, shown whole) or 'desktop' (landscape, top-cropped)
 *   fallback [role, index] borrows an existing role-<role>-N.jpg until the
 *            feature-<slug> file exists (null = show the labeled placeholder)
 */
function showcase_role_images(string $role): array {
    $found = glob(__DIR__ . '/assets/img/showcase/role-' . $role . '-*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [];
    sort($found);
    $out = [];
    foreach ($found as $f) {
        $out[] = 'assets/img/showcase/' . basename($f);
    }
    return $out;
}
$spotFeatures = [
    [
        'slug' => 'kali', 'device' => 'phone',
        'title' => 'Ask Kali, anytime.',
        'text' => 'Quick answers about a child’s growth, based on their own measurement history.',
        'alt' => 'Kali AI chat answering a question about a child’s growth',
        'fallback' => ['parent', 1],
    ],
    [
        'slug' => 'who', 'device' => 'desktop',
        'title' => 'WHO Child Growth Standards.',
        'text' => 'Every child is measured and classified against the international WHO standard, covering WFA, HFA, and WFH.',
        'alt' => 'WHO growth analysis with weight, height, and nutritional status per child',
        'fallback' => ['nutritionist', 0],
    ],
    [
        'slug' => 'monitoring', 'device' => 'desktop',
        'title' => 'Monthly and quarterly, automatic.',
        'text' => 'Growth monitoring is tracked every month and quarter on its own, with no paper logs or manual tallying.',
        'alt' => 'Monthly and quarterly growth monitoring summary',
        'fallback' => null,
    ],
    [
        'slug' => 'reports', 'device' => 'desktop',
        'title' => 'Reports that match eOPT forms.',
        'text' => 'Ready-to-submit exports, with no retyping from paper lists.',
        'alt' => 'eOPT form report export page',
        'fallback' => null,
    ],
    [
        'slug' => 'kiosks', 'device' => 'desktop',
        'title' => 'Every kiosk, one glance.',
        'text' => 'See which kiosks are online across every barangay in real time.',
        'alt' => 'Admin map showing kiosk status across barangays',
        'fallback' => ['admin', 0],
    ],
    [
        'slug' => 'growth', 'device' => 'phone',
        'title' => 'Growth, over time.',
        'text' => 'Weight and height history for your child, all in one view.',
        'alt' => 'Parent dashboard with a child’s weight and height history chart',
        'fallback' => ['parent', 0],
    ],
];
foreach ($spotFeatures as &$sf) {
    $sf['img'] = showcase_first('feature-' . $sf['slug'] . '.{jpg,jpeg,png,webp}');
    if (!$sf['img'] && !empty($sf['fallback'])) {
        $pool = showcase_role_images($sf['fallback'][0]);
        $sf['img'] = $pool[$sf['fallback'][1]] ?? null;
    }
}
unset($sf);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Sukat Kalusugan Showcase</title>
    <meta name="description" content="Public showcase of Sukat Kalusugan: auto anthropometry kiosk, sensors, and dashboards.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/showcase.css?v=22">
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

    <header class="sk-hero sk-hero--dark">
        <svg class="hero-pattern" viewBox="0 0 400 400" preserveAspectRatio="none" aria-hidden="true">
            <path d="M0,320 C80,300 120,340 200,260 C260,200 300,220 400,140" />
            <path d="M0,360 C90,330 140,370 220,300 C280,250 330,270 400,190" />
            <path d="M0,280 C70,260 110,300 190,220 C250,160 290,180 400,100" />
        </svg>
        <img class="sk-hero-logo" src="assets/img/logo/logo_forlight.svg?v=2" data-logo-light="assets/img/logo/logo_forlight.svg?v=2" data-logo-dark="assets/img/logo/logo_fordark.svg?v=2" alt="Sukat Kalusugan logo">
        <h1 class="sk-hero-title">
            <img class="sk-hero-logotext" src="assets/img/logo/logotext_forlight.svg?v=2" data-logo-light="assets/img/logo/logotext_forlight.svg?v=2" data-logo-dark="assets/img/logo/logotext_fordark.svg?v=2" alt="Sukat Kalusugan">
        </h1>
        <p class="sk-hero-tagline">Tamang <span class="hl">Sukat</span>, Gabay sa wastong <span class="hl">Kalusugan</span>.</p>
        <p class="sub">A Smart Kiosk-Based Anthropometric Monitoring System with a Web Application for the City Health Office in the City of San Fernando, Pampanga</p>
    </header>

    <?php showcase_feature_section([
        'id' => 'problem',
        'label' => 'Why we built this',
        'heading' => 'The Problem',
        'paragraph' => 'The eOPT Plus program still depends on manual logging and paper-based records for monthly and quarterly growth monitoring. Even with careful measuring, tracking this data by hand makes reporting slow, consolidation error-prone, and growth history hard for both nutritionists and parents to follow over time.',
        'images' => $problemImages,
        'placeholders' => [
            ['file' => 'problem-01.jpg', 'caption' => 'Paper OPT form'],
            ['file' => 'problem-02.jpg', 'caption' => 'Manual weighing'],
            ['file' => 'problem-03.jpg', 'caption' => 'Manual measuring'],
        ],
        'follow' => [
            'heading' => 'The solution',
            'paragraph' => 'To solve this, the team built an automatic measurement kiosk that logs height and weight directly into the system, following the WHO Child Growth Standards and the eOPT Plus program guidelines. This replaces manual paper logging with centralized, real-time records making reporting faster and giving parents, nutritionists, and administrators a clear, consistent view of each child\'s growth history.',
        ],
    ]); ?>

    <section class="sk-section sk-feature" id="meet-kiosk">
        <p class="eyebrow">Meet the kiosk</p>
        <h2>What&apos;s inside</h2>
        <p class="lead">Tap a marker to learn about that part.</p>
        <div class="sk-hotspot-stage" id="kioskStage">
            <img src="assets/img/showcase/kiosk-unit.png?v=2" alt="Sukat Kalusugan kiosk unit — height pole, screen, weighing platform" loading="lazy" draggable="false">
            <button class="sk-hotspot" type="button" style="--hx:50%;--hy:4%;" data-part="1" aria-expanded="false" aria-label="Part 1: TF-Luna LiDAR sensor">1</button>
            <button class="sk-hotspot" type="button" style="--hx:50%;--hy:26%;" data-part="2" aria-expanded="false" aria-label="Part 2: Tablet Kiosk screen">2</button>
            <button class="sk-hotspot" type="button" style="--hx:64%;--hy:31%;" data-part="3" aria-expanded="false" aria-label="Part 3: ESP32 microcontroller">3</button>
            <button class="sk-hotspot" type="button" style="--hx:50%;--hy:79%;" data-part="4" aria-expanded="false" aria-label="Part 4: Load cells and HX711">4</button>
            <div class="sk-part-modal" id="kioskModal" hidden>
                <div class="sk-part-modal-card" role="dialog" aria-labelledby="kioskModalTitle">
                    <button class="sk-hotspot-close" id="kioskModalClose" type="button" aria-label="Close part details">&times;</button>
                    <div class="sk-part-modal-media">
                        <img id="kioskModalImg" src="" alt="" loading="lazy" draggable="false">
                        <div class="sk-part-modal-ph" id="kioskModalPh" hidden>
                            <b>Part photo placeholder</b>
                            <small>Add <code id="kioskModalPhFile"></code></small>
                        </div>
                    </div>
                    <h3 id="kioskModalTitle"></h3>
                    <div class="sk-part-stats" id="kioskModalSpecs"></div>
                    <p id="kioskModalText" aria-live="polite"></p>
                </div>
            </div>
        </div>
    </section>

    <section class="sk-section" id="kiosk">
        <p class="eyebrow">Kiosk process</p>
        <h2>At the kiosk</h2>
        <p class="lead">Watch a full session in seconds from Start to End of kiosk interactive display.</p>
        <div class="sk-split">
            <div class="sk-side">
                <div class="sk-video-wrap">
                    <?php if ($kioskDemo): ?>
                        <video src="<?php echo htmlspecialchars($kioskDemo, ENT_QUOTES, 'UTF-8'); ?>" autoplay muted loop playsinline preload="metadata" aria-label="Kiosk walkthrough video"></video>
                    <?php else: ?>
                        <div class="sk-video-ph">
                            <b>Kiosk walkthrough</b>
                            <small>Add <code>assets/video/kiosk-demo.mp4</code><br>8&ndash;15s clip, autoplay loop</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sk-copy">
                <ol class="sk-steps">
                    <?php foreach ($kioskSteps as $i => $step): ?>
                        <li>
                            <span class="sk-step-n"><?php echo $i + 1; ?></span>
                            <div>
                                <strong><?php echo htmlspecialchars($step['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small><?php echo htmlspecialchars($step['text'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    </section>

    <section class="sk-section sk-features" id="features">
        <p class="eyebrow">The Web App</p>
        <h2>What Sukat Kalusugan can do</h2>
        <p class="lead">Take a look around. Tap any screen to see it bigger.</p>

        <div class="sk-feat-track" id="skFeatTrack" tabindex="0" role="group" aria-label="Feature highlights, swipe sideways to browse">
            <?php foreach ($spotFeatures as $i => $f): ?>
                <article class="sk-feat-card<?php echo $i === 0 ? ' is-active' : ''; ?>">
                    <div class="sk-feat-media<?php echo $f['device'] === 'phone' ? ' is-phone' : ''; ?>">
                        <?php if ($f['img']): ?>
                            <img src="<?php echo htmlspecialchars($f['img'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($f['alt'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $i < 2 ? '' : ' loading="lazy"'; ?> draggable="false">
                        <?php else: ?>
                            <div class="sk-feat-ph">
                                <b><?php echo htmlspecialchars($f['title'], ENT_QUOTES, 'UTF-8'); ?></b>
                                <small>Add <code>assets/img/showcase/feature-<?php echo htmlspecialchars($f['slug'], ENT_QUOTES, 'UTF-8'); ?>.jpg</code></small>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="sk-feat-body">
                        <h3><?php echo htmlspecialchars($f['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p><?php echo htmlspecialchars($f['text'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="sk-deck-ctrl sk-feat-ctrl">
            <div class="sk-dots" id="skFeatDots"></div>
        </div>
    </section>

    <?php
    $flowSteps = [
        [
            'label' => 'Sensors', 'sub' => 'Measure',
            'icon' => '<polyline points="8 18 12 22 16 18"/><polyline points="8 6 12 2 16 6"/><line x1="12" y1="2" x2="12" y2="22"/>',
            'title' => 'Sensors on the kiosk',
            'rows' => [['Height sensor', 'TF-Luna LiDAR'], ['Weight sensors', '4× 50kg load cells + HX711 amp']],
        ],
        [
            'label' => 'ESP32', 'sub' => 'Process',
            'icon' => '<rect x="6" y="6" width="12" height="12" rx="2"/><rect x="10" y="10" width="4" height="4"/><line x1="9" y1="2" x2="9" y2="6"/><line x1="15" y1="2" x2="15" y2="6"/><line x1="9" y1="18" x2="9" y2="22"/><line x1="15" y1="18" x2="15" y2="22"/><line x1="2" y1="9" x2="6" y2="9"/><line x1="2" y1="15" x2="6" y2="15"/><line x1="18" y1="9" x2="22" y2="9"/><line x1="18" y1="15" x2="22" y2="15"/>',
            'title' => 'ESP32 reads and checks',
            'rows' => [['Microcontroller', 'ESP32'], ['Reading method', '10 raw samples, stable snapshot']],
        ],
        [
            'label' => 'Firebase', 'sub' => 'Relay',
            'icon' => '<path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/>',
            'title' => 'Firebase passes it along',
            'rows' => [['Transport', 'HTTPS → Firebase Realtime DB'], ['Live bridge', 'Firebase (transient only)']],
        ],
        [
            'label' => 'Web app', 'sub' => 'Record',
            'icon' => '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
            'title' => 'Web app stores and shows it',
            'rows' => [['Backend', 'PHP + MySQL'], ['Frontend', 'Vanilla HTML / CSS / JS'], ['Hosting', 'Azure Virtual Machine'], ['Auth', 'SHA-256 hashing, prepared statements']],
        ],
    ];
    $flowStats = [
        ['value' => 10, 'suffix' => '', 'label' => 'Samples per reading'],
        ['value' => 4, 'suffix' => '', 'label' => 'Load cells'],
        ['value' => 150, 'suffix' => ' cm', 'label' => 'Sensor mount'],
        ['value' => 1, 'suffix' => '', 'label' => 'Stable snapshot'],
    ];
    ?>
    <section class="sk-section" id="hardware">
        <p class="eyebrow">Under the hood</p>
        <h2>Hardware &amp; software</h2>
        <p class="lead">Follow one measurement from the kiosk to the web app. Tap a step to see what&apos;s inside.</p>

        <div class="sk-flow" id="skFlow">
            <?php foreach ($flowSteps as $i => $st): ?>
                <?php if ($i > 0): ?><span class="sk-flow-link" aria-hidden="true"><i style="animation-delay:<?php echo ($i - 1) * 0.8; ?>s"></i></span><?php endif; ?>
                <button class="sk-flow-node<?php echo $i === 0 ? ' is-on' : ''; ?>" type="button" data-step="<?php echo $i; ?>" aria-pressed="<?php echo $i === 0 ? 'true' : 'false'; ?>" aria-controls="skFlowPanel<?php echo $i; ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?php echo $st['icon']; ?></svg>
                    <b><?php echo htmlspecialchars($st['label'], ENT_QUOTES, 'UTF-8'); ?></b>
                    <small><?php echo htmlspecialchars($st['sub'], ENT_QUOTES, 'UTF-8'); ?></small>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="sk-flow-panels">
            <?php foreach ($flowSteps as $i => $st): ?>
                <div class="sk-flow-panel" id="skFlowPanel<?php echo $i; ?>"<?php echo $i === 0 ? '' : ' hidden'; ?>>
                    <h3><?php echo htmlspecialchars($st['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                    <dl class="hw-table">
                        <?php foreach ($st['rows'] as $row): ?>
                            <div><dt><?php echo htmlspecialchars($row[0], ENT_QUOTES, 'UTF-8'); ?></dt><dd><?php echo htmlspecialchars($row[1], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sk-flow-stats">
            <?php foreach ($flowStats as $stat): ?>
                <div class="sk-flow-stat">
                    <span class="sk-flow-stat-label"><?php echo htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="sk-flow-stat-num" data-value="<?php echo (int)$stat['value']; ?>" data-suffix="<?php echo htmlspecialchars($stat['suffix'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo (int)$stat['value'] . htmlspecialchars($stat['suffix'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <details class="sk-fullspecs">
            <summary>Full specs</summary>
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
        </details>
    </section>

    <?php
    /* Team members. Put each person's full name in 'name' (leave '' to show only the role).
     * Photos: assets/img/showcase/member-01.jpg ... member-04.jpg (png/webp ok).
     * Until a photo exists, the round avatar shows the initials below. */
    $teamMembers = [
        ['name' => 'Shekainah Gonzales', 'role' => 'Principal investigator', 'initials' => 'SG'],
        ['name' => 'Ean Paolo Espiritu', 'role' => 'Developer', 'initials' => 'EE'],
        ['name' => 'Christian Rulloda', 'role' => 'Hardware specialist', 'initials' => 'CR'],
        ['name' => 'Laurence Tapang', 'role' => 'Hardware specialist', 'initials' => 'LT'],
    ];
    /* Captions for the "How we built it" deck, in photo order (team-01.jpg, team-02.jpg, ...).
     * A slide without a photo shows a placeholder with its caption. */
    $buildCaptions = ['Wiring the Sensors', 'Calibrating the Load Cell', 'Finishing Touches on the Kiosk', 'Initial Kiosk Design', 'Kiosk at the Barangay Health Center'];
    $buildCount = max(count($buildCaptions), count($teamPhotos));
    foreach ($teamMembers as $mi => &$tm) {
        $tm['photo'] = showcase_first(sprintf('member-%02d.{jpg,jpeg,png,webp}', $mi + 1));
    }
    unset($tm);
    ?>
    <section class="sk-section" id="team">
        <p class="eyebrow">The team</p>
        <h2>The people behind Sukat Kalusugan</h2>

        <div class="sk-members">
            <?php foreach ($teamMembers as $tm): ?>
                <div class="sk-member">
                    <div class="sk-member-av">
                        <?php if ($tm['photo']): ?>
                            <img src="<?php echo htmlspecialchars($tm['photo'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($tm['name'] !== '' ? $tm['name'] : $tm['role'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" draggable="false">
                        <?php else: ?>
                            <span aria-hidden="true"><?php echo htmlspecialchars($tm['initials'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($tm['name'] !== ''): ?>
                        <p class="sk-member-name"><?php echo htmlspecialchars($tm['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="sk-member-role"><?php echo htmlspecialchars($tm['role'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php else: ?>
                        <p class="sk-member-name"><?php echo htmlspecialchars($tm['role'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sk-build">
            <h3>How we built it</h3>
            <p class="lead">Photos from the build. Tap one to see it bigger.</p>
            <div class="sk-deck-wrap">
                <div class="sk-deck" id="teamDeck">
                    <div class="sk-deck-track">
                        <?php for ($i = 0; $i < $buildCount; $i++): ?>
                            <?php $src = $teamPhotos[$i] ?? null; $cap = $buildCaptions[$i] ?? ''; ?>
                            <?php if ($src): ?>
                                <div class="sk-card">
                                    <img src="<?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($cap !== '' ? $cap : 'Build photo ' . ($i + 1), ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
                                    <?php if ($cap !== ''): ?><span class="sk-card-cap"><?php echo htmlspecialchars($cap, ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="sk-card sk-card--ph">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                    <b><?php echo htmlspecialchars($cap !== '' ? $cap : 'Build photo', ENT_QUOTES, 'UTF-8'); ?></b>
                                    <small>Photo slot</small>
                                </div>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="sk-deck-ctrl">
                    <button class="sk-arrow" id="teamPrev" type="button" aria-label="Previous photo">&#8592;</button>
                    <div class="sk-dots" id="teamDots"></div>
                    <button class="sk-arrow" id="teamNext" type="button" aria-label="Next photo">&#8594;</button>
                </div>
            </div>
        </div>
    </section>

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

<script src="assets/js/showcase.js?v=17" defer></script>
</body>
</html>