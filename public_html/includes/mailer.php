<?php

require_once __DIR__ . '/config.php';

function send_mail(string $toEmail, string $subject, string $textBody): bool
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
        return @mail($toEmail, $subject, $textBody);
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
        $mail->isHTML(false);
        $mail->Body = $textBody;

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('[mailer] SMTP send failed for ' . $toEmail . ': ' . $mail->ErrorInfo);
        return false;
    }
}
