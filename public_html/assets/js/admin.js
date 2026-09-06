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

  document.querySelectorAll("[data-admin-confirm]").forEach((button) => {
    button.addEventListener("click", (event) => {
      const message = button.getAttribute("data-admin-confirm");

      if (message && !window.confirm(message)) {
        event.preventDefault();
      }
    });
  });

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
        filteredRows = rows.filter((row) => {
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
