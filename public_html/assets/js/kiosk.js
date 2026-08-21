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

  const pollSeconds = Math.max(
    0.25,
    Number(data?.defaults?.pollSeconds || 0.5)
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
      "select",
      "live",
      "processing",
      "results"
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

    state.step = step;

    body.dataset.kioskStep = step;

    if (welcomeScreen) {
      welcomeScreen.hidden =
        step !== "welcome";
    }

    if (stepBar) {
      stepBar.hidden =
        step === "welcome";
    }

    if (stage) {
      stage.hidden =
        step === "welcome";
    }

    screens.forEach(screen => {
      const active =
        screen.getAttribute(
          "data-kiosk-screen"
        ) === step;

      if (
        !active &&
        screen.contains(
          document.activeElement
        )
      ) {
        document.activeElement.blur();
      }

      screen.hidden = !active;

      screen.classList.toggle(
        "is-visible",
        active
      );

      screen.setAttribute(
        "aria-hidden",
        String(!active)
      );
    });

    stepButtons.forEach(button => {
      const target =
        button.getAttribute(
          "data-kiosk-step-jump"
        );

      button.classList.toggle(
        "is-active",
        target === step
      );
    });

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
        2 * Math.PI * 68;

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
      !state.processingStarted &&
      isValidWeight(state.weight) &&
      isValidHeight(state.height);

    button.disabled = !ready;

    if (state.processingStarted) {
      button.textContent =
        "Processing...";
    } else if (ready) {
      button.textContent =
        "Process Measurement";
    } else if (
      state.weightLocked &&
      !state.heightLocked
    ) {
      button.textContent =
        "Waiting for Height";
    } else if (
      !state.weightLocked &&
      state.heightLocked
    ) {
      button.textContent =
        "Waiting for Weight";
    } else {
      button.textContent =
        "Waiting for Measurement";
    }
  }

  function markMeasurementReady() {
    if (
      !isValidWeight(state.weight) ||
      !isValidHeight(state.height)
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
        "Measurement ready. Click Process Measurement to continue.";
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
          ? "Starting..."
          : deviceUnavailable
          ? "Device Offline"
          : "Start Measurement";
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

    if (refs.weightBars) {
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
        state.step !== "live"
      ) {
        setStep("live");
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
          "Please step on the platform...";
      }

      if (refs.heightStatus) {
        refs.heightStatus.textContent =
          "Please step on the platform...";
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
        state.step !== "live"
      ) {
        setStep("live");
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
            ? "Weight stable"
            : "Reading weight..."
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
            ? "Height stable"
            : "Reading height..."
        );
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

      if (
        state.weightLocked &&
        state.heightLocked
      ) {
        markMeasurementReady();
      }

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
        state.step !== "live"
      ) {
        setStep("live");
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
    // ERROR — never shown on Step 2. We advance to Step 3
    // (processing) and show the failure banner there instead.
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
    if (!firebaseEnabled) {
      setChip(
        connectedChip,
        "Device: Firebase Off",
        false
      );

      pushFeed(
        "Firebase unavailable",
        "Firebase is not configured.",
        "warn"
      );

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
          state.step !== "live"
        ) {
          setStep("live");
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
          !state.processingStarted
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

            setStep("live");

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
      pushFeed(
        "Start blocked",
        "Choose a child first.",
        "warn"
      );

      setStep("select");

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
      pushFeed(
        "Start blocked",
        "The kiosk device is offline. Check the ESP32 power and connection.",
        "error"
      );

      syncStartButtonState();

      return false;
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
      // LIVE SCREEN
      // ========================================================

      setStep("live");

      setProgress(
        10,
        "Please step on the platform now."
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
          "Please step on the platform...";
      }

      if (refs.heightStatus) {
        refs.heightStatus.textContent =
          "Please step on the platform...";
      }

      if (refs.weightBars) {
        refs.weightBars.innerHTML =
          "";
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

      setStep("select");

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
      !isValidWeight(
        state.weight
      ) ||
      !isValidHeight(
        state.height
      )
    ) {
      pushFeed(
        "Processing blocked",
        "Weight and height are not ready.",
        "warn"
      );

      return;
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
                sessionId
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
      "Preparing measurement..."
    );

    pushFeed(
      "Processing started",
      `${
        state.child.child_code ||
        "Child"
      } · Session #${sessionId} · calculating growth indicators`
    );

    const stages = [
      {
        progress: 70,
        message:
          "Calculating weight-for-age..."
      },

      {
        progress: 76,
        message:
          "Calculating height-for-age..."
      },

      {
        progress: 82,
        message:
          "Calculating weight-for-height..."
      },

      {
        progress: 88,
        message:
          "Classifying nutritional status..."
      },

      {
        progress: 94,
        message:
          "Saving measurement to SQL..."
      }
    ];

    let index = 0;

    if (state.processingTimer) {
      clearInterval(
        state.processingTimer
      );
    }

    state.processingTimer =
      setInterval(
        async () => {
          if (
            index <
            stages.length
          ) {
            const stageData =
              stages[index];

            setProgress(
              stageData.progress,
              stageData.message
            );

            index++;

            return;
          }

          clearInterval(
            state.processingTimer
          );

          state.processingTimer =
            null;

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

          await waitForBackendCompletion();
        },
        900
      );
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
          1000
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
    const ageMonths =
      measurement.age_months ??
      payload.age_months ??
      child?.age_months ??
      0;

    refs.resultMeta.textContent =
      `${ageMonths} months old`;
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
    // ALWAYS RETURN TO LIVE
    // ==========================================================

    setStep("live");

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

        setStep("live");

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
        "Waiting for HX711...";
    }

    if (refs.heightReadout) {
      refs.heightReadout.textContent =
        "--.-";
    }

    if (refs.heightStatus) {
      refs.heightStatus.textContent =
        "Waiting for TF-Luna...";
    }

    if (refs.heightBar) {
      refs.heightBar.style.width =
        "0%";
    }

    if (refs.weightBars) {
      refs.weightBars.innerHTML =
        "";
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

    if (refs.resultSource) {
      refs.resultSource.textContent =
        firebaseEnabled
          ? "ESP32 → Firebase → SQL"
          : "Firebase unavailable";
    }

    setProgress(
      0,
      "Ready to measure"
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
        "Select a child, then start the measurement.";
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
      isMeasurementActive()
    ) {
      pushFeed(
        "Reset blocked",
        "Wait for the active measurement to finish.",
        "warn"
      );

      return;
    }

    /*
     * A settled failure (phase === "error") is NOT "still
     * processing" — processingStarted stays true after a failure
     * so the Process button/step-jump guards don't reopen, but
     * that used to also block Reset itself, leaving the operator
     * stuck on the failed screen with no way out except waiting
     * for the whole session to time out. Only block Reset while
     * genuinely mid-flight (no error/complete outcome yet).
     */

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

            if (
              !getSelectedChild()
            ) {
              setStep("select");

              pushFeed(
                "Select child",
                "Choose a child before starting."
              );

              return;
            }

            startMeasurementFlow();

            return;
          }

          // ====================================================
          // PROCEED LIVE
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
          // BACK TO SELECT (from the Live screen) — deselects the
          // child and clears the pending session, same teardown as
          // a full reset, just landing on Select instead of Welcome.
          // ====================================================

          if (
            action ===
            "back-to-select"
          ) {
            event.preventDefault();

            if (
              isMeasurementActive()
            ) {
              pushFeed(
                "Back blocked",
                "Wait for the current measurement to finish.",
                "warn"
              );

              return;
            }

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

            clearSelectionAndSession(
              "select",
              "Back to selection",
              "Child deselected. Choose a child to continue."
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
    // STEP BUTTONS
    // ----------------------------------------------------------

    stepButtons.forEach(button => {
      button.addEventListener(
        "click",
        () => {
          const target =
            button.getAttribute(
              "data-kiosk-step-jump"
            );

          if (
            target === "select"
          ) {
            if (
              !isMeasurementActive() &&
              !state.processingStarted
            ) {
              setStep("select");
            }

            return;
          }

          if (
            target === "live"
          ) {
            /*
             * This tab used to only check state.session, which meant
             * a leftover/restored session let you jump straight to
             * the live screen with no regard for whether the device
             * is actually connected right now — the one path in the
             * UI that skipped the device-offline guard entirely.
             */
            if (
              state.session &&
              !state.processingStarted &&
              !(
                state.deviceStatusChecked &&
                !state.deviceOnline
              )
            ) {
              setStep("live");
            } else if (
              state.deviceStatusChecked &&
              !state.deviceOnline
            ) {
              pushFeed(
                "Live view unavailable",
                "The kiosk device is offline. Check the ESP32 power and connection.",
                "warn"
              );
            }

            return;
          }

          if (
            target === "processing"
          ) {
            pushFeed(
              "Step locked",
              "Click Process Measurement to continue.",
              "warn"
            );

            return;
          }

          if (
            target === "results"
          ) {
            pushFeed(
              "Step locked",
              "Results are available after processing.",
              "warn"
            );

            return;
          }
        }
      );
    });
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

    bindVisibilityHandling();

    bindUnloadHandling();

    startClock();

    /*
     * Check Firebase but do not block
     * kiosk initialization on Firebase.
     */

    checkFirebaseConnection();

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
        "Select a child, then start the measurement.";
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