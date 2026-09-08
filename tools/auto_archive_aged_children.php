<?php

/**
 * tools/auto_archive_aged_children.php
 *
 * Automatically archives (status → inactive) any active children who have
 * reached 60 months of age. Writes an audit log entry for each archived child.
 *
 * Designed to be run periodically (e.g., daily cron) or on-demand.
 *
 * Usage (CLI):
 *   php tools/auto_archive_aged_children.php            # live run
 *   php tools/auto_archive_aged_children.php --dry-run  # report only
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	exit("CLI only\n");
}

require __DIR__ . '/../public_html/includes/config.php';
require __DIR__ . '/../public_html/includes/db.php';
require __DIR__ . '/../public_html/includes/audit_logger.php';

$dryRun = in_array('--dry-run', $argv, true);

$conn = get_db_connection();

$res = mysqli_query(
	$conn,
	"SELECT c.id, c.child_code, c.first_name, c.last_name, c.birthdate,
	        TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) AS age_months
	 FROM children c
	 WHERE c.status = 'active'
	   AND TIMESTAMPDIFF(MONTH, c.birthdate, CURDATE()) >= 60
	 ORDER BY c.birthdate ASC"
);

if ($res === false) {
	fwrite(STDERR, 'Query failed: ' . mysqli_error($conn) . "\n");
	exit(1);
}

$total = 0;
$archived = 0;

while ($row = mysqli_fetch_assoc($res)) {
	$total++;
	$childId = (int)$row['id'];
	$name = trim($row['first_name'] . ' ' . $row['last_name']);

	echo "#{$childId} {$name} ({$row['child_code']}) — {$row['age_months']} months old\n";

	if (!$dryRun) {
		$stmt = mysqli_prepare($conn, 'UPDATE children SET status = ? WHERE id = ?');
		$inactive = 'inactive';
		mysqli_stmt_bind_param($stmt, 'si', $inactive, $childId);
		if (!mysqli_stmt_execute($stmt)) {
			fwrite(STDERR, "  FAILED to archive #{$childId}: " . mysqli_error($conn) . "\n");
			mysqli_stmt_close($stmt);
			continue;
		}
		mysqli_stmt_close($stmt);

		log_action(
			'UPDATE_CHILD',
			"Auto-archived child #{$childId} ({$row['child_code']}) — reached 60 months of age.",
			'warning'
		);
	}

	$archived++;
}

echo ($dryRun ? '[DRY RUN] ' : '') . "scanned={$total} archived={$archived}\n";
