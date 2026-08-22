<?php

/**
 * password_reset.php
 * Forgot-password token lifecycle, shared by staff (users) and parent accounts.
 *
 * Functions:
 *   password_reset_find_account(string $email): ?array
 *   password_reset_create_token(string $accountType, int $accountId): string   -- returns the RAW token
 *   password_reset_send_email(string $email, string $name, string $rawToken): void
 *   password_reset_validate_token(string $rawToken): ?array                    -- returns token row or null
 *   password_reset_consume_token(int $tokenId, string $accountType, int $accountId, string $newPasswordHash): void
 *
 * Only a SHA-256 hash of the token is ever stored, same idea as password_hash()
 * for passwords: even a full read of the database doesn't hand out working
 * reset links, only the raw token in the emailed URL does that.
 */

require_once __DIR__ . '/db.php';

if (!defined('PASSWORD_RESET_TOKEN_TTL_MINUTES')) {
    define('PASSWORD_RESET_TOKEN_TTL_MINUTES', 30);
}

/**
 * Looks up a single account (staff or parent) by email. Staff accounts win
 * if the same address somehow exists in both tables, mirroring the lookup
 * order api/auth/login.php already uses.
 *
 * @return array{type:string,id:int,name:string,email:string}|null
 */
function password_reset_find_account(string $email): ?array
{
    $conn = get_db_connection();

    $staffStmt = mysqli_prepare(
        $conn,
        'SELECT id, name, email FROM users WHERE LOWER(email) = LOWER(?) AND status = "active" LIMIT 1'
    );

    if ($staffStmt !== false) {
        mysqli_stmt_bind_param($staffStmt, 's', $email);
        mysqli_stmt_execute($staffStmt);
        $result = mysqli_stmt_get_result($staffStmt);
        $staff = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($staffStmt);

        if (is_array($staff)) {
            return [
                'type' => 'staff',
                'id' => (int)$staff['id'],
                'name' => (string)$staff['name'],
                'email' => (string)$staff['email'],
            ];
        }
    }

    $parentStmt = mysqli_prepare(
        $conn,
        'SELECT id, name, email FROM parents WHERE LOWER(email) = LOWER(?) AND status = "active" LIMIT 1'
    );

    if ($parentStmt !== false) {
        mysqli_stmt_bind_param($parentStmt, 's', $email);
        mysqli_stmt_execute($parentStmt);
        $result = mysqli_stmt_get_result($parentStmt);
        $parent = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($parentStmt);

        if (is_array($parent)) {
            return [
                'type' => 'parent',
                'id' => (int)$parent['id'],
                'name' => (string)$parent['name'],
                'email' => (string)$parent['email'],
            ];
        }
    }

    return null;
}

/**
 * Creates a fresh reset token for an account and returns the RAW token
 * (only ever put into the emailed link, never stored/logged as-is).
 * Any earlier unused tokens for the same account are invalidated first so
 * only the newest emailed link can work.
 */
