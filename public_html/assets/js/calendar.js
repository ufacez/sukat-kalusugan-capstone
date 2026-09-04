(function () {
  "use strict";

  const COMPACT_LIMIT = 3;
  const SELECTED_CLASS = "is-selected";

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
      const href = "/nutritionist/appointment_form.php?id=" + encodeURIComponent(entry.id);
      actionHtml = '<a class="sk-cal-event-action" href="' + escapeHtml(href) + '">Open →</a>';
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

  function renderDetail(detailEl, iso, entries) {
    const titleEl = detailEl.querySelector("[data-calendar-detail-title]");
    const subEl = detailEl.querySelector("[data-calendar-detail-sub]");
    const listEl = detailEl.querySelector("[data-calendar-detail-list]");
    const emptyEl = detailEl.querySelector("[data-calendar-detail-empty]");
    const linkEl = detailEl.querySelector("[data-calendar-detail-link]");

    if (!listEl) return;

    if (titleEl) {
      const rel = formatRelativeDay(iso);
      titleEl.textContent = rel || formatLongDate(iso);
      if (rel === "Today") {
        titleEl.innerHTML += ' <span class="sk-cal-detail-today">Today</span>';
      }
    }

    if (subEl) {
      subEl.textContent = entries.length + " event" + (entries.length === 1 ? "" : "s");
    }

    if (entries.length === 0) {
      listEl.innerHTML = "";
      if (emptyEl) emptyEl.style.display = "";
      if (linkEl) linkEl.setAttribute("aria-hidden", "true");
      return;
    }

    if (emptyEl) emptyEl.style.display = "none";

    const compact = entries.slice(0, COMPACT_LIMIT);
    let html = compact.map(buildEventCard).join("");
    if (entries.length > COMPACT_LIMIT) {
      html +=
        '<button type="button" class="sk-cal-show-more" data-calendar-show-more>' +
        "+ " + (entries.length - COMPACT_LIMIT) + " more" +
        "</button>";
      html += '<div class="sk-cal-event-list-extra" hidden>';
      html += entries.slice(COMPACT_LIMIT).map(buildEventCard).join("");
      html += "</div>";
    }
    listEl.innerHTML = html;

    if (linkEl) {
      linkEl.setAttribute("aria-hidden", "false");
      linkEl.setAttribute("href", "/nutritionist/appointments.php?from=" + iso + "&to=" + iso);
    }
  }

  function setupCalendar(scope) {
    const grid = (scope || document).querySelector("[data-sk-calendar]");
    if (!grid) return;

    const detailId = grid.getAttribute("data-sk-calendar-detail");
    const detailEl = detailId ? document.getElementById(detailId) : null;
    if (!detailEl) return;

    const defaultIso = grid.getAttribute("data-sk-calendar-default") || null;
    const days = grid.querySelectorAll("[data-calendar-day]");

    function selectDay(iso, entries) {
      days.forEach((btn) => {
        if (btn.getAttribute("data-calendar-day") === iso) {
          btn.classList.add(SELECTED_CLASS);
        } else {
          btn.classList.remove(SELECTED_CLASS);
        }
      });
      renderDetail(detailEl, iso, entries);
      detailEl.setAttribute("data-calendar-active-day", iso);
    }

    function clearSelection() {
      days.forEach((btn) => btn.classList.remove(SELECTED_CLASS));
      detailEl.removeAttribute("data-calendar-active-day");
    }

    days.forEach((btn) => {
      btn.addEventListener("click", () => {
        const iso = btn.getAttribute("data-calendar-day");
        if (!iso) return;

        const raw = btn.getAttribute("data-calendar-entries") || "[]";
        let entries = [];
        try {
          entries = JSON.parse(raw);
        } catch (e) {
          entries = [];
        }

        const alreadySelected = btn.classList.contains(SELECTED_CLASS);
        if (alreadySelected) {
          clearSelection();
          if (defaultIso) {
            const defaultBtn = grid.querySelector(
              '[data-calendar-day="' + defaultIso + '"]'
            );
            if (defaultBtn) {
              let defaultEntries = [];
              try {
                defaultEntries = JSON.parse(
                  defaultBtn.getAttribute("data-calendar-entries") || "[]"
                );
              } catch (e) {}
              selectDay(defaultIso, defaultEntries);
            }
          } else {
            renderDetail(detailEl, iso, []);
          }
          return;
        }

        selectDay(iso, entries);
      });
    });

    if (defaultIso) {
      const defaultBtn = grid.querySelector(
        '[data-calendar-day="' + defaultIso + '"]'
      );
      if (defaultBtn) {
        let entries = [];
        try {
          entries = JSON.parse(
            defaultBtn.getAttribute("data-calendar-entries") || "[]"
          );
        } catch (e) {}
        selectDay(defaultIso, entries);
      }
    }

    detailEl.addEventListener("click", (e) => {
      const moreBtn = e.target.closest("[data-calendar-show-more]");
      if (!moreBtn) return;
      const extra = moreBtn.parentElement.querySelector(".sk-cal-event-list-extra");
      if (!extra) return;
      const isHidden = extra.hasAttribute("hidden");
      if (isHidden) {
        extra.removeAttribute("hidden");
        moreBtn.textContent = "Show less";
      } else {
        extra.setAttribute("hidden", "");
        moreBtn.textContent = moreBtn.textContent.replace("Show less", "+ more");
      }
    });
  }

  function init() {
    document.querySelectorAll("[data-sk-calendar]").forEach((grid) => setupCalendar(grid));
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
