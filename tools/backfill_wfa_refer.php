<?php

/**
 * tools/backfill_wfa_refer.php
 *
 * Fixes WFA status values that incorrectly show 'OW' or 'Ob'.
 * Per DOH e-OPT Plus rules, WFA only has: SUW, MUW, Normal, Refer to WFL/H.
 * When WAZ > +2, WFA must be 'Refer to WFL/H' (never OW/Ob — those belong
 * to the WFL/H axis only).
 *
 * Usage (CLI):
 *   php tools/backfill_wfa_refer.php            # live run
 *   php tools/backfill_wfa_refer.php --dry-run  # report only
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require __DIR__ . '/../public_html/includes/config.php';
require __DIR__ . '/../public_html/includes/db.php';
require __DIR__ . '/../public_html/includes/who_calculator.php';

$dryRun = in_array('--dry-run', $argv, true);

$conn = get_db_connection();

// Find all measurements where wfa_status is OW or Ob (invalid on WFA axis)
$res = mysqli_query(
    $conn,
    "SELECT m.id, m.waz, m.wfa_status, m.child_id, c.sex
     FROM measurements m
     INNER JOIN children c ON c.id = m.child_id
     WHERE m.wfa_status IN ('OW', 'Ob', 'Overweight', 'Obese')
     ORDER BY m.id"
);

if ($res === false) {
    fwrite(STDERR, 'Query failed: ' . mysqli_error($conn) . "\n");
    exit(1);
}

$total = 0;
$updated = 0;

while ($row = mysqli_fetch_assoc($res)) {
    $total++;
    $id = (int)$row['id'];
    $waz = $row['waz'] !== null ? (float)$row['waz'] : null;
    $oldStatus = (string)$row['wfa_status'];

    // Recompute the correct WFA status from the stored WAZ
    if ($waz !== null) {
        $newStatus = classify_wfa_status($waz);
    } else {
        // If WAZ is null, mark as Normal (safe fallback)
        $newStatus = 'Normal';
    }

    if ($newStatus === $oldStatus) {
        echo "#{$id}: already '{$oldStatus}', skipping\n";
        continue;
    }

    echo "#{$id}: wfa_status '" . $oldStatus . "' (WAZ=" . ($waz !== null ? number_format($waz, 2) : 'NULL') . ") -> '{$newStatus}'\n";

    if (!$dryRun) {
        $stmt = mysqli_prepare($conn, 'UPDATE measurements SET wfa_status = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'si', $newStatus, $id);
        if (!mysqli_stmt_execute($stmt)) {
            fwrite(STDERR, "UPDATE failed for #{$id}: " . mysqli_error($conn) . "\n");
            mysqli_stmt_close($stmt);
            continue;
        }
        mysqli_stmt_close($stmt);
        $updated++;
    }
}

echo "\n" . ($dryRun ? '[DRY RUN] ' : '') . "found={$total} updated={$updated}\n";
