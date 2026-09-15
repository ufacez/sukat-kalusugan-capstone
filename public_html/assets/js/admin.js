(function () {
  // Theme toggle (used by both the sidebar [data-theme-toggle] and the topbar
  // [data-theme-toggle-topbar] buttons). Both should stay in sync — any click
  // updates the localStorage value, the data-theme attribute, and the
  // visible label of the sidebar toggle.
  const getPreferredTheme = () => {
    let stored = null;
    try {
      stored = localStorage.getItem("theme");
    } catch (error) {
      stored = null;
    }
    if (stored) return stored;
    return typeof window.matchMedia === "function" && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
  };

  const applyTheme = (theme, flashButton) => {
    document.documentElement.setAttribute("data-theme", theme);
    try {
      localStorage.setItem("theme", theme);
    } catch (error) {
      // Theme persistence is optional; sidebar navigation must still work.
    }
    if (flashButton) {
      // Brief pulse so the user gets a visual confirmation of the switch.
      flashButton.classList.remove("is-flashing");
      // Force reflow so the animation can replay on rapid clicks.
      void flashButton.offsetWidth;
      flashButton.classList.add("is-flashing");
    }
  };

  applyTheme(getPreferredTheme());

  document.querySelectorAll("[data-theme-toggle], [data-theme-toggle-topbar]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      const current = document.documentElement.getAttribute("data-theme");
      applyTheme(current === "dark" ? "light" : "dark", btn);
    });
  });

  const colorSchemeQuery = typeof window.matchMedia === "function"
    ? window.matchMedia("(prefers-color-scheme: dark)")
    : null;
  const handleColorSchemeChange = function (e) {
    let hasStoredTheme = false;
    try {
      hasStoredTheme = Boolean(localStorage.getItem("theme"));
    } catch (error) {
      hasStoredTheme = false;
    }
    if (!hasStoredTheme) {
      applyTheme(e.matches ? "dark" : "light");
    }
  };

  if (colorSchemeQuery && typeof colorSchemeQuery.addEventListener === "function") {
    colorSchemeQuery.addEventListener("change", handleColorSchemeChange);
  } else if (colorSchemeQuery && typeof colorSchemeQuery.addListener === "function") {
    colorSchemeQuery.addListener(handleColorSchemeChange);
  }

  const sidebar = document.querySelector("[data-admin-sidebar]");
  const toggle = document.querySelector("[data-admin-sidebar-toggle]");
  const shell = document.querySelector(".admin-shell");
  let overlay = document.querySelector("[data-admin-sidebar-overlay]");
  const getStoredValue = (key) => {
    try {
      return localStorage.getItem(key);
    } catch (error) {
      return null;
    }
  };
  const setStoredValue = (key, value) => {
    try {
      localStorage.setItem(key, value);
    } catch (error) {
      // Preference persistence is optional.
    }
  };

  if (sidebar && toggle && shell) {
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.className = "admin-sidebar-overlay";
      overlay.setAttribute("data-admin-sidebar-overlay", "");
      sidebar.insertAdjacentElement("afterend", overlay);
    }

    const isMobile = () => window.innerWidth <= 920;

    // Restore the desktop collapsed preference on load.
    const savedCollapse = getStoredValue("sidebar_collapsed");
    if (savedCollapse === "true" && !isMobile()) {
      shell.classList.add("is-collapsed");
    }

    // Sync the ARIA state on load so screen readers know whether the
    // sidebar is currently expanded (open) or collapsed (closed).
    const syncAria = () => {
      if (isMobile()) {
        const open = sidebar.classList.contains("is-open");
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
        sidebar.setAttribute("aria-hidden", open ? "false" : "true");
      } else {
        const collapsed = shell.classList.contains("is-collapsed");
        toggle.setAttribute("aria-expanded", collapsed ? "false" : "true");
        sidebar.setAttribute("aria-hidden", "false");
      }
    };
    syncAria();

    // Desktop: toggle the .is-collapsed class on the shell. The CSS
    // collapses the sidebar to a 68px rail in response to that class.
    const toggleDesktop = () => {
      shell.classList.toggle("is-collapsed");
      const collapsed = shell.classList.contains("is-collapsed");
      setStoredValue("sidebar_collapsed", collapsed ? "true" : "false");
      syncAria();
    };

    // Mobile: open the drawer as an overlay.
    const openMobile = () => {
      sidebar.classList.add("is-open");
      overlay.classList.add("is-open");
      document.body.classList.add("no-scroll");
      syncAria();
    };

    const closeMobile = () => {
      sidebar.classList.remove("is-open");
      overlay.classList.remove("is-open");
      document.body.classList.remove("no-scroll");
      syncAria();
    };

    toggle.addEventListener("click", (event) => {
      event.preventDefault();
      if (isMobile()) {
        if (sidebar.classList.contains("is-open")) {
          closeMobile();
        } else {
          openMobile();
        }
      } else {
        toggleDesktop();
      }
    });

    overlay.addEventListener("click", closeMobile);

    sidebar.querySelectorAll("a").forEach((link) => {
      link.addEventListener("click", () => {
        if (isMobile()) {
          closeMobile();
        }
      });
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && isMobile() && sidebar.classList.contains("is-open")) {
        closeMobile();
      }
    });

    // Swipe-to-close on touch devices. The user drags the sidebar left
    // past a 30% threshold and the drawer closes. The gesture only
    // activates when the drawer is already open on a mobile breakpoint.
    let touchStartX = 0;
    let touchStartY = 0;
    let touching = false;
    sidebar.addEventListener("touchstart", (event) => {
      if (!isMobile() || !sidebar.classList.contains("is-open")) return;
      const touch = event.touches[0];
      touchStartX = touch.clientX;
      touchStartY = touch.clientY;
      touching = true;
    }, { passive: true });

    sidebar.addEventListener("touchmove", (event) => {
      if (!touching || !isMobile()) return;
      const touch = event.touches[0];
      const dx = touch.clientX - touchStartX;
      const dy = touch.clientY - touchStartY;
      // If the gesture is more horizontal than vertical, drag the
      // drawer left in real time. We translate the sidebar up to -80%
      // before letting it snap closed.
      if (Math.abs(dx) > Math.abs(dy) && dx < 0) {
        const sidebarWidth = sidebar.offsetWidth || 280;
        const translate = Math.max(dx, -sidebarWidth * 0.8);
        sidebar.style.transform = "translateX(" + translate + "px)";
        sidebar.style.transition = "none";
      }
    }, { passive: true });

    const endTouch = (event) => {
      if (!touching) return;
      touching = false;
      sidebar.style.transition = "";
      const touch = (event.changedTouches && event.changedTouches[0]) || null;
      if (!touch) {
        sidebar.style.transform = "";
        return;
      }
      const dx = touch.clientX - touchStartX;
      const sidebarWidth = sidebar.offsetWidth || 280;
      if (dx < -sidebarWidth * 0.3) {
        closeMobile();
      }
      sidebar.style.transform = "";
    };
    sidebar.addEventListener("touchend", endTouch, { passive: true });
    sidebar.addEventListener("touchcancel", endTouch, { passive: true });

    window.addEventListener("resize", () => {
      if (!isMobile()) {
        // Crossing up to desktop — clear the mobile drawer state and
        // any inline transform left over from a swipe gesture.
        sidebar.classList.remove("is-open");
        overlay.classList.remove("is-open");
        document.body.classList.remove("no-scroll");
        sidebar.style.transform = "";
        syncAria();
      } else {
        // Crossing down to mobile — clear the desktop collapsed state
        // so the drawer starts in the "closed" position.
        shell.classList.remove("is-collapsed");
        setStoredValue("sidebar_collapsed", "false");
        syncAria();
      }
    });
  }

  // Strip out the legacy standalone sidebar collapse button if it's still
  // around — the topbar burger now handles both mobile drawer and desktop
  // collapse, so the chevron button is a no-op.
  document.querySelectorAll("[data-admin-sidebar-collapse]").forEach((btn) => {
    btn.remove();
  });

  /* Shared in-UI confirm + progress modals (replaces window.confirm).
   * The overlay shells are printed by confirm_modal_shell() in every portal
   * layout; if a page lacks them we fall back to the native dialog so the
   * action is never silently swallowed. */
  var SKConfirm = (function () {
    var lastFocus = null;

    function shell() {
      var overlay = document.getElementById("sk-confirm-overlay");
      if (!overlay) return null;
      return {
        overlay: overlay,
        title: document.getElementById("sk-confirm-title"),
        msg: document.getElementById("sk-confirm-msg"),
        ok: overlay.querySelector("[data-sk-confirm-ok]"),
      };
    }

    function close(result) {
      var s = shell();
      if (s) s.overlay.hidden = true;
      if (lastFocus && typeof lastFocus.focus === "function") {
        try { lastFocus.focus(); } catch (error) { /* focus restore is best-effort */ }
      }
      var cb = close._pending;
      close._pending = null;
      if (typeof cb === "function") cb(result === true);
    }

    function onKey(e) {
      if (e.key === "Escape") {
        e.stopPropagation();
        close(false);
      } else if (e.key === "Enter" && document.activeElement && document.activeElement.hasAttribute("data-sk-confirm-cancel")) {
        close(false);
      }
    }

    function confirm(message, opts) {
      var o = opts || {};
      return new Promise(function (resolve) {
        var s = shell();
        if (!s || !s.ok) {
          resolve(window.confirm(message || "Are you sure?"));
          return;
        }
        close._pending = resolve;
        lastFocus = document.activeElement;
        if (s.title) s.title.textContent = o.title || "Please confirm";
        if (s.msg) s.msg.textContent = message || "Are you sure?";
        s.ok.textContent = o.confirmLabel || "Confirm";
        s.ok.classList.toggle("is-danger", o.danger === true);
        s.overlay.hidden = false;
        try { s.ok.focus(); } catch (error) { /* focus is best-effort */ }
      });
    }

    document.addEventListener("keydown", function (e) {
      var s = shell();
      if (s && !s.overlay.hidden && (e.key === "Escape")) onKey(e);
    });
    document.addEventListener("click", function (e) {
      var s = shell();
      if (!s || s.overlay.hidden) return;
      var t = e.target;
      if (t && t.hasAttribute && t.hasAttribute("data-sk-confirm-cancel")) { close(false); return; }
      if (t && t.hasAttribute && t.hasAttribute("data-sk-confirm-ok")) { close(true); return; }
      if (t === s.overlay) close(false);
    });

    return confirm;
  })();

  var SKProgress = (function () {
    var failsafe = null;

    function shell() {
      var overlay = document.getElementById("sk-progress-overlay");
      if (!overlay) return null;
      return {
        overlay: overlay,
        title: document.getElementById("sk-progress-title"),
        msg: document.getElementById("sk-progress-msg"),
      };
    }

    function show(message, title) {
      var s = shell();
      if (!s) return;
      if (s.title) s.title.textContent = title || "Please wait…";
      if (s.msg) s.msg.textContent = message || "Working — please don't close this window.";
      s.overlay.hidden = false;
      if (failsafe) window.clearTimeout(failsafe);
      failsafe = window.setTimeout(hide, 30000);
    }

    function hide() {
      var s = shell();
      if (s) s.overlay.hidden = true;
      if (failsafe) { window.clearTimeout(failsafe); failsafe = null; }
    }

    window.addEventListener("pagehide", hide);

    return { show: show, hide: hide };
  })();

  window.SKConfirm = SKConfirm;
  window.SKProgress = SKProgress;

  function progressMessageFor(form, submitter) {
    var custom = form.getAttribute("data-progress") ||
      (submitter && submitter.getAttribute && submitter.getAttribute("data-progress"));
    if (custom) return custom;
    var label = submitter ? (submitter.value || submitter.textContent || "") : "";
    label = String(label).replace(/\s+/g, " ").trim();
    if (label.length > 80) label = label.slice(0, 77) + "…";
    if (label) return label + " — please don't close this window.";
    return "Working — please don't close this window.";
  }

  function wireConfirms(scope) {
    (scope || document).querySelectorAll("[data-admin-confirm]").forEach(function (el) {
      if (el.dataset.skConfirmWired === "1") return;
      el.dataset.skConfirmWired = "1";
      var message = el.getAttribute("data-admin-confirm") || "Are you sure?";
      var danger = el.hasAttribute("data-admin-confirm-danger");
      if (el.tagName === "FORM") {
        el.addEventListener("submit", function (e) {
          if (el.dataset.skConfirmed === "1") { delete el.dataset.skConfirmed; return; }
          if (typeof el.checkValidity === "function" && !el.checkValidity()) {
            if (typeof el.reportValidity === "function") el.reportValidity();
            return;
          }
          e.preventDefault();
          e.stopPropagation();
          SKConfirm(message, { danger: danger }).then(function (ok) {
            if (!ok) return;
            el.dataset.skConfirmed = "1";
            if (typeof el.requestSubmit === "function") el.requestSubmit();
            else el.submit();
          });
        });
      } else {
        el.addEventListener("click", function (e) {
          if (el.dataset.skConfirmed === "1") { delete el.dataset.skConfirmed; return; }
          e.preventDefault();
          e.stopPropagation();
          SKConfirm(message, { danger: danger }).then(function (ok) {
            if (!ok) return;
            if (el.tagName === "A" && el.getAttribute("href")) {
              window.location.href = el.href;
              return;
            }
            el.dataset.skConfirmed = "1";
            el.click();
          });
        }, true);
      }
    });
  }

  const paginatedTables = document.querySelectorAll("table.admin-table:not([data-no-paginate]), table.nutritionist-table:not([data-no-paginate]), table.parent-table:not([data-no-paginate])");
  const pageSize = 10;
  const chevronLeft = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5"/></svg>';
  const chevronRight = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/></svg>';

  function buildPageNumbers(current, total) {
    if (total <= 7) {
      return Array.from({ length: total }, (_, i) => {
        const p = i + 1;
        return '<button type="button" class="admin-page-num' + (p === current ? ' is-active' : '') + '" data-page="' + p + '">' + p + '</button>';
      }).join('');
    }
    let pages = [1];
    if (current > 3) pages.push('…');
    for (let i = Math.max(2, current - 1); i <= Math.min(total - 1, current + 1); i++) {
      pages.push(i);
    }
    if (current < total - 2) pages.push('…');
    pages.push(total);
    return pages.map(p => {
      if (p === '…') return '<span class="admin-page-ellipsis">…</span>';
      return '<button type="button" class="admin-page-num' + (p === current ? ' is-active' : '') + '" data-page="' + p + '">' + p + '</button>';
    }).join('');
  }

  paginatedTables.forEach((table) => {
    const rows = Array.from(table.querySelectorAll("tbody tr"));
    const filterInput = document.querySelector(`[data-admin-filter="#${table.id}"]`);
    // Per-table page-size override: any <table data-page-size="N"> uses N rows
    // per page, otherwise we fall back to the global default of 10.
    // The invitation history uses this to render 5 per page.
    const tablePageSize = parseInt(table.getAttribute("data-page-size"), 10);
    const effectivePageSize = Number.isFinite(tablePageSize) && tablePageSize > 0 ? tablePageSize : pageSize;
    let currentPage = 1;
    let filteredRows = rows;
    const pagination = document.createElement("div");

    pagination.className = "admin-pagination";
    pagination.innerHTML = '<span class="admin-pagination-status"></span><div class="admin-pagination-actions"><button type="button" class="admin-icon-btn admin-pagination-prev" title="Previous">' + chevronLeft + '</button><div class="admin-pagination-numbers"></div><button type="button" class="admin-icon-btn admin-pagination-next" title="Next">' + chevronRight + '</button></div>';
    table.parentElement.appendChild(pagination);

    const status = pagination.querySelector(".admin-pagination-status");
    const numbersEl = pagination.querySelector(".admin-pagination-numbers");
    const previousButton = pagination.querySelector(".admin-pagination-prev");
    const nextButton = pagination.querySelector(".admin-pagination-next");

    function render() {
      const pageCount = Math.max(1, Math.ceil(filteredRows.length / effectivePageSize));
      currentPage = Math.min(currentPage, pageCount);
      const start = (currentPage - 1) * effectivePageSize;
      const end = Math.min(start + effectivePageSize, filteredRows.length);
      const visibleRows = new Set(filteredRows.slice(start, end));

      rows.forEach((row) => {
        row.style.display = visibleRows.has(row) ? "" : "none";
      });

      status.textContent = filteredRows.length === 0 ? "No records" : "Page " + currentPage + " of " + pageCount;
      numbersEl.innerHTML = buildPageNumbers(currentPage, pageCount);

      numbersEl.querySelectorAll("[data-page]").forEach((btn) => {
        btn.addEventListener("click", () => {
          currentPage = parseInt(btn.dataset.page, 10);
          render();
        });
      });

      previousButton.disabled = currentPage === 1;
      nextButton.disabled = currentPage === pageCount;
      previousButton.style.display = pageCount <= 1 ? "none" : "";
      nextButton.style.display = pageCount <= 1 ? "none" : "";
      pagination.hidden = rows.length === 0;
    }

    if (filterInput) {
      filterInput.addEventListener("input", () => {
        const term = filterInput.value.trim().toLowerCase();
        // Optional per-table role gate: pills set data-role-filter on the
        // table ("", "admin", "nutritionist", …); rows carry data-role.
        // Clearing the attribute ("All") lifts the gate.
        const roleGate = (table.getAttribute("data-role-filter") || "").toLowerCase();
        filteredRows = rows.filter((row) => {
          if (roleGate && (row.getAttribute("data-role") || "").toLowerCase() !== roleGate) return false;
          const text = row.getAttribute("data-filter-text") || row.textContent || "";
          return text.toLowerCase().includes(term);
        });
        currentPage = 1;
        render();
      });
    }

    previousButton.addEventListener("click", () => {
      currentPage -= 1;
      render();
    });

    nextButton.addEventListener("click", () => {
      currentPage += 1;
      render();
    });

    render();
  });

  document.querySelectorAll("[data-admin-autosubmit]").forEach((field) => {
    const form = field.closest("form");

    if (!form) {
      return;
    }

    field.addEventListener("change", () => {
      form.requestSubmit();
    });
  });

  /* ============================================================================
   *  RESPONSIVE HELPERS
   * ----------------------------------------------------------------------------
   *  Three small behaviors that keep the layout fluid on every phone size:
   *
   *  1) BREADCRUMB TRUNCATION
   *     On viewports below the configured breakpoint (default 720 px) the
   *     crumb text shrinks to its first 4 chars + `…` so the icons to
   *     its right stay visible. The full text is preserved on the element
   *     as a `data-breadcrumb-full` attribute and reused as a tooltip.
   *
   *  2) TOPBAR ICON ORDER
   *     On small viewports the breadcrumb gets `flex: 0 1 auto` and the
   *     topbar profile text gets hidden. CSS handles the visual swap;
   *     this code only adds the data attribute the CSS uses.
   *
   *  3) FLUID CHART RE-INIT
   *     Every <canvas data-fluid-chart> exposes its `initChart(data)`
   *     function via `window['chart_' + id]`. A ResizeObserver re-runs
   *     init whenever the canvas's parent changes width (orientation,
   *     window resize, sidebar toggle, etc.).
   * ========================================================================== */

  // ---- 1) Breadcrumb truncation ----
  const BREADCRUMB_TRUNCATE_AT = 720; // px
  const breadcrumb = document.querySelector("[data-admin-breadcrumb]");
  if (breadcrumb) {
    const crumbs = breadcrumb.querySelectorAll(".admin-breadcrumb-group, .admin-breadcrumb-page");
    crumbs.forEach((crumb) => {
      const text = (crumb.textContent || "").trim();
      if (!text) return;
      if (!crumb.hasAttribute("data-breadcrumb-full")) {
        crumb.setAttribute("data-breadcrumb-full", text);
        crumb.setAttribute("title", text);
      }
    });
    const truncateCrumbs = () => {
      const shouldTruncate = window.innerWidth < BREADCRUMB_TRUNCATE_AT;
      if (shouldTruncate) {
        breadcrumb.classList.add("is-truncated");
        crumbs.forEach((crumb) => {
          const full = crumb.getAttribute("data-breadcrumb-full") || "";
          if (full.length > 4) {
            crumb.textContent = full.slice(0, 4) + "…";
          }
        });
      } else {
        breadcrumb.classList.remove("is-truncated");
        crumbs.forEach((crumb) => {
          const full = crumb.getAttribute("data-breadcrumb-full") || "";
          if (full) crumb.textContent = full;
        });
      }
    };
    truncateCrumbs();
    let resizeRaf = null;
    window.addEventListener("resize", () => {
      if (resizeRaf) cancelAnimationFrame(resizeRaf);
      resizeRaf = requestAnimationFrame(truncateCrumbs);
    });
  }

  // ---- 2) Topbar icon order: no JS needed, but mark a hook ----
  const topbar = document.querySelector(".admin-topbar");
  if (topbar) {
    topbar.setAttribute("data-admin-topbar", "");
  }

  // ---- 3) Fluid chart re-init via ResizeObserver ----
  const fluidCharts = document.querySelectorAll("canvas[data-fluid-chart]");
  if (fluidCharts.length > 0 && typeof ResizeObserver === "function") {
    const observers = new WeakMap();
    fluidCharts.forEach((canvas) => {
      const initName = canvas.getAttribute("data-init-fn");
      const id = canvas.id;
      if (!initName && !id) return;
      // Convention: <canvas data-fluid-chart id="auditWaveChart" data-init-fn="auditInit">
      // OR rely on window['chart_' + id] for the legacy audit page.
      const handler = () => {
        try {
          if (initName && typeof window !== "undefined" && typeof window[initName] === "function") {
            window[initName]();
            return;
          }
          if (id) {
            const w = window["chart_" + id];
            if (typeof w === "function") {
              w();
              return;
            }
            const legacy = window["init_" + id];
            if (typeof legacy === "function") {
              legacy();
            }
          }
        } catch (err) {
          // Silent: chart re-init failures should never break the page.
        }
      };
      const ro = new ResizeObserver(() => {
        // Debounce per-frame so rapid resize events coalesce.
        if (canvas._roRaf) cancelAnimationFrame(canvas._roRaf);
        canvas._roRaf = requestAnimationFrame(handler);
      });
      ro.observe(canvas.parentElement || canvas);
      observers.set(canvas, ro);
    });
  }
})();

