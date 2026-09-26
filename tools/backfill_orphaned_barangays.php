<?php

/**
 * tools/backfill_orphaned_barangays.php
 *
 * Heals rows orphaned by the retired barangay hard-delete: DELETE FROM
 * barangays nulled children/parents barangay_id (ON DELETE SET NULL) and
 * cascade-wiped local areas, while re-adding minted a NEW barangay id.
 * Children kept barangay_id = NULL, so they vanished from the dashboard's
 * per-barangay counts until each child edit form was re-saved.
 *
 * What it does:
 *   1. children.barangay_id IS NULL + parent has a barangay
 *      -> inherit the parent's barangay_id (mirrors the child-form
 *         derivation and admin_cascade_parent_barangay()).
 *   2. Healed children whose local_area_id belongs to a DIFFERENT
 *      barangay -> clear to NULL (areas are per-barangay).
 *
 * Rows whose parent is ALSO unassigned (NULL) are left alone and reported.
 *
 * Usage (CLI):
 *   php tools/backfill_orphaned_barangays.php --dry-run  # report only
 *   php tools/backfill_orphaned_barangays.php            # live run
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require __DIR__ . '/../public_html/includes/config.php';
require __DIR__ . '/../public_html/includes/db.php';

$dryRun = in_array('--dry-run', $argv, true);

$conn = get_db_connection();

// --- Step 1: orphaned children healable from their parent ---
$res = mysqli_query(
    $conn,
    "SELECT c.id, c.child_code, c.first_name, c.last_name, c.parent_id,
            p.barangay_id AS parent_barangay_id, b.name AS parent_barangay_name
     FROM children c
     INNER JOIN parents p ON p.id = c.parent_id
     LEFT JOIN barangays b ON b.id = p.barangay_id
     WHERE c.barangay_id IS NULL
     ORDER BY c.id"
);

if ($res === false) {
    fwrite(STDERR, 'Query failed: ' . mysqli_error($conn) . "\n");
    exit(1);
}

$orphaned = 0;
$healable = 0;
$healed = 0;
$parentUnassigned = 0;

while ($row = mysqli_fetch_assoc($res)) {
    $orphaned++;
    $id = (int)$row['id'];
    $code = (string)($row['child_code'] ?? ('#' . $id));
    $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    $parentBarangayId = $row['parent_barangay_id'] !== null ? (int)$row['parent_barangay_id'] : null;

    if ($parentBarangayId === null || $parentBarangayId <= 0) {
        $parentUnassigned++;
        echo "{$code} ({$name}): parent #{$row['parent_id']} also unassigned — skipping\n";
        continue;
    }

    $healable++;
    $barangayName = (string)($row['parent_barangay_name'] ?? ('#' . $parentBarangayId));
    echo "{$code} ({$name}): NULL -> barangay {$barangayName} (#{$parentBarangayId}) [parent #{$row['parent_id']}]\n";

    if (!$dryRun) {
        $stmt = mysqli_prepare($conn, 'UPDATE children SET barangay_id = ? WHERE id = ? AND barangay_id IS NULL');
        mysqli_stmt_bind_param($stmt, 'ii', $parentBarangayId, $id);
        if (!mysqli_stmt_execute($stmt)) {
            fwrite(STDERR, "UPDATE failed for {$code}: " . mysqli_error($conn) . "\n");
            mysqli_stmt_close($stmt);
            continue;
        }
        mysqli_stmt_close($stmt);
        $healed++;
    }
}
mysqli_free_result($res);

// --- Step 2: stale local areas on the healed scope ---
// Any child whose local area belongs to a different barangay than the
// child itself (same cleanup admin_cascade_parent_barangay() performs).
$staleRes = mysqli_query(
    $conn,
    "SELECT c.id, c.child_code, c.local_area_id, la.area_name,
            c.barangay_id AS child_barangay_id, la.barangay_id AS area_barangay_id
     FROM children c
     INNER JOIN local_areas la ON la.id = c.local_area_id
     WHERE c.local_area_id IS NOT NULL
       AND c.barangay_id IS NOT NULL
       AND la.barangay_id != c.barangay_id
     ORDER BY c.id"
);

if ($staleRes === false) {
    fwrite(STDERR, 'Stale-area query failed: ' . mysqli_error($conn) . "\n");
    exit(1);
}

$stale = 0;
$staleCleared = 0;

while ($row = mysqli_fetch_assoc($staleRes)) {
    $stale++;
    $id = (int)$row['id'];
    $code = (string)($row['child_code'] ?? ('#' . $id));
    echo "{$code}: local area '" . (string)($row['area_name'] ?? ('#' . $row['local_area_id']))
        . "' belongs to barangay #{$row['area_barangay_id']}, child is in #{$row['child_barangay_id']} — clearing\n";

    if (!$dryRun) {
        $stmt = mysqli_prepare($conn, 'UPDATE children SET local_area_id = NULL WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        if (!mysqli_stmt_execute($stmt)) {
            fwrite(STDERR, "CLEAR failed for {$code}: " . mysqli_error($conn) . "\n");
            mysqli_stmt_close($stmt);
            continue;
        }
        mysqli_stmt_close($stmt);
        $staleCleared++;
    }
}
mysqli_free_result($staleRes);

echo "\n" . ($dryRun ? '[DRY RUN] ' : '')
    . "orphaned_children={$orphaned} healable={$healable} healed={$healed} "
    . "parent_unassigned={$parentUnassigned} stale_areas={$stale} stale_cleared={$staleCleared}\n";
