<?php

declare(strict_types=1);

/**
 * includes/kali_widget.php
 *
 * Floating Kali AI widget (general-mode only), shared by the nutritionist
 * and parent portals. Rendered at the end of every page via
 * nutritionist_layout_end() / parent_layout_end().
 *
 * Set $kaliWidgetRole = 'parent' before requiring this file for the parent
 * portal (defaults to 'nutritionist'). Only the full-chat link differs;
 * the API base, markup, and behavior are identical on both portals.
 *
 * Backend is reused untouched: POST api/chatbot/conversations.php to create
 * a general conversation, POST api/chatbot/chat.php to send messages.
 * Child-specific analysis stays on the full page (ai_assistant.php).
 */

// Standalone-safe: the including layout already has these, but the widget
// must not fatal if rendered from a new context.
require_once __DIR__ . '/admin_helpers.php';

$kaliWidgetRole = $kaliWidgetRole ?? 'nutritionist';
$kaliFullPageUrl = ($kaliWidgetRole === 'parent')
    ? app_url('/parent/ai_assistant.php')
    : app_url('/nutritionist/ai_assistant.php');
$kaliApiBase = app_url('/api/chatbot');
?>
<link rel="stylesheet" href="<?php echo admin_e(app_url('/assets/css/kali_widget.css')); ?>">
<div class="kali-widget" id="kaliWidget" data-api-base="<?php echo admin_e($kaliApiBase); ?>">
    <div class="kali-panel" id="kaliPanel" hidden role="dialog" aria-label="Kali AI chat">
        <div class="kali-panel-header">
            <div class="kali-panel-title-wrap">
                <span class="kali-dot" aria-hidden="true"></span>
                <div>
                    <div class="kali-panel-title">Kali AI</div>
                    <div class="kali-panel-subtitle">General nutrition assistant</div>
                </div>
            </div>
            <div class="kali-panel-header-actions">
                <a class="kali-expand-link" href="<?php echo admin_e($kaliFullPageUrl); ?>" title="Open full Kali AI">Full chat &rarr;</a>
                <button type="button" class="kali-close" id="kaliClose" aria-label="Close chat">&times;</button>
            </div>
        </div>
        <div class="kali-messages" id="kaliMessages" role="log" aria-live="polite" aria-label="Chat messages">
            <div class="kali-empty" id="kaliEmpty">
                <p>Ask about child nutrition, growth monitoring, or eOPT Plus.</p>
                <div class="kali-chips">
                    <button type="button" class="kali-chip" data-msg="What does WAZ mean?">What does WAZ mean?</button>
                    <button type="button" class="kali-chip" data-msg="Explain stunting in children">Explain stunting</button>
                    <button type="button" class="kali-chip" data-msg="What is the eOPT Plus program?">eOPT Plus</button>
                </div>
            </div>
        </div>
        <form class="kali-input-row" id="kaliForm" autocomplete="off">
            <input type="text" id="kaliInput" placeholder="Ask Kali AI..." aria-label="Message Kali AI" maxlength="2000">
            <button type="submit" class="kali-send" id="kaliSend" title="Send message" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
            </button>
        </form>
    </div>
    <button type="button" class="kali-bubble" id="kaliBubble" aria-expanded="false" aria-controls="kaliPanel" aria-label="Open Kali AI chat">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/></svg>
    </button>
</div>
<script src="<?php echo admin_e(app_url('/assets/js/kali_widget.js')); ?>"></script>
