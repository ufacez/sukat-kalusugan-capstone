<?php
declare(strict_types=1);
/**
 * staging_guards.php
 * Codespaces staging protections (QA + pen-test). NO-OP unless APP_ENV=staging.
 *
 * - staging_send_noindex(): X-Robots-Tag so pen-test payloads never get indexed
 *   (called from config.php — header-only, no session needed).
 * - staging_require_gate(): shared-password gate for the public forwarded URL.
 *   Called once at the end of auth_middleware.php, so it covers pages AND APIs.
 *   API/pen-test clients can send header  X-Staging-Key: <STAGING_PASSWORD>.
 * - staging_banner(): visible "STAGING — test data only" bar for browser pages.
 */

/**
 * True only on staging. Production is unaffected when this is false.
 */
function staging_is_active(): bool
{
    return defined('APP_ENV') && APP_ENV === 'staging';
}

/**
 * Tell crawlers to drop every staging URL (defense against a repeat of the
 * Safe Browsing "Deceptive pages" flag caused by indexed pen-test payloads).
 */
function staging_send_noindex(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    if (!staging_is_active()) {
        return;
    }
    header('X-Robots-Tag: noindex, nofollow', false);
}

/**
 * Requests that bypass the gate: static assets (the gate page needs its own
 * CSS/images) and the ESP32 machine path (never behind a browser session).
 */
function staging_gate_exempt(): bool
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string)parse_url($uri, PHP_URL_PATH);
    if ($path === '') {
        $path = $uri;
    }
    if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|map)(\?.*)?$/i', $path)) {
        return true;
    }
    if (str_contains($path, '/api/esp32/')) {
        return true;
    }
    return false;
}

/**
 * Shared-password gate. Fail CLOSED when STAGING_PASSWORD is missing so a
 * public forwarded URL can never sit open by accident.
 */
function staging_require_gate(): void
{
    if (PHP_SAPI === 'cli' || !staging_is_active() || staging_gate_exempt()) {
        return;
    }

    $secret = defined('STAGING_PASSWORD') ? (string)STAGING_PASSWORD : '';
    if ($secret === '') {
        http_response_code(503);
        exit('Staging is not configured (STAGING_PASSWORD missing in .env).');
    }

    // Header bypass for API / curl pen-testing (documented in docs/qa-staging.md).
    $key = (string)($_SERVER['HTTP_X_STAGING_KEY'] ?? '');
    if ($key !== '' && hash_equals($secret, $key)) {
        return;
    }

    start_secure_session();
    if (($_SESSION['staging_ok'] ?? false) === true) {
        return;
    }

    $error = '';
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset($_POST['staging_password'])) {
        if (hash_equals($secret, (string)$_POST['staging_password'])) {
            $_SESSION['staging_ok'] = true;
            $to = strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
            header('Location: ' . ($to !== '' ? $to : '/'));
            exit;
        }
        $error = 'Wrong staging password.';
    }

    http_response_code(401);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Staging locked | Sukat Kalusugan QA</title>
<style>body{font-family:system-ui,sans-serif;background:#0f1a14;color:#dce8e1;display:grid;place-items:center;min-height:100vh;margin:0;padding:1rem}main{background:#162019;border:1px solid #2a3d32;border-radius:16px;padding:2rem;max-width:380px;width:100%;text-align:center}.badge{display:inline-block;background:#7c2d12;color:#fed7aa;font-size:.75rem;font-weight:700;border-radius:999px;padding:.3rem .8rem;margin-bottom:1rem}input{width:100%;padding:.7rem .9rem;border-radius:10px;border:1px solid #2a3d32;background:#0f1a14;color:#fff;margin:.8rem 0;box-sizing:border-box}button{background:#0b6e4f;color:#fff;border:0;border-radius:10px;padding:.7rem 1.2rem;font-weight:700;cursor:pointer;width:100%}.err{color:#f87171;font-size:.85rem;min-height:1.2em}p{font-size:.85rem;color:#a8beb0}</style>
</head>
<body>
<main>
<div class="badge">STAGING &middot; TEST DATA ONLY</div>
<h2 style="margin:.2rem 0 0;">Sukat Kalusugan QA</h2>
<p>Group A4Tech staging. Authorized testers only — ask the owner for the staging password.</p>
<?php if ($error !== ''): ?><p class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
<form method="post" action="">
<input type="password" name="staging_password" placeholder="Staging password" autocomplete="off" required autofocus>
<button type="submit">Unlock staging</button>
</form>
</main>
</body>
</html>
<?php
    exit;
}

/**
 * Visible staging bar for browser pages. Echo once near the top of <body>.
 * No output on production.
 */
function staging_banner(): void
{
    if (!staging_is_active()) {
        return;
    }
    echo '<div style="position:sticky;top:0;z-index:3000;text-align:center;font:700 .78rem/1.4 system-ui,sans-serif;letter-spacing:.04em;color:#fed7aa;background:#7c2d12;border-bottom:1px solid #c2410c;padding:.45rem .8rem;">STAGING &mdash; test data only &middot; Group A4Tech QA &middot; not production</div>';
}
