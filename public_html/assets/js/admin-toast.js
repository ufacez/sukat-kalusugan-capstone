/* AdminToast — floating notifications shared by every portal + auth pages.
 *
 * Converts the legacy inline strips into floating toasts on page load:
 *   - `[data-toast]` divs rendered by the portal layouts (?notice= flashes)
 *   - `.flash.flash-notice` divs on the standalone auth pages
 * The inline elements are removed after conversion; their CSS stays as a
 * no-JS fallback. Also usable directly: AdminToast.success/error/info().
 *
 * No dependencies. Zero backend changes — all 100+ notice producers flow
 * through untouched.
 */
(function () {
    'use strict';

    var SUCCESS_MS = 4000;
    var INFO_MS = 4000;
    var ERROR_MS = 8000;
    var MAX_STACK = 4;

    var ICONS = {
        success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg>',
        error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 9v4M12 17h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>'
    };

    var TITLES = {
        success: 'Success',
        error: 'Something went wrong',
        info: 'Notice'
    };

    // Technical strings users should never have to decode. Server texts are
    // untouched — this only softens what is displayed.
    var FRIENDLY_MAP = [
        [/^conversation not found\.?$/i, 'This chat could not be found. Please start a new chat.'],
        [/^that child could not be found\.?$/i, 'That child record could not be found.'],
        [/^please sign in to continue\.?$/i, 'Please sign in to continue.'],
        [/http\s*\d{3}/i, 'Something went wrong on our side. Please try again.'],
        [/not valid json|unexpected token/i, 'Something went wrong on our side. Please try again.'],
        [/failed to fetch|networkerror|network error/i, null], // handled with offline hint below
        [/load failed/i, 'Could not load the data. Please try again.']
    ];

    function friendlyMessage(text) {
        var msg = String(text == null ? '' : text);
        if (/failed to fetch|networkerror|network error/i.test(msg)) {
            if (typeof navigator !== 'undefined' && navigator.onLine === false) {
                return 'You appear to be offline. Please check your connection and try again.';
            }
            return 'Could not reach the server. Please check your connection and try again.';
        }
        for (var i = 0; i < FRIENDLY_MAP.length; i++) {
            if (FRIENDLY_MAP[i][1] && FRIENDLY_MAP[i][0].test(msg)) {
                return FRIENDLY_MAP[i][1];
            }
        }
        return msg;
    }

    function stack() {
        var el = document.querySelector('.admin-toast-stack');
        if (el) return el;
        el = document.createElement('div');
        el.className = 'admin-toast-stack';
        el.setAttribute('aria-live', 'polite');
        document.body.appendChild(el);
        return el;
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function dismiss(toast) {
        if (!toast || toast.classList.contains('is-leaving')) return;
        toast.classList.add('is-leaving');
        setTimeout(function () {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }, 220);
    }

    function show(message, type) {
        type = (type === 'error' || type === 'info') ? type : 'success';
        var text = friendlyMessage(message);
        if (!text.trim()) return null;

        var host = stack();
        while (host.children.length >= MAX_STACK) {
            dismiss(host.firstElementChild);
        }

        var duration = type === 'error' ? ERROR_MS : (type === 'info' ? INFO_MS : SUCCESS_MS);

        var toast = document.createElement('div');
        toast.className = 'admin-toast is-' + type;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.innerHTML =
            '<span class="admin-toast-icon" aria-hidden="true">' + ICONS[type] + '</span>' +
            '<div class="admin-toast-body">' +
                '<div class="admin-toast-title">' + esc(TITLES[type]) + '</div>' +
                '<div class="admin-toast-message">' + esc(text) + '</div>' +
            '</div>' +
            '<button type="button" class="admin-toast-close" aria-label="Dismiss notification">&times;</button>' +
            '<span class="admin-toast-progress" aria-hidden="true" style="animation-duration:' + duration + 'ms;"></span>';

        toast.querySelector('.admin-toast-close').addEventListener('click', function () {
            dismiss(toast);
        });

        var timer = setTimeout(function () { dismiss(toast); }, duration);
        toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
        toast.addEventListener('mouseleave', function () {
            timer = setTimeout(function () { dismiss(toast); }, 2000);
        });

        host.appendChild(toast);
        return toast;
    }

    // Convert one legacy inline strip into a floating toast, then remove it.
    function convert(el, fallbackType) {
        if (!el || el.dataset.toastDone) return;
        el.dataset.toastDone = '1';
        var text = (el.textContent || '').trim();
        var type = fallbackType;
        if (el.classList.contains('is-error') || el.classList.contains('flash-error')) type = 'error';
        else if (el.classList.contains('is-success')) type = 'success';
        else if (type !== 'error' && type !== 'info') type = 'success';
        if (text !== '') show(text, type);
        if (el.parentNode) el.parentNode.removeChild(el);
    }

    function convertLegacy() {
        // Portal layouts: only the layout-level flash carries data-toast, so
        // inline form-validation banners sharing .admin-flash are untouched.
        document.querySelectorAll('[data-toast]').forEach(function (el) {
            convert(el, 'success');
        });
        // Standalone auth pages (login / forgot / activate).
        document.querySelectorAll('.flash.flash-notice, .flash.flash-error').forEach(function (el) {
            if (el.id === 'globalNotice' && el.style.display === 'none') return;
            convert(el, 'info');
        });
    }

    window.AdminToast = {
        show: show,
        success: function (msg) { return show(msg, 'success'); },
        error: function (msg) { return show(msg, 'error'); },
        info: function (msg) { return show(msg, 'info'); }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', convertLegacy);
    } else {
        convertLegacy();
    }
})();
