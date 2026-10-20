<?php

declare(strict_types=1);

/**
 * tools/backfill_invitation_emails.php
 *
 * One-off backfill for legacy invitations created before the auto-email
 * flow: rows with no invitee_email get a local login-only address so the
 * Invitations table never shows a blank email.
 *
 * Rules:
 *   - only touches rows WHERE invitee_email IS NULL OR invitee_email = '';
 *   - if the user created from the invitation exists (same decrypted name)
 *     and has an email, reuse that real address;
 *   - otherwise derive firstname+surname+suffix@sukat.kalusugan, where the
 *     suffix comes from invitee_user_code when present, else the id padded
 *     to 3 digits;
 *   - the @sukat.kalusugan address is a display/login id only. Nothing is
 *     ever sent over SMTP.
 *
 * Idempotent: safe to re-run.
 *
 * Usage:
 *   php tools/backfill_invitation_emails.php           # preview only
 *   php tools/backfill_invitation_emails.php --apply   # write changes
 */

require_once __DIR__ . '/../public_html/includes/admin_helpers.php';

$apply = in_array('--apply', $argv, true);
$mode = $apply ? 'APPLY' : 'DRY-RUN (no changes written)';

echo "=== backfill_invitation_emails.php — {$mode} ===\n";

$rows = admin_fetch_all(
    "SELECT id, invitee_name, invitee_email, invitee_user_code, role, status
     FROM invitations
     WHERE invitee_email IS NULL OR invitee_email = ''
     ORDER BY id",
    '',
    []
);

if ($rows === []) {
    echo "Nothing to backfill — every invitation already has an email.\n";
    exit(0);
}

$updated = 0;
$skipped = 0;

foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    $name = trim((string)($row['invitee_name'] ?? ''));
    $email = '';

    // Prefer the real address of the account created from this invitation.
    if ($name !== '') {
        $user = admin_fetch_one(
            "SELECT email FROM users WHERE name = ? AND email <> '' ORDER BY id LIMIT 1",
            's',
            [$name]
        );
        $candidate = trim((string)($user['email'] ?? ''));
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
            $email = $candidate;
        }
    }

    // Otherwise derive the local login id.
    if ($email === '') {
        $code = trim((string)($row['invitee_user_code'] ?? ''));
        if ($code === '') {
            $code = 'USR-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
        }
        $parts = admin_split_full_name($name);
        $email = admin_make_staff_login($parts['first'], $parts['last'], $code)['email'];
    }

    if ($email === '') {
        echo "  skipped  #{$id}: could not derive an email\n";
        $skipped++;
        continue;
    }

    $status = (string)($row['status'] ?? '');
    $role = (string)($row['role'] ?? '');

    if (!$apply) {
        echo "  would set #{$id} ({$role}/{$status}) -> {$email}\n";
        continue;
    }

    $ok = admin_execute(
        "UPDATE invitations SET invitee_email = ?
         WHERE id = ? AND (invitee_email IS NULL OR invitee_email = '')",
        'si',
        [$email, $id]
    );

    $saved = $ok
        ? trim((string)(admin_fetch_one('SELECT invitee_email FROM invitations WHERE id = ? LIMIT 1', 'i', [$id])['invitee_email'] ?? ''))
        : '';

    if ($saved === $email) {
        echo "  updated  #{$id} ({$role}/{$status}) -> {$email}\n";
        $updated++;
    } else {
        echo "  failed   #{$id}\n";
        $skipped++;
    }
}

echo $apply
    ? "Done. Updated {$updated}, skipped {$skipped}.\n"
    : "Dry run complete. Re-run with --apply to write.\n";
