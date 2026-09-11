<?php

declare(strict_types=1);

/**
 * parent/ai_assistant.php
 *
 * Kali AI for parents. Same two-panel design as the nutritionist assistant:
 * left context sidebar (own children + recent chats), right chat area.
 *
 * Privacy is enforced server-side: api/chatbot/children.php only returns
 * this parent's own children, and api/chatbot/chat.php rejects any other
 * child_id with a 404 — so the picker below can never leak another family.
 */

require_once __DIR__ . '/../includes/parent_helpers.php';
require_once __DIR__ . '/../includes/who_calculator.php';

// The full chat lives on this page — hide the floating mini widget.
define('SKIP_KALI_WIDGET', true);

$user = parent_require_access();

$apiBase = app_url('/api/chatbot');

parent_layout_start(
    'Kali AI',
    "Friendly answers about your child's growth",
    'ai_assistant'
);

$aiCssVersion = (int) @filemtime(__DIR__ . '/../assets/css/ai_assistant.css');
$parentAiCssVersion = (int) @filemtime(__DIR__ . '/../assets/css/parent_ai_assistant.css');
?>

<link rel="stylesheet" href="<?php echo app_url('/assets/css/ai_assistant.css?v=' . $aiCssVersion); ?>">
<link rel="stylesheet" href="<?php echo app_url('/assets/css/parent_ai_assistant.css?v=' . $parentAiCssVersion); ?>">

<div class="ai-layout">

    <!-- ===== CONTEXT PANEL ===== -->
    <aside class="ai-sidebar" id="aiSidebar">
        <div class="ai-sidebar-header">
            <div class="ai-sidebar-title-row">
                <h3>Your children</h3>
                <button type="button" class="ai-context-close" id="aiContextClose" aria-label="Close child context panel">&times;</button>
            </div>
            <label class="ai-child-select-label">Child</label>
            <button type="button" class="ai-child-picker-btn" id="aiChildPickerBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span id="aiChildPickerLabel">Select your child</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><path d="m6 9 6 6 6-6"/></svg>
            </button>
        </div>

        <div class="ai-child-detail" id="aiChildDetail" style="display:none;">
            <div class="ai-detail-name" id="aiDetailName">—</div>
            <div class="ai-detail-meta" id="aiDetailMeta">—</div>
            <div class="ai-detail-scores" id="aiDetailScores"></div>
            <div class="ai-detail-status" id="aiDetailStatus"></div>
            <div class="ai-detail-date" id="aiDetailDate"></div>
        </div>

        <div class="ai-sessions">
            <div class="ai-sessions-header">
                <h3>Recent chats</h3>
                <span id="aiSessionCount">0</span>
            </div>
            <button type="button" class="ai-archive-btn" id="aiArchiveBtn">Clear conversations</button>
            <div class="ai-session-list" id="aiSessionList">
                <div class="ai-session-empty">No chats yet</div>
            </div>
            <div class="ai-session-pagination">
                <button type="button" class="ai-session-page-btn" id="aiSessionPrev" disabled aria-label="Previous chats">&lsaquo;</button>
                <span id="aiSessionPage">Page 1</span>
                <button type="button" class="ai-session-page-btn" id="aiSessionNext" disabled aria-label="Next chats">&rsaquo;</button>
            </div>
        </div>
    </aside>

    <!-- ===== CHAT ===== -->
    <main class="ai-chat">
        <div class="ai-chat-header">
            <div>
                <div class="ai-chat-title" id="aiChatTitle">Kali AI</div>
                <div class="ai-chat-subtitle" id="aiChatSubtitle">Pick your child above, or ask a general question</div>
            </div>
            <div class="ai-chat-actions">
                <button type="button" class="ai-btn-context" id="aiContextOpen" title="Choose a child">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    My child
                </button>
                <button type="button" class="ai-btn-new" id="aiBtnNew" title="New conversation">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    New
                </button>
                <button type="button" class="ai-btn-menu" id="aiContextMenu" title="Toggle children panel" aria-label="Toggle children panel" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </div>

        <div class="ai-messages" id="aiMessages" role="log" aria-live="polite" aria-label="Chat messages">
            <div class="ai-empty" id="aiEmptyState">
                <div class="ai-empty-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/></svg>
                </div>
                <h3>Hi, I&#8217;m Kali!</h3>
                <p>I can explain your child&#8217;s growth results in simple words. Pick your child above to begin.</p>
                <div class="ai-empty-suggestions">
                    <button class="ai-suggestion" data-msg="What does this result mean?">What does this mean?</button>
                    <button class="ai-suggestion" data-msg="Is my child growing well?">Growing well?</button>
                    <button class="ai-suggestion" data-msg="What does WAZ mean?">What is WAZ?</button>
                    <button class="ai-suggestion" data-msg="When should complementary feeding start?">Feeding tips</button>
                </div>
            </div>
        </div>

        <div class="ai-input-area">
            <div class="ai-input-row">
                <textarea id="aiInput" placeholder="Ask about your child's growth..." rows="1"></textarea>
                <button type="button" class="ai-send-btn" id="aiSendBtn" title="Send message" disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                </button>
            </div>
            <div class="ai-disclaimer">Kali explains measurement results in simple words — for medical advice, please visit your barangay nutritionist or doctor.</div>
        </div>
    </main>
