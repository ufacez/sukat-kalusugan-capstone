<?php
/**
 * email_template.php
 *
 * Single reusable branded HTML email layout for Sukat Kalusugan
 * (password reset, staff invitation/activation, and any future
 * action email). Industry-standard basics:
 *   - table layout + inline CSS (works in Gmail/Outlook clients)
 *   - hidden preheader text
 *   - one clear CTA button + copyable fallback link below it
 *   - plain-text AltBody is always sent alongside (see mailer.php)
 *
 * All dynamic values are escaped inside this file — callers pass
 * plain strings/URLs and never hand-build HTML.
 */

declare(strict_types=1);

/**
 * Builds a complete branded action email.
 *
 * @param string      $preheader  Short preview text shown in inbox lists.
 * @param string      $greeting   e.g. "Hi Juan,".
 * @param string      $headline   e.g. "Reset your password".
 * @param string[]    $paragraphs Plain-text body paragraphs.
 * @param string      $ctaLabel   Button text, e.g. "Reset password".
 * @param string      $ctaUrl     Absolute https:// URL behind the button.
 * @param string|null $codeLabel  Optional label above the code box (e.g. "Your activation code").
 * @param string|null $code       Optional code string (e.g. 6-char invite code).
 * @param string      $expiryNote e.g. "This link expires in 30 minutes.".
 */
function email_template_action(
    string $preheader,
    string $greeting,
    string $headline,
    array $paragraphs,
    string $ctaLabel,
    string $ctaUrl,
    ?string $codeLabel = null,
    ?string $code = null,
    string $expiryNote = ''
): string {
    $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

    $bodyHtml = '';
    foreach ($paragraphs as $p) {
        if (trim($p) === '') {
            continue;
        }
        $bodyHtml .= '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">' . $e($p) . '</p>';
    }

    $codeHtml = '';
    if ($code !== null && $code !== '') {
        $codeHtml = '<p style="margin:4px 0 6px;font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#64748b;">'
            . $e($codeLabel !== null && $codeLabel !== '' ? $codeLabel : 'Code') . '</p>'
            . '<div style="display:inline-block;padding:12px 24px;border:1px dashed #0b6e4f;border-radius:10px;background:#f0fdf6;'
            . 'font-family:Consolas,Menlo,monospace;font-size:22px;font-weight:700;letter-spacing:.18em;color:#0b6e4f;">'
            . $e($code) . '</div>';
    }

    $expiryHtml = trim($expiryNote) !== ''
        ? '<p style="margin:16px 0 0;font-size:13px;line-height:1.5;color:#64748b;">' . $e($expiryNote) . '</p>'
        : '';

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#eef4f0;">'
        . '<span style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $e($preheader) . '</span>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef4f0;padding:28px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;">'
        . '<tr><td style="background:#0b6e4f;padding:22px 30px;">'
        . '<div style="font-size:19px;font-weight:800;color:#ffffff;">Sukat Kalusugan</div>'
        . '<div style="font-size:12px;color:#c9e8d8;">Tamang Sukat, Gabay sa wastong Kalusugan</div>'
        . '</td></tr>'
        . '<tr><td style="padding:30px 30px 12px;">'
        . '<p style="margin:0 0 6px;font-size:15px;color:#334155;">' . $e($greeting) . '</p>'
        . '<h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;color:#0f2a20;">' . $e($headline) . '</h1>'
        . $bodyHtml
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0 6px;"><tr><td align="center" style="border-radius:10px;background:#0b6e4f;">'
        . '<a href="' . $e($ctaUrl) . '" style="display:inline-block;padding:13px 34px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">'
        . $e($ctaLabel) . '</a>'
        . '</td></tr></table>'
        . $codeHtml
        . $expiryHtml
        . '</td></tr>'
        . '<tr><td style="padding:18px 30px 24px;border-top:1px solid #e6efe9;">'
        . '<p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">'
        . 'If you didn&#39;t request this, you can safely ignore this email — your account will not be changed.</p>'
        . '</td></tr>'
        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';
}
