<?php
/**
 * bootstrap_errors.php
 * Central place that decides whether PHP errors are shown on-screen or
 * hidden and logged, based on APP_ENV (set in config.php).
 *
 * Included from config.php, right after APP_ENV is defined, so this runs
 * on every request before any other code executes.
 */

$appEnv = defined('APP_ENV') ? APP_ENV : 'development';

if ($appEnv === 'development') {
    // Development: surface everything to make debugging easier.
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    // Never show raw errors/warnings/stack traces to visitors in
    // staging/production — they can leak file paths, SQL, and other
    // internals (and corrupt JSON API envelopes).
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

    // Backstop: turn an uncaught exception / fatal error into a calm,
    // branded notice instead of a white screen or a stack trace.
    // Full details always go to the log; visitors only see this.
    if (!function_exists('sk_friendly_fatal_response')) {
        function sk_friendly_fatal_response(): void
        {
            if (headers_sent()) {
                return;
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(500);
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            $acceptsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
            $isXhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
            $isApi = stripos($uri, '/api/') !== false;
            if ($isApi || $isXhr || $acceptsJson) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success' => false,
                    'message' => 'Something didn\'t go as planned. Please try again.',
                ]);
                return;
            }
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<meta name="color-scheme" content="light dark">'
                . '<title>Something went wrong | Sukat Kalusugan</title>'
                . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
                . 'background:#f4f7f5;color:#173126;font-family:Inter,-apple-system,"Segoe UI",sans-serif;padding:20px;}'
                . '@media(prefers-color-scheme:dark){body{background:#0f1a14;color:#dce8e1;}}'
                . '.card{max-width:420px;width:100%;text-align:center;background:#fff;border:1px solid #d8e4dd;'
                . 'border-radius:22px;padding:36px 30px;box-shadow:0 30px 80px rgba(11,110,79,.12);}'
                . '@media(prefers-color-scheme:dark){.card{background:#162019;border-color:#2a3d32;}}'
                . '.mark{width:52px;height:52px;margin:0 auto 14px;border-radius:50%;display:flex;align-items:center;'
                . 'justify-content:center;background:rgba(11,110,79,.1);color:#0b6e4f;font-size:26px;font-weight:800;}'
                . 'h1{margin:0 0 8px;font-size:1.2rem;}p{margin:0 0 20px;font-size:.88rem;line-height:1.6;opacity:.75;}'
                . '.row{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}'
                . 'a,button{font:inherit;font-size:.85rem;font-weight:700;padding:10px 20px;border-radius:10px;cursor:pointer;'
                . 'text-decoration:none;border:1px solid #d8e4dd;background:transparent;color:inherit;}'
                . '.primary{background:#0b6e4f;border-color:#0b6e4f;color:#fff;}</style></head><body>'
                . '<div class="card" role="alert"><div class="mark">!</div>'
                . '<h1>Something didn\'t go as planned</h1>'
                . '<p>Please try again. If it keeps happening, contact your administrator.</p>'
                . '<div class="row"><button type="button" class="primary" onclick="history.back()">Go back</button>'
                . '<a href="/">Home</a></div></div></body></html>';
        }
    }

    if (!function_exists('sk_friendly_exception_handler')) {
        function sk_friendly_exception_handler(Throwable $e): void
        {
            error_log('[SukatKalusugan] Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            sk_friendly_fatal_response();
            exit(1);
        }
    }

    set_exception_handler('sk_friendly_exception_handler');

    register_shutdown_function(static function (): void {
        $last = error_get_last();
        if ($last === null) {
            return;
        }
        $fatal = (int)($last['type'] ?? 0);
        if (!in_array($fatal, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        error_log('[SukatKalusugan] Fatal ' . $fatal . ': ' . ($last['message'] ?? '') . ' in ' . ($last['file'] ?? '') . ':' . ($last['line'] ?? ''));
        sk_friendly_fatal_response();
    });
}