(function () {
  "use strict";

  /* One-shot welcome emphasis after login (flag planted by auth-handoff.js).
   * Adds body.sk-welcome for the header rise, then consumes the flag so a
   * refresh never replays it. Paint only � no navigation or data involved. */
  try {
    if (window.sessionStorage && window.sessionStorage.getItem("sk-welcome") === "1") {
      window.sessionStorage.removeItem("sk-welcome");
      document.body.classList.add("sk-welcome");
    }
  } catch (error) {
    // sessionStorage unavailable (private mode) � skip silently.
  }

  /* Count-up for dashboard stat numbers. Opt-in via data-count-up on an
   * element whose text is a plain number (commas allowed). Animates 0 ?
   * value over ~700ms with rAF; reduced-motion and non-numeric content
   * render the final value instantly. Idempotent per element. */
  function countUp(el) {
    if (!el || el.dataset.counted === "1") return;
    el.dataset.counted = "1";
    var raw = (el.textContent || "").trim().replace(/,/g, "");
    var target = Number(raw);
    if (!isFinite(target)) return;
    var reduceMotion =
      typeof window.matchMedia === "function" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduceMotion) {
      el.textContent = target.toLocaleString("en-US");
      return;
    }
    var duration = 700;
    var start = null;
    function frame(now) {
      if (start === null) start = now;
      var progress = Math.min((now - start) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      el.textContent = Math.round(target * eased).toLocaleString("en-US");
      if (progress < 1) window.requestAnimationFrame(frame);
    }
    window.requestAnimationFrame(frame);
  }

  function initCountUps(scope) {
    (scope || document).querySelectorAll("[data-count-up]").forEach(countUp);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () { initCountUps(document); });
  } else {
    initCountUps(document);
  }

  window.SKCountUp = { init: initCountUps };
})();

