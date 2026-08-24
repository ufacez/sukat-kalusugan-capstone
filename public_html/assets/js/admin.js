(function () {
  const sidebar = document.querySelector("[data-admin-sidebar]");
  const toggle = document.querySelector("[data-admin-sidebar-toggle]");

  if (sidebar && toggle) {
    toggle.addEventListener("click", () => {
      sidebar.classList.toggle("is-open");
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

  const paginatedTables = document.querySelectorAll("table.admin-table, table.nutritionist-table, table.parent-table");
  const pageSize = 10;

  paginatedTables.forEach((table) => {
    const rows = Array.from(table.querySelectorAll("tbody tr"));
    const filterInput = document.querySelector(`[data-admin-filter="#${table.id}"]`);
    let currentPage = 1;
    let filteredRows = rows;
    const pagination = document.createElement("div");

    pagination.className = "admin-pagination";
    pagination.innerHTML = '<span class="admin-pagination-status"></span><div class="admin-pagination-actions"><button type="button" class="admin-btn-secondary admin-pagination-prev">Previous</button><button type="button" class="admin-btn-secondary admin-pagination-next">Next</button></div>';
    table.parentElement.appendChild(pagination);

    const status = pagination.querySelector(".admin-pagination-status");
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

      status.textContent = filteredRows.length === 0 ? "No records" : `Showing ${start + 1}-${end} of ${filteredRows.length}`;
      previousButton.disabled = currentPage === 1;
      nextButton.disabled = currentPage === pageCount;
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