</div>

<!-- ===== CHILD PICKER MODAL ===== -->
<div class="ai-modal-overlay" id="aiChildModal" role="dialog" aria-modal="true" aria-label="Select your child" hidden>
    <div class="ai-modal">
        <div class="ai-modal-header">
            <h3>Select your child</h3>
            <button type="button" class="ai-modal-close" id="aiChildModalClose" aria-label="Close">&times;</button>
        </div>
        <div class="ai-modal-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input type="text" id="aiChildSearch" placeholder="Search by name..." autocomplete="off">
        </div>
        <div class="ai-modal-list" id="aiChildModalList"></div>
        <div class="ai-modal-footer">
            <button type="button" class="ai-modal-page-btn" id="aiChildModalPrev" disabled>&lsaquo; Prev</button>
            <span id="aiChildModalPage">Page 1</span>
            <button type="button" class="ai-modal-page-btn" id="aiChildModalNext" disabled>Next &rsaquo;</button>
        </div>
        <div class="ai-modal-general">
            <button type="button" class="ai-modal-general-btn" id="aiGeneralModeBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                General questions
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const API = '<?php echo $apiBase; ?>/';
    const PAGE_URLS = {
        growthHistory: '<?php echo app_url('/parent/growth_history.php'); ?>',
        children: '<?php echo app_url('/parent/children.php'); ?>',
        appointments: '<?php echo app_url('/parent/appointments.php'); ?>'
    };
    const $ = (s, p) => (p || document).querySelector(s);
    const $$ = (s, p) => [...(p || document).querySelectorAll(s)];

    // fetch() never times out on its own: on mobile data a hung request
    // would leave state.sending stuck true forever (grey Send button, every
    // later tap silently ignored). Every network call goes through here.
    function fetchWithTimeout(url, options, ms) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), ms);
        const opts = Object.assign({}, options, { signal: controller.signal });
        return fetch(url, opts).finally(() => clearTimeout(timer));
    }
    const CHAT_TIMEOUT_MS = 75000;
    const QUICK_TIMEOUT_MS = 20000;

    function isTimeout(error) {
        return !!error && (error.name === 'AbortError' || (error.message || '').toLowerCase().includes('abort'));
    }
    function timeoutMessage() {
        return 'Kali is taking too long to reply. Please check your connection and try again.';
    }

    // --- State ---
    let state = {
        children: [],
        sessions: [],
        sessionPage: 1,
        selectedChildId: null,
        conversationId: null,
        sending: false,
        creatingConversation: false,
        conversationRequestToken: 0,
        pendingMessage: null,
        childDetail: null,
        childModalPage: 1,
        childModalPageSize: 20,
        childSearch: '',
    };

    // --- DOM refs ---
    const dom = {
        sidebar:      $('#aiSidebar'),
        childDetail:  $('#aiChildDetail'),
        contextOpen:  $('#aiContextOpen'),
        contextClose: $('#aiContextClose'),
        contextMenu:  $('#aiContextMenu'),
        sessionList:  $('#aiSessionList'),
        sessionCount: $('#aiSessionCount'),
        sessionPageEl:  $('#aiSessionPage'),
        sessionPrev:  $('#aiSessionPrev'),
        sessionNext:  $('#aiSessionNext'),
        archiveBtn:   $('#aiArchiveBtn'),
        chatTitle:    $('#aiChatTitle'),
        chatSubtitle: $('#aiChatSubtitle'),
        messages:     $('#aiMessages'),
        emptyState:   $('#aiEmptyState'),
        input:        $('#aiInput'),
        sendBtn:      $('#aiSendBtn'),
        btnNew:       $('#aiBtnNew'),
        detailName:   $('#aiDetailName'),
        detailMeta:   $('#aiDetailMeta'),
        detailScores: $('#aiDetailScores'),
        detailStatus: $('#aiDetailStatus'),
        detailDate:   $('#aiDetailDate'),
        childPickerBtn:  $('#aiChildPickerBtn'),
        childPickerLabel: $('#aiChildPickerLabel'),
        childModal:       $('#aiChildModal'),
        childModalClose:  $('#aiChildModalClose'),
        childModalList:   $('#aiChildModalList'),
        childModalSearch: $('#aiChildSearch'),
        childModalPage:   $('#aiChildModalPage'),
        childModalPrev:   $('#aiChildModalPrev'),
        childModalNext:   $('#aiChildModalNext'),
        generalModeBtn:   $('#aiGeneralModeBtn'),
    };


    // --- Init guard: one missing element must not kill the whole script.
    // A dead script is what leaves the Send button grey forever, so fail loud.
    const on = (el, ev, fn) => {
        if (el) {
            el.addEventListener(ev, fn);
        } else {
            console.error('Kali AI: missing page element, skipped listener for', ev);
        }
    };

    /* ================================================================
     * CHILD LIST (backend returns ONLY this parent's own children)
     * ================================================================ */

    function loadChildren() {
        fetchWithTimeout(API + 'children.php', undefined, QUICK_TIMEOUT_MS)
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    console.error('Kali AI: children.php:', res.message || 'request failed');
                    return;
                }
                state.children = res.data.children || [];
            })
            .catch((error) => { console.error('Kali AI: children.php fetch failed:', error); });
    }

    /* ================================================================
     * CHILD PICKER MODAL
     * ================================================================ */

    function openChildModal() {
        state.childModalPage = 1;
        state.childSearch = '';
        dom.childModalSearch.value = '';
        renderChildModal();
        dom.childModal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
        dom.childModalSearch.focus();
    }

    function closeChildModal() {
        dom.childModal.setAttribute('hidden', '');
        document.body.style.overflow = '';
    }

    function renderChildModal() {
        const q = state.childSearch.toLowerCase().trim();
        const filtered = state.children.filter(c => {
            if (!q) return true;
            return (c.name || '').toLowerCase().includes(q)
                || (c.child_code || '').toLowerCase().includes(q);
        });

        const pageSize = state.childModalPageSize;
        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        state.childModalPage = Math.min(state.childModalPage, totalPages);
        const start = (state.childModalPage - 1) * pageSize;
        const page = filtered.slice(start, start + pageSize);

        dom.childModalPage.textContent = 'Page ' + state.childModalPage + ' of ' + totalPages;
        dom.childModalPrev.disabled = state.childModalPage <= 1;
        dom.childModalNext.disabled = state.childModalPage >= totalPages;

        if (page.length === 0) {
            dom.childModalList.innerHTML = '<div class="ai-modal-empty">No children found</div>';
            return;
        }

        dom.childModalList.innerHTML = page.map(child => {
            const active = Number(child.id) === state.selectedChildId ? ' is-active' : '';
            const initials = (child.name || '?').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
            const sexLabel = child.sex ? (child.sex === 'M' ? 'Boy' : 'Girl') : '';
            const ageLabel = child.birthdate ? computeAge(child.birthdate) : '';
            const metaParts = [sexLabel, ageLabel].filter(Boolean);
            const metaStr = metaParts.join(' · ');
            return `<button type="button" class="ai-modal-item${active}" data-child-id="${child.id}">
                <div class="ai-modal-avatar">${esc(initials)}</div>
                <div class="ai-modal-item-info">
                    <div class="ai-modal-item-name">${esc(child.name)}</div>
                    <div class="ai-modal-item-meta">${esc(metaStr)}${child.child_code ? ' · ' + esc(child.child_code) : ''}</div>
                </div>
            </button>`;
        }).join('');

        $$('.ai-modal-item', dom.childModalList).forEach(item => {
            item.addEventListener('click', () => {
                const childId = Number(item.dataset.childId);
                closeChildModal();
                selectChildFromModal(childId);
            });
        });
    }

    function selectChildFromModal(childId) {
        if (state.sending) return;
        state.selectedChildId = childId;
        state.conversationId = null;

        if (childId) {
            const child = state.children.find(c => Number(c.id) === childId);
            dom.childPickerLabel.textContent = child ? child.name : 'Select your child';
            if (child) {
                loadChildDetail(childId);
                dom.chatTitle.textContent = child.name;
                dom.chatSubtitle.textContent = child.child_code || 'Growth results';
            }
        }

        dom.messages.innerHTML = '';
        createConversation(childId);
    }

    function selectGeneralFromModal() {
        closeChildModal();
        if (state.sending) return;
        state.selectedChildId = null;
        state.conversationId = null;
        dom.childPickerLabel.textContent = 'General questions';
        dom.childDetail.style.display = 'none';
        dom.chatTitle.textContent = 'Kali AI';
        dom.chatSubtitle.textContent = 'General questions about child growth';
        dom.messages.innerHTML = '';
        createConversation(null);
    }

    function loadSessions() {
        fetchWithTimeout(API + 'conversations.php', undefined, QUICK_TIMEOUT_MS)
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    console.error('Kali AI: sessions:', res.message || 'request failed');
                    return;
                }
                state.sessions = res.data.conversations || [];
                state.sessionPage = 1;
                renderSessions();
            })
            .catch((error) => { console.error('Kali AI: sessions fetch failed:', error); });
    }

    function renderSessions() {
        const pageSize = 10;
        const totalPages = Math.max(1, Math.ceil(state.sessions.length / pageSize));
        state.sessionPage = Math.min(state.sessionPage, totalPages);
        const start = (state.sessionPage - 1) * pageSize;
        const pageSessions = state.sessions.slice(start, start + pageSize);

        dom.sessionCount.textContent = String(state.sessions.length);
        dom.sessionPageEl.textContent = 'Page ' + state.sessionPage + ' of ' + totalPages;
        dom.sessionPrev.disabled = state.sessionPage <= 1;
        dom.sessionNext.disabled = state.sessionPage >= totalPages;

        if (pageSessions.length === 0) {
            dom.sessionList.innerHTML = '<div class="ai-session-empty">No chats yet</div>';
            return;
        }

        dom.sessionList.innerHTML = pageSessions.map(session => {
            const active = Number(session.id) === state.conversationId ? ' is-active' : '';
            const title = session.title || 'New chat';
            const child = session.child_name || 'General question';
            return `<button type="button" class="ai-session-item${active}" data-session-id="${session.id}">
                <strong>${esc(title)}</strong><span>${esc(child)}</span>
            </button>`;
        }).join('');

        $$('.ai-session-item', dom.sessionList).forEach(item => {
            item.addEventListener('click', () => openSession(Number(item.dataset.sessionId)));
        });
    }


    /* ================================================================
     * CHILD SELECTION
     * ================================================================ */

    function revealContextPanel() {
        openChildModal();
    }

    function hideContextPanel() {
        $('.ai-layout').classList.remove('is-context-open');
        dom.contextMenu.setAttribute('aria-expanded', 'false');
        if (!dom.childModal.hasAttribute('hidden')) {
            closeChildModal();
        }
    }

    function toggleContextPanel() {
        // Mobile (<=720px): the sidebar is collapsed, so the menu button
        // opens it as an overlay sheet with the children + recent chats.
        // Desktop: the sidebar is already visible, so open the picker modal.
        if (window.matchMedia('(max-width: 720px)').matches) {
            const layout = $('.ai-layout');
            const open = !layout.classList.contains('is-context-open');
            layout.classList.toggle('is-context-open', open);
            if (dom.contextMenu) dom.contextMenu.setAttribute('aria-expanded', String(open));
        } else {
            revealContextPanel();
        }
    }

    function isChildAssessmentPrompt(text) {
        return /\b(child|children|kid|kids|my son|my daughter|which .*need|who .*need|follow[- ]?up|at[- ]?risk|worsening|improv|assess|review|growing well)\b/i.test(text);
    }

    function openSession(sessionId) {
        if (state.sending) return;
        const session = state.sessions.find(item => Number(item.id) === sessionId);
        if (!session) return;

        state.conversationId = sessionId;
        state.selectedChildId = session.child_id ? Number(session.child_id) : null;

        if (state.selectedChildId) {
            const child = state.children.find(c => Number(c.id) === state.selectedChildId);
            dom.childPickerLabel.textContent = child ? child.name : 'Select your child';
            if (child) {
                loadChildDetail(state.selectedChildId);
                dom.chatTitle.textContent = child.name;
                dom.chatSubtitle.textContent = child.child_code || 'Growth results';
            }
        } else {
            dom.childPickerLabel.textContent = 'General questions';
            dom.childDetail.style.display = 'none';
            dom.chatTitle.textContent = 'Kali AI';
            dom.chatSubtitle.textContent = 'Past chat';
        }

        dom.messages.innerHTML = '<div class="ai-session-loading">Loading chat...</div>';
        renderSessions();

        fetchWithTimeout(API + 'conversations.php?id=' + sessionId, undefined, QUICK_TIMEOUT_MS)
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error(res.message || 'Could not load this chat.');
                dom.messages.innerHTML = '';
                (res.data.messages || []).forEach(message => {
                    appendBubble(message.role === 'assistant' ? 'assistant' : 'user', message.content);
                });
            })
            .catch(error => {
                dom.messages.innerHTML = '';
                appendBubble('system', error.message || 'Could not load this chat.');
            });
    }

    function loadChildDetail(childId) {
        fetchWithTimeout(API + 'child_summary.php?child_id=' + childId, undefined, QUICK_TIMEOUT_MS)
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    console.error('Kali AI: child_summary:', res.message || 'request failed');
                    return;
                }
                state.childDetail = res.data;
                renderChildDetail(res.data);
            })
            .catch((error) => { console.error('Kali AI: child_summary fetch failed:', error); });
    }

    function renderChildDetail(data) {
        const c = data.child;
        const m = data.measurement;

        dom.childDetail.style.display = '';
        dom.detailName.textContent = c.name;
        dom.detailMeta.textContent = `${c.sex} | ${c.age}`;

        let scoresHtml = '';
        if (m) {
            scoresHtml += scoreCard('WAZ', m.waz);
            scoresHtml += scoreCard('HAZ', m.haz);
            scoresHtml += scoreCard('WHZ', m.whz);
            dom.detailStatus.textContent = m.nutritional_status || '—';
            dom.detailDate.textContent = 'Last measured: ' + (m.date || '—');
        } else {
            scoresHtml = '<div style="grid-column:1/-1;text-align:center;color:var(--ai-muted);font-size:0.72rem;padding:8px;">No measurements yet</div>';
            dom.detailStatus.textContent = '';
            dom.detailDate.textContent = '';
        }
        dom.detailScores.innerHTML = scoresHtml;
    }

    function scoreCard(label, value) {
        if (value === null || value === undefined) {
            return `<div class="ai-score"><div class="ai-score-label">${label}</div><div class="ai-score-value">—</div></div>`;
        }
        const v = parseFloat(value);
        let cls = 'is-normal';
        if (label === 'WAZ' && v < -2) cls = v < -3 ? 'is-danger' : 'is-warn';
        else if (label === 'HAZ' && v < -2) cls = v < -3 ? 'is-danger' : 'is-warn';
        else if (label === 'WHZ' && (v < -2 || v > 2)) cls = v < -3 || v > 3 ? 'is-danger' : 'is-warn';
        return `<div class="ai-score"><div class="ai-score-label">${label}</div><div class="ai-score-value ${cls}">${v.toFixed(2)}</div></div>`;
    }


    /* ================================================================
     * CONVERSATIONS
     * ================================================================ */

    function createConversation(childId) {
        const requestToken = ++state.conversationRequestToken;
        state.creatingConversation = true;
        fetchWithTimeout(API + 'conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ child_id: childId, title: null })
        }, QUICK_TIMEOUT_MS)
        .then(r => r.json())
        .then(res => {
            if (requestToken !== state.conversationRequestToken) return;
            if (!res.success) {
                // Never fail silently: the queued message is dropped here,
                // so say so and keep the chat usable.
                state.pendingMessage = null;
                console.error('Kali AI: conversations.php:', res.message || 'request failed');
                appendBubble('system', (res && res.message) || 'Could not start the chat. Please try again.');
                return;
            }
            state.conversationId = res.data.id;
            loadSessions();
            if (childId) {
                showEmptySuggestions();
            } else {
                showGlobalEmpty();
            }
            const pendingMessage = state.pendingMessage;
            state.pendingMessage = null;
            if (pendingMessage) sendMessage(pendingMessage);
        })
        .catch((error) => {
            console.error('Kali AI: conversations.php fetch failed:', error);
            if (requestToken !== state.conversationRequestToken) return;
            // The queued message is dropped here — say so instead of
            // swallowing it silently.
            state.pendingMessage = null;
            appendBubble('system', isTimeout(error) ? timeoutMessage() : 'Could not reach Kali. Please check your connection and try again.');
        })
        .finally(() => {
            if (requestToken === state.conversationRequestToken) {
                state.creatingConversation = false;
            }
        });
    }

    function showEmptySuggestions() {
        dom.messages.innerHTML = `
            <div class="ai-empty">
                <div class="ai-empty-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/></svg>
                </div>
                <h3>Ask about your child</h3>
                <p>I explain growth results in simple words — no confusing terms.</p>
                <div class="ai-empty-suggestions">
                    <button class="ai-suggestion" data-msg="What does this result mean?">What does this mean?</button>
                    <button class="ai-suggestion" data-msg="Is my child growing well?">Growing well?</button>
                    <button class="ai-suggestion" data-msg="Explain the z-scores simply">Explain simply</button>
                </div>
            </div>`;
        bindSuggestions();
    }


    /* ================================================================
     * MESSAGES
     * ================================================================ */

    function sendMessage(text) {
        if (state.sending || !text.trim()) return;
        if (isChildAssessmentPrompt(text) && !state.selectedChildId) {
            revealContextPanel();
        }
        if (!state.conversationId) {
            state.pendingMessage = text;
            if (!state.creatingConversation) createConversation(state.selectedChildId);
            return;
        }

        state.sending = true;
        dom.sendBtn.disabled = true;
        dom.sendBtn.classList.add('is-loading');
        dom.input.value = '';
        dom.input.style.height = 'auto';

        const emptyEl = dom.messages.querySelector('.ai-empty');
        if (emptyEl) emptyEl.remove();

        appendBubble('user', text);

        const typingEl = document.createElement('div');
        typingEl.className = 'ai-msg is-assistant';
        typingEl.innerHTML = '<div class="ai-typing"><div class="ai-typing-dot"></div><div class="ai-typing-dot"></div><div class="ai-typing-dot"></div></div>';
        dom.messages.appendChild(typingEl);
        scrollToBottom();

        fetchWithTimeout(API + 'chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                conversation_id: state.conversationId,
                child_id: state.selectedChildId,
                message: text
            })
        }, CHAT_TIMEOUT_MS)
        .then(async r => {
            const raw = await r.text();
            let res;
            try {
                res = JSON.parse(raw);
            } catch (error) {
                throw new Error('Kali returned an unclear response. Please try again.');
            }
            if (!r.ok || !res.success) {
                throw new Error(res.message || `Something went wrong (HTTP ${r.status}).`);
            }
            return res;
        })
        .then(res => {
            typingEl.remove();
            appendBubble('assistant', res.data.reply);
            appendNavigationAction(text);
            loadSessions();
        })
        .catch(error => {
            typingEl.remove();
            console.error('Kali AI: chat.php:', error);
            appendBubble('system', isTimeout(error) ? timeoutMessage() : (error.message || 'Could not reach Kali. Please try again.'));
        })
        .finally(() => {
            state.sending = false;
            dom.sendBtn.classList.remove('is-loading');
            dom.sendBtn.disabled = dom.input.value.trim() === '';
            dom.input.focus();
        });
    }

    function appendBubble(role, text) {
        const el = document.createElement('div');
        el.className = 'ai-msg is-' + role;
        const avatar = role === 'assistant'
            ? '<div class="ai-msg-avatar" aria-hidden="true">Kali</div>'
            : '';
        const content = role === 'assistant' ? formatAssistantText(text) : esc(text).replace(/\n/g, '<br>');
        el.innerHTML = avatar + '<div class="ai-msg-bubble">' + content + '</div>';
        dom.messages.appendChild(el);
        scrollToBottom();
    }

    function formatAssistantText(text) {
        return esc(text)
            .replace(/\*\*(.+?)\*\*/gs, '<strong>$1</strong>')
            .replace(/^###\s+(.+)$/gm, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
    }

    function appendNavigationAction(prompt) {
        const value = prompt.toLowerCase();
        let action = null;

        if (value.includes('appointment') || value.includes('check-up') || value.includes('checkup')) {
            action = { label: 'View Appointments', href: PAGE_URLS.appointments };
        } else if (value.includes('growth') || value.includes('measurement') || value.includes('result') || value.includes('weigh')) {
            action = { label: 'View Growth History', href: PAGE_URLS.growthHistory };
        } else if (value.includes('children') || value.includes('child list') || value.includes('my kids')) {
            action = { label: 'View My Children', href: PAGE_URLS.children };
        }

        if (!action) return;

        const card = document.createElement('div');
        card.className = 'ai-navigation-action';
        card.innerHTML = '<span>Open the matching page:</span>'
            + '<a href="' + action.href + '">' + esc(action.label) + ' <span aria-hidden="true">&rarr;</span></a>';
        dom.messages.appendChild(card);
        scrollToBottom();
    }

    function scrollToBottom() {
        requestAnimationFrame(() => {
            dom.messages.scrollTop = dom.messages.scrollHeight;
        });
    }


    /* ================================================================
     * NEW CONVERSATION
     * ================================================================ */

    function newConversation() {
        state.conversationId = null;
        dom.messages.innerHTML = '';

        if (state.selectedChildId) {
            createConversation(state.selectedChildId);
        } else {
            dom.childPickerLabel.textContent = 'Select your child';
            dom.childDetail.style.display = 'none';
            dom.chatTitle.textContent = 'Kali AI';
            dom.chatSubtitle.textContent = 'General questions about child growth';
            createConversation(null);
        }
    }

    function showGlobalEmpty() {
        dom.chatTitle.textContent = 'Hi, I\u2019m Kali!';
        dom.chatSubtitle.textContent = 'Pick your child above, or ask a general question';
        dom.messages.innerHTML = `
            <div class="ai-empty" id="aiEmptyState">
                <div class="ai-empty-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z"/></svg>
                </div>
                <h3>Hi, I&#8217;m Kali!</h3>
                <p>I can explain your child&#8217;s growth results in simple words. Pick your child above to begin.</p>
                <div class="ai-empty-suggestions">
                    <button class="ai-suggestion" data-msg="What does this result mean?">What does this mean?</button>
                    <button class="ai-suggestion" data-msg="Is my child growing well?">Growing well?</button>
                    <button class="ai-suggestion" data-msg="What does WAZ mean?">What is WAZ?</button>
                    <button class="ai-suggestion" data-msg="When should complementary feeding start?">Feeding tips</button>
                </div>
                <div class="ai-page-links" aria-label="Parent pages">
                    <a href="<?php echo app_url('/parent/growth_history.php'); ?>">Growth History</a>
                    <a href="<?php echo app_url('/parent/children.php'); ?>">My Children</a>
                    <a href="<?php echo app_url('/parent/appointments.php'); ?>">Appointments</a>
                </div>
            </div>`;
        bindSuggestions();
    }


    /* ================================================================
     * SUGGESTIONS
     * ================================================================ */

    function bindSuggestions() {
        $$('.ai-suggestion', dom.messages).forEach(btn => {
            btn.addEventListener('click', () => {
                const msg = btn.dataset.msg;
                if (msg) {
                    if (!state.conversationId) {
                        fetchWithTimeout(API + 'conversations.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ child_id: state.selectedChildId, title: null })
                        }, QUICK_TIMEOUT_MS)
                        .then(r => r.json())
                        .then(res => {
                            if (res.success) {
                                state.conversationId = res.data.id;
                                sendMessage(msg);
                            }
                        });
                    } else {
                        sendMessage(msg);
                    }
                }
            });
        });
    }


    /* ================================================================
     * HELPERS
     * ================================================================ */

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function computeAge(birthdate) {
        if (!birthdate) return '';
        const birth = new Date(birthdate);
        const today = new Date();
        let years = today.getFullYear() - birth.getFullYear();
        let months = today.getMonth() - birth.getMonth();
        if (months < 0) { years--; months += 12; }
        if (today.getDate() < birth.getDate()) { months--; if (months < 0) { years--; months += 12; } }
        if (years >= 1) {
            return years + 'y ' + months + 'm';
        } else if (months >= 1) {
            return months + ' months';
        } else {
            const days = Math.floor((today - birth) / (1000 * 60 * 60 * 24));
            return days + ' days';
        }
    }

    /* ================================================================
     * EVENT LISTENERS
     * ================================================================ */

    on(dom.childPickerBtn, 'click', openChildModal);
    on(dom.childModalClose, 'click', closeChildModal);
    on(dom.childModal, 'click', (e) => {
        if (e.target === dom.childModal) closeChildModal();
    });
    on(dom.childModalSearch, 'input', () => {
        state.childSearch = dom.childModalSearch.value;
        state.childModalPage = 1;
        renderChildModal();
    });
    on(dom.childModalPrev, 'click', () => {
        state.childModalPage--;
        renderChildModal();
    });
    on(dom.childModalNext, 'click', () => {
        state.childModalPage++;
        renderChildModal();
    });
    on(dom.generalModeBtn, 'click', selectGeneralFromModal);

    on(dom.contextOpen, 'click', revealContextPanel);
    on(dom.contextClose, 'click', hideContextPanel);
    on(dom.contextMenu, 'click', toggleContextPanel);

    on(dom.sessionPrev, 'click', () => {
        state.sessionPage -= 1;
        renderSessions();
    });

    on(dom.sessionNext, 'click', () => {
        state.sessionPage += 1;
        renderSessions();
    });

    on(dom.archiveBtn, 'click', () => {
        if (!state.sessions.length || !window.confirm('Delete all your chats with Kali?')) return;

        fetchWithTimeout(API + 'conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'archive_all' })
        }, QUICK_TIMEOUT_MS)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Could not delete chats.');
            state.conversationId = null;
            state.sessions = [];
            renderSessions();
            showGlobalEmpty();
            createConversation(null);
        })
        .catch(error => appendBubble('system', error.message));
    });

    on(dom.sendBtn, 'click', () => {
        sendMessage(dom.input.value.trim());
    });

    on(dom.input, 'keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(dom.input.value.trim());
        }
    });

    // Auto-resize textarea + dim send button when empty
    const syncSendState = () => {
        if (!dom.sendBtn || !dom.input) return;
        dom.sendBtn.disabled = state.sending || dom.input.value.trim() === '';
    };
    on(dom.input, 'input', () => {
        dom.input.style.height = 'auto';
        dom.input.style.height = Math.min(dom.input.scrollHeight, 120) + 'px';
        syncSendState();
    });
    syncSendState();

    on(dom.btnNew, 'click', newConversation);

    // Safety sweep: never leave the page scroll-locked (e.g. after
    // back-navigation or an interrupted modal interaction).
    window.addEventListener('pageshow', () => {
        if (dom.childModal && dom.childModal.hasAttribute('hidden')) {
            document.body.style.overflow = '';
        }
    });

    bindSuggestions();


    /* ================================================================
     * INIT
     * ================================================================ */

    loadChildren();
    loadSessions();
    createConversation(null);

})();
</script>

<?php parent_layout_end(); ?>
