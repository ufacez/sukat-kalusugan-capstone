<?php
declare(strict_types=1);

/**
 * confirm_modal.php — shared in-UI confirm + progress modals.
 *
 * Replaces every native window.confirm() (browser OK/Cancel) and bare
 * button-spinner submits across the admin / nutritionist / parent portals.
 * Reuses the existing .admin-modal-overlay / .admin-modal styles from
 * admin.css (loaded by all three portal layouts); the spinner + danger
 * button additions live in app.css.
 *
 * Integration: confirm_modal_shell() is echoed by the three *_layout_end()
 * functions, and the behaviour (window.SKConfirm / window.SKProgress)
 * lives in assets/js/admin.js which all three portals already load.
 */

function confirm_modal_shell(): string
{
    return <<<'HTML'
<div class="admin-modal-overlay" id="sk-confirm-overlay" hidden>
    <div class="admin-modal" style="max-width:440px;" role="alertdialog" aria-modal="true" aria-labelledby="sk-confirm-title" aria-describedby="sk-confirm-msg">
        <div class="admin-modal-head">
            <h3 id="sk-confirm-title">Please confirm</h3>
            <button class="admin-modal-close" data-sk-confirm-cancel type="button" aria-label="Cancel">&times;</button>
        </div>
        <div style="padding:16px 20px 20px;">
            <p id="sk-confirm-msg" style="margin:0;font-size:0.9rem;line-height:1.55;color:var(--admin-text);"></p>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px;flex-wrap:wrap;">
                <button class="admin-btn-secondary" data-sk-confirm-cancel type="button">Cancel</button>
                <button class="admin-btn" data-sk-confirm-ok type="button">Confirm</button>
            </div>
        </div>
    </div>
</div>
<div class="admin-modal-overlay" id="sk-progress-overlay" hidden>
    <div class="admin-modal" style="max-width:380px;" role="status" aria-live="polite">
        <div style="padding:30px 24px;text-align:center;">
            <span class="sk-progress-spinner" aria-hidden="true"></span>
            <div id="sk-progress-title" style="margin-top:14px;font-size:1rem;font-weight:800;color:var(--admin-text);">Working…</div>
            <div id="sk-progress-msg" style="margin-top:6px;font-size:0.82rem;color:var(--admin-muted);line-height:1.5;">Please wait — do not close this window.</div>
        </div>
    </div>
</div>
HTML;
}
