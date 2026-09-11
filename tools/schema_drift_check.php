<?php

declare(strict_types=1);

/**
 * tools/schema_drift_check.php
 *
 * Read-only drift audit: extracts table/column references from SQL strings
 * in public_html/api + public_html/includes and diffs them against the live
 * database INFORMATION_SCHEMA. Catches the "Unknown column" fatal class
 * (was: intervention_type breaking parent chat + follow-ups).
 *
 * Only SELECT (read-only) queries run against the DB. Credentials come from
 * CLI flags or the project .env — never hardcoded.
 *
 * Usage:
 *   php tools/schema_drift_check.php [--host=.. --user=.. --pass=.. --db=..]
 * Exit 1 when drift is found.
 */

$root = getcwd();
$cli = ['host' => null, 'user' => null, 'pass' => null, 'db' => null];
foreach ($argv as $arg) {
    foreach (array_keys($cli) as $key) {
        if (str_starts_with($arg, '--' . $key . '=')) {
            $cli[$key] = substr($arg, strlen($key) + 3);
        }
    }
}

// Fall back to project .env (same file config.php reads).
$envFile = $root . '/.env';
$env = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $env[trim($parts[0])] = trim($parts[1]);
        }
    }
}

$host = $cli['host'] ?? $env['DB_HOST'] ?? 'localhost';
$user = $cli['user'] ?? $env['DB_USER'] ?? 'root';
$pass = $cli['pass'] ?? $env['DB_PASS'] ?? '';
$dbName = $cli['db'] ?? $env['DB_NAME'] ?? 'sukat_kalusugan';

$conn = mysqli_connect($host, $user, $pass, $dbName);
if ($conn === false) {
    fwrite(STDERR, 'Cannot connect to database: ' . mysqli_connect_error() . "\n");
    exit(2);
}

// Map: table => [column => true] for every table in the schema.
$schema = [];
$res = mysqli_query($conn, 'SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()');
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $schema[$row['TABLE_NAME']][strtolower($row['COLUMN_NAME'])] = true;
}
if ($schema === []) {
    fwrite(STDERR, "No tables found in schema.\n");
    exit(2);
}

$SQL_KEYWORDS = ['select' => 1, 'where' => 1, 'order' => 1, 'group' => 1, 'limit' => 1, 'and' => 1,
    'or' => 1, 'on' => 1, 'as' => 1, 'by' => 1, 'asc' => 1, 'desc' => 1, 'from' => 1, 'join' => 1,
    'set' => 1, 'values' => 1, 'into' => 1, 'update' => 1, 'inner' => 1, 'left' => 1, 'right' => 1,
    'null' => 1, 'count' => 1, 'max' => 1, 'min' => 1, 'sum' => 1, 'avg' => 1, 'distinct' => 1,
    'case' => 1, 'when' => 1, 'then' => 1, 'else' => 1, 'end' => 1, 'like' => 1, 'in' => 1,
    'is' => 1, 'not' => 1, 'between' => 1, 'exists' => 1, 'union' => 1, 'having' => 1];

$scanDirs = [$root . '/public_html/api', $root . '/public_html/includes'];
$files = [];
foreach ($scanDirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            $files[] = $file->getPathname();
        }
    }
}
sort($files);

$normRoot = str_replace('\\', '/', $root);
$issues = [];

/** Pull out only string literals that look like SQL (kills prose matches like
 *  'children.php' filenames or 'users.delete' permission codes). */
function sql_text_of(string $code): string
{
    $tokens = token_get_all($code);
    $chunks = [];
    foreach ($tokens as $tok) {
        if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
            $inner = substr($tok[1], 1, -1);
            if (preg_match('/\b(SELECT|INSERT\s+INTO|UPDATE\s|DELETE\s+FROM|FROM|JOIN)\b/i', $inner)) {
                $chunks[] = $inner;
            }
        }
    }
    return implode("\n", $chunks);
}

/** Approximate original line number for a snippet found in the SQL text. */
function sql_line_of(string $code, string $snippet): int
{
    $pos = strpos($code, $snippet);
    if ($pos === false) {
        foreach (preg_split('/\s+/', trim($snippet)) as $word) {
            if (strlen($word) > 6) {
                $pos = strpos($code, $word);
                if ($pos !== false) {
                    break;
                }
            }
        }
    }
    return $pos === false ? 1 : substr_count($code, "\n", 0, $pos) + 1;
}

foreach ($files as $file) {
    $code = file_get_contents($file);
    if ($code === false) {
        continue;
    }
    $rel = ltrim(str_replace('\\', '/', substr($file, strlen($normRoot))), '/');
    $sql = sql_text_of($code);
    if ($sql === '') {
        continue;
    }

    // Alias map for this file: FROM/JOIN <table> [AS] <alias>.
    $aliases = [];
    if (preg_match_all('/\b(?:FROM|JOIN)\s+`?([a-z_][a-z0-9_]*)`?(?:\s+(?:AS\s+)?([a-z_][a-z0-9_]*))?/i', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $table = strtolower($hit[1]);
            if (isset($SQL_KEYWORDS[$table])) {
                continue;
            }
            $aliases[strtolower($hit[2] ?? $hit[1])] = $table;
        }
    }
    // INSERT INTO <table> (col, col, ...) and UPDATE <table> SET col = ...
    $inserts = [];
    if (preg_match_all('/\bINSERT\s+INTO\s+`?([a-z_][a-z0-9_]*)`?\s*\(([^)]+)\)/i', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $cols = [];
            foreach (explode(',', $hit[2]) as $col) {
                $col = strtolower(trim($col, " \t\n\r\0\x0B`'\""));
                if ($col !== '' && !isset($SQL_KEYWORDS[$col])) {
                    $cols[] = $col;
                }
            }
            $inserts[] = [$hit[1], $cols, sql_line_of($code, $hit[0])];
        }
    }
    foreach ($inserts as [$table, $cols, $line]) {
        $table = strtolower($table);
        if (!isset($schema[$table])) {
            $issues[] = "{$rel}:{$line} [missing-table] INSERT INTO unknown table `{$table}`";
            continue;
        }
        foreach ($cols as $col) {
            if (!isset($schema[$table][$col])) {
                $issues[] = "{$rel}:{$line} [missing-column] INSERT lists unknown column `{$table}`.`{$col}`";
            }
        }
    }

    // prefix.column references (table or alias qualified).
    if (preg_match_all('/\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/i', $sql, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $hit) {
            $prefix = strtolower($hit[1][0]);
            $col = strtolower($hit[2][0]);
            if (isset($SQL_KEYWORDS[$prefix]) || isset($SQL_KEYWORDS[$col])) {
                continue;
            }
            $table = $aliases[$prefix] ?? (isset($schema[$prefix]) ? $prefix : null);
            if ($table === null) {
                continue; // not a table reference (e.g. object property, $row->field is -> not .)
            }
            if (!isset($schema[$table])) {
                continue; // reported via INSERT path or unknown alias; avoid dupes
            }
            if (!isset($schema[$table][$col])) {
                $line = sql_line_of($code, $hit[0][0]);
                $issues[] = "{$rel}:{$line} [missing-column] unknown column `{$table}`.`{$col}`";
            }
        }
    }
}

$issues = array_values(array_unique($issues));
sort($issues);

if ($issues === []) {
    echo 'No schema drift: all referenced tables/columns exist in `' . $dbName . "`.\n";
    exit(0);
}

echo '=== SCHEMA DRIFT (' . count($issues) . ") ===\n";
foreach ($issues as $issue) {
    echo $issue . "\n";
}
exit(1);
