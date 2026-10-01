<?php

declare(strict_types=1);

/**
 * tools/encrypt_pii.php — AES-256-GCM backfill + status for PII at rest.
 *
 * Reads APP_ENCRYPTION_KEY from .env (via includes/config.php constants).
 *
 *   php tools/encrypt_pii.php --status    show key state + plaintext vs encrypted counts
 *   php tools/encrypt_pii.php --dry-run   preview what --apply would encrypt
 *   php tools/encrypt_pii.php --apply     encrypt remaining plaintext PII (idempotent)
 *   php tools/encrypt_pii.php --verify    decrypt-check every enveloped value
 *
 * Safety:
 * - Refuses --apply when no valid key is configured (would be a no-op anyway).
 * - Refuses --apply when any target column is still VARCHAR (ciphertext would
 *   truncate). Import db/20261001_pii_encryption_columns.sql first.
 * - Skips empty/NULL and already-encrypted values; verifies each write by
 *   reading the row back and comparing the decrypt round-trip.
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/public_html/includes/config.php';
require_once $projectRoot . '/public_html/includes/db.php';
require_once $projectRoot . '/public_html/includes/crypto.php';

$mode = $argv[1] ?? '--status';
if (!in_array($mode, ['--status', '--dry-run', '--apply', '--verify'], true)) {
    fwrite(STDERR, "Usage: php tools/encrypt_pii.php [--status|--dry-run|--apply|--verify]\n");
    exit(2);
}

// table => id column => [pii columns]
$targets = [
    'parents' => ['id' => 'id', 'cols' => ['name', 'phone', 'address']],
    'children' => ['id' => 'id', 'cols' => ['first_name', 'middle_name', 'last_name']],
];

function pii_table_exists(mysqli $conn, string $table): bool
{
    $stmt = mysqli_prepare($conn, 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    if ($stmt === false) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $table);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ok = $res instanceof mysqli_result && mysqli_fetch_assoc($res) !== null;
    mysqli_stmt_close($stmt);
    return $ok;
}

function pii_column_type(mysqli $conn, string $table, string $col): string
{
    $stmt = mysqli_prepare($conn, 'SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    if ($stmt === false) {
        return 'unknown';
    }
    mysqli_stmt_bind_param($stmt, 'ss', $table, $col);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res instanceof mysqli_result ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return is_array($row) ? (string)($row['DATA_TYPE'] ?? 'unknown') : 'unknown';
}

$conn = get_db_connection();
$keyOk = sk_pii_encryption_enabled();

echo 'AES-256-GCM PII encryption: ' . ($keyOk ? "ENABLED (valid key)\n" : "DISABLED (no/invalid APP_ENCRYPTION_KEY — writes stay plaintext)\n");

$totals = ['plain' => 0, 'encrypted' => 0, 'empty' => 0];
$rows_to_encrypt = [];

foreach ($targets as $table => $spec) {
    if (!pii_table_exists($conn, $table)) {
        echo "[skip] table `{$table}` not found.\n";
        continue;
    }
    $idCol = $spec['id'];
    $cols = array_filter($spec['cols'], static function (string $c) use ($conn, $table): bool {
        $stmt = mysqli_prepare($conn, 'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
        if ($stmt === false) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $table, $c);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $ok = $res instanceof mysqli_result && mysqli_fetch_assoc($res) !== null;
        mysqli_stmt_close($stmt);
        return $ok;
    });
    if ($cols === []) {
        continue;
    }

    $select = 'SELECT `' . $idCol . '`, `' . implode('`, `', $cols) . '` FROM `' . $table . '`';
    $res = mysqli_query($conn, $select);
    if (!$res instanceof mysqli_result) {
        echo "[error] cannot read `{$table}`: " . mysqli_error($conn) . "\n";
        exit(1);
    }
    while ($row = mysqli_fetch_assoc($res)) {
        foreach ($cols as $c) {
            $v = $row[$c] ?? null;
            if ($v === null || $v === '') {
                $totals['empty']++;
                continue;
            }
            if (sk_is_encrypted((string)$v)) {
                $totals['encrypted']++;
                continue;
            }
            $totals['plain']++;
            $rows_to_encrypt[] = ['table' => $table, 'id' => (int)$row[$idCol], 'col' => $c, 'plain' => (string)$v];
        }
    }
    mysqli_free_result($res);
}

echo sprintf(
    "PII values — plaintext: %d | encrypted: %d | empty: %d\n",
    $totals['plain'],
    $totals['encrypted'],
    $totals['empty']
);

if ($mode === '--status') {
    exit(0);
}

if ($mode === '--verify') {
    if (!$keyOk) {
        fwrite(STDERR, "Cannot verify without a valid APP_ENCRYPTION_KEY.\n");
        exit(1);
    }
    $fail = 0;
    $checked = 0;
    foreach (['parents', 'children'] as $table) {
        if (!pii_table_exists($conn, $table)) {
            continue;
        }
        $cols = $targets[$table]['cols'];
        $res = mysqli_query($conn, 'SELECT * FROM `' . $table . '`');
        if (!$res instanceof mysqli_result) {
            continue;
        }
        while ($row = mysqli_fetch_assoc($res)) {
            foreach ($cols as $c) {
                if (!array_key_exists($c, $row)) {
                    continue;
                }
                $v = $row[$c];
                if (!is_string($v) || !sk_is_encrypted($v)) {
                    continue;
                }
                $checked++;
                $back = sk_decrypt_value($v);
                if ($back === $v) {
                    $fail++;
                    echo "[FAIL] {$table}#{$row['id']}.{$c}: authentication failed (wrong key or tampered).\n";
                }
            }
        }
        mysqli_free_result($res);
    }
    echo "Verified {$checked} enveloped value(s), {$fail} failure(s).\n";
    exit($fail > 0 ? 1 : 0);
}

if ($mode === '--dry-run') {
    $preview = array_slice($rows_to_encrypt, 0, 20);
    foreach ($preview as $item) {
        echo "[would encrypt] {$item['table']}#{$item['id']}.{$item['col']}\n";
    }
    if (count($rows_to_encrypt) > 20) {
        echo '... and ' . (count($rows_to_encrypt) - 20) . " more.\n";
    }
    exit(0);
}

// --apply
if (!$keyOk) {
    fwrite(STDERR, "Refusing --apply: no valid APP_ENCRYPTION_KEY configured.\n");
    exit(1);
}

$narrow = [];
foreach ($targets as $table => $spec) {
    foreach ($spec['cols'] as $c) {
        $type = pii_column_type($conn, $table, $c);
        if ($type !== 'unknown' && stripos($type, 'text') === false && stripos($type, 'longtext') === false && stripos($type, 'mediumtext') === false) {
            $narrow[] = "{$table}.{$c} ({$type})";
        }
    }
}
if ($narrow !== []) {
    fwrite(STDERR, "Refusing --apply: ciphertext would truncate in narrow columns:\n  - " . implode("\n  - ", $narrow) . "\nImport db/20261001_pii_encryption_columns.sql first.\n");
    exit(1);
}

if ($rows_to_encrypt === []) {
    echo "Nothing to encrypt — all PII already enveloped.\n";
    exit(0);
}

$done = 0;
$failed = 0;
foreach ($rows_to_encrypt as $item) {
    $enc = sk_encrypt_value($item['plain']);
    if (!is_string($enc) || !sk_is_encrypted($enc)) {
        $failed++;
        echo "[FAIL] {$item['table']}#{$item['id']}.{$item['col']}: encrypt failed.\n";
        continue;
    }
    $stmt = mysqli_prepare($conn, 'UPDATE `' . $item['table'] . '` SET `' . $item['col'] . '` = ? WHERE `id` = ? LIMIT 1');
    if ($stmt === false) {
        $failed++;
        echo "[FAIL] {$item['table']}#{$item['id']}.{$item['col']}: prepare failed.\n";
        continue;
    }
    mysqli_stmt_bind_param($stmt, 'si', $enc, $item['id']);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        $failed++;
        echo "[FAIL] {$item['table']}#{$item['id']}.{$item['col']}: " . mysqli_error($conn) . "\n";
        continue;
    }
    // Read-back round-trip check.
    $check = mysqli_prepare($conn, 'SELECT `' . $item['col'] . '` AS v FROM `' . $item['table'] . '` WHERE `id` = ? LIMIT 1');
    if ($check === false) {
        $failed++;
        echo "[FAIL] {$item['table']}#{$item['id']}.{$item['col']}: verify prepare failed.\n";
        continue;
    }
    mysqli_stmt_bind_param($check, 'i', $item['id']);
    mysqli_stmt_execute($check);
    $res = mysqli_stmt_get_result($check);
    $back = $res instanceof mysqli_result ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($check);
    if (!is_array($back) || sk_decrypt_value((string)($back['v'] ?? '')) !== $item['plain']) {
        $failed++;
        echo "[FAIL] {$item['table']}#{$item['id']}.{$item['col']}: round-trip mismatch — investigate before continuing.\n";
        continue;
    }
    $done++;
}

echo "Encrypted {$done} value(s), {$failed} failure(s).\n";
exit($failed > 0 ? 1 : 0);
