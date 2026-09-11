/* Kali AI floating widget (general-mode only).
 * Reuses POST api/chatbot/conversations.php + POST api/chatbot/chat.php.
 * Child-specific analysis stays on the full page (ai_assistant.php). */
(function () {
    'use strict';

    var root = document.getElementById('kaliWidget');
    if (!root) return;

    var API = (root.getAttribute('data-api-base') || '').replace(/\/$/, '') + '/';
    var bubble = document.getElementById('kaliBubble');
    var panel = document.getElementById('kaliPanel');
    var closeBtn = document.getElementById('kaliClose');
    var messages = document.getElementById('kaliMessages');
    var form = document.getElementById('kaliForm');
    var input = document.getElementById('kaliInput');
    var sendBtn = document.getElementById('kaliSend');

    var conversationId = null;
    var sending = false;
    var creating = false;

    // fetch() never times out on its own — a hung request would leave the
    // widget stuck (grey button / endless typing dots) on mobile data.
    var CHAT_TIMEOUT_MS = 75000;
    var QUICK_TIMEOUT_MS = 20000;

    function fetchWithTimeout(url, options, ms) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, ms);
        var opts = {};
        for (var key in options) {
            if (Object.prototype.hasOwnProperty.call(options, key)) opts[key] = options[key];
        }
        opts.signal = controller.signal;
        return fetch(url, opts).finally(function () { clearTimeout(timer); });
    }

    function isTimeout(err) {
        return !!err && (err.name === 'AbortError' || String((err && err.message) || '').toLowerCase().indexOf('abort') !== -1);
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function formatAssistant(text) {
        return esc(text)
            .replace(/\*\*(.+?)\*\*/gs, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
    }

    function scrollDown() {
        requestAnimationFrame(function () {
            messages.scrollTop = messages.scrollHeight;
        });
    }

    function bubbleMsg(role, html) {
        var el = document.createElement('div');
        el.className = 'kali-msg is-' + role;
        el.innerHTML = html;
        messages.appendChild(el);
        scrollDown();
    }

    function clearEmpty() {
        var empty = document.getElementById('kaliEmpty');
        if (empty) empty.remove();
    }

    function setOpen(open) {
        if (open) {
            panel.removeAttribute('hidden');
            bubble.setAttribute('aria-expanded', 'true');
            input.focus();
        } else {
            panel.setAttribute('hidden', '');
            bubble.setAttribute('aria-expanded', 'false');
            bubble.focus();
        }
    }

    function ensureConversation(thenSend) {
        if (conversationId) {
            thenSend();
            return;
        }
        var stored = null;
        try { stored = sessionStorage.getItem('kali_conv_id'); } catch (e) {}
        if (stored && Number(stored) > 0) {
            conversationId = Number(stored);
            thenSend();
            return;
        }
        if (creating) return;
        creating = true;
        fetchWithTimeout(API + 'conversations.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ child_id: null, title: null })
        }, QUICK_TIMEOUT_MS)
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success && res.data && res.data.id) {
                    conversationId = Number(res.data.id);
                    try { sessionStorage.setItem('kali_conv_id', String(conversationId)); } catch (e) {}
                    thenSend();
                } else {
                    bubbleMsg('system', esc((res && res.message) || 'Could not start the chat.'));
                }
            })
            .catch(function (err) {
                bubbleMsg('system', isTimeout(err)
                    ? 'Kali is taking too long to reply. Please check your connection and try again.'
                    : 'Could not reach the assistant. Please try again.');
            })
            .finally(function () { creating = false; });
    }

    function sendMessage(text) {
        if (sending || !text.trim()) return;
        ensureConversation(function () {
            sending = true;
            sendBtn.disabled = true;
            input.value = '';
            clearEmpty();
            bubbleMsg('user', esc(text).replace(/\n/g, '<br>'));

            var typing = document.createElement('div');
            typing.className = 'kali-typing';
            typing.innerHTML = '<span></span><span></span><span></span>';
            messages.appendChild(typing);
            scrollDown();

            fetchWithTimeout(API + 'chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    conversation_id: conversationId,
                    child_id: 0,
                    message: text
                })
            }, CHAT_TIMEOUT_MS)
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    typing.remove();
                    if (res && res.success) {
                        bubbleMsg('assistant', formatAssistant(res.data.reply));
                    } else {
                        bubbleMsg('system', esc((res && res.message) || 'The assistant returned an error.'));
                    }
                })
                .catch(function (err) {
                    typing.remove();
                    bubbleMsg('system', isTimeout(err)
                        ? 'Kali is taking too long to reply. Please check your connection and try again.'
                        : 'Could not reach the assistant. Please try again.');
                })
                .finally(function () {
                    sending = false;
                    sendBtn.disabled = input.value.trim() === '';
                    input.focus();
                });
        });
    }

    bubble.addEventListener('click', function () {
        setOpen(panel.hasAttribute('hidden'));
    });
    closeBtn.addEventListener('click', function () {
        setOpen(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !panel.hasAttribute('hidden')) setOpen(false);
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        sendMessage(input.value.trim());
    });
    input.addEventListener('input', function () {
        sendBtn.disabled = sending || input.value.trim() === '';
    });

    root.querySelectorAll('.kali-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            if (chip.dataset.msg) sendMessage(chip.dataset.msg);
        });
    });
})();
