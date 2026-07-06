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

  document.querySelectorAll("[data-admin-filter]").forEach((input) => {
    const targetId = input.getAttribute("data-admin-filter");
    const table = targetId ? document.querySelector(targetId) : null;

    if (!table) {
      return;
    }

    const rows = Array.from(table.querySelectorAll("tbody tr"));

    input.addEventListener("input", () => {
      const term = input.value.trim().toLowerCase();

      rows.forEach((row) => {
        const text = row.getAttribute("data-filter-text") || row.textContent || "";
        row.style.display = text.toLowerCase().includes(term) ? "" : "none";
      });
    });
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
