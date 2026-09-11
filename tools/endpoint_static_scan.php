<?php

declare(strict_types=1);

/**
 * tools/endpoint_static_scan.php
 *
 * Read-only static audit of API endpoints + shared includes. Flags the bug
 * classes that caused this month's production fatals:
 *
 *   HIGH  missing-require  admin_* helper used without requiring admin_helpers.php
 *                          (was: conversations.php:140 fatal)
 *   HIGH  ternary-bind     inline ternary/expression inside mysqli_stmt_bind_param()
 *                          (was: measurements_create.php:244 fatal on PHP 8)
 *   HIGH  suppress-throw   @-suppressed prepare() — @ cannot stop PHP 8
 *                          mysqli_sql_exception (was: followup_scheduler.php:708 fatal)
 *   HIGH  no-auth-gate     api/ file with no session/device-key gate
 *   MED   unguarded-prepare prepare() with no === false check or try/catch nearby
 *   MED   raw-envelope     direct echo json_encode instead of api_response()
 *   INFO  raw-superglobal  raw $_GET/$_POST reads (normal for query params; listed only)
 *
 * Usage:  php tools/endpoint_static_scan.php [--path=public_html]
 * Exit 1 when any HIGH finding exists.
 */

$root = getcwd();
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--path=')) {
        $root = rtrim(substr($arg, 7), '/\\');
    }
}

$scanDirs = [$root . '/public_html/api', $root . '/public_html/includes'];
$files = [];
foreach ($scanDirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$findings = ['HIGH' => [], 'MED' => [], 'INFO' => []];

function add_finding(array &$findings, string $sev, string $file, int $line, string $rule, string $detail): void
{
    $findings[$sev][] = [$file, $line, $rule, $detail];
}

function short_path(string $root, string $file): string
{
    return ltrim(str_replace('\\', '/', substr($file, strlen(str_replace('\\', '/', $root)))), '/');
}

/** Rules waived inline via: // scan-ok: rule-a, rule-b */
function waived_rules(string $code): array
{
    $waived = [];
    if (preg_match_all('/scan-ok:\s*([a-z0-9\-,\s]+)/i', $code, $m)) {
        foreach ($m[1] as $list) {
            foreach (preg_split('/[\s,]+/', trim($list)) as $rule) {
                if ($rule !== '') {
                    $waived[strtolower($rule)] = true;
                }
            }
        }
    }
    return $waived;
}

/** Resolve require_once __DIR__ . '...' targets (depth-limited, for transitive helper coverage). */
function resolved_requires(string $file, int $depth = 0): array
{
    if ($depth > 2 || !is_file($file)) {
        return [];
    }
    $code = file_get_contents($file);
    if ($code === false) {
        return [];
    }
    $out = [];
    if (preg_match_all("/require_once\s+__DIR__\s*\.\s*'([^']+)'/", $code, $m)) {
        foreach ($m[1] as $rel) {
            $target = realpath(dirname($file) . '/' . $rel);
            if ($target !== false) {
                $out[] = $target;
                foreach (resolved_requires($target, $depth + 1) as $nested) {
                    $out[] = $nested;
                }
            }
        }
    }
    return array_unique($out);
}

function requires_helper(array $resolved, string $helperFile): bool
{
    foreach ($resolved as $target) {
        if (str_ends_with(str_replace('\\', '/', $target), 'includes/' . $helperFile)) {
            return true;
        }
    }
    return false;
}

/** Extract the balanced-paren argument span starting at $openPos. Returns [endPos, innerText]. */
function paren_span(string $code, int $openPos): array
{
    $depth = 0;
    $len = strlen($code);
    for ($i = $openPos; $i < $len; $i++) {
        $ch = $code[$i];
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
            if ($depth === 0) {
                return [$i, substr($code, $openPos + 1, $i - $openPos - 1)];
            }
        } elseif ($ch === "'" || $ch === '"') {
            $quote = $ch;
            $i++;
            while ($i < $len && $code[$i] !== $quote) {
                if ($code[$i] === '\\') {
                    $i++;
                }
                $i++;
            }
        }
    }
    return [$len, substr($code, $openPos + 1)];
}

function line_of(string $code, int $pos): int
{
    return substr_count($code, "\n", 0, $pos) + 1;
}

