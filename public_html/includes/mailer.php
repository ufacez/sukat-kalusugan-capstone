<?php

require_once __DIR__ . '/config.php';

/**
 * Sends an email via SMTP (PHPMailer) with mail() fallback.
 *
 * $textBody is always sent (as the whole body for text-only mail, or as
 * AltBody when $htmlBody is provided). Existing text-only callers keep
 * working unchanged — pass $htmlBody to get a multipart HTML email with
 * a button-style CTA plus the plain-text fallback.
 */
function send_mail(string $toEmail, string $subject, string $textBody, string $htmlBody = ''): bool
{
    $autoloadPath = __DIR__ . '/../../vendor/autoload.php';

    if (!file_exists($autoloadPath)) {
        error_log('[mailer] vendor/autoload.php missing — run composer install.');
        return false;
    }

    require_once $autoloadPath;

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        error_log('[mailer] PHPMailer not installed — run composer require phpmailer/phpmailer.');
        return false;
    }

    $smtpUser = defined('SMTP_USER') ? SMTP_USER : '';
    $smtpPass = defined('SMTP_PASS') ? SMTP_PASS : '';

    if ($smtpUser === '' || $smtpPass === '') {
        error_log('[mailer] SMTP_USER / SMTP_PASS not configured — falling back to mail().');
        return send_mail_via_php_mail($toEmail, $subject, $textBody, $htmlBody);
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $mail->Port = defined('SMTP_PORT') && SMTP_PORT !== '' ? (int)SMTP_PORT : 587;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = (defined('SMTP_ENCRYPTION') && SMTP_ENCRYPTION === 'ssl')
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

        $fromEmail = defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS !== '' ? MAIL_FROM_ADDRESS : $smtpUser;
        $fromName = defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME : 'Sukat Kalusugan';

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($toEmail);
        $mail->Subject = $subject;

        if ($htmlBody !== '') {
            $mail->isHTML(true);
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody;
        } else {
            $mail->isHTML(false);
            $mail->Body = $textBody;
        }

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('[mailer] SMTP send failed for ' . $toEmail . ': ' . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Plain mail() fallback that also supports an HTML body (multipart).
 * Used when SMTP credentials are not configured (local dev).
 */
function send_mail_via_php_mail(string $toEmail, string $subject, string $textBody, string $htmlBody = ''): bool
{
    $fromEmail = defined('MAIL_FROM_ADDRESS') && MAIL_FROM_ADDRESS !== '' ? MAIL_FROM_ADDRESS : 'no-reply@sukat.local';
    $fromName = defined('MAIL_FROM_NAME') && MAIL_FROM_NAME !== '' ? MAIL_FROM_NAME : 'Sukat Kalusugan';

    if ($htmlBody === '') {
        return @mail($toEmail, $subject, $textBody);
    }

    $boundary = 'sk_' . bin2hex(random_bytes(16));
    $headers = 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n"
        . "MIME-Version: 1.0\r\n"
        . 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";

    $message = "--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $textBody . "\r\n\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/html; charset=utf-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $htmlBody . "\r\n\r\n"
        . "--{$boundary}--";

    return @mail($toEmail, $subject, $message, $headers);
}
