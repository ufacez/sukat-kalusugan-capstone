(function () {
  // Theme toggle
  const themeToggle = document.querySelector("[data-theme-toggle]");
  if (themeToggle) {
    const getPreferredTheme = () => {
      const stored = localStorage.getItem("theme");
      if (stored) return stored;
      return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    };

    const applyTheme = (theme) => {
      document.documentElement.setAttribute("data-theme", theme);
      localStorage.setItem("theme", theme);
      const label = themeToggle.querySelector(".theme-toggle-label");
      if (label) label.textContent = theme === "dark" ? "Light Mode" : "Dark Mode";
    };

    applyTheme(getPreferredTheme());

    themeToggle.addEventListener("click", () => {
      const current = document.documentElement.getAttribute("data-theme");
      applyTheme(current === "dark" ? "light" : "dark");
    });

    window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", (e) => {
      if (!localStorage.getItem("theme")) {
        applyTheme(e.matches ? "dark" : "light");
      }
    });
  }

  const sidebar = document.querySelector("[data-admin-sidebar]");
  const toggle = document.querySelector("[data-admin-sidebar-toggle]");
  let overlay = document.querySelector("[data-admin-sidebar-overlay]");

  if (sidebar && toggle) {
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.className = "admin-sidebar-overlay";
      overlay.setAttribute("data-admin-sidebar-overlay", "");
      sidebar.insertAdjacentElement("afterend", overlay);
    }

    toggle.setAttribute("aria-expanded", "false");
    sidebar.setAttribute("aria-hidden", "true");

    const openSidebar = () => {
      sidebar.classList.add("is-open");
      overlay.classList.add("is-open");
      document.body.classList.add("no-scroll");
      toggle.setAttribute("aria-expanded", "true");
      sidebar.setAttribute("aria-hidden", "false");
    };

    const closeSidebar = () => {
      sidebar.classList.remove("is-open");
      overlay.classList.remove("is-open");
      document.body.classList.remove("no-scroll");
      toggle.setAttribute("aria-expanded", "false");
      sidebar.setAttribute("aria-hidden", "true");
    };

    toggle.addEventListener("click", () => {
      if (sidebar.classList.contains("is-open")) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });

    overlay.addEventListener("click", closeSidebar);

    sidebar.querySelectorAll("a").forEach((link) => {
      link.addEventListener("click", closeSidebar);
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && sidebar.classList.contains("is-open")) {
        closeSidebar();
      }
    });

    window.addEventListener("resize", () => {
      if (window.innerWidth > 920 && sidebar.classList.contains("is-open")) {
        closeSidebar();
      }
    });
  }

  // Sidebar collapse (desktop)
  const collapseBtn = document.querySelector("[data-admin-sidebar-collapse]");
  const shell = document.querySelector(".admin-shell");
  if (collapseBtn && shell) {
    const savedCollapse = localStorage.getItem("sidebar_collapsed");
    if (savedCollapse === "true" && window.innerWidth > 920) {
      shell.classList.add("is-collapsed");
    }
    collapseBtn.addEventListener("click", () => {
      shell.classList.toggle("is-collapsed");
      localStorage.setItem("sidebar_collapsed", shell.classList.contains("is-collapsed"));
    });
    window.addEventListener("resize", () => {
      if (window.innerWidth <= 920) {
        shell.classList.remove("is-collapsed");
      }
    });
  }

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
      const pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize));
      currentPage = Math.min(currentPage, pageCount);
      const start = (currentPage - 1) * pageSize;
      const end = Math.min(start + pageSize, filteredRows.length);
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
})();
