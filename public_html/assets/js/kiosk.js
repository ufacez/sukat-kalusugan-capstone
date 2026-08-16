(function () {
  const data = window.KIOSK_DATA || {};
  const body = document.body;

  const welcomeScreen = document.querySelector('[data-kiosk-screen="welcome"]');
  const stepBar = document.querySelector(".kiosk-stepbar");
  const stage = document.querySelector(".kiosk-stage");
  const screens = Array.from(document.querySelectorAll("[data-kiosk-screen]"));
  const stepButtons = Array.from(document.querySelectorAll("[data-kiosk-step-jump]"));
  const actionButtons = Array.from(document.querySelectorAll("[data-kiosk-action]"));
  const childCards = Array.from(document.querySelectorAll("[data-kiosk-child-card]"));
  const searchInput = document.querySelector("[data-kiosk-search]");
  const clock = document.querySelector("[data-kiosk-clock]");
  const feed = document.querySelector("[data-kiosk-feed]");
  const welcomeClock = document.querySelector("[data-kiosk-live-clock]");
  const welcomeDate = document.querySelector("[data-kiosk-live-date]");
  const heroNote = document.querySelector(".kiosk-hero-note");
  const startButton = document.querySelector('[data-kiosk-action="start"]');
  const resetButton = document.querySelector('[data-kiosk-action="reset"]');

  const firebaseBaseUrl =
    typeof data?.firebase?.databaseUrl === "string"
      ? data.firebase.databaseUrl.trim()
      : "";
  const firebaseEnabled = !!data?.firebase?.enabled && firebaseBaseUrl !== "";

  const state = {
    step: "welcome",
    child: null,
    session: null,
    phase: "idle",
    statusTimer: null,
    firebaseTimer: null,
    height: null,
    weight: null,
    status: null,
    submitting: false,
    awaitingLiveResult: false,
    firebaseSessionId: null,
    lastFirebaseTimestamp: "",
    deviceOnline: null,
  };

  const refs = {
    currentChildLabel: document.querySelector("[data-kiosk-current-child-label]"),
    heightReadout: document.querySelector("[data-kiosk-height-readout]"),
    heightStatus: document.querySelector("[data-kiosk-height-status]"),
    heightBar: document.querySelector("[data-kiosk-height-bar]"),
    heightFinal: document.querySelector("[data-kiosk-height-final]"),
    weightReadout: document.querySelector("[data-kiosk-weight-readout]"),
    weightStatus: document.querySelector("[data-kiosk-weight-status]"),
    weightBars: document.querySelector("[data-kiosk-weight-bars]"),
    progressValue: document.querySelector("[data-kiosk-progress-value]"),
    processStage: document.querySelector("[data-kiosk-process-stage]"),
    progressRing: document.querySelector("[data-kiosk-progress-ring]"),
    resultChild: document.querySelector("[data-kiosk-result-child]"),
    resultMeta: document.querySelector("[data-kiosk-result-meta]"),
    resultStatus: document.querySelector("[data-kiosk-result-status]"),
    resultHeight: document.querySelector("[data-kiosk-result-height]"),
    resultWeight: document.querySelector("[data-kiosk-result-weight]"),
    resultWaz: document.querySelector("[data-kiosk-result-waz]"),
    resultHaz: document.querySelector("[data-kiosk-result-haz]"),
    resultWhz: document.querySelector("[data-kiosk-result-whz]"),
    resultSource: document.querySelector("[data-kiosk-result-source]"),
    sidebarChild: document.querySelector("[data-kiosk-sidebar-child]"),
    sidebarParent: document.querySelector("[data-kiosk-sidebar-parent]"),
    sidebarBarangay: document.querySelector("[data-kiosk-sidebar-barangay]"),
    sidebarResult: document.querySelector("[data-kiosk-sidebar-result]"),
    sidebarStatus: document.querySelector("[data-kiosk-sidebar-status]"),
    sessionId: document.querySelector("[data-kiosk-session-id]"),
    sessionStatus: document.querySelector("[data-kiosk-session-status]"),
    sessionStarted: document.querySelector("[data-kiosk-session-started]"),
  };

  const children =
    Array.isArray(data.children) && data.children.length
      ? data.children
      : Array.from(document.querySelectorAll("[data-kiosk-child-card]"))
          .map((card) => ({
            id: Number(card.dataset.childId || 0),
            child_code: card.querySelector(".kiosk-child-code")?.textContent.trim() || "",
            first_name: card.querySelector(".kiosk-child-name")?.textContent.trim() || "",
            last_name: "",
            age_months: 0,
            sex: "Male",
            barangay: "",
            parent_name: "",
          }))
          .filter((c) => c.id > 0);

  const deviceId = data?.defaults?.deviceId || "ESP32-KIOSK-01";

  function escapeHtml(text) {
    return String(text)
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

  function pushFeed(action, detail, level = "info") {
    if (!feed) return;
    const row = document.createElement("div");
    row.className = "kiosk-feed-row";
    row.dataset.level = level;
    row.innerHTML = `
      <span class="kiosk-feed-time">${formatNow()}</span>
      <strong>${escapeHtml(action)}</strong>
      <span>${escapeHtml(detail)}</span>
    `;
    feed.prepend(row);
    while (feed.children.length > 8) feed.removeChild(feed.lastElementChild);
  }

  function formatLabel(child) {
    if (!child) return "Choose a child";
    return `${child.first_name || ""} ${child.last_name || ""}`.trim();
  }

  function getSelectedChild() {
    return state.child || null;
  }

  function updateSessionInfo(session) {
    if (!session) {
      if (refs.sessionId) refs.sessionId.textContent = "—";
      if (refs.sessionStatus) refs.sessionStatus.textContent = "Idle";
      if (refs.sessionStarted) refs.sessionStarted.textContent = "—";
      return;
    }

    if (refs.sessionId)
      refs.sessionId.textContent = String(session.session_id || "—");
    if (refs.sessionStatus)
      refs.sessionStatus.textContent = String(session.status || session.state || "IDLE");
    if (refs.sessionStarted)
      refs.sessionStarted.textContent = session.started_at
        ? new Date(session.started_at).toLocaleString()
        : "—";
  }

  function setStep(step) {
    state.step = step;
    body.dataset.kioskStep = step;

    if (welcomeScreen) welcomeScreen.hidden = step !== "welcome";
    if (stepBar) stepBar.hidden = step === "welcome";
    if (stage) stage.hidden = step === "welcome";

    screens.forEach((screen) => {
      const active = screen.getAttribute("data-kiosk-screen") === step;
      screen.hidden = !active;
      screen.classList.toggle("is-visible", active);
      screen.setAttribute("aria-hidden", String(!active));
    });

    stepButtons.forEach((button) => {
      const target = button.getAttribute("data-kiosk-step-jump");
      button.classList.toggle("is-active", target === step);
    });
  }

  function setProgress(progress, message) {
    const p = Math.max(0, Math.min(100, Number(progress) || 0));
    if (refs.progressValue) refs.progressValue.textContent = `${Math.round(p)}%`;
    if (refs.progressRing)
      refs.progressRing.style.strokeDashoffset = `${427 - p * 4.27}`;
    if (refs.processStage && message) refs.processStage.textContent = message;
    if (heroNote && message) heroNote.textContent = message;
  }

  function setWeight(value, message = "Weight received") {
    const weight = Number(value);
    if (!Number.isFinite(weight) || weight < 0 || weight > 300) return false;

    state.weight = weight;

    if (refs.weightReadout) refs.weightReadout.textContent = weight.toFixed(2);
    if (refs.weightStatus) refs.weightStatus.textContent = message;

    // Small visual activity indicator.
    if (refs.weightBars) {
      refs.weightBars.innerHTML = "";
      for (let i = 0; i < 8; i++) {
        const bar = document.createElement("span");
        bar.style.height = `${20 + Math.min(70, weight) * (0.45 + i * 0.06)}%`;
        refs.weightBars.appendChild(bar);
      }
    }

    return true;
  }

  function setHeight(value, message = "Height received") {
    const height = Number(value);
    if (!Number.isFinite(height) || height < 0 || height > 300) return false;

    state.height = height;

    if (refs.heightReadout) refs.heightReadout.textContent = height.toFixed(1);
    if (refs.heightFinal) refs.heightFinal.textContent = `${height.toFixed(1)} cm`;
    if (refs.heightStatus) refs.heightStatus.textContent = message;
    if (refs.heightBar) refs.heightBar.style.width = `${Math.min(100, Math.max(0, height / 2.5))}%`;

    return true;
  }

  // External sensor API is kept for future direct ESP32/browser integrations.
  window.kioskUpdateSensor = function (payload = {}) {
    if (payload.weight != null) setWeight(payload.weight);
    if (payload.height != null) setHeight(payload.height);
  };

  function selectChild(childId) {
    if (isMeasurementActive()) {
      pushFeed("Selection locked", "Wait for the current measurement to finish.", "warn");
      return;
    }

    state.child =
      children.find((entry) => String(entry.id) === String(childId)) || null;

    childCards.forEach((card) => {
      card.classList.toggle(
        "is-selected",
        String(card.dataset.childId) === String(childId)
      );
    });

    const child = getSelectedChild();

    if (refs.currentChildLabel)
      refs.currentChildLabel.textContent = formatLabel(child);
    if (refs.sidebarChild)
      refs.sidebarChild.textContent = formatLabel(child) || "None selected";
    if (refs.sidebarParent)
      refs.sidebarParent.textContent = child?.parent_name || "---";
    if (refs.sidebarBarangay)
      refs.sidebarBarangay.textContent = child?.barangay || "---";
    if (refs.sidebarStatus)
      refs.sidebarStatus.textContent = child ? "Ready" : "Waiting";

    const continueBtn = document.querySelector('[data-kiosk-action="proceed-height"]');
    if (continueBtn) continueBtn.disabled = !child;

    if (child) {
      pushFeed("Child selected", `${child.child_code || "Child"} · ${formatLabel(child)}`);
    }
  }

  window.kioskSelectChild = selectChild;

  function isMeasurementActive(session = state.session) {
    return !!session &&
      ["START_REQUESTED", "MEASURING"].includes(String(session.status || ""));
  }

  function syncStartButtonState() {
    if (!startButton) return;
    startButton.disabled = state.submitting || isMeasurementActive();
    startButton.textContent = state.submitting
      ? "Starting..."
      : "Start Measurement";
  }

  function firebaseLatestMeasurementUrl() {
    if (!firebaseEnabled) return "";
    return `${firebaseBaseUrl.replace(/\/$/, "")}/latest_measurements/${encodeURIComponent(deviceId)}.json`;
  }

  function applyFirebaseStatus(payload) {
    if (!payload || typeof payload !== "object") return;

    const sessionId = Number(payload.session_id || 0);
    if (state.firebaseSessionId && sessionId && sessionId !== state.firebaseSessionId) {
      return;
    }

    const status = String(payload.status || "").toUpperCase();
    const weight = Number(payload.weight_kg);
    const height = Number(payload.height_cm);

    if (Number.isFinite(weight) && weight >= 0) setWeight(weight);
    if (Number.isFinite(height) && height >= 0) setHeight(height);

    if (status === "MEASURING" || status === "WEIGHT_MEASURING") {
      state.phase = "weight";
      setStep("weight");
      setProgress(25, "Scanning weight...");
      if (refs.weightStatus) refs.weightStatus.textContent = "Place both feet on the platform.";
      if (refs.sidebarStatus) refs.sidebarStatus.textContent = "Measuring weight";
      return;
    }

    if (status === "HEIGHT_MEASURING") {
      state.phase = "height";
      setStep("height");
      setProgress(50, "Scanning height...");
      if (refs.heightStatus) refs.heightStatus.textContent = "Stand straight and look forward.";
      if (refs.sidebarStatus) refs.sidebarStatus.textContent = "Measuring height";
      return;
    }

    if (status === "PROCESSING") {
      state.phase = "processing";
      setStep("processing");
      setProgress(80, "Calculating nutritional indicators...");
      if (refs.sidebarStatus) refs.sidebarStatus.textContent = "Calculating";
      return;
    }

    if (status === "COMPLETE") {
      if (Number.isFinite(weight)) setWeight(weight, "Weight locked");
      if (Number.isFinite(height)) setHeight(height, "Height locked");

      state.phase = "complete";
      state.submitting = false;
      state.awaitingLiveResult = false;

      if (refs.resultChild) refs.resultChild.textContent = payload.child_name || formatLabel(getSelectedChild());
      if (refs.resultMeta) refs.resultMeta.textContent = `${getSelectedChild()?.age_months || 0} months old`;
      if (refs.resultHeight) refs.resultHeight.textContent = Number.isFinite(height) ? `${height.toFixed(1)} cm` : "--.- cm";
      if (refs.resultWeight) refs.resultWeight.textContent = Number.isFinite(weight) ? `${weight.toFixed(2)} kg` : "--.-- kg";
      if (refs.resultStatus) refs.resultStatus.textContent = payload.nutritional_status || "Pending";
      if (refs.resultWaz) refs.resultWaz.textContent = payload.waz != null ? Number(payload.waz).toFixed(2) : "--";
      if (refs.resultHaz) refs.resultHaz.textContent = payload.haz != null ? Number(payload.haz).toFixed(2) : "--";
      if (refs.resultWhz) refs.resultWhz.textContent = payload.whz != null ? Number(payload.whz).toFixed(2) : "--";
      if (refs.resultSource) refs.resultSource.textContent = `ESP32 → Firebase → SQL`;

      setProgress(100, "Measurement complete");
      if (refs.sidebarStatus) refs.sidebarStatus.textContent = "Complete";
      if (refs.sidebarResult) refs.sidebarResult.textContent = payload.nutritional_status || "Complete";

      setStep("results");
      pushFeed("Measurement complete", `${payload.child_code || "Child"} · ${Number.isFinite(weight) ? weight.toFixed(2) : "--"} kg · ${Number.isFinite(height) ? height.toFixed(1) : "--"} cm`);

      if (state.firebaseTimer) {
        clearInterval(state.firebaseTimer);
        state.firebaseTimer = null;
      }
      return;
    }

    if (status === "ERROR" || status === "CANCELLED") {
      state.phase = "error";
      state.submitting = false;
      if (refs.resultStatus) refs.resultStatus.textContent = "Error";
      if (refs.sidebarStatus) refs.sidebarStatus.textContent = "Error";
      setStep("processing");
      setProgress(100, payload.error_message || "Measurement error");
    }
  }

  async function refreshFirebaseLatestMeasurement() {
    if (!firebaseEnabled || !state.awaitingLiveResult) return null;

    const url = firebaseLatestMeasurementUrl();
    if (!url) return null;

    try {
      const response = await fetch(url, { cache: "no-store" });
      if (!response.ok) return null;

      const payload = await response.json();
      if (!payload || typeof payload !== "object") return null;

      const sessionId = Number(payload.session_id || 0);
      if (state.firebaseSessionId && sessionId && sessionId !== state.firebaseSessionId) {
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
      return null;
    }
  }

  function startFirebasePolling() {
    if (!firebaseEnabled) {
      pushFeed("Firebase unavailable", "The kiosk cannot receive live sensor readings.", "warn");
      return;
    }

    if (state.firebaseTimer) clearInterval(state.firebaseTimer);

    state.firebaseTimer = setInterval(
      refreshFirebaseLatestMeasurement,
      Number(data?.defaults?.pollSeconds || 1) * 1000
    );

    refreshFirebaseLatestMeasurement();
  }

  async function refreshMeasurementStatus(scheduleNext = true) {
    if (!state.session) return null;

    try {
      const endpoint =
        data?.endpoints?.measurementStatus ||
        "../api/kiosk/measurement_status.php";

      const url = new URL(endpoint, window.location.href);
      url.searchParams.set("device_id", deviceId);

      const response = await fetch(url.toString(), { cache: "no-store" });
      const json = await response.json().catch(() => ({}));
      const payload = json?.data || {};

      if (!response.ok || json?.success !== true) {
        throw new Error(json?.message || "Unable to load measurement status");
      }

      state.session = payload;
      updateSessionInfo(payload);

      if (payload.status === "ERROR" || payload.status === "CANCELLED") {
        applyFirebaseStatus({
          status: "ERROR",
          error_message: payload.error_message || "Measurement failed",
          session_id: payload.session_id,
        });
        return payload;
      }

      if (scheduleNext && isMeasurementActive(payload)) {
        state.statusTimer = setTimeout(
          () => refreshMeasurementStatus(true),
          Number(data?.defaults?.pollSeconds || 1) * 1000
        );
      }

      return payload;
    } catch (error) {
      if (scheduleNext && isMeasurementActive(state.session)) {
        state.statusTimer = setTimeout(
          () => refreshMeasurementStatus(true),
          Number(data?.defaults?.pollSeconds || 1) * 1000
        );
      }
      return null;
    }
  }

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
        headers: { "Content-Type": "application/json" },
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
      state.firebaseSessionId = Number(payload.session_id || 0) || null;
      state.awaitingLiveResult = true;
      state.lastFirebaseTimestamp = "";
      state.submitting = false;

      updateSessionInfo(payload);
      syncStartButtonState();

      // The actual sensor sequence begins in the ESP32.
      // The kiosk now waits for Firebase's WEIGHT_MEASURING state.
      setStep("weight");
      setProgress(20, "Starting weight measurement...");
      if (refs.weightStatus) refs.weightStatus.textContent = "Waiting for the scale...";
      if (refs.sidebarStatus) refs.sidebarStatus.textContent = "Starting";

      pushFeed(
        "Measurement started",
        `${child.child_code || "Child"} · waiting for ESP32`
      );

      startFirebasePolling();
      refreshMeasurementStatus(true);
      return true;
    } catch (error) {
      state.submitting = false;
      syncStartButtonState();
      pushFeed("Start failed", error.message || "Unable to contact server", "error");
      setStep("select");
      return false;
    }
  }

  function resetKioskToIdle() {
    if (isMeasurementActive()) {
      pushFeed("Reset blocked", "Wait for the current measurement to finish.", "warn");
      return;
    }

    if (state.statusTimer) clearTimeout(state.statusTimer);
    if (state.firebaseTimer) clearInterval(state.firebaseTimer);

    state.statusTimer = null;
    state.firebaseTimer = null;
    state.session = null;
    state.phase = "idle";
    state.submitting = false;
    state.awaitingLiveResult = false;
    state.firebaseSessionId = null;
    state.lastFirebaseTimestamp = "";
    state.height = null;
    state.weight = null;
    state.status = null;

    if (refs.weightReadout) refs.weightReadout.textContent = "--.--";
    if (refs.weightStatus) refs.weightStatus.textContent = "Ready to measure weight";
    if (refs.heightReadout) refs.heightReadout.textContent = "--.-";
    if (refs.heightStatus) refs.heightStatus.textContent = "Ready to measure height";
    if (refs.heightFinal) refs.heightFinal.textContent = "--.- cm";
    if (refs.resultHeight) refs.resultHeight.textContent = "--.- cm";
    if (refs.resultWeight) refs.resultWeight.textContent = "--.-- kg";
    if (refs.resultWaz) refs.resultWaz.textContent = "--";
    if (refs.resultHaz) refs.resultHaz.textContent = "--";
    if (refs.resultWhz) refs.resultWhz.textContent = "--";
    if (refs.resultStatus) refs.resultStatus.textContent = "Pending";
    if (refs.resultSource) refs.resultSource.textContent = "ESP32 → Firebase → SQL";
    if (refs.sidebarStatus) refs.sidebarStatus.textContent = "Waiting";
    if (refs.sidebarResult) refs.sidebarResult.textContent = "---";

    updateSessionInfo(null);
    setProgress(0, "Ready to measure");
    setStep("welcome");
    syncStartButtonState();

    pushFeed("Kiosk reset", "Ready for the next child.");
  }

  function bindEvents() {
    if (searchInput) {
      searchInput.addEventListener("input", () => {
        const term = searchInput.value.trim().toLowerCase();
        childCards.forEach((card) => {
          const text = (card.dataset.filterText || "").toLowerCase();
          card.hidden = !text.includes(term);
        });
      });
    }

    childCards.forEach((card) => {
      card.addEventListener("click", () => {
        selectChild(card.dataset.childId);
      });
    });

    if (startButton) {
      startButton.addEventListener("click", () => {
        // Welcome button only opens child selection.
        // It must NEVER create a measurement session.
        setStep("select");
        pushFeed("Measurement setup", "Select a child first.");
      });
    }

    if (resetButton) {
      resetButton.addEventListener("click", resetKioskToIdle);
    }

    actionButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const action = button.getAttribute("data-kiosk-action");

        if (action === "start") {
          setStep("select");
          pushFeed("Measurement setup", "Select a child first.");
        }

        // Child selection's Continue now only moves to the weight screen.
        // It does NOT start a sensor session.
        if (action === "proceed-height") {
          if (getSelectedChild()) setStep("weight");
          else setStep("select");
        }

        // The ESP32 controls actual scanning after Start Measurement.
        // These buttons are informational/navigation only.
        if (action === "start-weight") {
          // THIS is the only kiosk button that creates a server session.
          startMeasurementFlow();
        }

        if (action === "start-height") {
          pushFeed("Waiting", "Height starts automatically after weight is locked.", "info");
        }

        if (action === "back-select") setStep("select");
        if (action === "back-height") setStep("height");
        if (action === "reset") resetKioskToIdle();
      });
    });

    syncStartButtonState();
  }

  if (clock) {
    const tick = () => (clock.textContent = formatNow());
    tick();
    setInterval(tick, 1000);
  }

  if (welcomeClock) {
    const tick = () => {
      welcomeClock.textContent = formatNow();
      if (welcomeDate) welcomeDate.textContent = formatDate();
    };
    tick();
    setInterval(tick, 1000);
  }

  bindEvents();

  // Do not auto-select a child. The operator must choose one.
  selectChild(null);

  setStep("welcome");
  if (heroNote) heroNote.textContent = "Select a child, then start the measurement.";
  refreshMeasurementStatus(false);
})();
