(function () {
  "use strict";

  function escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = String(value ?? "");
    return div.innerHTML;
  }

  function formatLongDate(iso) {
    const d = new Date(iso + "T00:00:00");
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleDateString(undefined, {
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  }

  function formatRelativeDay(iso) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const target = new Date(iso + "T00:00:00");
    if (isNaN(target.getTime())) return null;
    const diffDays = Math.round((target - today) / 86400000);
    if (diffDays === 0) return "Today";
    if (diffDays === 1) return "Tomorrow";
    if (diffDays === -1) return "Yesterday";
    if (diffDays > 0 && diffDays < 7) return "In " + diffDays + " days";
    if (diffDays < 0 && diffDays > -7) return Math.abs(diffDays) + " days ago";
    return null;
  }

  function buildEventCard(entry) {
    const timeHtml = entry.time
      ? '<div class="sk-cal-event-time">' + escapeHtml(entry.time) + '</div>'
      : "";
    const locHtml = entry.location
      ? '<div class="sk-cal-event-loc">' +
        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z"/></svg>' +
        '<span>' + escapeHtml(entry.location) + '</span></div>'
      : "";

    const statusBadge = entry.status && entry.status !== "completed"
      ? '<span class="sk-cal-event-status is-' + escapeHtml(entry.status) + '">' +
        escapeHtml(entry.status.charAt(0).toUpperCase() + entry.status.slice(1)) +
        '</span>'
      : "";

    let actionHtml = "";
    if (entry.id) {
      const href = "followup_child.php?id=" + encodeURIComponent(entry.id);
      actionHtml = '<a class="sk-cal-event-action" href="' + escapeHtml(href) + '">View \u2192</a>';
    }

    return (
      '<div class="sk-cal-event" data-entry-type="' + escapeHtml(entry.type) + '">' +
        '<div class="sk-cal-event-head">' +
          '<span class="sk-cal-event-dot" style="background:' + escapeHtml(entry.color) + ';"></span>' +
          '<span class="sk-cal-event-type">' + escapeHtml(entry.label || entry.type) + '</span>' +
          statusBadge +
        '</div>' +
        '<div class="sk-cal-event-body">' +
          timeHtml +
          '<div class="sk-cal-event-title">' + escapeHtml(entry.title || "(untitled)") + '</div>' +
          locHtml +
        '</div>' +
        actionHtml +
      '</div>'
    );
  }

  /* ── Inline detail panel (dashboard) ── */
  function showInlineDetail(iso, entries) {
    var panel = document.getElementById("cal-detail-panel");
    if (!panel) return;

    if (!entries || entries.length === 0) {
      closeInlineDetail();
      return;
    }

    var rel = formatRelativeDay(iso);
    var heading = rel || formatLongDate(iso);
    var first = entries[0];
    var remaining = entries.length - 1;

    var moreHtml = remaining > 0
      ? '<div class="sk-cal-detail-more">+' + remaining + ' more appointment' + (remaining === 1 ? '' : 's') + '</div>'
      : "";

    panel.innerHTML =
      '<div class="sk-cal-detail-head">' +
        '<h4 class="sk-cal-detail-title">' + escapeHtml(heading) + '</h4>' +
        '<button type="button" class="sk-cal-detail-close" data-cal-detail-close>&times;</button>' +
      '</div>' +
      buildEventCard(first) +
      moreHtml;

    panel.classList.add("is-open");

    panel.querySelector("[data-cal-detail-close]").addEventListener("click", function () {
      closeInlineDetail();
    });
  }

  function closeInlineDetail() {
    var panel = document.getElementById("cal-detail-panel");
    if (panel) {
      panel.classList.remove("is-open");
      panel.innerHTML = "";
    }
    document.querySelectorAll(".sk-cal-day.is-selected").forEach(function (d) {
      d.classList.remove("is-selected");
    });
  }

  /* ── Modal (appointments page fallback) ── */
  function openModal(iso, entries) {
    closeModal();

    var rel = formatRelativeDay(iso);
    var heading = rel || formatLongDate(iso);

    var cardsHtml = entries.map(buildEventCard).join("");

    var overlay = document.createElement("div");
    overlay.className = "sk-cal-modal-overlay";
    overlay.setAttribute("data-calendar-modal", "");
    overlay.innerHTML =
      '<div class="sk-cal-modal">' +
        '<div class="sk-cal-modal-head">' +
          '<h3 class="sk-cal-modal-title">' + escapeHtml(heading) +
            (rel === "Today" ? ' <span class="sk-cal-detail-today">Today</span>' : "") +
          '</h3>' +
          '<button type="button" class="sk-cal-modal-close" data-calendar-modal-close>&times;</button>' +
        '</div>' +
        '<div class="sk-cal-modal-sub">' + entries.length + ' appointment' + (entries.length === 1 ? '' : 's') + '</div>' +
        '<div class="sk-cal-modal-body">' + cardsHtml + '</div>' +
      '</div>';

    document.body.appendChild(overlay);
    document.body.style.overflow = "hidden";

    overlay.addEventListener("click", function (e) {
      if (e.target === overlay || e.target.closest("[data-calendar-modal-close]")) {
        closeModal();
      }
    });

    document.addEventListener("keydown", function handler(e) {
      if (e.key === "Escape") {
        closeModal();
        document.removeEventListener("keydown", handler);
      }
    });
  }

  function closeModal() {
    var existing = document.querySelector("[data-calendar-modal]");
    if (existing) {
      existing.remove();
      document.body.style.overflow = "";
    }
  }

  /* ── Calendar setup ── */
  function setupCalendar(scope) {
    var grid = scope && scope.matches && scope.matches("[data-sk-calendar]")
      ? scope
      : (scope || document).querySelector("[data-sk-calendar]");
    if (!grid) return;

    var useInline = !!document.getElementById("cal-detail-panel");
    var days = grid.querySelectorAll("[data-calendar-day]");

    days.forEach(function (btn) {
      btn.addEventListener("click", function () {
        var iso = btn.getAttribute("data-calendar-day");
        if (!iso) return;

        var raw = btn.getAttribute("data-calendar-entries") || "[]";
        var entries = [];
        try {
          entries = JSON.parse(raw);
        } catch (e) {
          entries = [];
        }

        if (entries.length === 0) {
          if (useInline) closeInlineDetail();
          return;
        }

        if (useInline) {
          var wasSelected = btn.classList.contains("is-selected");
          document.querySelectorAll(".sk-cal-day.is-selected").forEach(function (d) {
            d.classList.remove("is-selected");
          });
          if (wasSelected) {
            closeInlineDetail();
          } else {
            btn.classList.add("is-selected");
            showInlineDetail(iso, entries);
          }
        } else {
          openModal(iso, entries);
        }
      });
    });
  }

  function init() {
    document.querySelectorAll("[data-sk-calendar]").forEach(function (grid) {
      setupCalendar(grid);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
