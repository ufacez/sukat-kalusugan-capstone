<?php
/**
 * tools/redact_audit_pii.php
 *
 * One-time scrub of personal data (child names, emails, activation codes)
 * from historical audit_logs.description rows. New rows are already written
 * in de-identified form by the log_action() call sites.
 *
 * Only rows matching LEGACY patterns are touched — already-clean rows
 * never match, so the script is idempotent and safe to re-run.
 *
 * Usage:
 *   php tools/redact_audit_pii.php            # dry run (default)
 *   php tools/redact_audit_pii.php --apply    # write changes
 */

declare(strict_types=1);

require_once __DIR__ . '/../public_html/includes/db.php';

$apply = in_array('--apply', $argv ?? [], true);

$conn = get_db_connection();

/**
 * Each rule: which actions to scan, a matcher returning the redacted text
 * (or null to skip the row), using the row's own user_id where needed.
 *
 * @var array<int, array{actions: string[], rewrite: callable}>
 */
$rules = [
    // Second pass: strip measurement values from already-redacted rows,
    // leaving general identifiers (+ staff reason where present).
    [
        'actions' => ['measurement.create'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^(Manual measurement #\d+ for CHD-\d+): .*$/', (string)$row['description'], $m) === 1) {
                return $m[1];
            }
            return null;
        },
    ],
    [
        'actions' => ['MEASUREMENT_OVERRIDE'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^(Override measurement #\d+ for CHD-\d+): .* \| (Reason: .*)$/', (string)$row['description'], $m) === 1) {
                return $m[1] . ' | ' . $m[2];
            }
            return null;
        },
    ],
    [
        'actions' => ['MEASUREMENT_RECHECK'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^(Recheck measurement #\d+ for CHD-\d+): .* \| (Verifies #\S+) \| (Reason: .*)$/', (string)$row['description'], $m) === 1) {
                return $m[1] . ' | ' . $m[2] . ' | ' . $m[3];
            }
            return null;
        },
    ],
    [
        'actions' => ['measurement.create'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^(Manual measurement #\d+) recorded for .* \((CHD-\d+)\):(.*)$/', (string)$row['description'], $m) === 1) {
                return $m[1] . ' for ' . $m[2] . ':' . $m[3];
            }
            return null;
        },
    ],
    [
        'actions' => ['MEASUREMENT_OVERRIDE'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^(Override measurement #\d+) recorded for .* \((CHD-\d+)\):(.*)$/', (string)$row['description'], $m) === 1) {
                return $m[1] . ' for ' . $m[2] . ':' . $m[3];
            }
            return null;
        },
    ],
    [
        'actions' => ['MEASUREMENT_RECHECK'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^(Recheck measurement #\d+) recorded for .* \((CHD-\d+)\):(.*)$/', (string)$row['description'], $m) === 1) {
                return $m[1] . ' for ' . $m[2] . ':' . $m[3];
            }
            return null;
        },
    ],
    [
        'actions' => ['MEASUREMENT_RECHECK_START'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^(Kiosk recheck started for child #\d+) \(.*?\):(.*)$/', (string)$row['description'], $m) === 1) {
                return $m[1] . ':' . $m[2];
            }
            return null;
        },
    ],
    [
        'actions' => ['LOGIN', 'LOGOUT'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^(Staff|Parent) (login|logout) for \S+$/', (string)$row['description'], $m) === 1) {
                $who = $row['user_id'] !== null ? '(#' . (int)$row['user_id'] . ')' : '[redacted]';
                return $m[1] . ' ' . $m[2] . ' ' . $who;
            }
            return null;
        },
    ],
    [
        'actions' => ['CREATE_USER'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^Created user \S+ as (\w+)$/', (string)$row['description'], $m) === 1) {
                return 'Created staff account as ' . $m[1];
            }
            return null;
        },
    ],
    [
        'actions' => ['UPDATE_USER', 'DELETE_USER'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^(Updated|Archived|Restored|Permanently deleted) user \S+ \((\d+)\)$/', (string)$row['description'], $m) === 1) {
                return $m[1] . ' staff #' . $m[2];
            }
            return null;
        },
    ],
    [
        'actions' => ['CREATE_PARENT'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^Created parent account (\S+)( via family form)?$/', (string)$row['description'], $m) === 1) {
                if (str_starts_with($m[1], '#')) {
                    return null; // already de-identified
                }
                return 'Created parent account [redacted]' . ($m[2] ?? '');
            }
            return null;
        },
    ],
    [
        'actions' => ['UPDATE_PARENT'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^(Updated|Archived|Restored) parent \S+ \((\d+)\)(.*)$/', (string)$row['description'], $m) === 1) {
                return $m[1] . ' parent #' . $m[2] . $m[3];
            }
            return null;
        },
    ],
    [
        'actions' => ['CREATE_INVITATION'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^Generated (\w+) invitation for .* — role: (\w+)(, code: \S+)?$/', (string)$row['description'], $m) === 1) {
                return 'Generated ' . $m[1] . ' invitation — role: ' . $m[2];
            }
            return null;
        },
    ],
    [
        'actions' => ['DELETE_INVITATION'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^Cancelled invitation for .* \(code: .*\)$/', (string)$row['description']) === 1) {
                return 'Cancelled invitation [redacted]';
            }
            return null;
        },
    ],
    [
        'actions' => ['ACCOUNT_ACTIVATED'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^Account activated via code for .* — role: (\w+)$/', (string)$row['description'], $m) === 1) {
                return 'Staff account activated via invitation code — role: ' . $m[1];
            }
            return null;
        },
    ],
    [
        'actions' => ['PASSWORD_RESET_REQUEST'],
        'rewrite' => static function (array $row): ?string {
            if (preg_match('/^Password reset requested for \S+$/', (string)$row['description']) === 1) {
                return 'Password reset requested [redacted]';
            }
            return null;
        },
    ],
];

$totalMatched = 0;
$totalUpdated = 0;

foreach ($rules as $rule) {
    foreach ($rule['actions'] as $action) {
        $stmt = $conn->prepare('SELECT id, user_id, description FROM audit_logs WHERE action = ?');
        if ($stmt === false) {
            fwrite(STDERR, "prepare failed for {$action}\n");
            exit(1);
        }
        $stmt->bind_param('s', $action);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $new = ($rule['rewrite'])($row);
            if ($new === null || $new === (string)$row['description']) {
                continue;
            }
            $totalMatched++;
            echo '#' . $row['id'] . " [{$action}]\n";
            echo '  - ' . mb_substr((string)$row['description'], 0, 200) . "\n";
            echo '  + ' . mb_substr($new, 0, 200) . "\n";
            if ($apply) {
                $upd = $conn->prepare('UPDATE audit_logs SET description = ? WHERE id = ?');
                if ($upd === false) {
                    fwrite(STDERR, "update prepare failed\n");
                    exit(1);
                }
                $id = (int)$row['id'];
                $upd->bind_param('si', $new, $id);
                if (!$upd->execute()) {
                    fwrite(STDERR, "update failed for #{$id}\n");
                    exit(1);
                }
                $upd->close();
                $totalUpdated++;
            }
        }
        $stmt->close();
    }
}

echo ($apply ? "APPLIED: {$totalUpdated} row(s) redacted.\n" : "DRY RUN: {$totalMatched} row(s) would be redacted. Re-run with --apply to write.\n");
