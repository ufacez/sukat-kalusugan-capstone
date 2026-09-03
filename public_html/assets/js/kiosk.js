(function () {
  "use strict";

  console.log("[SukatKalusugan] kiosk.js loaded");

  // ============================================================
  // CONFIGURATION
  // ============================================================

  const data = window.KIOSK_DATA || {};
  const body = document.body;

  const deviceId =
    data?.defaults?.deviceId || "ESP32-KIOSK-01";

  const firebaseBaseUrl =
    typeof data?.firebase?.databaseUrl === "string"
      ? data.firebase.databaseUrl.trim()
      : "";

  const firebaseEnabled =
    Boolean(data?.firebase?.enabled) &&
    firebaseBaseUrl !== "";

  // ============================================================
  // WEBSOCKET (direct ESP32 connection, same LAN)
  // ============================================================

  const wsEnabled =
    Boolean(data?.websocket?.enabled) &&
    Boolean(data?.websocket?.esp32_ip);

  const wsEsp32Ip =
    data?.websocket?.esp32_ip || "";

  const wsPort = 80;

  const wsPath = "/ws";

  let wsConnection = null;
  let wsConnected = false;
  let wsReconnectTimer = null;
  let wsReconnectAttempts = 0;
  const wsMaxReconnectAttempts = 30;
  const wsReconnectBaseMs = 1000;

  const pollSeconds = Math.max(
    0.2,
    Number(data?.defaults?.pollSeconds || 0.2)
  );

  const pollIntervalMs = pollSeconds * 1000;

  const syncSeconds = Math.max(
    2,
    Number(data?.defaults?.syncSeconds || 5)
  );

  const deviceStatusIntervalMs =
    syncSeconds * 1000;

  const sessionTimeoutSeconds = Math.max(
    30,
    Number(data?.defaults?.sessionTimeoutSeconds || 180)
  );

  const STORAGE_KEY =
    "sukat_kalusugan_kiosk_session";

  const CHILD_STORAGE_KEY =
    "sukat_kalusugan_kiosk_child";

  // ============================================================
  // DOM
  // ============================================================

  const welcomeScreen =
    document.querySelector(
      '[data-kiosk-screen="welcome"]'
    );

  const stepBar =
    document.querySelector(".kiosk-stepbar");

  const stage =
    document.querySelector(".kiosk-stage");

  const screens =
    Array.from(
      document.querySelectorAll(
        "[data-kiosk-screen]"
      )
    );

  const stepButtons =
    Array.from(
      document.querySelectorAll(
        "[data-kiosk-step-jump]"
      )
    );

  const actionButtons =
    Array.from(
      document.querySelectorAll(
        "[data-kiosk-action]"
      )
    );

  const childCards =
    Array.from(
      document.querySelectorAll(
        "[data-kiosk-child-card]"
      )
    );

  const searchInput =
    document.querySelector(
      "[data-kiosk-search]"
    );

  // Child lookup elements
  const childIdInput =
    document.getElementById("childIdInput");

  const clearChildIdBtn =
    document.getElementById("clearChildId");

  const lookupSubmitBtn =
    document.getElementById("lookupSubmit");

  const lookupPreview =
    document.getElementById("lookupPreview");

  let foundChild = null;
  let searchDebounceTimer = null;

  function showLookupState(stateName) {
    document.querySelectorAll("[data-lookup-state]").forEach(function (el) {
      el.hidden = el.getAttribute("data-lookup-state") !== stateName;
    });
  }

  function resetLookup() {
    if (childIdInput) childIdInput.value = "";
    if (clearChildIdBtn) clearChildIdBtn.hidden = true;
    if (lookupPreview) {
      lookupPreview.hidden = true;
      lookupPreview.innerHTML = "";
    }
    foundChild = null;
    showLookupState("idle");
  }

  function populateFoundCard(child) {
    foundChild = child;

    const nameEl = document.getElementById("foundChildName");
    const codeEl = document.getElementById("foundChildCode");
    const ageEl = document.getElementById("foundChildAge");
    const sexEl = document.getElementById("foundChildSex");

    if (nameEl) nameEl.textContent = child.first_name + " " + child.last_name;
    if (codeEl) codeEl.textContent = child.child_code;
    if (ageEl) {
      var months = child.age_months || 0;
      var days = child.age_days || 0;
      var primary = months >= 12
        ? Math.floor(months / 12) + " taon, " + (months % 12) + " buwan"
        : months + " buwan";
      ageEl.textContent = primary + " (" + days + " araw)";
    }
    if (sexEl) sexEl.textContent = child.sex === "Male" ? "Lalaki" : "Babae";
  }

  function selectChildFromPreview(child) {
    populateFoundCard(child);
    state.child = child;
    if (lookupPreview) lookupPreview.hidden = true;
    showLookupState("found");
  }

  function buildPreviewCardHtml(c) {
    var months = c.age_months || 0;
    var days = c.age_days || 0;
    var ageText = months >= 12
      ? Math.floor(months / 12) + " taon, " + (months % 12) + " buwan · " + days + " araw"
      : months + " buwan · " + days + " araw";
    var firstInitial = (c.first_name || "?").charAt(0).toUpperCase();
    var lastInitial = (c.last_name || "?").charAt(0).toUpperCase();
    var initials = firstInitial + lastInitial;
    var sexLabel = c.sex === "Male" ? "Lalaki" : "Babae";
    var sexIcon = c.sex === "Male" ? "♂" : "♀";

    return (
      '<button type="button" class="kiosk-lookup-preview-card" ' +
        'data-preview-child-id="' + c.id + '">' +
        '<div class="kiosk-lookup-preview-avatar">' + escapeHtml(initials) + '</div>' +
        '<div class="kiosk-lookup-preview-info">' +
          '<div class="kiosk-lookup-preview-name">' +
            escapeHtml(c.first_name + " " + c.last_name) +
          '</div>' +
          '<div class="kiosk-lookup-preview-meta">' +
            '<span>' + escapeHtml(ageText) + '</span>' +
            '<span class="kiosk-lookup-preview-meta-dot"></span>' +
            '<span>' + sexIcon + ' ' + escapeHtml(sexLabel) + '</span>' +
          '</div>' +
          '<div class="kiosk-lookup-preview-code">' +
            escapeHtml(c.child_code || "") +
          '</div>' +
        '</div>' +
      '</button>'
    );
  }

  function renderLookupPreview(matches) {
    if (!lookupPreview) return;

    if (!matches || matches.length === 0) {
      lookupPreview.innerHTML =
        '<div class="kiosk-lookup-preview-empty">' +
          'Walang nahanap na record. Subukan ang ibang pangalan o Child ID.' +
        '</div>';
      lookupPreview.hidden = false;
      return;
    }

    var html = matches.slice(0, 5).map(buildPreviewCardHtml).join("");
    lookupPreview.innerHTML = html;
    lookupPreview.hidden = false;

    lookupPreview
      .querySelectorAll("[data-preview-child-id]")
      .forEach(function (btn) {
        btn.addEventListener("click", function () {
          var childId = btn.getAttribute("data-preview-child-id");
          var child = (data.children || []).find(function (c) {
            return String(c.id) === String(childId);
          });
          if (child) {
            selectChildFromPreview(child);
          }
        });
      });
  }

  function handleLiveSearch() {
    if (!childIdInput) return;
    var input = childIdInput.value.trim();

    if (!input) {
      if (lookupPreview) {
        lookupPreview.hidden = true;
        lookupPreview.innerHTML = "";
      }
      return;
    }

    var children = data.children || [];
    var lowerInput = input.toLowerCase();
    var matches = children.filter(function (c) {
      var code = (c.child_code || "").toLowerCase();
      var firstName = (c.first_name || "").toLowerCase();
      var lastName = (c.last_name || "").toLowerCase();
      var fullName = (firstName + " " + lastName).trim();
      return (
        code.indexOf(lowerInput) !== -1 ||
        firstName.indexOf(lowerInput) !== -1 ||
        lastName.indexOf(lowerInput) !== -1 ||
        fullName.indexOf(lowerInput) !== -1
      );
    });

    renderLookupPreview(matches);
  }

  function handleChildLookup() {
    const input = (childIdInput?.value || "").trim();
    if (!input) return;

    showLookupState("searching");

    setTimeout(function () {
      const children = data.children || [];
      const lowerInput = input.toLowerCase();
      const match = children.find(function (c) {
        const code = (c.child_code || "").toLowerCase();
        const firstName = (c.first_name || "").toLowerCase();
        const lastName = (c.last_name || "").toLowerCase();
        const fullName = (firstName + " " + lastName).trim();
        return (
          code === lowerInput ||
          fullName === lowerInput ||
          firstName === lowerInput ||
          lastName === lowerInput ||
          fullName.indexOf(lowerInput) !== -1
        );
      });

      if (!match) {
        showLookupState("idle");
        pushFeed(
          "Hindi nahanap",
          "Walang record na may ganiyang Child ID. Subukan muli.",
          "warn"
        );
        return;
      }

      populateFoundCard(match);
      if (lookupPreview) lookupPreview.hidden = true;
      showLookupState("found");
    }, 600);
  }

  if (childIdInput) {
    childIdInput.addEventListener("input", function () {
      if (clearChildIdBtn) clearChildIdBtn.hidden = !childIdInput.value;

      if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
      }
      searchDebounceTimer = setTimeout(handleLiveSearch, 200);
    });
    childIdInput.addEventListener("keydown", function (e) {
      if (e.key === "Enter") {
        e.preventDefault();
        handleChildLookup();
      } else if (e.key === "Escape") {
        if (lookupPreview) {
          lookupPreview.hidden = true;
        }
      }
    });
  }

  if (clearChildIdBtn) {
    clearChildIdBtn.addEventListener("click", function () {
      if (childIdInput) {
        childIdInput.value = "";
        childIdInput.focus();
        clearChildIdBtn.hidden = true;
      }
    });
  }

  if (lookupSubmitBtn) {
    lookupSubmitBtn.addEventListener("click", handleChildLookup);
  }

  const processBtn =
    document.getElementById("processBtn");

  const clock =
    document.querySelector(
      "[data-kiosk-clock]"
    );

  const welcomeClock =
    document.querySelector(
      "[data-kiosk-live-clock]"
    );

  const welcomeDate =
    document.querySelector(
      "[data-kiosk-live-date]"
    );

  const heroNote =
    document.querySelector(
      ".kiosk-hero-note"
    );

  const feed =
    document.querySelector(
      "[data-kiosk-feed]"
    );

  const startButton =
    document.querySelector(
      '[data-kiosk-action="start"]'
    );

  const proceedLiveButton =
    document.querySelector(
      '[data-kiosk-action="proceed-live"]'
    );

  // ============================================================
  // SENSOR CHIPS
  // ============================================================

  const lidarChip =
    document.querySelector(
      "[data-kiosk-chip-lidar]"
    );

  const loadCellChip =
    document.querySelector(
      "[data-kiosk-chip-loadcell]"
    );

  const connectedChip =
    document.querySelector(
      "[data-kiosk-chip-connected]"
    );

  // ============================================================
  // REFERENCES
  // ============================================================

  const refs = {
    currentChildLabel:
      document.querySelector(
        "[data-kiosk-current-child-label]"
      ),

    heightReadout:
      document.querySelector(
        "[data-kiosk-height-readout]"
      ),

    heightStatus:
      document.querySelector(
        "[data-kiosk-height-status]"
      ),

    heightBar:
      document.querySelector(
        "[data-kiosk-height-bar]"
      ),

    weightReadout:
      document.querySelector(
        "[data-kiosk-weight-readout]"
      ),

    weightStatus:
      document.querySelector(
        "[data-kiosk-weight-status]"
      ),

    weightBars:
      document.querySelector(
        "[data-kiosk-weight-bars]"
      ),

    heightIndicator:
      document.querySelector(
        "[data-kiosk-height-indicator]"
      ),

    weightIndicator:
      document.querySelector(
        "[data-kiosk-weight-indicator]"
      ),

    progressValue:
      document.querySelector(
        "[data-kiosk-progress-value]"
      ),

    progressRing:
      document.querySelector(
        "[data-kiosk-progress-ring]"
      ),

    processStage:
      document.querySelector(
        "[data-kiosk-process-stage]"
      ),

    processingError:
      document.querySelector(
        "[data-kiosk-processing-error]"
      ),

    processingErrorMessage:
      document.querySelector(
        "[data-kiosk-processing-error-message]"
      ),

    resultChild:
      document.querySelector(
        "[data-kiosk-result-child]"
      ),

    resultMeta:
      document.querySelector(
        "[data-kiosk-result-meta]"
      ),

    resultStatus:
      document.querySelector(
        "[data-kiosk-result-status]"
      ),

    resultFlag:
      document.querySelector(
        "[data-kiosk-result-flag]"
      ),

    resultFlagReason:
      document.querySelector(
        "[data-kiosk-result-flag-reason]"
      ),

    resultHeight:
      document.querySelector(
        "[data-kiosk-result-height]"
      ),

    resultWeight:
      document.querySelector(
        "[data-kiosk-result-weight]"
      ),

    resultWaz:
      document.querySelector(
        "[data-kiosk-result-waz]"
      ),

    resultHaz:
      document.querySelector(
        "[data-kiosk-result-haz]"
      ),

    resultWhz:
      document.querySelector(
        "[data-kiosk-result-whz]"
      ),

    resultSource:
      document.querySelector(
        "[data-kiosk-result-source]"
      ),

    resultSex:
      document.querySelector(
        "[data-kiosk-result-sex]"
      ),

    resultDate:
      document.querySelector(
        "[data-kiosk-result-date]"
      ),

    resultTime:
      document.querySelector(
        "[data-kiosk-result-time]"
      ),

    resultInitials:
      document.querySelector(
        "[data-kiosk-result-initials]"
      ),

    resultWfaStatus:
      document.querySelector(
        "[data-kiosk-result-wfa-status]"
      ),

    resultHfaStatus:
      document.querySelector(
        "[data-kiosk-result-hfa-status]"
      ),

    resultWflhStatus:
      document.querySelector(
        "[data-kiosk-result-wflh-status]"
      ),

    resultBmi:
      null,

    sessionId:
      document.querySelector(
        "[data-kiosk-session-id]"
      ),

    sessionStatus:
      document.querySelector(
        "[data-kiosk-session-status]"
      ),

    sessionStarted:
      document.querySelector(
        "[data-kiosk-session-started]"
      )
  };

  // ============================================================
  // CHILDREN
  // ============================================================

  const children =
    Array.isArray(data.children)
      ? data.children
      : [];

  // ============================================================
  // STATE
  // ============================================================

  const state = {
    step: "welcome",

    child: null,

    session: null,

    phase: "idle",

    submitting: false,

    awaitingLiveResult: false,

    firebaseSessionId: null,

    lastFirebaseTimestamp: "",

    lastFirebaseSignature: "",

    firebaseTimer: null,

    statusTimer: null,

    processingTimer: null,

    backendCompletionTimer: null,

    weight: null,

    height: null,

    nutritionalStatus: null,

    weightLocked: false,

    heightLocked: false,

    lastWeightRaw: null,

    lastHeightRaw: null,

    weightStableCount: 0,

    heightStableCount: 0,

    firebaseOnline: false,

    deviceOnline: false,

    deviceStatusChecked: false,

    deviceStatusTimer: null,

    deviceStatusRequestInProgress: false,

    measurementReady: false,

    finalReady: false,
    finalSequence: 0,
    finalWeight: null,
    finalHeight: null,

    processingStarted: false,

    restoredSession: false,

    startRequestInProgress: false,

    firebaseRequestInProgress: false,

    statusRequestInProgress: false,

    destroyed: false
  };

  // ============================================================
  // HELPERS
  // ============================================================

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function formatNow(date = new Date()) {
    return new Intl.DateTimeFormat(
      "en-PH",
      {
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: true
      }
    ).format(date);
  }

  function formatDate(date = new Date()) {
    return new Intl.DateTimeFormat(
      "en-PH",
      {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric"
      }
    ).format(date);
  }

  function formatChildName(child) {
    if (!child) {
      return "Choose a child";
    }

    return (
      `${child.first_name || ""} ` +
      `${child.last_name || ""}`
    ).trim();
  }

  function getSelectedChild() {
    return state.child;
  }

  function getCurrentSessionId() {
    if (!state.session) {
      return null;
    }

    const id = Number(
      state.session.session_id ||
      state.session.id ||
      0
    );

    return id > 0 ? id : null;
  }

  function normalizeStatus(value) {
    return String(value || "")
      .trim()
      .toUpperCase();
  }

  function isValidWeight(value) {
    /*
     * IMPORTANT: null/undefined mean "no reading yet", not "0 kg".
     * Number(null) === 0 in JS, so without this guard a missing
     * reading was silently treated as a valid 0 kg measurement,
     * which is how the live readout would end up stuck showing
     * "0.00" (e.g. via restoreActiveSession() coercing a not-yet-
     * captured saved.weight of null into 0).
     */
    if (value === null || value === undefined || value === "") {
      return false;
    }

    const n = Number(value);

    return (
      Number.isFinite(n) &&
      n > 0 &&
      n <= 300
    );
  }

  function isValidHeight(value) {
    if (value === null || value === undefined || value === "") {
      return false;
    }

    const n = Number(value);

    return (
      Number.isFinite(n) &&
      n > 0 &&
      n <= 300
    );
  }

  function pushFeed(
    action,
    detail,
    level = "info"
  ) {
    if (!feed) {
      return;
    }

    const row =
      document.createElement("div");

    row.className =
      "kiosk-feed-row";

    row.dataset.level = level;

    row.innerHTML = `
      <span class="kiosk-feed-time">
        ${escapeHtml(formatNow())}
      </span>

      <strong>
        ${escapeHtml(action)}
      </strong>

      <span>
        ${escapeHtml(detail)}
      </span>
    `;

    feed.prepend(row);

    while (feed.children.length > 8) {
      feed.removeChild(
        feed.lastElementChild
      );
    }
  }

  // ============================================================
  // STORAGE
  // ============================================================

  function saveSessionToStorage() {
    try {
      if (!state.session) {
        return;
      }

      const saved = {
        session: state.session,

        childId:
          state.child?.id || null,

        weight:
          state.weight,

        height:
          state.height,

        weightLocked:
          state.weightLocked,

        heightLocked:
          state.heightLocked,

        measurementReady:
          state.measurementReady,

        step:
          state.step,

        phase:
          state.phase,

        firebaseSessionId:
          state.firebaseSessionId,

        savedAt:
          Date.now()
      };

      sessionStorage.setItem(
        STORAGE_KEY,
        JSON.stringify(saved)
      );

      if (state.child) {
        sessionStorage.setItem(
          CHILD_STORAGE_KEY,
          JSON.stringify(state.child)
        );
      }
    } catch (error) {
      console.warn(
        "[SukatKalusugan] saveSessionToStorage failed",
        error
      );
    }
  }

  function loadSessionFromStorage() {
    try {
      const raw =
        sessionStorage.getItem(
          STORAGE_KEY
        );

      if (!raw) {
        return null;
      }

      const saved =
        JSON.parse(raw);

      if (!saved) {
        return null;
      }

      if (
        saved.savedAt &&
        Date.now() -
          Number(saved.savedAt) >
          sessionTimeoutSeconds * 1000
      ) {
        clearSessionStorage();

        return null;
      }

      return saved;
    } catch (error) {
      console.warn(
        "[SukatKalusugan] loadSessionFromStorage failed",
        error
      );

      return null;
    }
  }

  function clearSessionStorage() {
    try {
      sessionStorage.removeItem(
        STORAGE_KEY
      );

      sessionStorage.removeItem(
        CHILD_STORAGE_KEY
      );
    } catch (error) {
      console.warn(
        "[SukatKalusugan] clearSessionStorage failed",
        error
      );
    }
  }

  function restoreChild(childId) {
    if (!childId) {
      return null;
    }

    return (
      children.find(
        child =>
          String(child.id) ===
          String(childId)
      ) || null
    );
  }

  // ============================================================
  // SESSION UI
  // ============================================================

  function updateSessionInfo(session) {
    if (!session) {
      if (refs.sessionId) {
        refs.sessionId.textContent = "—";
      }

      if (refs.sessionStatus) {
        refs.sessionStatus.textContent =
          "Idle";
      }

      if (refs.sessionStarted) {
        refs.sessionStarted.textContent =
          "—";
      }

      return;
    }

    if (refs.sessionId) {
      refs.sessionId.textContent =
        String(
          session.session_id ||
          session.id ||
          "—"
        );
    }

    if (refs.sessionStatus) {
      refs.sessionStatus.textContent =
        String(
          session.status ||
          session.state ||
          "IDLE"
        );
    }

    if (refs.sessionStarted) {
      refs.sessionStarted.textContent =
        session.started_at
          ? new Date(
              session.started_at
            ).toLocaleString()
          : "—";
    }
  }

  // ============================================================
  // STEP NAVIGATION
  // ============================================================

  function setStep(step) {
    const allowed = [
      "welcome",
      "privacy",
      "child-lookup",
      "measurement",
      "processing",
      "results",
      "thankyou",
      "view-results",
      "info"
    ];

    if (!allowed.includes(step)) {
      return;
    }

    if (
      step === "processing" &&
      !state.processingStarted
    ) {
      console.warn(
        "[SukatKalusugan] Processing navigation blocked."
      );

      return;
    }

    if (
      step === "results" &&
      !state.processingStarted
    ) {
      console.warn(
        "[SukatKalusugan] Results navigation blocked."
      );

      return;
    }

    const prevStep = state.step;
    state.step = step;

    body.dataset.kioskStep = step;

    // Determine transition direction
    const stepOrder = ["welcome", "privacy", "child-lookup", "measurement", "processing", "results", "thankyou"];
    const prevIdx = stepOrder.indexOf(prevStep);
    const newIdx = stepOrder.indexOf(step);
    const goingForward = newIdx >= prevIdx;

    screens.forEach(screen => {
      const screenName = screen.getAttribute("data-kiosk-screen");
      const active = screenName === step;

      if (!active && screen.contains(document.activeElement)) {
        document.activeElement.blur();
      }

      // Remove transition classes
      screen.classList.remove("is-active", "is-exit-left");

      if (active) {
        // Entering: set initial state then animate in
        screen.hidden = false;
        screen.style.display = "";

        // Force reflow
        void screen.offsetHeight;

        screen.classList.add("is-active");
      } else {
        // Exiting
        screen.classList.remove("is-active");

        if (screenName === prevStep) {
          // The screen we're leaving
          if (goingForward) {
            screen.classList.add("is-exit-left");
          }
        }

        // Hide after transition
        setTimeout(() => {
          if (!screen.classList.contains("is-active")) {
            screen.hidden = true;
          }
        }, 450);
      }

      screen.setAttribute("aria-hidden", String(!active));
    });

    if (welcomeScreen) {
      welcomeScreen.hidden = (step !== "welcome");
    }

    if (stepBar) {
      stepBar.hidden = true;
    }

    if (stage) {
      stage.hidden = true;
    }

    stepButtons.forEach(button => {
      const target = button.getAttribute("data-kiosk-step-jump");
      button.classList.toggle("is-active", target === step);
    });

    // Reset privacy checkboxes when entering privacy screen
    if (step === "privacy") {
      var cb = document.getElementById("privacyCheckbox");
      var ib = document.getElementById("infoSheetCheckbox");
      var btn = document.getElementById("privacyContinueBtn");
      if (cb) cb.checked = false;
      if (ib) ib.checked = false;
      if (btn) btn.disabled = true;
    }

    // Reset child lookup when entering lookup screen
    if (step === "child-lookup") {
      resetLookup();
    }

    saveSessionToStorage();
  }

  // ============================================================
  // PROGRESS
  // ============================================================

  function setProgress(
    progress,
    message
  ) {
    const value =
      Math.max(
        0,
        Math.min(
          100,
          Number(progress) || 0
        )
      );

    if (refs.progressValue) {
      refs.progressValue.textContent =
        `${Math.round(value)}%`;
    }

    if (refs.progressRing) {
      const circumference =
        2 * Math.PI * 88;

      refs.progressRing.style.strokeDasharray =
        String(circumference);

      refs.progressRing.style.strokeDashoffset =
        String(
          circumference -
            (circumference * value) /
              100
        );
    }

    if (
      refs.processStage &&
      message
    ) {
      refs.processStage.textContent =
        message;
    }
  }

  // ============================================================
  // STEP 3 (PROCESSING) ERROR BANNER
  // ============================================================
  //
  // Step 2 (live) never shows "Measurement failed" — that
  // banner only ever belongs on Step 3 (processing/results).
  // ============================================================

  function showProcessingError(message) {
    if (refs.processingError) {
      refs.processingError.hidden = false;
    }

    if (refs.processingErrorMessage) {
      refs.processingErrorMessage.textContent =
        message ||
        "Something went wrong with the measurement.";
    }
  }

  function hideProcessingError() {
    if (refs.processingError) {
      refs.processingError.hidden = true;
    }
  }

  // ============================================================
  // PROCESS BUTTON
  // ============================================================

  function getProcessButton() {
    return document.querySelector(
      '[data-kiosk-action="process-measurement"]'
    );
  }

  function updateProcessButton() {
    const button =
      getProcessButton();

    if (!button) {
      return;
    }

    const ready =
      state.measurementReady &&
      state.finalReady &&
      state.finalSequence > 0 &&
      !state.processingStarted &&
      isValidWeight(state.weight) &&
      isValidHeight(state.height);

    button.disabled = !ready;

    if (state.processingStarted) {
      button.textContent =
        "Pinoproseso...";
    } else if (ready) {
      button.textContent =
        "I-process ang measurement";
    } else if (
      state.weightLocked &&
      !state.heightLocked
    ) {
      button.textContent =
        "Naghihintay ng Taas";
    } else if (
      !state.weightLocked &&
      state.heightLocked
    ) {
      button.textContent =
        "Naghihintay ng Timbang";
    } else {
      button.textContent =
        "Naghihintay ng Stable na Reading";
    }
  }

  function markMeasurementReady() {
    if (
      !state.finalReady ||
      state.finalSequence <= 0 ||
      !isValidWeight(state.finalWeight) ||
      !isValidHeight(state.finalHeight)
    ) {
      state.measurementReady =
        false;

      updateProcessButton();

      return false;
    }

    state.measurementReady =
      true;

    state.awaitingLiveResult =
      true;

    if (refs.weightStatus) {
      refs.weightStatus.textContent =
        "Weight captured";
    }

    if (refs.heightStatus) {
      refs.heightStatus.textContent =
        "Height captured";
    }

    setProgress(
      60,
      "Measurement ready. Click Process Measurement."
    );

    if (heroNote) {
      heroNote.textContent =
        "Pumili ng bata, pagkatapos ay simulan ang pagsukat.";
    }

    updateProcessButton();

    pushFeed(
      "Measurement ready",
      "Weight and height captured. Waiting for operator."
    );

    saveSessionToStorage();

    return true;
  }

  // ============================================================
  // CHILD SELECTION
  // ============================================================

  function selectChild(childId) {
    if (isMeasurementActive()) {
      pushFeed(
        "Selection locked",
        "Wait for the current measurement to finish.",
        "warn"
      );

      return;
    }

    if (state.processingStarted) {
      pushFeed(
        "Selection locked",
        "The current measurement is being processed.",
        "warn"
      );

      return;
    }

    state.child =
      children.find(
        child =>
          String(child.id) ===
          String(childId)
      ) || null;

    childCards.forEach(card => {
      card.classList.toggle(
        "is-selected",
        String(
          card.dataset.childId
        ) === String(childId)
      );
    });

    const child =
      getSelectedChild();

    if (refs.currentChildLabel) {
      refs.currentChildLabel.textContent =
        formatChildName(child);
    }

    // Let syncStartButtonState() decide proceedLiveButton's disabled
    // state — it also accounts for device connectivity, which a plain
    // "!child" check here does not.
    syncStartButtonState();

    if (child) {
      pushFeed(
        "Child selected",
        `${
          child.child_code || "Child"
        } · ${formatChildName(child)}`
      );

      try {
        sessionStorage.setItem(
          CHILD_STORAGE_KEY,
          JSON.stringify(child)
        );
      } catch (error) {
        console.warn(error);
      }
    }
  }

  window.kioskSelectChild =
    selectChild;

  // ============================================================
  // MEASUREMENT ACTIVE
  // ============================================================

  function isMeasurementActive(
    session = state.session
  ) {
    if (!session) {
      return false;
    }

    const status =
      normalizeStatus(
        session.status
      );

    return [
      "START_REQUESTED",
      "MEASURING",
      "WEIGHT_MEASURING",
      "HEIGHT_MEASURING"
    ].includes(status);
  }

  // ============================================================
  // START BUTTON
  // ============================================================

  function syncStartButtonState() {
    const deviceUnavailable =
      state.deviceStatusChecked &&
      !state.deviceOnline;

    if (startButton) {
      const disabled =
        state.submitting ||
        state.startRequestInProgress ||
        isMeasurementActive() ||
        state.processingStarted ||
        deviceUnavailable;

      startButton.disabled =
        disabled;

      startButton.textContent =
        state.submitting ||
        state.startRequestInProgress
          ? "Sinisimulan..."
          : deviceUnavailable
          ? "Device Offline"
          : "SIMULAN";
    }

    /*
     * "Proceed to Live" used to only track whether a child was
     * selected (see selectChild()). That meant once a child was
     * picked, this button stayed clickable even after the device
     * dropped offline, since nothing re-synced it the way
     * startButton gets re-synced on every device status poll here.
     * Fold the same deviceUnavailable check in so both buttons
     * agree with the actual connectivity state at all times.
     */
    if (proceedLiveButton) {
      proceedLiveButton.disabled =
        !getSelectedChild() ||
        state.submitting ||
        state.startRequestInProgress ||
        isMeasurementActive() ||
        state.processingStarted ||
        deviceUnavailable;
    }
  }

  // ============================================================
  // FIREBASE URL
  // ============================================================

  function firebaseLatestMeasurementUrl() {
    if (!firebaseEnabled) {
      return "";
    }

    return (
      firebaseBaseUrl.replace(
        /\/$/,
        ""
      ) +
      "/latest_measurements/" +
      encodeURIComponent(deviceId) +
      ".json"
    );
  }

  // ============================================================
  // CHIPS
  // ============================================================

  function setChip(
    element,
    label,
    good
  ) {
    if (!element) {
      return;
    }

    const dot =
      element.querySelector(
        ".kiosk-dot"
      );

    if (dot) {
      dot.style.background =
        good
          ? "#2ec57a"
          : "#d85d5d";
    }

    element.classList.toggle(
      "is-success",
      good
    );

    const textNodes =
      Array.from(
        element.childNodes
      ).filter(
        node =>
          node.nodeType ===
          Node.TEXT_NODE
      );

    if (textNodes.length) {
      textNodes[
        textNodes.length - 1
      ].textContent =
        ` ${label}`;
    }
  }

  // ============================================================
  // SENSOR UI
  // ============================================================

  // Track last weight that triggered a bars rebuild
  let lastWeightBarsValue = -1;
  const WEIGHT_BARS_STEP_KG = 0.05;

  function setWeight(
    value,
    message = "Reading weight..."
  ) {
    const weight =
      Number(value);

    if (!isValidWeight(weight)) {
      return false;
    }

    state.weight =
      weight;

    if (refs.weightReadout) {
      refs.weightReadout.textContent =
        weight.toFixed(2);
    }

    if (refs.weightStatus) {
      refs.weightStatus.textContent =
        message;
    }

    // Update stability icon
    const weightStatusIcon =
      refs.weightStatus?.querySelector(
        ".kiosk-status-icon"
      );
    if (weightStatusIcon) {
      weightStatusIcon.className =
        "kiosk-status-icon " +
        (message.includes("stable") || message.includes("captured")
          ? "stable"
          : "waiting");
    }

    // Throttle weight bars: only rebuild when weight
    // changes by more than WEIGHT_BARS_STEP_KG.
    // This cuts ~25 DOM ops per WS message to ~3.
    if (
      refs.weightBars &&
      Math.abs(weight - lastWeightBarsValue) >= WEIGHT_BARS_STEP_KG
    ) {
      lastWeightBarsValue = weight;

      refs.weightBars.innerHTML = "";

      const normalized =
        Math.min(
          100,
          Math.max(5, weight)
        );

      for (let i = 0; i < 8; i++) {
        const bar =
          document.createElement(
            "span"
          );

        const height =
          Math.min(
            100,
            20 +
              normalized *
                (0.35 +
                  i * 0.06)
          );

        bar.style.height =
          `${height}%`;

        refs.weightBars.appendChild(
          bar
        );
      }
    }

    setChip(
      loadCellChip,
      "Scale: Live",
      true
    );

    // Update horizontal weight bar indicator (0-50 kg scale)
    if (refs.weightIndicator) {
      const barPercent = Math.min(
        100,
        Math.max(0, (weight / 50) * 100)
      );
      refs.weightIndicator.style.left =
        `${barPercent}%`;
    }

    return true;
  }

  function setHeight(
    value,
    message = "Reading height..."
  ) {
    const height =
      Number(value);

    if (!isValidHeight(height)) {
      return false;
    }

    state.height =
      height;

    if (refs.heightReadout) {
      refs.heightReadout.textContent =
        height.toFixed(1);
    }

    if (refs.heightStatus) {
      refs.heightStatus.textContent =
        message;
    }

    // Update stability icon
    const heightStatusIcon =
      refs.heightStatus?.querySelector(
        ".kiosk-status-icon"
      );
    if (heightStatusIcon) {
      heightStatusIcon.className =
        "kiosk-status-icon " +
        (message.includes("stable") || message.includes("captured")
          ? "stable"
          : "waiting");
    }

    if (refs.heightBar) {
      const percentage =
        Math.min(
          100,
          Math.max(
            0,
            (height / 250) * 100
          )
        );

      refs.heightBar.style.width =
        `${percentage}%`;
    }

    // Update ruler indicator position
    if (refs.heightIndicator) {
      const rulerPercent =
        Math.min(
          100,
          Math.max(
            0,
            (height / 150) * 100
          )
        );

      refs.heightIndicator.style.bottom =
        `${rulerPercent}%`;
    }

    setChip(
      lidarChip,
      "LiDAR: Live",
      true
    );

    return true;
  }

  // ============================================================
  // STABILITY
  // ============================================================

  function updateStability(
    type,
    value,
    deviceStable
  ) {
    const isWeight =
      type === "weight";

    const lockedKey =
      isWeight
        ? "weightLocked"
        : "heightLocked";

    // Prefer the device's own raw-sample stability flag when the
    // firmware provides one: it's computed from consecutive RAW
    // sensor readings on the ESP32, so it can't be fooled by a
    // slow-moving smoothed average looking "stable" while the true
    // reading is still settling. Sticky, like the fallback below —
    // once true, stays true for the rest of this session.
    if (
      typeof deviceStable ===
      "boolean"
    ) {
      if (
        deviceStable
      ) {
        state[lockedKey] = true;
      }

      return state[lockedKey];
    }

    /*
     * Real sensor noise floor:
     * - HX711 load cells commonly drift +/-50-150g even on a
     *   perfectly still platform, so 0.05kg was tighter than the
     *   hardware itself can hold, which is what made "stable"
     *   feel like it never arrived.
     * - TF-Luna height noise is on the order of a few mm to ~1cm.
     */

    const epsilon =
      isWeight
        ? 0.15
        : 1.0;

    const lastKey =
      isWeight
        ? "lastWeightRaw"
        : "lastHeightRaw";

    const countKey =
      isWeight
        ? "weightStableCount"
        : "heightStableCount";

    const last =
      state[lastKey];

    if (
      last !== null &&
      Math.abs(
        Number(value) -
          Number(last)
      ) <= epsilon
    ) {
      state[countKey] += 1;
    } else {
      /*
       * DECAY, DON'T WIPE.
       *
       * A single noisy sample used to reset the whole counter to
       * 0, so if you were 2 of 3 readings into locking, one blip
       * threw away all that progress and restarted the wait from
       * scratch. On noisy hardware that meant "stable" could take
       * a very long time (or never happen) even though the value
       * was genuinely holding steady overall. Decrementing instead
       * still requires sustained agreement to lock, but survives
       * an isolated stray reading instead of restarting on it.
       */

      state[countKey] =
        Math.max(
          0,
          state[countKey] - 1
        );
    }

    state[lastKey] =
      Number(value);

    /*
     * Require several consistent readings.
     * This prevents one accidental identical
     * reading from locking immediately.
     */

    if (state[countKey] >= 3) {
      state[lockedKey] = true;
    }

    return state[lockedKey];
  }

  // ============================================================
  // FIREBASE PAYLOAD
  // ============================================================

  function applyFirebaseStatus(
    payload
  ) {
    if (
      !payload ||
      typeof payload !== "object"
    ) {
      return;
    }

    const status =
      normalizeStatus(
        payload.status
      );

    const weight =
      payload.weight_kg != null
        ? Number(
            payload.weight_kg
          )
        : NaN;

    const height =
      payload.height_cm != null
        ? Number(
            payload.height_cm
          )
        : NaN;

    const hasWeight =
      isValidWeight(weight);

    const hasHeight =
      isValidHeight(height);

    console.log(
      "[SukatKalusugan] Firebase payload:",
      payload
    );

    state.firebaseOnline =
      true;

    setChip(
      connectedChip,
      "Device: Connected",
      true
    );

    // ==========================================================
    // GET READY — shown BEFORE we start reading the sensors,
    // so the kiosk never flashes an error/blank screen first.
    // ==========================================================

      if (
        [
          "START_REQUESTED",
          "GET_READY"
        ].includes(status)
      ) {
        state.phase = "live";

        if (
          !state.processingStarted &&
          state.step !== "measurement"
        ) {
          setStep("measurement");
        }

      const secondsLeft =
        Number.isFinite(
          Number(payload.seconds_left)
        )
          ? Number(payload.seconds_left)
          : null;

      const readyMessage =
        payload.message ||
        "Please step on the platform now.";

      setProgress(
        10,
        secondsLeft !== null && secondsLeft > 0
          ? `${readyMessage} (${secondsLeft})`
          : readyMessage
      );

      if (refs.weightStatus) {
        refs.weightStatus.textContent =
          "Tumayo sa platform...";
      }

      if (refs.heightStatus) {
        refs.heightStatus.textContent =
          "Tumayo sa platform...";
      }

      saveSessionToStorage();

      return;
    }

    // ==========================================================
    // MEASURING
    // ==========================================================

    if (
      [
        "MEASURING",
        "WEIGHT_MEASURING",
        "HEIGHT_MEASURING"
      ].includes(status)
    ) {
      state.phase = "live";

      if (
        !state.processingStarted &&
        state.step !== "measurement"
      ) {
        setStep("measurement");
      }

      const deviceWeightStable =
        typeof payload.weight_stable ===
        "boolean"
          ? payload.weight_stable
          : undefined;

      const deviceHeightStable =
        typeof payload.height_stable ===
        "boolean"
          ? payload.height_stable
          : undefined;

      if (hasWeight) {
        const locked =
          updateStability(
            "weight",
            weight,
            deviceWeightStable
          );

        setWeight(
          weight,
          locked
            ? "Timbang stable"
            : "Binabasa ang timbang..."
        );
      }

      if (hasHeight) {
        const locked =
          updateStability(
            "height",
            height,
            deviceHeightStable
          );

        setHeight(
          height,
          locked
            ? "Taas stable"
            : "Binabasa ang taas..."
        );
      }

      // A stable flag alone is NOT enough. The ESP32 must hold both
      // sensors stable, freeze one exact snapshot, and publish final_ready.
      const finalReady = payload.final_ready === true;
      const finalSequence = Number(payload.final_sequence || 0);
      const sequence = Number(payload.sequence || 0);
      const finalWeight = Number(payload.final_weight_kg);
      const finalHeight = Number(payload.final_height_cm);

      if (
        finalReady &&
        finalSequence > 0 &&
        finalSequence <= sequence &&
        isValidWeight(finalWeight) &&
        isValidHeight(finalHeight)
      ) {
        state.finalReady = true;
        state.finalSequence = finalSequence;
        state.finalWeight = finalWeight;
        state.finalHeight = finalHeight;
        state.weight = finalWeight;
        state.height = finalHeight;
        state.weightLocked = true;
        state.heightLocked = true;

        setWeight(finalWeight, "Huling timbang na stable");
        setHeight(finalHeight, "Huling taas na stable");
        markMeasurementReady();
      } else if (!state.finalReady) {
        state.finalSequence = 0;
        state.finalWeight = null;
        state.finalHeight = null;
        state.measurementReady = false;
        updateProcessButton();
      }

      let progress = 20;

      if (state.weightLocked) {
        progress += 20;
      }

      if (state.heightLocked) {
        progress += 20;
      }

      setProgress(
        progress,
        payload.message ||
          "Stand still while the sensors capture your measurement..."
      );

      saveSessionToStorage();

      return;
    }

    // ==========================================================
    // COMPLETE
    // ==========================================================

    if (status === "COMPLETE") {
      if (hasWeight) {
        state.weightLocked =
          true;

        setWeight(
          weight,
          "Weight captured"
        );
      }

      if (hasHeight) {
        state.heightLocked =
          true;

        setHeight(
          height,
          "Height captured"
        );
      }

      state.phase = "live";

      state.submitting =
        false;

      state.awaitingLiveResult =
        true;

      if (
        !state.processingStarted &&
        state.step !== "measurement"
      ) {
        setStep("measurement");
      }

      markMeasurementReady();

      pushFeed(
        "Sensors complete",
        "Weight and height captured. Click Process Measurement."
      );

      saveSessionToStorage();

      return;
    }

    // ==========================================================
    // ERROR — never shown on measurement screens. We advance to
    // processing and show the failure banner there instead.
    // ==========================================================

    if (
      status === "ERROR" ||
      status === "CANCELLED"
    ) {
      state.phase =
        "error";

      state.submitting =
        false;

      state.awaitingLiveResult =
        false;

      state.processingStarted =
        true;

      updateProcessButton();

      setStep("processing");

      showProcessingError(
        payload.message ||
          payload.error_message ||
          "Measurement failed."
      );

      setProgress(
        100,
        payload.message ||
          payload.error_message ||
          "Measurement failed."
      );

      pushFeed(
        "Measurement failed",
        payload.message ||
          payload.error_message ||
          "Unknown measurement error.",
        "error"
      );

      saveSessionToStorage();

      stopFirebasePolling();
      disconnectWebSocket();
    }
  }

  // ============================================================
  // FIREBASE POLLING
  // ============================================================

  async function refreshFirebaseLatestMeasurement() {
    if (
      !firebaseEnabled ||
      !state.awaitingLiveResult ||
      state.firebaseRequestInProgress
    ) {
      return null;
    }

    const url =
      firebaseLatestMeasurementUrl();

    if (!url) {
      return null;
    }

    state.firebaseRequestInProgress =
      true;

    try {
      const response =
        await fetch(
          url,
          {
            cache: "no-store",
            headers: {
              Accept:
                "application/json"
            }
          }
        );

      if (!response.ok) {
        state.firebaseOnline =
          false;

        setChip(
          connectedChip,
          "Device: Offline",
          false
        );

        return null;
      }

      const payload =
        await response
          .json()
          .catch(() => null);

      if (
        !payload ||
        typeof payload !== "object"
      ) {
        return null;
      }

      state.firebaseOnline =
        true;

      setChip(
        connectedChip,
        "Device: Connected",
        true
      );

      // ========================================================
      // SESSION PROTECTION
      // ========================================================

      const payloadSessionId =
        Number(
          payload.session_id ||
          payload.sessionId ||
          0
        );

      const expectedSessionId =
        Number(
          state.firebaseSessionId ||
          getCurrentSessionId() ||
          0
        );

      /*
       * If Firebase contains a session ID,
       * it MUST match our SQL session.
       */

      if (
        expectedSessionId > 0 &&
        payloadSessionId > 0 &&
        payloadSessionId !==
          expectedSessionId
      ) {
        console.warn(
          "[SukatKalusugan] Ignoring Firebase session mismatch",
          {
            expected:
              expectedSessionId,
            received:
              payloadSessionId
          }
        );

        return null;
      }

      /*
       * If Firebase has a session ID of 0/missing,
       * allow live sensor values.
       *
       * This is important because your current
       * ESP32 firmware may not always send
       * session_id.
       *
       * However, COMPLETE is only trusted when
       * our current SQL session is active.
       */

      // ========================================================
      // DUPLICATE DETECTION
      // ========================================================

      const timestamp =
        String(
          payload.timestamp ||
          payload.updated_at ||
          payload.updatedAt ||
          ""
        );

      /*
       * IMPORTANT FIX:
       *
       * The previous code used:
       *
       * timestamp === lastTimestamp
       *
       * When ESP32 did not provide a timestamp,
       * both values were "" and the payload was
       * ignored forever.
       *
       * We now generate a signature from the
       * actual payload values.
       */

      const signature =
        JSON.stringify({
          status:
            payload.status || "",
          session_id:
            payload.session_id ||
            payload.sessionId ||
            "",
          weight_kg:
            payload.weight_kg ?? "",
          height_cm:
            payload.height_cm ?? "",
          sequence: payload.sequence ?? "",
          final_ready: payload.final_ready ?? false,
          final_sequence: payload.final_sequence ?? "",
          final_weight_kg: payload.final_weight_kg ?? "",
          final_height_cm: payload.final_height_cm ?? "",
          timestamp
        });

      if (
        signature ===
        state.lastFirebaseSignature
      ) {
        return payload;
      }

      state.lastFirebaseSignature =
        signature;

      if (timestamp) {
        state.lastFirebaseTimestamp =
          timestamp;
      }

      applyFirebaseStatus(
        payload
      );

      return payload;
    } catch (error) {
      console.error(
        "[SukatKalusugan] Firebase polling error",
        error
      );

      state.firebaseOnline =
        false;

      setChip(
        connectedChip,
        "Device: Offline",
        false
      );

      return null;
    } finally {
      state.firebaseRequestInProgress =
        false;
    }
  }

  function startFirebasePolling() {
    // Always try WebSocket first for local fast updates
    connectWebSocket();

    if (!firebaseEnabled) {
      if (!wsEnabled) {
        setChip(
          connectedChip,
          "Device: Offline",
          false
        );
      }

      if (wsEnabled && !wsConnected) {
        setChip(
          connectedChip,
          "Device: Waiting",
          false
        );
      }

      return;
    }

    stopFirebasePolling();

    state.firebaseTimer =
      setInterval(
        () => {
          refreshFirebaseLatestMeasurement();
        },
        pollIntervalMs
      );

    refreshFirebaseLatestMeasurement();
  }

  function stopFirebasePolling() {
    if (state.firebaseTimer) {
      clearInterval(
        state.firebaseTimer
      );

      state.firebaseTimer =
        null;
    }
  }

  // ============================================================
  // WEBSOCKET — DIRECT ESP32 CONNECTION
  // ============================================================
  //
  // When the kiosk browser is on the same LAN as the ESP32, a
  // WebSocket connection gives us ~50ms push updates instead of
  // the 200ms+ round-trip of Firebase HTTP polling. Firebase
  // stays active for remote dashboards; WebSocket is purely a
  // local fast-path.
  //

  function wsUrl() {
    if (!wsEnabled || !wsEsp32Ip) {
      return "";
    }

    return (
      "ws://" +
      wsEsp32Ip +
      ":" +
      wsPort +
      wsPath
    );
  }

  function connectWebSocket() {
    if (!wsEnabled) {
      return;
    }

    const url = wsUrl();

    if (!url) {
      return;
    }

    if (
      wsConnection &&
      (wsConnection.readyState ===
        WebSocket.CONNECTING ||
        wsConnection.readyState ===
        WebSocket.OPEN)
    ) {
      return;
    }

    console.log(
      "[SukatKalusugan] WS connecting to",
      url
    );

    try {
      wsConnection =
        new WebSocket(url);
    } catch (err) {
      console.warn(
        "[SukatKalusugan] WS create failed",
        err
      );
      scheduleWsReconnect();
      return;
    }

    wsConnection.onopen =
      function () {
        console.log(
          "[SukatKalusugan] WS connected"
        );

        wsConnected = true;
        wsReconnectAttempts = 0;

        var welcomeDot = document.querySelector("[data-kiosk-device-status] .kiosk-device-dot");
        var welcomeLabel = document.querySelector("[data-kiosk-device-status] .kiosk-device-label");
        if (welcomeDot) {
          welcomeDot.classList.add("online");
        }
        if (welcomeLabel) {
          welcomeLabel.textContent = "Device online";
        }

        setChip(
          connectedChip,
          "Device: Connected",
          true
        );

        // WebSocket is now the primary data path.
        // Stop Firebase HTTP polling to eliminate
        // duplicate DOM updates and blocking fetches.
        stopFirebasePolling();

        pushFeed(
          "WebSocket connected",
          "Live sensor data via local network."
        );
      };

    wsConnection.onclose =
      function () {
        console.log(
          "[SukatKalusugan] WS disconnected"
        );

        wsConnected = false;

        scheduleWsReconnect();
      };

    wsConnection.onerror =
      function (err) {
        console.warn(
          "[SukatKalusugan] WS error",
          err
        );

        wsConnected = false;
      };

    wsConnection.onmessage =
      function (event) {
        try {
          const payload =
            JSON.parse(event.data);

          if (
            payload.type ===
            "sensor_data"
          ) {
            handleWsPayload(payload);
          }
        } catch (e) {
          console.warn(
            "[SukatKalusugan] WS parse error",
            e
          );
        }
      };
  }

  function scheduleWsReconnect() {
    if (
      wsReconnectTimer ||
      wsReconnectAttempts >=
        wsMaxReconnectAttempts
    ) {
      return;
    }

    wsReconnectAttempts++;

    const delayMs =
      Math.min(
        wsReconnectBaseMs *
          Math.pow(
            1.5,
            wsReconnectAttempts - 1
          ),
        15000
      );

    console.log(
      "[SukatKalusugan] WS reconnect in",
      delayMs,
      "ms (attempt",
      wsReconnectAttempts,
      ")"
    );

    wsReconnectTimer = setTimeout(
      function () {
        wsReconnectTimer = null;
        connectWebSocket();
      },
      delayMs
    );
  }

  function disconnectWebSocket() {
    if (wsReconnectTimer) {
      clearTimeout(wsReconnectTimer);
      wsReconnectTimer = null;
    }

    wsReconnectAttempts =
      wsMaxReconnectAttempts;

    if (wsConnection) {
      try {
        wsConnection.close();
      } catch (_) {}

      wsConnection = null;
    }

    wsConnected = false;
  }

  function sendWsCommand(command) {
    if (
      !wsConnected ||
      !wsConnection ||
      wsConnection.readyState !==
        WebSocket.OPEN
    ) {
      return false;
    }

    try {
      wsConnection.send(
        JSON.stringify({
          type: "command",
          command: command
        })
      );

      return true;
    } catch (err) {
      console.warn(
        "[SukatKalusugan] WS send error",
        err
      );

      return false;
    }
  }

  function handleWsPayload(payload) {
    /*
     * WS payload has the same shape as the Firebase
     * latest_measurements snapshot. Feed it through the
     * same applyFirebaseStatus() state machine so all
     * the chip updates, progress bar, stability locking,
     * and final_ready detection work identically.
     */

    // Update Firebase signature so polling doesn't
    // reprocess payloads we already handled via WS.
    state.lastFirebaseSignature =
      JSON.stringify(payload);

    // Batch rapid WS messages into animation frames.
    // Only the latest payload in each frame is rendered.
    if (state._wsRafId) {
      cancelAnimationFrame(state._wsRafId);
    }

    state._wsRafId = requestAnimationFrame(
      function () {
        state._wsRafId = null;
        applyWsPayload(payload);
      }
    );
  }

  function applyWsPayload(payload) {
    const status =
      normalizeStatus(
        payload.status
      );

    const weight =
      payload.weight_kg != null
        ? Number(payload.weight_kg)
        : NaN;

    const height =
      payload.height_cm != null
        ? Number(payload.height_cm)
        : NaN;

    const hasWeight =
      isValidWeight(weight);

    const hasHeight =
      isValidHeight(height);

    state.firebaseOnline = true;

    if (hasWeight) {
      const locked =
        updateStability(
          "weight",
          weight,
          payload.weight_stable
        );

      setWeight(
        weight,
        locked
          ? "Timbang stable"
          : "Binabasa ang timbang..."
      );
    }

    if (hasHeight) {
      const locked =
        updateStability(
          "height",
          height,
          payload.height_stable
        );

      setHeight(
        height,
        locked
          ? "Taas stable"
          : "Binabasa ang taas..."
      );
    }

    const finalReady =
      payload.final_ready === true;

    const finalSequence =
      Number(
        payload.final_sequence || 0
      );

    const sequence =
      Number(payload.sequence || 0);

    const finalWeight =
      Number(payload.final_weight_kg);

    const finalHeight =
      Number(payload.final_height_cm);

    if (
      status === "MEASURING" ||
      status === "WEIGHT_MEASURING" ||
      status === "HEIGHT_MEASURING"
    ) {
      state.phase = "live";

      if (
        !state.processingStarted &&
        state.step !== "measurement"
      ) {
        setStep("measurement");
      }

      if (
        finalReady &&
        finalSequence > 0 &&
        finalSequence <= sequence &&
        isValidWeight(finalWeight) &&
        isValidHeight(finalHeight)
      ) {
        state.finalReady = true;
        state.finalSequence =
          finalSequence;
        state.finalWeight =
          finalWeight;
        state.finalHeight =
          finalHeight;
        state.weight = finalWeight;
        state.height = finalHeight;
        state.weightLocked = true;
        state.heightLocked = true;

        setWeight(
          finalWeight,
          "Huling timbang na stable"
        );

        setHeight(
          finalHeight,
          "Huling taas na stable"
        );

        markMeasurementReady();
      } else if (!state.finalReady) {
        state.finalSequence = 0;
        state.finalWeight = null;
        state.finalHeight = null;
        state.measurementReady = false;

        updateProcessButton();
      }

      let progress = 20;

      if (state.weightLocked) {
        progress += 20;
      }

      if (state.heightLocked) {
        progress += 20;
      }

      setProgress(
        progress,
        payload.message ||
          "Stand still while the sensors capture your measurement..."
      );

      saveSessionToStorage();

      return;
    }

    if (status === "COMPLETE") {
      if (hasWeight) {
        state.weightLocked = true;

        setWeight(weight, "Weight captured");
      }

      if (hasHeight) {
        state.heightLocked = true;

        setHeight(height, "Height captured");
      }

      state.phase = "live";

      state.submitting = false;

      state.awaitingLiveResult = true;

      if (
        !state.processingStarted &&
        state.step !== "measurement"
      ) {
        setStep("measurement");
      }

      markMeasurementReady();

      pushFeed(
        "Sensors complete",
        "Weight and height captured. Click Process Measurement."
      );

      saveSessionToStorage();

      return;
    }

    if (
      status === "ERROR" ||
      status === "CANCELLED"
    ) {
      state.phase = "error";

      state.submitting = false;

      state.awaitingLiveResult = false;

      state.processingStarted = true;

      updateProcessButton();

      setStep("processing");

      showProcessingError(
        payload.message ||
          payload.error_message ||
          "Measurement failed."
      );

      setProgress(
        100,
        payload.message ||
          payload.error_message ||
          "Measurement failed."
      );

      pushFeed(
        "Measurement failed",
        payload.message ||
          payload.error_message ||
          "Unknown measurement error.",
        "error"
      );

      saveSessionToStorage();

      stopFirebasePolling();
    }
  }

  // ============================================================
  // BACKEND STATUS
  // ============================================================

  async function refreshMeasurementStatus(
    scheduleNext = true
  ) {
    if (
      !state.session ||
      state.statusRequestInProgress
    ) {
      return null;
    }

    const expectedSessionId =
      getCurrentSessionId();

    if (!expectedSessionId) {
      return null;
    }

    state.statusRequestInProgress =
      true;

    try {
      const endpoint =
        data?.endpoints
          ?.measurementStatus ||
        "../api/kiosk/measurement_status.php";

      const url =
        new URL(
          endpoint,
          window.location.href
        );

      url.searchParams.set(
        "device_id",
        deviceId
      );

      url.searchParams.set(
        "session_id",
        String(
          expectedSessionId
        )
      );

      const response =
        await fetch(
          url.toString(),
          {
            cache: "no-store",
            headers: {
              Accept:
                "application/json"
            }
          }
        );

      const json =
        await response
          .json()
          .catch(() => ({}));

      if (
        !response.ok ||
        json?.success !== true
      ) {
        throw new Error(
          json?.message ||
            "Unable to load measurement status."
        );
      }

      const payload =
        json?.data || {};

      // ========================================================
      // EXACT SESSION VALIDATION
      // ========================================================

      const returnedSessionId =
        Number(
          payload.session_id ||
          payload.id ||
          0
        );

      if (
        returnedSessionId !==
        expectedSessionId
      ) {
        console.error(
          "[SukatKalusugan] SQL SESSION MISMATCH",
          {
            expected:
              expectedSessionId,
            received:
              returnedSessionId
          }
        );

        pushFeed(
          "Session mismatch",
          `Expected SQL session #${expectedSessionId}, but server returned #${
            returnedSessionId || "unknown"
          }.`,
          "error"
        );

        /*
         * NEVER replace our current session
         * with another session returned by SQL.
         */

        return state.session;
      }

      state.session =
        payload;

      updateSessionInfo(
        payload
      );

      saveSessionToStorage();

      const status =
        normalizeStatus(
          payload.status
        );

      // ========================================================
      // ERROR
      // ========================================================

      if (
        status === "ERROR" ||
        status === "CANCELLED"
      ) {
        processingFailed(
          payload.error_message ||
            "Measurement failed."
        );

        return payload;
      }

      // ========================================================
      // ACTIVE
      // ========================================================

      if (
        isMeasurementActive(
          payload
        )
      ) {
        if (
          !state.processingStarted &&
          state.step !== "measurement"
        ) {
          setStep("measurement");
        }

        if (scheduleNext) {
          scheduleStatusRefresh();
        }

        return payload;
      }

      // ========================================================
      // COMPLETE
      // ========================================================

      if (
        status === "COMPLETE"
      ) {
        /*
         * COMPLETE is NOT Results.
         *
         * If the operator hasn't clicked
         * Process Measurement, stay on Live.
         */

      if (
        !state.processingStarted &&
        state.step !== "measurement"
      ) {
        if (
          isValidWeight(
            state.weight
          ) &&
          isValidHeight(
            state.height
          )
        ) {
          state.measurementReady =
            true;

          state.awaitingLiveResult =
            true;

          setStep("measurement");

          markMeasurementReady();
        }
      }

        return payload;
      }

      if (scheduleNext) {
        scheduleStatusRefresh();
      }

      return payload;
    } catch (error) {
      console.warn(
        "[SukatKalusugan] Session status error",
        error
      );

      if (
        scheduleNext &&
        isMeasurementActive(
          state.session
        )
      ) {
        scheduleStatusRefresh();
      }

      return null;
    } finally {
      state.statusRequestInProgress =
        false;
    }
  }

  function scheduleStatusRefresh() {
    if (state.statusTimer) {
      clearTimeout(
        state.statusTimer
      );
    }

    state.statusTimer =
      setTimeout(
        () => {
          refreshMeasurementStatus(
            true
          );
        },
        pollIntervalMs
      );
  }

  // ============================================================
  // START MEASUREMENT
  // ============================================================

  async function startMeasurementFlow() {
    const child =
      getSelectedChild();

    if (!child) {
      // No child selected yet — go to child lookup
      setStep("child-lookup");

      pushFeed(
        "Child lookup",
        "Enter Child ID to find the record."
      );

      return false;
    }

    if (
      state.startRequestInProgress
    ) {
      return false;
    }

    if (
      isMeasurementActive()
    ) {
      pushFeed(
        "Start blocked",
        "A measurement is already active.",
        "warn"
      );

      return false;
    }

    if (
      state.processingStarted
    ) {
      pushFeed(
        "Start blocked",
        "The current measurement is still being processed.",
        "warn"
      );

      return false;
    }

    if (
      state.deviceStatusChecked &&
      !state.deviceOnline
    ) {
      // Device is offline — show a clear warning but still navigate
      // to the measurement screen so the operator can see the layout
      // and try reconnecting the device. The PROSESO button will stay
      // disabled because markMeasurementReady() never fires without
      // live data, so no measurement can be submitted.
      pushFeed(
        "Device offline",
        "Hindi makakonekta sa device. I-check ang ESP32 at subukan muli.",
        "warn"
      );
    }

    state.startRequestInProgress =
      true;

    state.submitting =
      true;

    syncStartButtonState();

    try {
      const endpoint =
        data?.endpoints
          ?.startMeasurement ||
        "../api/kiosk/start_measurement.php";

      const response =
        await fetch(
          endpoint,
          {
            method: "POST",

            headers: {
              "Content-Type":
                "application/json",

              Accept:
                "application/json"
            },

            body: JSON.stringify({
              device_id:
                deviceId,

              child_id:
                child.id,

              location:
                "Kiosk"
            }),

            cache: "no-store"
          }
        );

      const json =
        await response
          .json()
          .catch(() => ({}));

      if (
        !response.ok ||
        json?.success !== true
      ) {
        throw new Error(
          json?.message ||
            "Could not start measurement."
        );
      }

      const payload =
        json?.data || {};

      const newSessionId =
        Number(
          payload.session_id ||
          payload.id ||
          0
        );

      if (
        newSessionId <= 0
      ) {
        throw new Error(
          "Server did not return a valid measurement session ID."
        );
      }

      // ========================================================
      // RESET MEASUREMENT VALUES
      // ========================================================

      state.session =
        payload;

      state.firebaseSessionId =
        newSessionId;

      state.awaitingLiveResult =
        true;

      state.lastFirebaseTimestamp =
        "";

      state.lastFirebaseSignature =
        "";

      state.submitting =
        false;

      state.processingStarted =
        false;

      state.measurementReady =
        false;

      state.weight =
        null;

      state.height =
        null;

      state.weightLocked =
        false;

      state.heightLocked =
        false;

      state.lastWeightRaw =
        null;

      state.lastHeightRaw =
        null;

      state.weightStableCount =
        0;

      state.heightStableCount =
        0;

      state.phase =
        "live";

      state.restoredSession =
        false;

      hideProcessingError();

      /*
       * Hand chip updates over to Firebase polling for the
       * live screen; stop the pre-start heartbeat check.
       */

      stopDeviceStatusPolling();

      updateSessionInfo(
        payload
      );

      saveSessionToStorage();

      syncStartButtonState();

      // ========================================================
      // HEIGHT SCREEN
      // ========================================================

      setStep("measurement");

      setProgress(
        10,
        "Tumayo sa platform ngayon."
      );

      if (refs.weightReadout) {
        refs.weightReadout.textContent =
          "--.--";
      }

      if (refs.heightReadout) {
        refs.heightReadout.textContent =
          "--.-";
      }

      if (refs.weightStatus) {
        refs.weightStatus.textContent =
          "Tumayo sa platform...";
      }

      if (refs.heightStatus) {
        refs.heightStatus.textContent =
          "Tumayo sa platform...";
      }

      if (refs.weightBars) {
        refs.weightBars.innerHTML =
          "";
        lastWeightBarsValue = -1;
      }

      if (refs.heightBar) {
        refs.heightBar.style.width =
          "0%";
      }

      setChip(
        connectedChip,
        "Device: Waiting",
        false
      );

      setChip(
        loadCellChip,
        "Scale: Waiting",
        false
      );

      setChip(
        lidarChip,
        "LiDAR: Waiting",
        false
      );

      pushFeed(
        "Measurement started",
        `${
          child.child_code || "Child"
        } · Session #${newSessionId} · waiting for ESP32`
      );

      startFirebasePolling();

      await refreshMeasurementStatus(
        true
      );

      return true;
    } catch (error) {
      console.error(
        "[SukatKalusugan] Start measurement failed",
        error
      );

      state.submitting =
        false;

      state.startRequestInProgress =
        false;

      state.awaitingLiveResult =
        false;

      syncStartButtonState();

      pushFeed(
        "Start failed",
        error.message ||
          "Unable to contact server.",
        "error"
      );

      // Only bounce back to child-lookup if the device was online
      // (so the failure was unexpected). If the device was offline,
      // stay on the measurement screen so the operator can see the
      // device-offline state and try reconnecting.
      if (
        !state.deviceStatusChecked ||
        state.deviceOnline
      ) {
        setStep("child-lookup");
      }

      return false;
    } finally {
      state.startRequestInProgress =
        false;

      state.submitting =
        false;

      syncStartButtonState();
    }
  }

  // ============================================================
  // PROCESS MEASUREMENT
  // ============================================================

  async function processMeasurement() {
    if (
      state.processingStarted
    ) {
      return;
    }

    if (!state.child) {
      pushFeed(
        "Processing blocked",
        "No child selected.",
        "warn"
      );

      return;
    }

    if (
      !state.finalReady ||
      state.finalSequence <= 0 ||
      !isValidWeight(state.finalWeight) ||
      !isValidHeight(state.finalHeight)
    ) {
      // Dual-mode: allow processing with one sensor if other is manual
      const hasHeightManual = state.autoHeight && !state.autoWeight && state.manualWeightInput != null;
      const hasWeightManual = state.autoWeight && !state.autoHeight && state.manualHeightInput != null;
      const hasOneLive = (isValidHeight(state.finalHeight) || isValidWeight(state.finalWeight));
      
      if (!state.autoHeight && isValidHeight(state.finalHeight) && state.manualWeightInput != null) {
        // Height from ESP32, weight entered manually — allow
      } else if (!state.autoWeight && isValidWeight(state.finalWeight) && state.manualHeightInput != null) {
        // Weight from ESP32, height entered manually — allow
      } else if (hasOneLive && (state.manualWeightInput != null || state.manualHeightInput != null)) {
        // At least one live + at least one manual entry — allow
      } else {
        pushFeed(
          "Processing blocked",
          "Weight and height are not ready.",
          "warn"
        );
        return;
      }
    }

    if (!state.session) {
      pushFeed(
        "Processing blocked",
        "No active measurement session.",
        "warn"
      );

      return;
    }

    const sessionId =
      getCurrentSessionId();

    if (!sessionId) {
      pushFeed(
        "Processing blocked",
        "The measurement session ID is missing.",
        "error"
      );

      return;
    }

    /*
     * ========================================================
     * TELL THE ESP32 TO FINALIZE
     * ========================================================
     *
     * The ESP32 sits in a live-sampling loop and polls
     * get_command.php roughly every 1.5s (SESSION_VALIDATE_
     * INTERVAL) waiting to see command === "PROCESS" for this
     * session. It will NOT compute or submit a final
     * measurement until it sees that. This call is what flips
     * measurement_sessions.command to 'PROCESS' in SQL so the
     * ESP32's next poll picks it up.
     *
     * Do this BEFORE touching any local "processing" UI state,
     * so a failed request leaves the live screen exactly as it
     * was and the operator can just try again.
     */

    let processRequestOk = false;

    try {
      const endpoint =
        data?.endpoints
          ?.requestProcess ||
        "../api/kiosk/request_process.php";

      const response =
        await fetch(
          endpoint,
          {
            method: "POST",

            headers: {
              "Content-Type":
                "application/json",

              Accept:
                "application/json"
            },

            body: JSON.stringify({
              device_id:
                deviceId,

              session_id:
                sessionId,

              final_sequence:
                state.finalSequence,

              final_weight_kg:
                state.finalWeight,

              final_height_cm:
                state.finalHeight
            }),

            cache: "no-store"
          }
        );

      const json =
        await response
          .json()
          .catch(() => ({}));

      processRequestOk =
        response.ok &&
        json?.success === true;

      if (!processRequestOk) {
        pushFeed(
          "Processing blocked",
          json?.message ||
            "Could not request processing from the device.",
          "error"
        );
      }
    } catch (error) {
      console.error(
        "[SukatKalusugan] Request process failed",
        error
      );

      pushFeed(
        "Processing blocked",
        "Could not reach the server to request processing.",
        "error"
      );
    }

    if (!processRequestOk) {
      return;
    }

    /*
     * This is the ONLY place processing starts.
     */

    state.processingStarted =
      true;

    state.phase =
      "processing";

    state.awaitingLiveResult =
      false;

    updateProcessButton();

    /*
     * Clear any "Measurement failed" banner left over from a
     * previous failed attempt on this same session before
     * showing Step 3 again. Without this, retrying after a
     * failure re-enters Processing with the old error banner
     * still on screen even though this new attempt hasn't
     * failed (yet).
     */

    hideProcessingError();

    setStep("processing");

    setProgress(
      65,
      "Hinihintay mag-send ng sukat yung device..."
    );

    pushFeed(
      "Processing started",
      `${
        state.child.child_code ||
        "Child"
      } · Session #${sessionId} · waiting for ESP32 to finalize`
    );

    if (state.processingTimer) {
      clearInterval(state.processingTimer);
    }

    // Smooth indeterminate progress while we wait for the real backend
    // result. Starts at 65% and creeps toward 92% over ~5 seconds, then
    // holds at 92% with a "pinagproseso pa..." message until the backend
    // (refreshMeasurementStatus) reports COMPLETE or ERROR.
    const smoothStartPct = 65;
    const smoothEndPct = 92;
    const smoothDurationMs = 5000;
    const smoothStepMs = 100;
    const smoothStepPct =
      ((smoothEndPct - smoothStartPct) /
        (smoothDurationMs / smoothStepMs));
    let currentPct = smoothStartPct;
    const startedAt = Date.now();

    state.processingTimer = setInterval(async () => {
      const elapsed = Date.now() - startedAt;

      if (elapsed < smoothDurationMs) {
        // Smoothly interpolate progress.
        currentPct = Math.min(
          smoothEndPct,
          smoothStartPct + smoothStepPct * (elapsed / smoothStepMs)
        );
        setProgress(
          Math.round(currentPct),
          elapsed < smoothDurationMs / 2
            ? "Hinihintay mag-send ng sukat yung device..."
            : "Kumukuha ng data para sa mga growth indicators..."
        );
        return;
      }

      // Reached the cap — show "almost done" and let the backend
      // polling take over.
      clearInterval(state.processingTimer);
      state.processingTimer = null;

      setProgress(
        94,
        "Fina-finalize na yung sukat, wait lang..."
      );

      const status = await refreshMeasurementStatus(false);

      if (
        status &&
        normalizeStatus(status.status) === "COMPLETE"
      ) {
        finishResults(
          status,
          state.weight,
          state.height
        );

        return;
      }

      await waitForBackendCompletion();
    }, smoothStepMs);
  }

  // ============================================================
  // BACKEND COMPLETION
  // ============================================================

  async function waitForBackendCompletion() {
    let attempts = 0;

    /*
     * After Process is clicked, a full round trip can legitimately
     * take a while: the ESP32 has to notice the PROCESS command on
     * its next poll (up to ~1.5s), re-validate the session with one
     * more HTTP call to get_command.php (which itself makes a
     * server-side Firebase request with up to a 7s worst-case
     * timeout), then POST the final result to submit_measurement.php
     * (another server-side Firebase call, same worst-case 7s
     * timeout). 30 attempts at 1s each (30s total) was tight enough
     * that ordinary WiFi hiccups made this give up and show
     * "Measurement failed" while the device was still genuinely
     * working and about to succeed. 90 attempts (90s) gives real
     * headroom for that full chain, including a retry or two.
     */

    const maxAttempts = 90;

    const check = async () => {
      if (
        !state.processingStarted
      ) {
        return;
      }

      attempts++;

      const status =
        await refreshMeasurementStatus(
          false
        );

      if (
        status &&
        normalizeStatus(
          status.status
        ) === "COMPLETE"
      ) {
        finishResults(
          status,
          state.weight,
          state.height
        );

        return;
      }

      if (
        status &&
        [
          "ERROR",
          "CANCELLED"
        ].includes(
          normalizeStatus(
            status.status
          )
        )
      ) {
        processingFailed(
          status.error_message ||
            "Measurement processing failed."
        );

        return;
      }

      if (
        attempts >=
        maxAttempts
      ) {
        processingFailed(
          "The server did not confirm the completed measurement."
        );

        return;
      }

      setProgress(
        Math.min(
          98,
          94 +
            attempts * 0.05
        ),
        "Waiting for SQL to confirm the measurement..."
      );

      state.backendCompletionTimer =
        setTimeout(
          check,
          500
        );
    };

    check();
  }

  // ============================================================
  // PROCESSING FAILED
  // ============================================================

  function processingFailed(
    message
  ) {
    if (
      state.processingTimer
    ) {
      clearInterval(
        state.processingTimer
      );

      state.processingTimer =
        null;
    }

    if (
      state.backendCompletionTimer
    ) {
      clearTimeout(
        state.backendCompletionTimer
      );

      state.backendCompletionTimer =
        null;
    }

    state.processingStarted =
      false;

    state.phase =
      "error";

    updateProcessButton();

    showProcessingError(
      message
    );

    setProgress(
      100,
      message
    );

    pushFeed(
      "Processing failed",
      message,
      "error"
    );

    saveSessionToStorage();
  }

  // ============================================================
  // RESULTS
  // ============================================================

  /*
   * Maps the ENUM values in measurements.nutritional_status (see
   * who_calculator.php::classify_nutritional_status) to a status-pill
   * color so the results screen reads at a glance instead of everyone
   * getting the same green pill regardless of severity.
   */
  const STATUS_PILL_CLASSES = [
    "is-success",
    "is-warn",
    "is-orange",
    "is-danger",
    "is-muted"
  ];

  function nutritionalStatusPillClass(status) {
    const normalized = String(
      status || ""
    ).trim().toLowerCase();

    // Green (success)
    if (normalized === "normal" || normalized === "tall") {
      return "is-success";
    }

    // Yellow (warn) — moderate categories
    if (
      normalized === "moderately underweight" ||
      normalized === "moderately stunted" ||
      normalized === "moderately wasted" ||
      normalized === "muw" ||
      normalized === "mst" ||
      normalized === "mw"
    ) {
      return "is-warn";
    }

    // Orange — overweight/obese
    if (
      normalized === "overweight" ||
      normalized === "obese" ||
      normalized === "ow" ||
      normalized === "ob"
    ) {
      return "is-orange";
    }

    // Red (danger) — severe categories
    if (
      normalized === "severely underweight" ||
      normalized === "severely stunted" ||
      normalized === "severely wasted" ||
      normalized === "suw" ||
      normalized === "sst" ||
      normalized === "sw"
    ) {
      return "is-danger";
    }

    // Muted — pending
    if (normalized === "pending" || normalized === "") {
      return "is-muted";
    }

    return "is-muted";
  }

  function nutritionalStatusBadgeClass(status) {
    const normalized = String(
      status || ""
    ).trim().toLowerCase();

    if (normalized === "normal" || normalized === "tall") {
      return "is-normal";
    }

    if (
      normalized === "moderately underweight" ||
      normalized === "moderately stunted" ||
      normalized === "moderately wasted" ||
      normalized === "muw" || normalized === "mst" || normalized === "mw"
    ) {
      return "is-underweight";
    }

    if (
      normalized === "overweight" || normalized === "obese" ||
      normalized === "ow" || normalized === "ob"
    ) {
      return "is-overweight";
    }

    if (
      normalized === "severely underweight" ||
      normalized === "severely stunted" ||
      normalized === "severely wasted" ||
      normalized === "suw" || normalized === "sst" || normalized === "sw"
    ) {
      return "is-severe";
    }

    return "is-normal";
  }

  function applyStatusPillClass(status) {
    if (!refs.resultStatus) {
      return;
    }

    STATUS_PILL_CLASSES.forEach(
      className => {
        refs.resultStatus.classList.remove(
          className
        );
      }
    );

    const pillClass =
      nutritionalStatusPillClass(
        status
      );

    if (pillClass) {
      refs.resultStatus.classList.add(
        pillClass
      );
    }
  }

  function applyResultFlag(
    isFlagged,
    reason
  ) {
    if (!refs.resultFlag) {
      return;
    }

    refs.resultFlag.hidden =
      !isFlagged;

    if (
      isFlagged &&
      refs.resultFlagReason
    ) {
      refs.resultFlagReason.textContent =
        reason ||
        "One or more readings look unusual for this child. Please double-check height and weight.";
    }
  }

function finishResults(
  payload,
  weight,
  height
) {
  const child =
    getSelectedChild();

  /*
   * The backend returns WHO indicators inside
   * payload.measurement.
   *
   * Keep a fallback to payload so this also works
   * if the API is changed later to return them
   * at the top level.
   */
  const measurement =
    payload?.measurement || payload || {};

  const nutritionalStatus =
    measurement.nutritional_status ||
    payload?.nutritional_status ||
    "Pending";

  state.nutritionalStatus =
    nutritionalStatus;

  state.phase =
    "results";

  state.processingStarted =
    true;

  // ==========================================================
  // CHILD
  // ==========================================================

  if (refs.resultChild) {
    refs.resultChild.textContent =
      payload.child_name ||
      measurement.child_name ||
      formatChildName(child);
  }

  // ==========================================================
  // AGE
  // ==========================================================

  if (refs.resultMeta) {
    // The kiosk child list now ships both the canonical day count
    // and a whole-number month estimate. Day count is what the WHO
    // calculator uses internally; the month estimate (intdiv(days,
    // 30)) is for the "X mo" label so the operator can match it
    // against the eOPT Plus worksheet.
    const ageDays =
      measurement.age_days ??
      payload.age_days ??
      child?.age_days ??
      0;

    const ageMonths =
      measurement.age_months ??
      payload.age_months ??
      child?.age_months ??
      Math.floor(ageDays / 30);

    refs.resultMeta.textContent =
      ageMonths >= 12
        ? `${Math.floor(ageMonths / 12)} taon, ${ageMonths % 12} buwan (${ageDays} araw)`
        : `${ageMonths} buwan (${ageDays} araw)`;
  }

  // ==========================================================
  // HEIGHT
  // ==========================================================

  const resultHeight =
    measurement.height_cm ??
    payload.height_cm ??
    height;

  if (refs.resultHeight) {
    refs.resultHeight.textContent =
      isValidHeight(resultHeight)
        ? `${Number(resultHeight).toFixed(1)} cm`
        : "--.- cm";
  }

  // ==========================================================
  // WEIGHT
  // ==========================================================

  const resultWeight =
    measurement.weight_kg ??
    payload.weight_kg ??
    weight;

  if (refs.resultWeight) {
    refs.resultWeight.textContent =
      isValidWeight(resultWeight)
        ? `${Number(resultWeight).toFixed(2)} kg`
        : "--.-- kg";
  }

  // ==========================================================
  // NUTRITIONAL STATUS
  // ==========================================================

  if (refs.resultStatus) {
    refs.resultStatus.textContent =
      nutritionalStatus;
  }

  applyStatusPillClass(
    nutritionalStatus
  );

  // ==========================================================
  // FLAG / NEEDS REVIEW
  // ==========================================================

  const isFlagged = Boolean(
    measurement.is_flagged ??
    payload?.is_flagged ??
    false
  );

  const flagReason =
    measurement.flag_reason ??
    payload?.flag_reason ??
    null;

  applyResultFlag(
    isFlagged,
    flagReason
  );

  // ==========================================================
  // WAZ — WEIGHT FOR AGE
  // ==========================================================

  const waz =
    measurement.waz ??
    payload.waz ??
    null;

  if (refs.resultWaz) {
    refs.resultWaz.textContent =
      waz !== null &&
      waz !== undefined &&
      Number.isFinite(Number(waz))
        ? Number(waz).toFixed(2)
        : "--";
  }

  // ==========================================================
  // HAZ — HEIGHT FOR AGE
  // ==========================================================

  const haz =
    measurement.haz ??
    payload.haz ??
    null;

  if (refs.resultHaz) {
    refs.resultHaz.textContent =
      haz !== null &&
      haz !== undefined &&
      Number.isFinite(Number(haz))
        ? Number(haz).toFixed(2)
        : "--";
  }

  // ==========================================================
  // WHZ — WEIGHT FOR HEIGHT
  // ==========================================================

  const whz =
    measurement.whz ??
    payload.whz ??
    null;

  if (refs.resultWhz) {
    refs.resultWhz.textContent =
      whz !== null &&
      whz !== undefined &&
      Number.isFinite(Number(whz))
        ? Number(whz).toFixed(2)
        : "--";
  }

  // ==========================================================
  // SOURCE
  // ==========================================================

  if (refs.resultSource) {
    refs.resultSource.textContent =
      "ESP32 → Firebase → SQL → WHO";
  }

  // ==========================================================
  // SEX
  // ==========================================================

  if (refs.resultSex) {
    refs.resultSex.textContent =
      child?.sex || measurement?.sex || "—";
  }

  // ==========================================================
  // DATE / TIME
  // ==========================================================

  const now = new Date();
  if (refs.resultDate) {
    refs.resultDate.textContent =
      new Intl.DateTimeFormat("en-PH", {
        month: "long",
        day: "numeric",
        year: "numeric",
      }).format(now);
  }
  if (refs.resultTime) {
    refs.resultTime.textContent =
      new Intl.DateTimeFormat("en-PH", {
        hour: "2-digit",
        minute: "2-digit",
        hour12: true,
      }).format(now);
  }

  // ==========================================================
  // INITIALS
  // ==========================================================

  if (refs.resultInitials) {
    const init = (
      (child?.first_name?.[0] || "") +
      (child?.last_name?.[0] || "")
    ).toUpperCase();
    refs.resultInitials.textContent = init || "—";
  }

  // ==========================================================
  // NUTRITIONAL STATUS AXES (WFA, HFA, WFL/H)
  // ==========================================================

  const wfaStatus =
    measurement?.wfa_status ||
    measurement?.nutritional_status ||
    "—";

  const hfaStatus =
    measurement?.hfa_status ||
    "—";

  const wflhStatus =
    measurement?.wflh_status ||
    measurement?.nutritional_status ||
    "—";

  if (refs.resultWfaStatus) {
    refs.resultWfaStatus.textContent = wfaStatus;
    refs.resultWfaStatus.className =
      "kiosk-status-badge " + nutritionalStatusBadgeClass(wfaStatus);
  }

  if (refs.resultHfaStatus) {
    refs.resultHfaStatus.textContent = hfaStatus;
    refs.resultHfaStatus.className =
      "kiosk-status-badge " + nutritionalStatusBadgeClass(hfaStatus);
  }

  if (refs.resultWflhStatus) {
    refs.resultWflhStatus.textContent = wflhStatus;
    refs.resultWflhStatus.className =
      "kiosk-status-badge " + nutritionalStatusBadgeClass(wflhStatus);
  }

  // ==========================================================
  // COMPLETE UI
  // ==========================================================

  setProgress(
    100,
    "Measurement complete"
  );

  setStep("results");

  pushFeed(
    "Measurement complete",
    `${
      payload.child_code ||
      child?.child_code ||
      "Child"
    } · ${
      isValidWeight(resultWeight)
        ? Number(resultWeight).toFixed(2)
        : "--"
    } kg · ${
      isValidHeight(resultHeight)
        ? Number(resultHeight).toFixed(1)
        : "--"
    } cm`
  );

  stopFirebasePolling();
  disconnectWebSocket();

  if (state.statusTimer) {
    clearTimeout(
      state.statusTimer
    );

    state.statusTimer =
      null;
  }

  if (state.backendCompletionTimer) {
    clearTimeout(
      state.backendCompletionTimer
    );

    state.backendCompletionTimer =
      null;
  }

  /*
   * Only clear the browser session AFTER
   * results are successfully displayed.
   */
  clearSessionStorage();
}

  // ============================================================
  // RESTORE ACTIVE SESSION
  // ============================================================

  async function restoreActiveSession() {
    const saved =
      loadSessionFromStorage();

    if (!saved) {
      return false;
    }

    const savedSession =
      saved.session;

    if (!savedSession) {
      clearSessionStorage();

      return false;
    }

    const savedSessionId =
      Number(
        savedSession.session_id ||
        savedSession.id ||
        saved.firebaseSessionId ||
        0
      );

    if (
      savedSessionId <= 0
    ) {
      clearSessionStorage();

      return false;
    }

    const savedStatus =
      normalizeStatus(
        savedSession.status
      );

    if (
      [
        "COMPLETE",
        "ERROR",
        "CANCELLED"
      ].includes(
        savedStatus
      )
    ) {
      clearSessionStorage();

      return false;
    }

    const child =
      restoreChild(
        saved.childId
      );

    if (!child) {
      clearSessionStorage();

      return false;
    }

    // ==========================================================
    // RESTORE EXACT SESSION
    // ==========================================================

    state.child =
      child;

    state.session =
      savedSession;

    state.firebaseSessionId =
      savedSessionId;

    state.weight =
      isValidWeight(
        saved.weight
      )
        ? Number(saved.weight)
        : null;

    state.height =
      isValidHeight(
        saved.height
      )
        ? Number(saved.height)
        : null;

    state.weightLocked =
      Boolean(
        saved.weightLocked
      );

    state.heightLocked =
      Boolean(
        saved.heightLocked
      );

    state.measurementReady =
      Boolean(
        saved.measurementReady
      );

    state.processingStarted =
      false;

    state.phase =
      "live";

    state.awaitingLiveResult =
      true;

    state.restoredSession =
      true;

    state.lastFirebaseSignature =
      "";

    // ==========================================================
    // CHILD UI
    // ==========================================================

    childCards.forEach(card => {
      card.classList.toggle(
        "is-selected",
        String(
          card.dataset.childId
        ) ===
          String(child.id)
      );
    });

    if (refs.currentChildLabel) {
      refs.currentChildLabel.textContent =
        formatChildName(child);
    }

    // Was unconditionally `false` here — a restored session would
    // re-enable "Proceed to Live" even if the device is offline right
    // now. Route through the shared sync so device status still gates it.
    syncStartButtonState();

    // ==========================================================
    // SENSOR UI
    // ==========================================================

    if (
      isValidWeight(
        state.weight
      )
    ) {
      setWeight(
        state.weight,
        state.weightLocked
          ? "Weight captured"
          : "Weight restored"
      );
    }

    if (
      isValidHeight(
        state.height
      )
    ) {
      setHeight(
        state.height,
        state.heightLocked
          ? "Height captured"
          : "Height restored"
      );
    }

    updateSessionInfo(
      state.session
    );

    // ==========================================================
    // ALWAYS RETURN TO HEIGHT MEASUREMENT
    // ==========================================================

    setStep("measurement");

    if (
      isValidWeight(
        state.weight
      ) &&
      isValidHeight(
        state.height
      ) &&
      state.weightLocked &&
      state.heightLocked
    ) {
      state.measurementReady =
        true;

      markMeasurementReady();
    } else {
      setProgress(
        20,
        "Restored active measurement session. Waiting for sensors..."
      );
    }

    pushFeed(
      "Session restored",
      `${
        child.child_code ||
        "Child"
      } · Session #${savedSessionId}`
    );

    startFirebasePolling();

    // ==========================================================
    // VERIFY EXACT SQL SESSION
    // ==========================================================

    const latest =
      await refreshMeasurementStatus(
        false
      );

    if (!latest) {
      /*
       * Keep the restored session.
       * Firebase may still be available even
       * if PHP temporarily fails.
       */

      scheduleStatusRefresh();

      return true;
    }

    const latestStatus =
      normalizeStatus(
        latest.status
      );

    if (
      latestStatus ===
      "COMPLETE"
    ) {
      if (
        isValidWeight(
          state.weight
        ) &&
        isValidHeight(
          state.height
        )
      ) {
        state.measurementReady =
          true;

        setStep("measurement");

        markMeasurementReady();
      }
    } else if (
      isMeasurementActive(
        latest
      )
    ) {
      scheduleStatusRefresh();
    }

    syncStartButtonState();

    return true;
  }

  // ============================================================
  // RESET
  // ============================================================

  /*
   * Shared by resetKioskToIdle() (back to welcome, full reset) and the
   * "Back" button on the Live screen (back to select, child deselected).
   * Both need the same teardown — clear the pending session, sensor
   * readouts, and the selected child — they only differ in which step
   * they land on and what they tell the operator afterward.
   */
  function clearSelectionAndSession(
    targetStep,
    feedTitle,
    feedMessage
  ) {
    if (state.statusTimer) {
      clearTimeout(
        state.statusTimer
      );
    }

    if (state.processingTimer) {
      clearInterval(
        state.processingTimer
      );
    }

    if (
      state.backendCompletionTimer
    ) {
      clearTimeout(
        state.backendCompletionTimer
      );
    }

    stopFirebasePolling();
    disconnectWebSocket();

    startDeviceStatusPolling();

    state.statusTimer =
      null;

    state.processingTimer =
      null;

    state.backendCompletionTimer =
      null;

    state.session =
      null;

    state.phase =
      "idle";

    state.submitting =
      false;

    state.startRequestInProgress =
      false;

    state.awaitingLiveResult =
      false;

    state.firebaseSessionId =
      null;

    state.lastFirebaseTimestamp =
      "";

    state.lastFirebaseSignature =
      "";

    state.weight =
      null;

    state.height =
      null;

    state.nutritionalStatus =
      null;

    state.child =
      null;

    state.weightLocked =
      false;

    state.heightLocked =
      false;

    state.lastWeightRaw =
      null;

    state.lastHeightRaw =
      null;

    state.weightStableCount =
      0;

    state.heightStableCount =
      0;

    state.measurementReady =
      false;

    state.processingStarted =
      false;

    state.restoredSession =
      false;

    clearSessionStorage();

    childCards.forEach(card => {
      card.classList.remove(
        "is-selected"
      );
    });

    if (refs.currentChildLabel) {
      refs.currentChildLabel.textContent =
        "Choose a child";
    }

    if (searchInput) {
      searchInput.value = "";
    }

    childCards.forEach(card => {
      card.hidden = false;
    });

    if (refs.weightReadout) {
      refs.weightReadout.textContent =
        "--.--";
    }

    if (refs.weightStatus) {
      refs.weightStatus.textContent =
        "Naghihintay ng timbang...";
    }

    if (refs.heightReadout) {
      refs.heightReadout.textContent =
        "--.-";
    }

    if (refs.heightStatus) {
      refs.heightStatus.textContent =
        "Naghihintay ng taas...";
    }

    if (refs.heightBar) {
      refs.heightBar.style.width =
        "0%";
    }

    if (refs.weightBars) {
      refs.weightBars.innerHTML =
        "";
      lastWeightBarsValue = -1;
    }

    if (refs.resultChild) {
      refs.resultChild.textContent =
        "Name";
    }

    if (refs.resultMeta) {
      refs.resultMeta.textContent =
        "-- months old";
    }

    if (refs.resultHeight) {
      refs.resultHeight.textContent =
        "--.- cm";
    }

    if (refs.resultWeight) {
      refs.resultWeight.textContent =
        "--.-- kg";
    }

    if (refs.resultWaz) {
      refs.resultWaz.textContent =
        "--";
    }

    if (refs.resultHaz) {
      refs.resultHaz.textContent =
        "--";
    }

    if (refs.resultWhz) {
      refs.resultWhz.textContent =
        "--";
    }

    if (refs.resultStatus) {
      refs.resultStatus.textContent =
        "Pending";
    }

    applyStatusPillClass("");
    applyResultFlag(false, null);

    if (refs.resultSource) {
      refs.resultSource.textContent =
        firebaseEnabled
          ? "ESP32 → Firebase → SQL"
          : "Firebase unavailable";
    }

    setProgress(
      0,
      "Handa nang sumukat"
    );

    setChip(
      lidarChip,
      "LiDAR: Waiting",
      false
    );

    setChip(
      loadCellChip,
      "Scale: Waiting",
      false
    );

    setChip(
      connectedChip,
      firebaseEnabled
        ? "Device: Waiting"
        : "Device: Firebase Off",
      false
    );

    updateSessionInfo(null);

    setStep(targetStep);

    if (heroNote) {
      heroNote.textContent =
        "Pumili ng bata, pagkatapos ay simulan ang pagsukat.";
    }

    syncStartButtonState();

    if (feedTitle) {
      pushFeed(
        feedTitle,
        feedMessage
      );
    }
  }

  function resetKioskToIdle() {
    if (
      state.processingStarted &&
      state.step === "processing" &&
      state.phase !== "error"
    ) {
      pushFeed(
        "Reset blocked",
        "Wait for measurement processing to finish.",
        "warn"
      );

      return;
    }

    if (
      state.session &&
      state.session.session_id
    ) {
      if (
        !confirm(
          "A measurement is active. Going back will cancel the current session. Continue?"
        )
      ) {
        return;
      }

      const cancelBody =
        JSON.stringify({
          device_id: deviceId,
          session_id:
            state.session.session_id,
        });

      fetch(
        "../api/kiosk/cancel_session.php",
        {
          method: "POST",
          headers: {
            "Content-Type":
              "application/json",
          },
          body: cancelBody,
        }
      ).catch(function () {});
    }

    clearSelectionAndSession(
      "welcome",
      "Kiosk reset",
      "Ready for the next child."
    );
  }

  // ============================================================
  // FIREBASE CONNECTION CHECK
  // ============================================================

  async function checkFirebaseConnection() {
    if (!firebaseEnabled) {
      setChip(
        connectedChip,
        "Device: Firebase Off",
        false
      );

      return false;
    }

    const url =
      firebaseLatestMeasurementUrl();

    if (!url) {
      return false;
    }

    try {
      const response =
        await fetch(
          url,
          {
            cache: "no-store"
          }
        );

      if (response.ok) {
        state.firebaseOnline =
          true;

        setChip(
          connectedChip,
          "Device: Firebase Ready",
          true
        );

        return true;
      }

      setChip(
        connectedChip,
        "Device: Firebase Error",
        false
      );

      return false;
    } catch (error) {
      console.warn(
        "[SukatKalusugan] Firebase unavailable",
        error
      );

      state.firebaseOnline =
        false;

      setChip(
        connectedChip,
        "Device: Offline",
        false
      );

      return false;
    }
  }

  // ============================================================
  // DEVICE STATUS (PRE-MEASUREMENT HEARTBEAT)
  // ============================================================
  //
  // Separate from checkFirebaseConnection() above: that only
  // confirms Firebase itself is reachable. This confirms the
  // physical ESP32 kiosk device has actually checked in recently
  // (devices.last_seen_at), so we don't let a parent tap
  // "Start Measurement" against a powered-off or disconnected
  // device.

  async function refreshDeviceStatus() {
    if (state.deviceStatusRequestInProgress) {
      return state.deviceOnline;
    }

    state.deviceStatusRequestInProgress =
      true;

    try {
      const endpoint =
        data?.endpoints?.ping ||
        "../api/esp32/device_ping.php";

      const url =
        new URL(
          endpoint,
          window.location.href
        );

      url.searchParams.set(
        "device",
        deviceId
      );

      const response =
        await fetch(
          url.toString(),
          {
            cache: "no-store",
            headers: {
              Accept:
                "application/json"
            }
          }
        );

      const json =
        await response
          .json()
          .catch(() => ({}));

      const payload =
        json?.data || {};

      const online = Boolean(
        response.ok &&
        json?.success === true &&
        payload.connected
      );

      const wasChecked =
        state.deviceStatusChecked;

      const wasOnline =
        state.deviceOnline;

      state.deviceOnline =
        online;

      state.deviceStatusChecked =
        true;

      var welcomeDot = document.querySelector("[data-kiosk-device-status] .kiosk-device-dot");
      var welcomeLabel = document.querySelector("[data-kiosk-device-status] .kiosk-device-label");
      if (welcomeDot) {
        welcomeDot.classList.toggle("online", online);
      }
      if (welcomeLabel) {
        welcomeLabel.textContent = online ? "Device online" : "Device offline";
      }

      setChip(
        connectedChip,
        online
          ? "Device: Online"
          : "Device: Offline",
        online
      );

      setChip(
        lidarChip,
        payload.lidar_status ===
          "ready"
          ? "LiDAR: Ready"
          : "LiDAR: Waiting",
        payload.lidar_status ===
          "ready"
      );

      setChip(
        loadCellChip,
        payload.loadcell_status ===
          "ready"
          ? "Scale: Ready"
          : "Scale: Waiting",
        payload.loadcell_status ===
          "ready"
      );

      if (heroNote && state.step === "welcome") {
        heroNote.textContent =
          online
            ? "Select a child, then start the measurement."
            : (
                payload.message ||
                "Kiosk device is offline. Check the ESP32 power and Wi-Fi connection."
              );
      }

      /*
       * Only announce the transition, not every poll,
       * so the activity feed doesn't get spammed every
       * few seconds.
       */

      if (
        (!wasChecked || wasOnline) &&
        !online
      ) {
        pushFeed(
          "Device offline",
          payload.message ||
            "The kiosk device stopped responding.",
          "error"
        );
      } else if (
        wasChecked &&
        !wasOnline &&
        online
      ) {
        pushFeed(
          "Device online",
          "The kiosk device is connected and ready.",
          "info"
        );
      }

      syncStartButtonState();

      return online;
    } catch (error) {
      console.warn(
        "[SukatKalusugan] Device status check failed",
        error
      );

      const wasOnline =
        state.deviceOnline;

      state.deviceOnline =
        false;

      state.deviceStatusChecked =
        true;

      var welcomeDot = document.querySelector("[data-kiosk-device-status] .kiosk-device-dot");
      var welcomeLabel = document.querySelector("[data-kiosk-device-status] .kiosk-device-label");
      if (welcomeDot) {
        welcomeDot.classList.remove("online");
      }
      if (welcomeLabel) {
        welcomeLabel.textContent = "Device offline";
      }

      setChip(
        connectedChip,
        "Device: Offline",
        false
      );

      if (heroNote && state.step === "welcome") {
        heroNote.textContent =
          "Kiosk device is offline. Check the ESP32 power and Wi-Fi connection.";
      }

      if (wasOnline) {
        pushFeed(
          "Device offline",
          "Lost contact with the kiosk device.",
          "error"
        );
      }

      syncStartButtonState();

      return false;
    } finally {
      state.deviceStatusRequestInProgress =
        false;
    }
  }

  function startDeviceStatusPolling() {
    stopDeviceStatusPolling();

    state.deviceStatusTimer =
      setInterval(
        () => {
          refreshDeviceStatus();
        },
        deviceStatusIntervalMs
      );

    refreshDeviceStatus();
  }

  function stopDeviceStatusPolling() {
    if (state.deviceStatusTimer) {
      clearInterval(
        state.deviceStatusTimer
      );

      state.deviceStatusTimer =
        null;
    }
  }

  // ============================================================
  // EVENTS
  // ============================================================

  function bindEvents() {
    // ----------------------------------------------------------
    // SEARCH
    // ----------------------------------------------------------

    if (searchInput) {
      searchInput.addEventListener(
        "input",
        () => {
          const term =
            searchInput.value
              .trim()
              .toLowerCase();

          childCards.forEach(card => {
            const text =
              (
                card.dataset
                  .filterText || ""
              ).toLowerCase();

            card.hidden =
              !text.includes(term);
          });
        }
      );
    }

    // ----------------------------------------------------------
    // CHILD CARDS
    // ----------------------------------------------------------

    childCards.forEach(card => {
      card.addEventListener(
        "click",
        () => {
          selectChild(
            card.dataset.childId
          );
        }
      );
    });

    // ----------------------------------------------------------
    // ACTION BUTTONS
    // ----------------------------------------------------------

    actionButtons.forEach(button => {
      button.addEventListener(
        "click",
        event => {
          const action =
            button.getAttribute(
              "data-kiosk-action"
            );

          // ====================================================
          // START
          // ====================================================

          if (
            action === "start"
          ) {
            event.preventDefault();
            setStep("privacy");
            return;
          }

          // ====================================================
          // VIEW RESULTS (from welcome)
          // ====================================================

          if (
            action === "view-results"
          ) {
            event.preventDefault();
            populateViewResults();
            setStep("view-results");
            return;
          }

          // ====================================================
          // INFO (from welcome)
          // ====================================================

          if (
            action === "info"
          ) {
            event.preventDefault();
            setStep("info");
            return;
          }

          // ====================================================
          // OPEN FULL PRIVACY NOTICE MODAL
          // ====================================================

          if (
            action === "open-full-privacy"
          ) {
            event.preventDefault();
            var modal = document.getElementById("privacyModal");
            if (modal) {
              modal.hidden = false;
            }
            return;
          }

          // ====================================================
          // CLOSE PRIVACY NOTICE MODAL
          // ====================================================

          if (
            action === "close-privacy-modal"
          ) {
            event.preventDefault();
            var modal = document.getElementById("privacyModal");
            if (modal) {
              modal.hidden = true;
            }
            return;
          }

          // ====================================================
          // OPEN INFORMATION SHEET MODAL
          // ====================================================

          if (
            action === "open-info-sheet"
          ) {
            event.preventDefault();
            var modal = document.getElementById("infoSheetModal");
            if (modal) {
              modal.hidden = false;
            }
            return;
          }

          // ====================================================
          // CLOSE INFORMATION SHEET MODAL
          // ====================================================

          if (
            action === "close-info-sheet"
          ) {
            event.preventDefault();
            var modal = document.getElementById("infoSheetModal");
            if (modal) {
              modal.hidden = true;
            }
            return;
          }

          // ====================================================
          // PRIVACY CONTINUE (go to child lookup)
          // ====================================================

          if (
            action === "privacy-continue"
          ) {
            event.preventDefault();
            setStep("child-lookup");
            return;
          }

          // ====================================================
          // BACK TO WELCOME
          // ====================================================

          if (
            action === "back-to-welcome"
          ) {
            event.preventDefault();
            setStep("welcome");
            return;
          }

          // ====================================================
          // BACK TO PRIVACY (from child-lookup)
          // ====================================================

          if (
            action === "back-to-privacy"
          ) {
            event.preventDefault();
            setStep("privacy");
            return;
          }

          // ====================================================
          // BACK TO INFO (from height)
          // ====================================================

          if (
            action === "back-to-info"
          ) {
            event.preventDefault();
            setStep("child-lookup");
            return;
          }

          // ====================================================
          // BACK TO LOOKUP (from measurement)
          // ====================================================

          if (
            action === "back-to-lookup"
          ) {
            event.preventDefault();
            setStep("child-lookup");
            return;
          }

          // ====================================================
          // LOOKUP: RETRY (try again)
          // ====================================================

          if (
            action === "lookup-retry"
          ) {
            event.preventDefault();
            resetLookup();
            return;
          }

          // ====================================================
          // LOOKUP: CONFIRM (child found, proceed)
          // ====================================================

          if (
            action === "lookup-confirm"
          ) {
            event.preventDefault();
            if (foundChild) {
              state.child = foundChild;
              pushFeed("Bata napili", foundChild.first_name + " " + foundChild.last_name);
              // Reset the readouts so they don't show stale data from a
              // previous attempt.
              if (refs.weightReadout) {
                refs.weightReadout.textContent = "--.--";
              }
              if (refs.heightReadout) {
                refs.heightReadout.textContent = "--.-";
              }
              if (refs.weightStatus) {
                refs.weightStatus.textContent = "Naghihintay...";
              }
              if (refs.heightStatus) {
                refs.heightStatus.textContent = "Naghihintay...";
              }
              if (refs.weightBars) {
                refs.weightBars.innerHTML = "";
                lastWeightBarsValue = -1;
              }
              if (refs.heightBar) {
                refs.heightBar.style.width = "0%";
              }
              // Reflect the actual device connectivity in the chips so
              // the operator immediately knows whether readings will
              // come in or not.
              if (
                state.deviceStatusChecked &&
                !state.deviceOnline
              ) {
                setChip(
                  connectedChip,
                  "Device: Offline",
                  false
                );
                setChip(
                  loadCellChip,
                  "Scale: Offline",
                  false
                );
                setChip(
                  lidarChip,
                  "LiDAR: Offline",
                  false
                );
                if (refs.weightStatus) {
                  refs.weightStatus.textContent =
                    "Device offline";
                }
                if (refs.heightStatus) {
                  refs.heightStatus.textContent =
                    "Device offline";
                }
              } else {
                setChip(
                  connectedChip,
                  "Device: Waiting",
                  false
                );
                setChip(
                  loadCellChip,
                  "Scale: Waiting",
                  false
                );
                setChip(
                  lidarChip,
                  "LiDAR: Waiting",
                  false
                );
              }
              // Always navigate to the measurement screen first so the
              // user sees the live measurement UI even if the device is
              // offline (common during development). The start flow
              // below handles the actual session creation and will
              // gracefully fall back if the device is unreachable.
              setStep("measurement");
              startMeasurementFlow();
            } else {
              pushFeed(
                "Walang napiling bata",
                "Maghanap muna ng bata bago magpatuloy.",
                "warn"
              );
            }
            return;
          }

          // ====================================================
          // LOOKUP: OPEN HELP MODAL
          // ====================================================

          if (
            action === "open-lookup-help"
          ) {
            event.preventDefault();
            var modal = document.getElementById("lookupHelpModal");
            if (modal) modal.hidden = false;
            return;
          }

          // ====================================================
          // LOOKUP: CLOSE HELP MODAL
          // ====================================================

          if (
            action === "close-lookup-help"
          ) {
            event.preventDefault();
            var modal = document.getElementById("lookupHelpModal");
            if (modal) modal.hidden = true;
            return;
          }

          // ====================================================
          // BACK TO HEIGHT (from weight)
          // ====================================================

          if (
            action === "back-to-height"
          ) {
            event.preventDefault();
            setStep("measurement");
            return;
          }

          // ====================================================
          // FINISH (from results → thankyou)
          // ====================================================

          if (
            action === "finish"
          ) {
            event.preventDefault();
            setStep("thankyou");
            return;
          }

          // ====================================================
          // PROCEED LIVE (kept for compatibility)
          // ====================================================

          if (
            action ===
            "proceed-live"
          ) {
            event.preventDefault();

            if (
              !getSelectedChild()
            ) {
              return;
            }

            startMeasurementFlow();

            return;
          }

          // ====================================================
          // BACK TO INFO (from measurement) — deselects the
          // child and clears the pending session.
          // ====================================================

          if (
            action ===
            "back-to-select"
          ) {
            event.preventDefault();

            if (
              state.processingStarted
            ) {
              pushFeed(
                "Back blocked",
                "The current measurement is being processed.",
                "warn"
              );

              return;
            }

            if (
              state.session &&
              state.session.session_id
            ) {
              if (
                !confirm(
                  "A measurement is active. Going back will cancel the current session. Continue?"
                )
              ) {
                return;
              }

              pushFeed(
                "Cancelling",
                "Ending active measurement session...",
                "info"
              );

              const cancelBody =
                JSON.stringify({
                  device_id: deviceId,
                  session_id:
                    state.session.session_id,
                });

              fetch(
                "../api/kiosk/cancel_session.php",
                {
                  method: "POST",
                  headers: {
                    "Content-Type":
                      "application/json",
                  },
                  body: cancelBody,
                }
              )
                .then(function () {
            clearSelectionAndSession(
              "child-lookup",
              "Back to lookup",
              "Session cancelled. Enter Child ID to continue."
            );
                })
                .catch(function () {
                  clearSelectionAndSession(
                    "child-lookup",
                    "Back to lookup",
                    "Child deselected. Enter Child ID to continue."
                  );
                });

              return;
            }

            clearSelectionAndSession(
              "child-lookup",
              "Back to lookup",
              "Child deselected. Enter Child ID to continue."
            );

            return;
          }

          // ====================================================
          // PROCESS
          // ====================================================

          if (
            action ===
            "process-measurement"
          ) {
            event.preventDefault();

            processMeasurement();

            return;
          }

          // ====================================================
          // RESET
          // ====================================================

          if (
            action === "reset"
          ) {
            event.preventDefault();

            resetKioskToIdle();

            return;
          }
        }
      );
    });

    // ----------------------------------------------------------
    // STEP BUTTONS (hidden in new design, kept for compatibility)
    // ----------------------------------------------------------

    stepButtons.forEach(button => {
      button.addEventListener(
        "click",
        () => {
          // Step bar is hidden in the new 6-step design
        }
      );
    });

    // ----------------------------------------------------------
    // (child-info form removed — replaced by child-lookup)
    // ----------------------------------------------------------
  }

  // ============================================================
  // VIEW RESULTS — Populate children list
  // ============================================================

  function populateViewResults() {
    const list = document.getElementById("viewResultsList");
    const search = document.getElementById("viewResultsSearch");
    if (!list) return;

    const children = data.children || [];

    function render(filter) {
      const q = (filter || "").toLowerCase();
      const filtered = q
        ? children.filter(
            (c) =>
              (c.first_name + " " + c.last_name)
                .toLowerCase()
                .includes(q) ||
              (c.child_code || "").toLowerCase().includes(q)
          )
        : children;

      if (filtered.length === 0) {
        list.innerHTML =
          '<div class="kiosk-view-results-empty">' +
          '<svg width="48" height="48" viewBox="0 0 48 48" fill="none" stroke="#d4e5dc" stroke-width="2"><circle cx="24" cy="24" r="20"/><line x1="18" y1="24" x2="30" y2="24"/><line x1="24" y1="18" x2="24" y2="30"/></svg>' +
          "<p>" +
          (q
            ? "Walang nakitang resulta para sa \"" + q + "\""
            : "Wala pang rehistradong bata sa kiosk na ito.") +
          "</p></div>";
        return;
      }

      list.innerHTML = filtered
        .map(function (c) {
          var initials = (
            (c.first_name || "").charAt(0) +
            (c.last_name || "").charAt(0)
          ).toUpperCase();
          var statusClass = nutritionalStatusBadgeClass(c.status);
          var ageText =
            c.age_months >= 12
              ? Math.floor(c.age_months / 12) +
                " taon, " +
                (c.age_months % 12) +
                " buwan"
              : c.age_months + " buwan";
          return (
            '<div class="kiosk-view-results-card" data-child-id="' +
            c.id +
            '">' +
            '<div class="kiosk-view-results-card-avatar">' +
            initials +
            "</div>" +
            '<div class="kiosk-view-results-card-info">' +
            '<div class="kiosk-view-results-card-name">' +
            (c.first_name + " " + c.last_name) +
            "</div>" +
            '<div class="kiosk-view-results-card-meta">' +
            ageText +
            " &middot; " +
            (c.sex === "Male" ? "Lalaki" : "Babae") +
            " &middot; " +
            (c.barangay || "N/A") +
            "</div>" +
            "</div>" +
            '<span class="kiosk-view-results-card-badge ' +
            statusClass +
            '">' +
            (c.status || "Pending") +
            "</span>" +
            "</div>"
          );
        })
        .join("");
    }

    render("");
    if (search) {
      search.value = "";
      search.oninput = function () {
        render(search.value);
      };
    }
  }

  // ============================================================
  // CLOCK
  // ============================================================

  function startClock() {
    function update() {
      if (clock) {
        clock.textContent =
          formatNow();
      }

      if (welcomeClock) {
        welcomeClock.textContent =
          formatNow();
      }

      if (welcomeDate) {
        welcomeDate.textContent =
          formatDate();
      }
    }

    update();

    setInterval(
      update,
      1000
    );
  }

  // ============================================================
  // VISIBILITY
  // ============================================================

  function bindVisibilityHandling() {
    document.addEventListener(
      "visibilitychange",
      () => {
        if (
          document.visibilityState !==
          "visible"
        ) {
          return;
        }

        if (
          state.session &&
          !state.processingStarted
        ) {
          refreshMeasurementStatus(
            true
          );
        }

        if (
          state.awaitingLiveResult &&
          firebaseEnabled
        ) {
          refreshFirebaseLatestMeasurement();
        }

        if (state.deviceStatusTimer) {
          refreshDeviceStatus();
        }
      }
    );
  }

  // ============================================================
  // BEFORE UNLOAD
  // ============================================================

  function bindUnloadHandling() {
    window.addEventListener(
      "beforeunload",
      () => {
        /*
         * NEVER clear sessionStorage here.
         *
         * Refreshing Chrome must preserve
         * the current kiosk measurement.
         */

        if (
          state.session &&
          !state.processingStarted
        ) {
          saveSessionToStorage();
        }
      }
    );
  }

  // ============================================================
  // INITIALIZATION
  // ============================================================

  async function initialize() {
    bindEvents();

    var privacyCheckbox = document.getElementById("privacyCheckbox");
    var infoSheetCheckbox = document.getElementById("infoSheetCheckbox");
    var privacyContinueBtn = document.getElementById("privacyContinueBtn");

    function updateContinueBtn() {
      if (privacyContinueBtn) {
        privacyContinueBtn.disabled = !(privacyCheckbox && privacyCheckbox.checked && infoSheetCheckbox && infoSheetCheckbox.checked);
      }
    }

    if (privacyCheckbox) {
      privacyCheckbox.addEventListener("change", updateContinueBtn);
    }
    if (infoSheetCheckbox) {
      infoSheetCheckbox.addEventListener("change", updateContinueBtn);
    }

    bindVisibilityHandling();

    bindUnloadHandling();

    startClock();

    /*
     * Check Firebase but do not block
     * kiosk initialization on Firebase.
     */

    checkFirebaseConnection();

    /*
     * Try WebSocket for fast local ESP32 push.
     * Non-blocking; falls back silently if ESP32 IP unknown.
     */

    if (wsEnabled) {
      connectWebSocket();
    }

    /*
     * Poll the ESP32 heartbeat (devices.last_seen_at) so the
     * Start button and status chips reflect whether the kiosk
     * hardware is actually reachable, not just Firebase.
     */

    startDeviceStatusPolling();

    /*
     * FIRST restore browser session.
     *
     * Do not immediately force Welcome.
     */

    const restored =
      await restoreActiveSession();

    if (restored) {
      console.log(
        "[SukatKalusugan] Active session restored.",
        {
          sessionId:
            state.firebaseSessionId,

          child:
            state.child?.child_code
        }
      );

      /*
       * A measurement is already in flight; Firebase polling
       * owns the status chips from here, not the pre-start
       * heartbeat check.
       */

      stopDeviceStatusPolling();

      return;
    }

    // ==========================================================
    // NO ACTIVE SESSION
    // ==========================================================

    setStep("welcome");

    if (heroNote) {
      heroNote.textContent =
        "Pumili ng bata, pagkatapos ay simulan ang pagsukat.";
    }

    if (refs.resultSource) {
      refs.resultSource.textContent =
        firebaseEnabled
          ? "ESP32 → Firebase → SQL"
          : "Firebase unavailable";
    }

    updateProcessButton();

    syncStartButtonState();

    console.log(
      "[SukatKalusugan] Kiosk initialized",
      {
        deviceId,
        firebaseEnabled,
        firebaseUrl:
          firebaseBaseUrl ||
          "(missing)",
        children:
          children.length,
        pollIntervalMs
      }
    );
  }

  // ============================================================
  // START
  // ============================================================

  initialize();

})();