function password_reset_create_token(string $accountType, int $accountId): string
{
    $conn = get_db_connection();

    $invalidateStmt = mysqli_prepare(
        $conn,
        'UPDATE password_reset_tokens
         SET used_at = NOW()
         WHERE account_type = ? AND account_id = ? AND used_at IS NULL'
    );

    if ($invalidateStmt !== false) {
        mysqli_stmt_bind_param($invalidateStmt, 'si', $accountType, $accountId);
        mysqli_stmt_execute($invalidateStmt);
        mysqli_stmt_close($invalidateStmt);
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $ttlMinutes = PASSWORD_RESET_TOKEN_TTL_MINUTES;
    $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $insertStmt = mysqli_prepare(
        $conn,
        'INSERT INTO password_reset_tokens (account_type, account_id, token_hash, expires_at, ip_address)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)'
    );

    mysqli_stmt_bind_param($insertStmt, 'sisis', $accountType, $accountId, $tokenHash, $ttlMinutes, $ipAddress);
    mysqli_stmt_execute($insertStmt);
    mysqli_stmt_close($insertStmt);

    return $rawToken;
}

/**
 * Sends the reset email via Gmail SMTP (through PHPMailer) when SMTP_USER /
 * SMTP_PASS are configured — this is what you want in production, since
 * PHP's built-in mail() is unreliable on most hosts and tends to land in
 * spam. Falls back to mail(), and finally to logging the link, so local
 * dev keeps working even before SMTP or PHPMailer are set up. See
 * config.example.php for the SMTP_* constants and README/HOW_TO_APPLY.md
 * for the Gmail App Password setup steps.
 */
function password_reset_send_email(string $email, string $name, string $rawToken): void
{
    $resetUrl = app_url('/auth/reset-password.php?token=' . urlencode($rawToken));
    $fullResetUrl = password_reset_absolute_url($resetUrl);

    $subject = 'Reset your Sukat Kalusugan password';
    $textBody = "Hi " . $name . ",\n\n"
        . "We received a request to reset your Sukat Kalusugan password. "
        . "This link expires in " . PASSWORD_RESET_TOKEN_TTL_MINUTES . " minutes:\n\n"
        . $fullResetUrl . "\n\n"
        . "If you didn't request this, you can safely ignore this email — "
        . "your password will not be changed.\n";

    if (password_reset_smtp_configured()) {
        $sent = password_reset_send_via_smtp($email, $name, $subject, $textBody, $fullResetUrl);

        if ($sent) {
            return;
        }

        // SMTP is configured but the send itself failed (bad credentials,
        // Gmail blocked it, network hiccup, etc.) — fall through to mail()
        // below rather than silently losing the email.
    }

    $headers = 'From: ' . password_reset_from_header() . "\r\nContent-Type: text/plain; charset=utf-8";
    $sent = @mail($email, $subject, $textBody, $headers);

    if (!$sent) {
        // Last-resort dev fallback — most local XAMPP setups have no MTA
        // configured at all, so mail() has nowhere to deliver to.
        error_log('[password_reset] Could not send email (SMTP not configured or failed, mail() also failed). Reset link for '
            . $email . ': ' . $fullResetUrl);
    }
}

/**
 * True once Gmail (or any SMTP) credentials are filled in in config.php.
 */
function password_reset_smtp_configured(): bool
{
    return defined('SMTP_HOST') && SMTP_HOST !== ''
        && defined('SMTP_USER') && SMTP_USER !== ''
        && defined('SMTP_PASS') && SMTP_PASS !== '';
}

function password_reset_from_header(): string
{
    $fromEmail = defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS !== '' ? MAIL_FROM_ADDRESS : 'no-reply@sukat.local';
    $fromName = defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME : 'Sukat Kalusugan';

    return $fromName . ' <' . $fromEmail . '>';
}

/**
 * Sends via PHPMailer + SMTP (Gmail by default: smtp.gmail.com:587, STARTTLS,
 * authenticated with an address + a 16-character Gmail App Password — Gmail
 * rejects your normal account password for SMTP once 2FA is on, which it
 * needs to be to generate an App Password in the first place).
 *
 * Requires PHPMailer (`composer require phpmailer/phpmailer`, see
 * composer.json). If vendor/autoload.php isn't there yet — composer install
 * hasn't been run — this just returns false so the caller falls back to
 * mail().
 */
function password_reset_send_via_smtp(string $toEmail, string $toName, string $subject, string $textBody, string $resetUrl): bool
{
    $autoloadPath = __DIR__ . '/../../vendor/autoload.php';

    if (!file_exists($autoloadPath)) {
        error_log('[password_reset] SMTP_* is configured but vendor/autoload.php is missing — run `composer install` (see composer.json) to install PHPMailer.');

        return false;
    }

    require_once $autoloadPath;

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        error_log('[password_reset] SMTP_* is configured but PHPMailer is not installed — run `composer require phpmailer/phpmailer`.');

        return false;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = defined('SMTP_PORT') && SMTP_PORT !== '' ? (int)SMTP_PORT : 587;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS; // Gmail App Password, not your login password.
        $mail->SMTPSecure = (defined('SMTP_ENCRYPTION') && SMTP_ENCRYPTION === 'ssl')
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $fromEmail = defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS !== '' ? MAIL_FROM_ADDRESS : SMTP_USER;
        $fromName = defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME : 'Sukat Kalusugan';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->isHTML(false);
        $mail->Body = $textBody;

        $mail->send();

        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('[password_reset] PHPMailer SMTP send failed for ' . $toEmail . ': ' . $mail->ErrorInfo);

        return false;
    }
}

/**
 * Turns an app-relative URL (from app_url()) into an absolute one for the
 * email body, using the current request's scheme + host.
 */
function password_reset_absolute_url(string $relativeUrl): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host . $relativeUrl;
}

/**
 * Validates a raw token from the reset link: must exist, be unused, and not
 * be expired. Returns the token row (with account_type/account_id) or null.
 *
 * @return array{id:int,account_type:string,account_id:int}|null
 */
function password_reset_validate_token(string $rawToken): ?array
{
    if ($rawToken === '') {
        return null;
    }

    $conn = get_db_connection();
    $tokenHash = hash('sha256', $rawToken);

    $stmt = mysqli_prepare(
        $conn,
        'SELECT id, account_type, account_id
         FROM password_reset_tokens
         WHERE token_hash = ?
           AND used_at IS NULL
           AND expires_at > NOW()
         LIMIT 1'
    );

    if ($stmt === false) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 's', $tokenHash);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result instanceof mysqli_result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'account_type' => (string)$row['account_type'],
        'account_id' => (int)$row['account_id'],
    ];
}

/**
 * Applies the new password hash to the right table and marks the token used.
 * Wrapped in a transaction so a failure partway through can't leave the
 * token burned with the password unchanged, or vice versa.
 */
function password_reset_consume_token(int $tokenId, string $accountType, int $accountId, string $newPasswordHash): bool
{
    $conn = get_db_connection();
    $table = $accountType === 'staff' ? 'users' : 'parents';

    mysqli_begin_transaction($conn);

    try {
        $updateStmt = mysqli_prepare($conn, "UPDATE {$table} SET password_hash = ? WHERE id = ? LIMIT 1");

        if ($updateStmt === false) {
            throw new RuntimeException('Unable to prepare password update.');
        }

        mysqli_stmt_bind_param($updateStmt, 'si', $newPasswordHash, $accountId);
        mysqli_stmt_execute($updateStmt);
        $updated = mysqli_stmt_affected_rows($updateStmt) > 0;
        mysqli_stmt_close($updateStmt);

        if (!$updated) {
            throw new RuntimeException('Account not found.');
        }

        $burnStmt = mysqli_prepare(
            $conn,
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL LIMIT 1'
        );

        if ($burnStmt === false) {
            throw new RuntimeException('Unable to prepare token update.');
        }

        mysqli_stmt_bind_param($burnStmt, 'i', $tokenId);
        mysqli_stmt_execute($burnStmt);
        $burned = mysqli_stmt_affected_rows($burnStmt) > 0;
        mysqli_stmt_close($burnStmt);

        if (!$burned) {
            throw new RuntimeException('Token already used.');
        }

        mysqli_commit($conn);

        return true;
    } catch (Throwable $e) {
        mysqli_rollback($conn);

        return false;
    }
}