(function () {
  "use strict";

  /* SKBusy � shared buffering feedback for slow internet.
   * Paint only: spins the trigger and blocks double-activation while a
   * request is in flight. Never changes URLs, payloads, or timing. */
  var BUSY_FAILSAFE_MS = 60000;
  var DOWNLOAD_FAILSAFE_MS = 45000;

  function ensureSpinner(el) {
    if (!el) return null;
    var spin = el.querySelector(":scope > .sk-btn-spinner");
    if (!spin) {
      spin = document.createElement("span");
      spin.className = "sk-btn-spinner";
      spin.setAttribute("aria-hidden", "true");
      el.insertBefore(spin, el.firstChild);
    }
    return spin;
  }

  function setBusy(el, busy) {
    if (!el) return function () {};
    if (busy) {
      if (el.dataset.skBusy === "1") return function () {};
      el.dataset.skBusy = "1";
      ensureSpinner(el);
      el.classList.add("is-busy");
      el.setAttribute("aria-busy", "true");
      if ("disabled" in el) {
        try { el.disabled = true; } catch (error) { /* read-only control */ }
      }
      var failsafe = window.setTimeout(function () { setBusy(el, false); }, BUSY_FAILSAFE_MS);
      el._skFailsafe = failsafe;
    } else {
      if (el._skFailsafe) {
        window.clearTimeout(el._skFailsafe);
        el._skFailsafe = null;
      }
      delete el.dataset.skBusy;
      el.classList.remove("is-busy");
      el.removeAttribute("aria-busy");
      if ("disabled" in el && el.dataset.skKeepDisabled !== "1") {
        try { el.disabled = false; } catch (error) { /* read-only control */ }
      }
    }
    return function () {};
  }

  /* Full-page POST forms: spin the submitter + show the progress modal.
   * Runs after validators (setTimeout 0) so blocked submits never spin.
   * Page unload on success clears everything naturally; the failsafe and
   * pagehide handler cover fetch-handled forms. */
  function wireForms(scope) {
    (scope || document).querySelectorAll("form[data-validate-form]").forEach(function (form) {
      if (form.dataset.skBusyWired === "1") return;
      form.dataset.skBusyWired = "1";
      form.addEventListener("submit", function (e) {
        var submitter = e.submitter || form.querySelector('[type="submit"]');
        window.setTimeout(function () {
          if (e.defaultPrevented) return;
          setBusy(submitter, true);
          if (!form.hasAttribute("data-no-progress")) {
            SKProgress.show(progressMessageFor(form, submitter));
          }
        }, 0);
      });
    });
  }

  /* File-download links: the page never unloads, so clear on refocus
   * (save dialog blurs the window) plus a failsafe. Scoped to known
   * export endpoints only � never navigation links. */
  var DOWNLOAD_SELECTOR = "a[href*=\"eopt_reports_export.php\"], a[href*=\"who_reference_export.php\"], a[href*=\"eopt_pdf_generate.php\"]";

  function wireDownloads(scope) {
    (scope || document).querySelectorAll(DOWNLOAD_SELECTOR).forEach(function (link) {
      if (link.dataset.skBusyWired === "1") return;
      link.dataset.skBusyWired = "1";
      link.addEventListener("click", function () {
        if (link.dataset.skBusy === "1") return;
        setBusy(link, true);
        var done = false;
        var release = function () {
          if (done) return;
          done = true;
          setBusy(link, false);
          window.removeEventListener("focus", release);
        };
        window.addEventListener("focus", release);
        window.setTimeout(release, DOWNLOAD_FAILSAFE_MS);
      }, true);
    });
  }

  /* Offline awareness via the existing toast system (if loaded). */
  function announceOnline() {
    if (window.AdminToast && typeof window.AdminToast.success === "function") {
      window.AdminToast.success("Back online.");
    }
  }
  function announceOffline() {
    if (window.AdminToast && typeof window.AdminToast.error === "function") {
      window.AdminToast.error("You are offline. Actions may fail until connection returns.");
    }
  }
  window.addEventListener("online", announceOnline);
  window.addEventListener("offline", announceOffline);

  function init(scope) {
    wireConfirms(scope);
    wireForms(scope);
    wireDownloads(scope);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () { init(document); });
  } else {
    init(document);
  }

  window.SKBusy = { set: setBusy, init: init };
})();