foreach ($files as $file) {
    $code = file_get_contents($file);
    if ($code === false) {
        continue;
    }
    $rel = short_path($root, $file);
    $isApi = str_contains($rel, 'public_html/api/');
    $waived = waived_rules($code);
    $skip = static function (string $rule) use ($waived): bool {
        return isset($waived[$rule]);
    };

    // --- R1: admin_* usage without the require (transitive-aware) ------
    if (!$skip('missing-require') && preg_match_all('/\b(admin_[a-z_]+)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
        $definesOwn = (bool)preg_match('/function\s+admin_[a-z_]+\s*\(/', $code);
        $covered = $definesOwn || requires_helper(resolved_requires($file), 'admin_helpers.php');
        if (!$covered) {
            $seen = [];
            foreach ($m[1] as $fn) {
                if (!isset($seen[$fn[0]])) {
                    $seen[$fn[0]] = true;
                    add_finding($findings, 'HIGH', $rel, line_of($code, $fn[1]), 'missing-require', $fn[0] . '() used without requiring admin_helpers.php');
                }
            }
        }
    }

    // --- R2: ternary/expression inside bind_param() --------------------
    // (Skips the admin_bind_params forwarder, which intentionally builds a
    // reference array and delegates via call_user_func_array.)
    $off = 0;
    while (!$skip('ternary-bind') && ($pos = strpos($code, 'mysqli_stmt_bind_param', $off)) !== false) {
        if (str_contains(substr($code, max(0, $pos - 40), 40), 'call_user_func_array')) {
            $off = $pos + 1;
            continue;
        }
        $open = strpos($code, '(', $pos);
        if ($open === false) {
            break;
        }
        [$end, $inner] = paren_span($code, $open);
        // Strip the first two args ($stmt, $types) by splitting top-level commas.
        $depth = 0;
        $args = [];
        $cur = '';
        $inStr = null;
        $ilen = strlen($inner);
        for ($i = 0; $i < $ilen; $i++) {
            $ch = $inner[$i];
            if ($inStr !== null) {
                $cur .= $ch;
                if ($ch === '\\') {
                    $cur .= $inner[++$i] ?? '';
                } elseif ($ch === $inStr) {
                    $inStr = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inStr = $ch;
                $cur .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
                $cur .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depth--;
                $cur .= $ch;
                continue;
            }
            if ($ch === ',' && $depth === 0) {
                $args[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $args[] = trim($cur);
        foreach (array_slice($args, 2) as $arg) {
            // A bound value must be a plain $variable. Literals are only
            // safe for the $types string (arg index 1, already skipped).
            if (!preg_match('/^\$[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\[[^\]]*\])?$/', $arg)) {
                add_finding($findings, 'HIGH', $rel, line_of($code, $pos), 'ternary-bind', 'non-variable bound value: ' . mb_substr($arg, 0, 80));
                break;
            }
        }
        $off = $end + 1;
    }

    // --- R3: @-suppressed prepare() ------------------------------------
    if (!$skip('suppress-throw') && preg_match_all('/@\s*(?:\$[A-Za-z_][\w]*->prepare|mysqli_prepare)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            add_finding($findings, 'HIGH', $rel, line_of($code, $hit[1]), 'suppress-throw', '@ cannot stop PHP 8 mysqli_sql_exception — use try/catch');
        }
    }

    // --- R3b: prepare() with no nearby guard ---------------------------
    if (!$skip('unguarded-prepare') && preg_match_all('/(?:mysqli_prepare\s*\(|\$[A-Za-z_][\w]*->prepare\s*\()/', $code, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $window = substr($code, $hit[1], 600);
            if (!str_contains($window, '=== false') && !str_contains($window, 'try')) {
                add_finding($findings, 'MED', $rel, line_of($code, $hit[1]), 'unguarded-prepare', 'no === false check or try/catch within ~600 chars after prepare()');
            }
        }
    }

    // --- R4: api/ file without an auth gate ----------------------------
    if ($isApi && !$skip('no-auth-gate')) {
        $gated = str_contains($code, 'api_require_')
            || str_contains($code, 'api_require_device_key')
            || str_contains($code, 'current_user')
            || str_contains($code, 'start_secure_session')
            || str_contains($code, 'deny_access')
            || str_contains($code, 'require_login');
        if (!$gated && preg_match('#public_html/api/(kiosk|esp32)/#', $rel) && stripos($code, 'device_code') !== false) {
            add_finding($findings, 'INFO', $rel, 1, 'device-gated', 'kiosk/esp32 endpoint validating device_code (reviewed pattern, no session gate)');
            $gated = true;
        }
        if (!$gated) {
            add_finding($findings, 'HIGH', $rel, 1, 'no-auth-gate', 'no session/device-key gate detected');
        }
    }

    // --- R6: raw envelope ----------------------------------------------
    if ($isApi && !$skip('raw-envelope') && preg_match('/\becho\s+json_encode\s*\(/', $code) && !str_contains($code, 'function api_response')) {
        add_finding($findings, 'MED', $rel, 1, 'raw-envelope', 'direct echo json_encode instead of api_response()/api_success()/api_error()');
    }

    // --- R5: raw superglobals (info only) ------------------------------
    if ($isApi && preg_match_all('/\$_(GET|POST|REQUEST|FILES|COOKIE)\b/', $code, $m)) {
        add_finding($findings, 'INFO', $rel, 1, 'raw-superglobal', count($m[0]) . ' raw superglobal read(s)');
    }
}

$total = 0;
foreach (['HIGH', 'MED', 'INFO'] as $sev) {
    if ($findings[$sev] === []) {
        continue;
    }
    echo "=== {$sev} (" . count($findings[$sev]) . ") ===\n";
    foreach ($findings[$sev] as [$file, $line, $rule, $detail]) {
        echo "{$file}:{$line} [{$rule}] {$detail}\n";
        $total++;
    }
    echo "\n";
}

echo 'Scanned ' . count($files) . ' files, ' . $total . " finding(s).\n";
exit($findings['HIGH'] === [] ? 0 : 1);
