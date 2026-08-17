(function () {
  "use strict";

  console.log("[SukatKalusugan] kiosk.js loaded");

  // ============================================================
  // CONFIGURATION
  // ============================================================

  const data = window.KIOSK_DATA || {};

  const body = document.body;

  const deviceId = data?.defaults?.deviceId || "ESP32-KIOSK-01";

  const firebaseBaseUrl =
    typeof data?.firebase?.databaseUrl === "string"
      ? data.firebase.databaseUrl.trim()
      : "";

  const firebaseEnabled =
    Boolean(data?.firebase?.enabled) && firebaseBaseUrl !== "";

  const pollSeconds = Math.max(
    0.25,
    Number(data?.defaults?.pollSeconds || 0.5),
  );

  const pollIntervalMs = pollSeconds * 1000;

  const sessionTimeoutSeconds = Math.max(
    30,
    Number(data?.defaults?.sessionTimeoutSeconds || 180),
  );

  // ============================================================
  // DOM
  // ============================================================

  const welcomeScreen = document.querySelector('[data-kiosk-screen="welcome"]');

  const stepBar = document.querySelector(".kiosk-stepbar");

  const stage = document.querySelector(".kiosk-stage");

  const screens = Array.from(document.querySelectorAll("[data-kiosk-screen]"));

  const stepButtons = Array.from(
    document.querySelectorAll("[data-kiosk-step-jump]"),
  );

  const actionButtons = Array.from(
    document.querySelectorAll("[data-kiosk-action]"),
  );

  const childCards = Array.from(
    document.querySelectorAll("[data-kiosk-child-card]"),
  );

  const searchInput = document.querySelector("[data-kiosk-search]");

  const clock = document.querySelector("[data-kiosk-clock]");

  const welcomeClock = document.querySelector("[data-kiosk-live-clock]");

  const welcomeDate = document.querySelector("[data-kiosk-live-date]");

  const heroNote = document.querySelector(".kiosk-hero-note");

  const feed = document.querySelector("[data-kiosk-feed]");

  const startButton = document.querySelector('[data-kiosk-action="start"]');

  const proceedLiveButton = document.querySelector(
    '[data-kiosk-action="proceed-live"]',
  );

  // ============================================================
  // SENSOR STATUS CHIPS
  // ============================================================

  const lidarChip = document.querySelector("[data-kiosk-chip-lidar]");

  const loadCellChip = document.querySelector("[data-kiosk-chip-loadcell]");

  const connectedChip = document.querySelector("[data-kiosk-chip-connected]");

  // ============================================================
  // REFERENCES
  // ============================================================

  const refs = {
    currentChildLabel: document.querySelector(
      "[data-kiosk-current-child-label]",
    ),

    heightReadout: document.querySelector("[data-kiosk-height-readout]"),

    heightStatus: document.querySelector("[data-kiosk-height-status]"),

    heightBar: document.querySelector("[data-kiosk-height-bar]"),

    weightReadout: document.querySelector("[data-kiosk-weight-readout]"),

    weightStatus: document.querySelector("[data-kiosk-weight-status]"),

    weightBars: document.querySelector("[data-kiosk-weight-bars]"),

    progressValue: document.querySelector("[data-kiosk-progress-value]"),

    progressRing: document.querySelector("[data-kiosk-progress-ring]"),

    processStage: document.querySelector("[data-kiosk-process-stage]"),

    resultChild: document.querySelector("[data-kiosk-result-child]"),

    resultMeta: document.querySelector("[data-kiosk-result-meta]"),

    resultStatus: document.querySelector("[data-kiosk-result-status]"),

    resultHeight: document.querySelector("[data-kiosk-result-height]"),

    resultWeight: document.querySelector("[data-kiosk-result-weight]"),

    resultWaz: document.querySelector("[data-kiosk-result-waz]"),

    resultHaz: document.querySelector("[data-kiosk-result-haz]"),

    resultWhz: document.querySelector("[data-kiosk-result-whz]"),

    resultSource: document.querySelector("[data-kiosk-result-source]"),

    sessionId: document.querySelector("[data-kiosk-session-id]"),

    sessionStatus: document.querySelector("[data-kiosk-session-status]"),

    sessionStarted: document.querySelector("[data-kiosk-session-started]"),
  };

  // ============================================================
  // CHILD DATA
  // ============================================================

  const children = Array.isArray(data.children) ? data.children : [];

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

    firebaseTimer: null,

    statusTimer: null,

    processingTimer: null,

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

    measurementReady: false,

    processingStarted: false,

    restoredSession: false,
  };

  // ============================================================
  // STORAGE KEYS
  // ============================================================

  const STORAGE_KEY = "sukat_kalusugan_kiosk_session";

  const CHILD_STORAGE_KEY = "sukat_kalusugan_kiosk_child";

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
    return new Intl.DateTimeFormat("en-PH", {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: true,
    }).format(date);
  }

  function formatDate(date = new Date()) {
    return new Intl.DateTimeFormat("en-PH", {
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric",
    }).format(date);
  }

  function formatChildName(child) {
    if (!child) {
      return "Choose a child";
    }

    return `${child.first_name || ""} ${child.last_name || ""}`.trim();
  }

  function getSelectedChild() {
    return state.child;
  }

  function pushFeed(action, detail, level = "info") {
    if (!feed) {
      return;
    }

    const row = document.createElement("div");

    row.className = "kiosk-feed-row";

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
      feed.removeChild(feed.lastElementChild);
    }
  }

  // ============================================================
  // SESSION STORAGE
  // ============================================================

  function saveSessionToStorage() {
    try {
      if (!state.session) {
        return;
      }

      const saved = {
        session: state.session,

        childId: state.child?.id || null,

        weight: state.weight,

        height: state.height,

        weightLocked: state.weightLocked,

        heightLocked: state.heightLocked,

        measurementReady: state.measurementReady,

        step: state.step,

        phase: state.phase,

        savedAt: Date.now(),
      };

      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(saved));

      if (state.child) {
        sessionStorage.setItem(CHILD_STORAGE_KEY, JSON.stringify(state.child));
      }
    } catch (error) {
      console.warn("[SukatKalusugan] Unable to save session storage", error);
    }
  }

  function loadSessionFromStorage() {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);

      if (!raw) {
        return null;
      }

      const saved = JSON.parse(raw);

      if (!saved) {
        return null;
      }

      if (
        saved.savedAt &&
        Date.now() - Number(saved.savedAt) > sessionTimeoutSeconds * 1000
      ) {
        clearSessionStorage();

        return null;
      }

      return saved;
    } catch (error) {
      console.warn("[SukatKalusugan] Session storage parse failed", error);

      return null;
    }
  }

  function clearSessionStorage() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);

      sessionStorage.removeItem(CHILD_STORAGE_KEY);
    } catch (error) {
      console.warn("[SukatKalusugan] Unable to clear storage", error);
    }
  }

  function restoreChild(childId) {
    if (!childId) {
      return null;
    }

    return (
      children.find((child) => String(child.id) === String(childId)) || null
    );
  }

  // ============================================================
  // SESSION INFORMATION
  // ============================================================

  function updateSessionInfo(session) {
    if (!session) {
      if (refs.sessionId) {
        refs.sessionId.textContent = "—";
      }

      if (refs.sessionStatus) {
        refs.sessionStatus.textContent = "Idle";
      }

      if (refs.sessionStarted) {
        refs.sessionStarted.textContent = "—";
      }

      return;
    }

    if (refs.sessionId) {
      refs.sessionId.textContent = String(
        session.session_id || session.id || "—",
      );
    }

    if (refs.sessionStatus) {
      refs.sessionStatus.textContent = String(
        session.status || session.state || "IDLE",
      );
    }

    if (refs.sessionStarted) {
      refs.sessionStarted.textContent = session.started_at
        ? new Date(session.started_at).toLocaleString()
        : "—";
    }
  }

  // ============================================================
  // STEP NAVIGATION
  // ============================================================

  function setStep(step) {
    const allowedSteps = ["welcome", "select", "live", "processing", "results"];

    if (!allowedSteps.includes(step)) {
      return;
    }

    /*
        ------------------------------------------------------------
        IMPORTANT:
        Do not allow arbitrary navigation into processing/results.
        Those steps are controlled by the measurement flow.
        ------------------------------------------------------------
        */

    if (step === "processing" && !state.processingStarted) {
      console.warn(
        "[SukatKalusugan] Processing blocked. " +
          "User must click Process Measurement.",
      );

      return;
    }

    if (step === "results" && !state.processingStarted) {
      return;
    }

    state.step = step;

    body.dataset.kioskStep = step;

    if (welcomeScreen) {
      welcomeScreen.hidden = step !== "welcome";
    }

    if (stepBar) {
      stepBar.hidden = step === "welcome";
    }

    if (stage) {
      stage.hidden = step === "welcome";
    }

    screens.forEach((screen) => {
      const active = screen.getAttribute("data-kiosk-screen") === step;

      if (!active && screen.contains(document.activeElement)) {
        document.activeElement.blur();
      }

      screen.hidden = !active;

      screen.classList.toggle("is-visible", active);

      screen.setAttribute("aria-hidden", String(!active));
    });

    stepButtons.forEach((button) => {
      const target = button.getAttribute("data-kiosk-step-jump");

      button.classList.toggle("is-active", target === step);
    });

    saveSessionToStorage();
  }

  // ============================================================
  // PROGRESS
  // ============================================================

  function setProgress(progress, message) {
    const value = Math.max(0, Math.min(100, Number(progress) || 0));

    if (refs.progressValue) {
      refs.progressValue.textContent = `${Math.round(value)}%`;
    }

    if (refs.progressRing) {
      const circumference = 2 * Math.PI * 68;

      refs.progressRing.style.strokeDasharray = circumference;

      refs.progressRing.style.strokeDashoffset =
        circumference - (circumference * value) / 100;
    }

    if (refs.processStage && message) {
      refs.processStage.textContent = message;
    }
  }

  // ============================================================
  // PROCESS BUTTON
  // ============================================================

  function getProcessButton() {
    return document.querySelector('[data-kiosk-action="process-measurement"]');
  }

  function updateProcessButton() {
    const button = getProcessButton();

    if (!button) {
      return;
    }

    const ready =
      state.measurementReady &&
      !state.processingStarted &&
      Number.isFinite(state.weight) &&
      Number.isFinite(state.height);

    button.disabled = !ready;

    if (state.processingStarted) {
      button.textContent = "Processing...";
    } else if (ready) {
      button.textContent = "Process Measurement";
    } else {
      button.textContent = "Waiting for Measurement";
    }
  }

  function markMeasurementReady() {
    if (!Number.isFinite(state.weight) || !Number.isFinite(state.height)) {
      state.measurementReady = false;

      updateProcessButton();

      return;
    }

    state.measurementReady = true;

    if (refs.weightStatus) {
      refs.weightStatus.textContent = "Weight captured";
    }

    if (refs.heightStatus) {
      refs.heightStatus.textContent = "Height captured";
    }

    setProgress(60, "Measurement ready. Click Process Measurement.");

    if (heroNote) {
      heroNote.textContent =
        "Measurement ready. Click Process Measurement to continue.";
    }

    updateProcessButton();

    pushFeed(
      "Measurement ready",
      "Weight and height captured. Waiting for operator.",
    );

    saveSessionToStorage();
  }

  // ============================================================
  // CHILD SELECTION
  // ============================================================

  function selectChild(childId) {
    if (isMeasurementActive()) {
      pushFeed(
        "Selection locked",
        "Wait for the current measurement to finish.",
        "warn",
      );

      return;
    }

    state.child =
      children.find((child) => String(child.id) === String(childId)) || null;

    childCards.forEach((card) => {
      card.classList.toggle(
        "is-selected",
        String(card.dataset.childId) === String(childId),
      );
    });

    const child = getSelectedChild();

    if (refs.currentChildLabel) {
      refs.currentChildLabel.textContent = formatChildName(child);
    }

    if (proceedLiveButton) {
      proceedLiveButton.disabled = !child;
    }

    if (child) {
      pushFeed(
        "Child selected",
        `${child.child_code || "Child"} · ${formatChildName(child)}`,
      );

      try {
        sessionStorage.setItem(CHILD_STORAGE_KEY, JSON.stringify(child));
      } catch (error) {
        console.warn(error);
      }
    }
  }

  window.kioskSelectChild = selectChild;

  // ============================================================
  // MEASUREMENT ACTIVE
  // ============================================================

  function isMeasurementActive(session = state.session) {
    if (!session) {
      return false;
    }

    const status = String(session.status || "").toUpperCase();

    return [
      "START_REQUESTED",
      "MEASURING",
      "WEIGHT_MEASURING",
      "HEIGHT_MEASURING",
    ].includes(status);
  }

  // ============================================================
  // START BUTTON
  // ============================================================

  function syncStartButtonState() {
    if (!startButton) {
      return;
    }

    startButton.disabled = state.submitting || isMeasurementActive();

    startButton.textContent = state.submitting
      ? "Starting..."
      : "Start Measurement";
  }

  // ============================================================
  // FIREBASE URL
  // ============================================================

  function firebaseLatestMeasurementUrl() {
    if (!firebaseEnabled) {
      return "";
    }

    return (
      firebaseBaseUrl.replace(/\/$/, "") +
      "/latest_measurements/" +
      encodeURIComponent(deviceId) +
      ".json"
    );
  }

  // ============================================================
  // CHIPS
  // ============================================================

  function setChip(element, label, good) {
    if (!element) {
      return;
    }

    const dot = element.querySelector(".kiosk-dot");

    if (dot) {
      dot.style.background = good ? "#2ec57a" : "#d85d5d";
    }

    element.classList.toggle("is-success", good);

    const textNodes = Array.from(element.childNodes).filter(
      (node) => node.nodeType === Node.TEXT_NODE,
    );

    if (textNodes.length) {
      textNodes[textNodes.length - 1].textContent = ` ${label}`;
    }
  }

  // ============================================================
  // LIVE SENSOR UI
  // ============================================================

  function setWeight(value, message = "Reading weight...") {
    const weight = Number(value);

    if (!Number.isFinite(weight) || weight < 0 || weight > 300) {
      return false;
    }

    state.weight = weight;

    if (refs.weightReadout) {
      refs.weightReadout.textContent = weight.toFixed(2);
    }

    if (refs.weightStatus) {
      refs.weightStatus.textContent = message;
    }

    if (refs.weightBars) {
      refs.weightBars.innerHTML = "";

      const normalized = Math.min(100, Math.max(5, weight));

      for (let i = 0; i < 8; i++) {
        const bar = document.createElement("span");

        const height = Math.min(100, 20 + normalized * (0.35 + i * 0.06));

        bar.style.height = `${height}%`;

        refs.weightBars.appendChild(bar);
      }
    }

    setChip(loadCellChip, "Scale: Live", true);

    return true;
  }

  function setHeight(value, message = "Reading height...") {
    const height = Number(value);

    if (!Number.isFinite(height) || height < 0 || height > 300) {
      return false;
    }

    state.height = height;

    if (refs.heightReadout) {
      refs.heightReadout.textContent = height.toFixed(1);
    }

    if (refs.heightStatus) {
      refs.heightStatus.textContent = message;
    }

    if (refs.heightBar) {
      const percentage = Math.min(100, Math.max(0, height / 2.5));

      refs.heightBar.style.width = `${percentage}%`;
    }

    setChip(lidarChip, "LiDAR: Live", true);

    return true;
  }

  // ============================================================
  // STABILITY
  // ============================================================

  function updateStability(type, value) {
    const isWeight = type === "weight";

    const epsilon = isWeight ? 0.05 : 0.5;

    const lastKey = isWeight ? "lastWeightRaw" : "lastHeightRaw";

    const countKey = isWeight ? "weightStableCount" : "heightStableCount";

    const lockedKey = isWeight ? "weightLocked" : "heightLocked";

    const last = state[lastKey];

    if (last !== null && Math.abs(value - last) <= epsilon) {
      state[countKey] += 1;
    } else {
      state[countKey] = 0;
    }

    state[lastKey] = value;

    if (state[countKey] >= 2) {
      state[lockedKey] = true;
    }

    return state[lockedKey];
  }

  // ============================================================
  // FIREBASE PAYLOAD
  // ============================================================

  function applyFirebaseStatus(payload) {
    if (!payload || typeof payload !== "object") {
      return;
    }

    const status = String(payload.status || "").toUpperCase();

    const weight = payload.weight_kg == null ? NaN : Number(payload.weight_kg);

    const height = payload.height_cm == null ? NaN : Number(payload.height_cm);

    const hasWeight = Number.isFinite(weight) && weight >= 0 && weight <= 300;

    const hasHeight = Number.isFinite(height) && height >= 0 && height <= 300;

    console.log("[SukatKalusugan] Firebase update", payload);

    state.firebaseOnline = true;

    setChip(connectedChip, "Device: Connected", true);

    // ========================================================
    // MEASURING
    // ========================================================

    if (
      status === "MEASURING" ||
      status === "WEIGHT_MEASURING" ||
      status === "HEIGHT_MEASURING"
    ) {
      /*
            IMPORTANT:
            Stay on STEP 2.
            */

      state.phase = "live";

      if (state.step !== "live") {
        setStep("live");
      }

      if (hasWeight) {
        const locked = updateStability("weight", weight);

        setWeight(weight, locked ? "Weight stable" : "Reading weight...");
      }

      if (hasHeight) {
        const locked = updateStability("height", height);

        setHeight(height, locked ? "Height stable" : "Reading height...");
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
        "Stand still while the sensors capture your measurement...",
      );

      /*
            DO NOT PROCESS AUTOMATICALLY.
            */

      if (state.weightLocked && state.heightLocked) {
        markMeasurementReady();
      }

      saveSessionToStorage();

      return;
    }

    // ========================================================
    // COMPLETE FROM ESP32
    // ========================================================

    if (status === "COMPLETE") {
      if (hasWeight) {
        state.weightLocked = true;

        setWeight(weight, "Weight captured");
      }

      if (hasHeight) {
        state.heightLocked = true;

        setHeight(height, "Height captured");
      }

      /*
            ========================================================
            CRITICAL FIX

            ESP32 COMPLETE does NOT mean PROCESSING.

            The sensors are finished, but the kiosk remains
            on STEP 2 until the operator presses:

                Process Measurement

            ========================================================
            */

      state.phase = "live";

      state.submitting = false;

      state.awaitingLiveResult = true;

      state.measurementReady = true;

      setStep("live");

      markMeasurementReady();

      pushFeed(
        "Sensors complete",
        "Weight and height captured. Click Process Measurement.",
      );

      saveSessionToStorage();

      return;
    }

    // ========================================================
    // ERROR
    // ========================================================

    if (status === "ERROR" || status === "CANCELLED") {
      state.phase = "error";

      state.submitting = false;

      state.awaitingLiveResult = false;

      stopFirebasePolling();

      setProgress(100, payload.error_message || "Measurement failed.");

      pushFeed(
        "Measurement failed",
        payload.error_message || "Unknown measurement error",
        "error",
      );

      saveSessionToStorage();
    }
  }

  // ============================================================
  // PROCESS MEASUREMENT
  // ============================================================

  async function processMeasurement() {
    if (state.processingStarted) {
      return;
    }

    if (!state.child) {
      pushFeed("Processing blocked", "No child selected.", "warn");

      return;
    }

    if (!Number.isFinite(state.weight) || !Number.isFinite(state.height)) {
      pushFeed(
        "Processing blocked",
        "Weight and height are not ready.",
        "warn",
      );

      return;
    }

    if (!state.session) {
      pushFeed("Processing blocked", "No active measurement session.", "warn");

      return;
    }

    /*
        ------------------------------------------------------------
        START PROCESSING ONLY AFTER BUTTON CLICK
        ------------------------------------------------------------
        */

    state.processingStarted = true;

    state.phase = "processing";

    updateProcessButton();

    setStep("processing");

    setProgress(65, "Preparing measurement...");

    pushFeed(
      "Processing started",
      `${state.child.child_code || "Child"} · calculating growth indicators`,
    );

    /*
        ------------------------------------------------------------
        The actual SQL save is normally performed by the backend
        after the ESP32 submits its COMPLETE measurement.

        We therefore give the backend time to finish and then
        retrieve the latest session status.
        ------------------------------------------------------------
        */

    const stages = [
      {
        progress: 70,
        message: "Calculating weight-for-age...",
      },

      {
        progress: 76,
        message: "Calculating height-for-age...",
      },

      {
        progress: 82,
        message: "Calculating weight-for-height...",
      },

      {
        progress: 88,
        message: "Classifying nutritional status...",
      },

      {
        progress: 94,
        message: "Saving measurement to SQL...",
      },
    ];

    let index = 0;

    if (state.processingTimer) {
      clearInterval(state.processingTimer);
    }

    state.processingTimer = setInterval(async () => {
      if (index < stages.length) {
        const stageData = stages[index];

        setProgress(stageData.progress, stageData.message);

        index++;

        return;
      }

      clearInterval(state.processingTimer);

      state.processingTimer = null;

      /*
                    ------------------------------------------------
                    Check backend session.
                    ------------------------------------------------
                    */

      const status = await refreshMeasurementStatus(false);

      if (status && String(status.status || "").toUpperCase() === "COMPLETE") {
        finishResults(status, state.weight, state.height);

        return;
      }

      /*
                    ------------------------------------------------
                    If backend has not returned COMPLETE yet,
                    keep checking rather than pretending it is done.
                    ------------------------------------------------
                    */

      await waitForBackendCompletion();
    }, 900);
  }

  // ============================================================
  // WAIT FOR BACKEND COMPLETION
  // ============================================================

  async function waitForBackendCompletion() {
    let attempts = 0;

    const maxAttempts = 20;

    const check = async () => {
      attempts++;

      const status = await refreshMeasurementStatus(false);

      if (status && String(status.status || "").toUpperCase() === "COMPLETE") {
        finishResults(status, state.weight, state.height);

        return;
      }

      if (
        status &&
        ["ERROR", "CANCELLED"].includes(
          String(status.status || "").toUpperCase(),
        )
      ) {
        processingFailed(
          status.error_message || "Measurement processing failed.",
        );

        return;
      }

      if (attempts >= maxAttempts) {
        processingFailed(
          "The server did not confirm the completed measurement.",
        );

        return;
      }

      setProgress(
        Math.min(96, 90 + attempts * 0.3),
        "Waiting for SQL to confirm the measurement...",
      );

      setTimeout(check, 1000);
    };

    check();
  }

  function processingFailed(message) {
    state.processingStarted = false;

    state.phase = "error";

    updateProcessButton();

    setProgress(100, message);

    pushFeed("Processing failed", message, "error");
  }

  // ============================================================
  // RESULTS
  // ============================================================

  function finishResults(payload, weight, height) {
    const child = getSelectedChild();

    const nutritionalStatus =
      payload.nutritional_status ||
      payload.measurement?.nutritional_status ||
      "Pending";

    state.nutritionalStatus = nutritionalStatus;

    state.phase = "results";

    state.processingStarted = true;

    if (refs.resultChild) {
      refs.resultChild.textContent =
        payload.child_name || formatChildName(child);
    }

    if (refs.resultMeta) {
      refs.resultMeta.textContent = `${child?.age_months || 0} months old`;
    }

    if (refs.resultHeight) {
      refs.resultHeight.textContent = Number.isFinite(height)
        ? `${height.toFixed(1)} cm`
        : "--.- cm";
    }

    if (refs.resultWeight) {
      refs.resultWeight.textContent = Number.isFinite(weight)
        ? `${weight.toFixed(2)} kg`
        : "--.-- kg";
    }

    if (refs.resultStatus) {
      refs.resultStatus.textContent = nutritionalStatus;
    }

    if (refs.resultWaz) {
      refs.resultWaz.textContent =
        payload.waz != null ? Number(payload.waz).toFixed(2) : "--";
    }

    if (refs.resultHaz) {
      refs.resultHaz.textContent =
        payload.haz != null ? Number(payload.haz).toFixed(2) : "--";
    }

    if (refs.resultWhz) {
      refs.resultWhz.textContent =
        payload.whz != null ? Number(payload.whz).toFixed(2) : "--";
    }

    if (refs.resultSource) {
      refs.resultSource.textContent = "ESP32 → Firebase → SQL";
    }

    setProgress(100, "Measurement complete");

    setStep("results");

    pushFeed(
      "Measurement complete",
      `${payload.child_code || child?.child_code || "Child"} · ` +
        `${Number.isFinite(weight) ? weight.toFixed(2) : "--"} kg · ` +
        `${Number.isFinite(height) ? height.toFixed(1) : "--"} cm`,
    );

    clearSessionStorage();
  }

  // ============================================================
  // FIREBASE POLLING
  // ============================================================

  async function refreshFirebaseLatestMeasurement() {
    if (!firebaseEnabled || !state.awaitingLiveResult) {
      return null;
    }

    const url = firebaseLatestMeasurementUrl();

    if (!url) {
      return null;
    }

    try {
      const response = await fetch(url, {
        cache: "no-store",

        headers: {
          Accept: "application/json",
        },
      });

      if (!response.ok) {
        state.firebaseOnline = false;

        setChip(connectedChip, "Device: Offline", false);

        return null;
      }

      const payload = await response.json();

      if (!payload || typeof payload !== "object") {
        return null;
      }

      state.firebaseOnline = true;

      setChip(connectedChip, "Device: Connected", true);

      const payloadSessionId = Number(payload.session_id || 0);

      /*
            ----------------------------------------------------------
            SESSION MISMATCH FIX

            We DO NOT accept an unrelated Firebase session as our
            measurement.

            This prevents an old/stale ESP32 measurement from being
            displayed in the current kiosk session.
            ----------------------------------------------------------
            */

      if (
        state.firebaseSessionId &&
        payloadSessionId &&
        payloadSessionId !== state.firebaseSessionId
      ) {
        console.warn("[SukatKalusugan] Ignoring Firebase session mismatch", {
          expected: state.firebaseSessionId,

          received: payloadSessionId,
        });

        return null;
      }

      const timestamp = String(payload.timestamp || "");

      if (timestamp && timestamp === state.lastFirebaseTimestamp) {
        return payload;
      }

      state.lastFirebaseTimestamp = timestamp;

      applyFirebaseStatus(payload);

      return payload;
    } catch (error) {
      console.error("[SukatKalusugan] Firebase polling error", error);

      setChip(connectedChip, "Device: Offline", false);

      return null;
    }
  }

  function startFirebasePolling() {
    if (!firebaseEnabled) {
      pushFeed("Firebase unavailable", "Firebase is not configured.", "warn");

      return;
    }

    stopFirebasePolling();

    state.firebaseTimer = setInterval(
      refreshFirebaseLatestMeasurement,
      pollIntervalMs,
    );

    refreshFirebaseLatestMeasurement();
  }

  function stopFirebasePolling() {
    if (state.firebaseTimer) {
      clearInterval(state.firebaseTimer);

      state.firebaseTimer = null;
    }
  }

  // ============================================================
  // BACKEND SESSION STATUS
  // ============================================================

  async function refreshMeasurementStatus(scheduleNext = true) {
    if (!state.session) {
      return null;
    }

    try {
      const endpoint =
        data?.endpoints?.measurementStatus ||
        "../api/kiosk/measurement_status.php";

      const url = new URL(endpoint, window.location.href);

      url.searchParams.set("device_id", deviceId);

      /*
            ----------------------------------------------------------
            IMPORTANT

            If the backend supports session_id, always send it.

            This prevents the kiosk from accidentally reading another
            session belonging to the same device.
            ----------------------------------------------------------
            */

      const sessionId = Number(
        state.session.session_id || state.session.id || 0,
      );

      if (sessionId) {
        url.searchParams.set("session_id", String(sessionId));
      }

      const response = await fetch(url.toString(), {
        cache: "no-store",

        headers: {
          Accept: "application/json",
        },
      });

      const json = await response.json().catch(() => ({}));

      const payload = json?.data || {};

      if (!response.ok || json?.success !== true) {
        throw new Error(json?.message || "Unable to load measurement status");
      }

      /*
            ----------------------------------------------------------
            Reject a different session returned by SQL.
            ----------------------------------------------------------
            */

      const returnedSessionId = Number(payload.session_id || payload.id || 0);

      if (sessionId && returnedSessionId && sessionId !== returnedSessionId) {
        console.warn("[SukatKalusugan] SQL session mismatch", {
          expected: sessionId,

          received: returnedSessionId,
        });

        return state.session;
      }

      state.session = payload;

      updateSessionInfo(payload);

      saveSessionToStorage();

      const status = String(payload.status || "").toUpperCase();

      if (status === "ERROR" || status === "CANCELLED") {
        processingFailed(payload.error_message || "Measurement failed.");

        return payload;
      }

      /*
            ----------------------------------------------------------
            DO NOT CHANGE STEP 2 TO STEP 3 HERE.

            SQL COMPLETE only means the sensor/session is complete.
            The operator still needs to click Process Measurement.
            ----------------------------------------------------------
            */

      if (status === "COMPLETE" && !state.processingStarted) {
        state.measurementReady =
          Number.isFinite(state.weight) && Number.isFinite(state.height);

        if (state.measurementReady) {
          setStep("live");

          markMeasurementReady();
        }
      }

      if (scheduleNext && isMeasurementActive(payload)) {
        if (state.statusTimer) {
          clearTimeout(state.statusTimer);
        }

        state.statusTimer = setTimeout(
          () => refreshMeasurementStatus(true),
          pollIntervalMs,
        );
      }

      return payload;
    } catch (error) {
      console.warn("[SukatKalusugan] Session status error", error);

      if (scheduleNext && isMeasurementActive(state.session)) {
        state.statusTimer = setTimeout(
          () => refreshMeasurementStatus(true),
          pollIntervalMs,
        );
      }

      return null;
    }
  }

  // ============================================================
  // START MEASUREMENT
  // ============================================================

  async function startMeasurementFlow() {
    const child = getSelectedChild();

    if (!child) {
      pushFeed("Start blocked", "Choose a child first.", "warn");

      setStep("select");

      return false;
    }

    if (isMeasurementActive()) {
      pushFeed("Start blocked", "A measurement is already active.", "warn");

      return false;
    }

    state.submitting = true;

    syncStartButtonState();

    try {
      const endpoint =
        data?.endpoints?.startMeasurement ||
        "../api/kiosk/start_measurement.php";

      const response = await fetch(endpoint, {
        method: "POST",

        headers: {
          "Content-Type": "application/json",

          Accept: "application/json",
        },

        body: JSON.stringify({
          device_id: deviceId,

          child_id: child.id,

          location: "Kiosk",
        }),

        cache: "no-store",
      });

      const json = await response.json().catch(() => ({}));

      const payload = json?.data || {};

      if (!response.ok || json?.success !== true) {
        throw new Error(json?.message || "Could not start measurement");
      }

      state.session = payload;

      state.firebaseSessionId =
        Number(payload.session_id || payload.id || 0) || null;

      state.awaitingLiveResult = true;

      state.lastFirebaseTimestamp = "";

      state.submitting = false;

      state.processingStarted = false;

      state.measurementReady = false;

      state.weight = null;

      state.height = null;

      state.weightLocked = false;

      state.heightLocked = false;

      state.lastWeightRaw = null;

      state.lastHeightRaw = null;

      state.weightStableCount = 0;

      state.heightStableCount = 0;

      state.phase = "live";

      updateSessionInfo(payload);

      saveSessionToStorage();

      syncStartButtonState();

      /*
            ----------------------------------------------------------
            STEP 2
            ----------------------------------------------------------
            */

      setStep("live");

      setProgress(20, "Starting live measurement...");

      if (refs.weightStatus) {
        refs.weightStatus.textContent = "Waiting for HX711...";
      }

      if (refs.heightStatus) {
        refs.heightStatus.textContent = "Waiting for TF-Luna...";
      }

      setChip(connectedChip, "Device: Waiting", false);

      pushFeed(
        "Measurement started",
        `${child.child_code || "Child"} · waiting for ESP32`,
      );

      startFirebasePolling();

      refreshMeasurementStatus(true);

      return true;
    } catch (error) {
      console.error("[SukatKalusugan] Start measurement failed", error);

      state.submitting = false;

      state.awaitingLiveResult = false;

      syncStartButtonState();

      pushFeed(
        "Start failed",
        error.message || "Unable to contact server.",
        "error",
      );

      setStep("select");

      return false;
    }
  }

  // ============================================================
  // RESTORE ACTIVE SESSION AFTER REFRESH
  // ============================================================

  async function restoreActiveSession() {
    const saved = loadSessionFromStorage();

    if (!saved) {
      return false;
    }

    const savedSession = saved.session;

    if (!savedSession) {
      clearSessionStorage();

      return false;
    }

    const savedStatus = String(savedSession.status || "").toUpperCase();

    /*
        ------------------------------------------------------------
        Do not restore completed sessions.
        ------------------------------------------------------------
        */

    if (["COMPLETE", "ERROR", "CANCELLED"].includes(savedStatus)) {
      clearSessionStorage();

      return false;
    }

    const child = restoreChild(saved.childId);

    if (!child) {
      clearSessionStorage();

      return false;
    }

    state.child = child;

    state.session = savedSession;

    state.firebaseSessionId =
      Number(savedSession.session_id || savedSession.id || 0) || null;

    state.weight = Number.isFinite(Number(saved.weight))
      ? Number(saved.weight)
      : null;

    state.height = Number.isFinite(Number(saved.height))
      ? Number(saved.height)
      : null;

    state.weightLocked = Boolean(saved.weightLocked);

    state.heightLocked = Boolean(saved.heightLocked);

    state.measurementReady = Boolean(saved.measurementReady);

    state.processingStarted = false;

    state.phase = "live";

    state.awaitingLiveResult = true;

    state.restoredSession = true;

    childCards.forEach((card) => {
      card.classList.toggle(
        "is-selected",
        String(card.dataset.childId) === String(child.id),
      );
    });

    if (refs.currentChildLabel) {
      refs.currentChildLabel.textContent = formatChildName(child);
    }

    if (proceedLiveButton) {
      proceedLiveButton.disabled = false;
    }

    if (Number.isFinite(state.weight)) {
      setWeight(
        state.weight,
        state.weightLocked ? "Weight captured" : "Weight restored",
      );
    }

    if (Number.isFinite(state.height)) {
      setHeight(
        state.height,
        state.heightLocked ? "Height captured" : "Height restored",
      );
    }

    updateSessionInfo(state.session);

    setStep("live");

    if (
      state.measurementReady ||
      (Number.isFinite(state.weight) && Number.isFinite(state.height))
    ) {
      state.measurementReady = true;

      markMeasurementReady();
    } else {
      setProgress(
        20,
        "Restored active measurement session. Waiting for sensors...",
      );
    }

    pushFeed(
      "Session restored",
      `${child.child_code || "Child"} · Session #${state.firebaseSessionId || "—"}`,
    );

    startFirebasePolling();

    refreshMeasurementStatus(true);

    syncStartButtonState();

    return true;
  }

  // ============================================================
  // RESET
  // ============================================================

  function resetKioskToIdle() {
    if (isMeasurementActive()) {
      pushFeed(
        "Reset blocked",
        "Wait for the active measurement to finish.",
        "warn",
      );

      return;
    }

    if (state.statusTimer) {
      clearTimeout(state.statusTimer);
    }

    if (state.processingTimer) {
      clearInterval(state.processingTimer);

      clearTimeout(state.processingTimer);
    }

    stopFirebasePolling();

    state.statusTimer = null;

    state.processingTimer = null;

    state.session = null;

    state.phase = "idle";

    state.submitting = false;

    state.awaitingLiveResult = false;

    state.firebaseSessionId = null;

    state.lastFirebaseTimestamp = "";

    state.weight = null;

    state.height = null;

    state.nutritionalStatus = null;

    state.child = null;

    state.weightLocked = false;

    state.heightLocked = false;

    state.lastWeightRaw = null;

    state.lastHeightRaw = null;

    state.weightStableCount = 0;

    state.heightStableCount = 0;

    state.measurementReady = false;

    state.processingStarted = false;

    state.restoredSession = false;

    clearSessionStorage();

    childCards.forEach((card) => card.classList.remove("is-selected"));

    if (refs.currentChildLabel) {
      refs.currentChildLabel.textContent = "Choose a child";
    }

    if (proceedLiveButton) {
      proceedLiveButton.disabled = true;
    }

    if (searchInput) {
      searchInput.value = "";
    }

    childCards.forEach((card) => {
      card.hidden = false;
    });

    if (refs.weightReadout) {
      refs.weightReadout.textContent = "--.--";
    }

    if (refs.weightStatus) {
      refs.weightStatus.textContent = "Waiting for HX711...";
    }

    if (refs.heightReadout) {
      refs.heightReadout.textContent = "--.-";
    }

    if (refs.heightStatus) {
      refs.heightStatus.textContent = "Waiting for TF-Luna...";
    }

    if (refs.heightBar) {
      refs.heightBar.style.width = "0%";
    }

    if (refs.weightBars) {
      refs.weightBars.innerHTML = "";
    }

    if (refs.resultChild) {
      refs.resultChild.textContent = "Name";
    }

    if (refs.resultMeta) {
      refs.resultMeta.textContent = "-- months old";
    }

    if (refs.resultHeight) {
      refs.resultHeight.textContent = "--.- cm";
    }

    if (refs.resultWeight) {
      refs.resultWeight.textContent = "--.-- kg";
    }

    if (refs.resultWaz) {
      refs.resultWaz.textContent = "--";
    }

    if (refs.resultHaz) {
      refs.resultHaz.textContent = "--";
    }

    if (refs.resultWhz) {
      refs.resultWhz.textContent = "--";
    }

    if (refs.resultStatus) {
      refs.resultStatus.textContent = "Pending";
    }

    if (refs.resultSource) {
      refs.resultSource.textContent = "ESP32 → Firebase → SQL";
    }

    setProgress(0, "Ready to measure");

    setChip(lidarChip, "LiDAR: Waiting", false);

    setChip(loadCellChip, "Scale: Waiting", false);

    setChip(connectedChip, "Device: Waiting", false);

    updateSessionInfo(null);

    setStep("welcome");

    syncStartButtonState();

    pushFeed("Kiosk reset", "Ready for the next child.");
  }

  // ============================================================
  // FIREBASE CONNECTION CHECK
  // ============================================================

  async function checkFirebaseConnection() {
    if (!firebaseEnabled) {
      setChip(connectedChip, "Device: Firebase Off", false);

      return;
    }

    const url = firebaseLatestMeasurementUrl();

    try {
      const response = await fetch(url, {
        cache: "no-store",
      });

      if (response.ok) {
        state.firebaseOnline = true;

        setChip(connectedChip, "Device: Firebase Ready", true);
      } else {
        setChip(connectedChip, "Device: Firebase Error", false);
      }
    } catch (error) {
      console.warn("[SukatKalusugan] Firebase unavailable", error);

      setChip(connectedChip, "Device: Offline", false);
    }
  }

  // ============================================================
  // EVENTS
  // ============================================================

  function bindEvents() {
    // --------------------------------------------------------
    // Search
    // --------------------------------------------------------

    if (searchInput) {
      searchInput.addEventListener("input", () => {
        const term = searchInput.value.trim().toLowerCase();

        childCards.forEach((card) => {
          const text = (card.dataset.filterText || "").toLowerCase();

          card.hidden = !text.includes(term);
        });
      });
    }

    // --------------------------------------------------------
    // Child cards
    // --------------------------------------------------------

    childCards.forEach((card) => {
      card.addEventListener("click", () => {
        selectChild(card.dataset.childId);
      });
    });

    // --------------------------------------------------------
    // Actions
    // --------------------------------------------------------

    actionButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const action = button.getAttribute("data-kiosk-action");

        // START
        if (action === "start") {
          if (!getSelectedChild()) {
            setStep("select");

            pushFeed("Select child", "Choose a child before starting.");

            return;
          }

          startMeasurementFlow();

          return;
        }

        // PROCEED TO LIVE
        if (action === "proceed-live") {
          if (!getSelectedChild()) {
            return;
          }

          startMeasurementFlow();

          return;
        }

        // PROCESS MEASUREMENT
        if (action === "process-measurement") {
          processMeasurement();

          return;
        }

        // RESET
        if (action === "reset") {
          resetKioskToIdle();

          return;
        }
      });
    });

    // --------------------------------------------------------
    // Step buttons
    // --------------------------------------------------------

    stepButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const target = button.getAttribute("data-kiosk-step-jump");

        /*
                        ------------------------------------------------
                        STEP 1
                        ------------------------------------------------
                        */

        if (target === "select") {
          if (!isMeasurementActive() && !state.processingStarted) {
            setStep("select");
          }

          return;
        }

        /*
                        ------------------------------------------------
                        STEP 2

                        This is allowed during the active session.
                        ------------------------------------------------
                        */

        if (target === "live") {
          if (state.session && !state.processingStarted) {
            setStep("live");
          }

          return;
        }

        /*
                        ------------------------------------------------
                        STEP 3

                        NEVER allow manual jump.

                        The only way to Step 3 is the
                        Process Measurement button.
                        ------------------------------------------------
                        */

        if (target === "processing") {
          pushFeed(
            "Step locked",
            "Click Process Measurement to continue.",
            "warn",
          );

          return;
        }

        /*
                        ------------------------------------------------
                        STEP 4

                        NEVER manually jump.
                        ------------------------------------------------
                        */

        if (target === "results") {
          return;
        }
      });
    });
  }

  // ============================================================
  // CLOCK
  // ============================================================

  function startClock() {
    function update() {
      if (clock) {
        clock.textContent = formatNow();
      }

      if (welcomeClock) {
        welcomeClock.textContent = formatNow();
      }

      if (welcomeDate) {
        welcomeDate.textContent = formatDate();
      }
    }

    update();

    setInterval(update, 1000);
  }

  // ============================================================
  // INITIALIZATION
  // ============================================================

  async function initialize() {
    bindEvents();

    startClock();

    checkFirebaseConnection();

    /*
        ------------------------------------------------------------
        IMPORTANT REFRESH BEHAVIOR

        First attempt to restore an existing active session.

        We do NOT immediately do:

            setStep("welcome")

        because that was the reason refresh sent the kiosk back
        to the beginning while SQL still had an active session.
        ------------------------------------------------------------
        */

    const restored = await restoreActiveSession();

    if (restored) {
      console.log("[SukatKalusugan] Active session restored after refresh.");

      return;
    }

    /*
        ------------------------------------------------------------
        No active session.
        Start normally.
        ------------------------------------------------------------
        */

    setStep("welcome");

    if (heroNote) {
      heroNote.textContent = "Select a child, then start the measurement.";
    }

    if (refs.resultSource) {
      refs.resultSource.textContent = firebaseEnabled
        ? "ESP32 → Firebase → SQL"
        : "Firebase unavailable";
    }

    syncStartButtonState();

    console.log("[SukatKalusugan] Kiosk initialized", {
      deviceId,
      firebaseEnabled,
      firebaseUrl: firebaseBaseUrl || "(missing)",
      children: children.length,
      pollIntervalMs,
    });
  }

  initialize();
})();
