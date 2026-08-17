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
    processingTimer: null,
    height: null,
    weight: null,
    status: null,
    submitting: false,
    awaitingLiveResult: false,
    firebaseSessionId: null,
    lastFirebaseTimestamp: "",
    deviceOnline: null,
    // Live Measurement (Step 2) stability tracking. Weight and Height are
    // captured together in the same session by the ESP32, so both are
    // tracked independently but shown on one combined screen.
    weightLocked: false,
    heightLocked: false,
    lastWeightRaw: null,
    lastHeightRaw: null,
    weightStableCount: 0,
    heightStableCount: 0,
  };

  const refs = {
    currentChildLabel: document.querySelector("[data-kiosk-current-child-label]"),
    heightReadout: document.querySelector("[data-kiosk-height-readout]"),
    heightStatus: document.querySelector("[data-kiosk-height-status]"),
    heightBar: document.querySelector("[data-kiosk-height-bar]"),
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

  // Tracks whether a live reading has settled ("locked") by comparing
  // consecutive Firebase updates. The ESP32 streams a running average while
  // it collects samples, so once a value stops moving beyond a small
  // tolerance for a couple of updates in a row, we treat it as stable.
  function updateStability(kind, value) {
    const isWeight = kind === "weight";
    const epsilon = isWeight ? 0.05 : 0.5;
    const lastKey = isWeight ? "lastWeightRaw" : "lastHeightRaw";
    const countKey = isWeight ? "weightStableCount" : "heightStableCount";
    const lockedKey = isWeight ? "weightLocked" : "heightLocked";

    const last = state[lastKey];
    if (last != null && Math.abs(value - last) <= epsilon) {
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

    const continueBtn = document.querySelector('[data-kiosk-action="proceed-live"]');
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
    const weight = payload.weight_kg == null ? NaN : Number(payload.weight_kg);
    const height = payload.height_cm == null ? NaN : Number(payload.height_cm);
    const hasWeight = Number.isFinite(weight) && weight >= 0;
    const hasHeight = Number.isFinite(height) && height >= 0;

    // The ESP32 measures weight (HX711) and height (TF-Luna) together during
    // the same 5-second sampling window and streams both readings under a
    // single "MEASURING" status. The kiosk shows them on one combined Live
    // Measurement screen instead of separate steps. Older firmware strings
    // are still accepted here for backward compatibility.
    if (
      status === "MEASURING" ||
      status === "WEIGHT_MEASURING" ||
      status === "HEIGHT_MEASURING"
    ) {
      state.phase = "live";
      setStep("live");
      if (refs.sidebarStatus) refs.sidebarStatus.textContent = "Measuring";

      if (hasWeight) {
        const locked = updateStability("weight", weight);
        setWeight(weight, locked ? "Weight locked" : "Reading weight...");
      } else if (refs.weightStatus && !state.weightLocked) {
        refs.weightStatus.textContent = "Waiting for sensor...";
      }

      if (hasHeight) {
        const locked = updateStability("height", height);
        setHeight(height, locked ? "Height locked" : "Reading height...");
      } else if (refs.heightStatus && !state.heightLocked) {
        refs.heightStatus.textContent = "Waiting for sensor...";
      }

      const progress =
        20 + (state.weightLocked ? 15 : 0) + (state.heightLocked ? 15 : 0);
      setProgress(progress, "Capturing weight and height...");
      return;
    }

    if (status === "COMPLETE") {
      if (hasWeight) {
        state.weightLocked = true;
        setWeight(weight, "Weight locked");
      }
      if (hasHeight) {
        state.heightLocked = true;
        setHeight(height, "Height locked");
      }

      state.phase = "processing";
      state.submitting = false;
      state.awaitingLiveResult = false;

      if (state.firebaseTimer) {
        clearInterval(state.firebaseTimer);
        state.firebaseTimer = null;
      }

      // Both readings are captured and the ESP32 has already finished
      // saving the measurement. Move to Processing, then automatically
      // reveal the Result screen without any manual step navigation.
      setStep("processing");
      setProgress(85, "Calculating nutritional indicators...");
      if (refs.sidebarStatus) refs.sidebarStatus.textContent = "Calculating";
      pushFeed(
        "Measurement captured",
        `${payload.child_code || "Child"} · ${hasWeight ? weight.toFixed(2) : "--"} kg · ${hasHeight ? height.toFixed(1) : "--"} cm`
      );

      if (state.processingTimer) clearTimeout(state.processingTimer);
      state.processingTimer = setTimeout(() => {
        state.processingTimer = null;
        setProgress(100, "Measurement complete");

        if (refs.resultChild) refs.resultChild.textContent = payload.child_name || formatLabel(getSelectedChild());
        if (refs.resultMeta) refs.resultMeta.textContent = `${getSelectedChild()?.age_months || 0} months old`;
        if (refs.resultHeight) refs.resultHeight.textContent = hasHeight ? `${height.toFixed(1)} cm` : "--.- cm";
        if (refs.resultWeight) refs.resultWeight.textContent = hasWeight ? `${weight.toFixed(2)} kg` : "--.-- kg";
        if (refs.resultStatus) refs.resultStatus.textContent = payload.nutritional_status || "Pending";
        if (refs.resultWaz) refs.resultWaz.textContent = payload.waz != null ? Number(payload.waz).toFixed(2) : "--";
        if (refs.resultHaz) refs.resultHaz.textContent = payload.haz != null ? Number(payload.haz).toFixed(2) : "--";
        if (refs.resultWhz) refs.resultWhz.textContent = payload.whz != null ? Number(payload.whz).toFixed(2) : "--";
        if (refs.resultSource) refs.resultSource.textContent = `ESP32 → Firebase → SQL`;

        if (refs.sidebarStatus) refs.sidebarStatus.textContent = "Complete";
        if (refs.sidebarResult) refs.sidebarResult.textContent = payload.nutritional_status || "Complete";

        setStep("results");
        pushFeed(
          "Measurement complete",
          `${payload.child_code || "Child"} · ${hasWeight ? weight.toFixed(2) : "--"} kg · ${hasHeight ? height.toFixed(1) : "--"} cm`
        );
      }, 1200);

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

      // The actual sensor sequence begins in the ESP32, which captures
      // weight and height together in one pass. The kiosk now waits for
      // Firebase's MEASURING state and shows both live on Step 2.
      setStep("live");
      setProgress(20, "Starting live measurement...");
      if (refs.weightStatus) refs.weightStatus.textContent = "Waiting for sensor...";
      if (refs.heightStatus) refs.heightStatus.textContent = "Waiting for sensor...";
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
    if (state.processingTimer) clearTimeout(state.processingTimer);

    state.statusTimer = null;
    state.firebaseTimer = null;
    state.processingTimer = null;
    state.session = null;
    state.phase = "idle";
    state.submitting = false;
    state.awaitingLiveResult = false;
    state.firebaseSessionId = null;
    state.lastFirebaseTimestamp = "";
    state.height = null;
    state.weight = null;
    state.status = null;
    state.child = null;
    state.weightLocked = false;
    state.heightLocked = false;
    state.lastWeightRaw = null;
    state.lastHeightRaw = null;
    state.weightStableCount = 0;
    state.heightStableCount = 0;

    childCards.forEach((card) => card.classList.remove("is-selected"));
    if (refs.currentChildLabel) refs.currentChildLabel.textContent = "Choose a child";
    if (refs.sidebarChild) refs.sidebarChild.textContent = "None selected";
    if (refs.sidebarParent) refs.sidebarParent.textContent = "---";
    if (refs.sidebarBarangay) refs.sidebarBarangay.textContent = "---";

    const selectContinueBtn = document.querySelector('[data-kiosk-action="proceed-live"]');
    if (selectContinueBtn) selectContinueBtn.disabled = true;

    if (searchInput) searchInput.value = "";
    childCards.forEach((card) => { card.hidden = false; });

    if (refs.weightReadout) refs.weightReadout.textContent = "--.--";
    if (refs.weightStatus) refs.weightStatus.textContent = "Waiting for sensor...";
    if (refs.heightReadout) refs.heightReadout.textContent = "--.-";
    if (refs.heightStatus) refs.heightStatus.textContent = "Waiting for sensor...";
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

    if (resetButton) {
      resetButton.addEventListener("click", resetKioskToIdle);
    }

    actionButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const action = button.getAttribute("data-kiosk-action");

        if (action === "start") {
          if (!getSelectedChild()) {
            setStep("select");
            return;
          }
          startMeasurementFlow();
        }

        // Continue from Select Child actually starts the ESP32 session
        // (creates the measurement_sessions row and begins Firebase polling).
        if (action === "proceed-live") {
          if (getSelectedChild()) startMeasurementFlow();
          else setStep("select");
        }

        // The ESP32 controls actual scanning after Start Measurement.
        // Weight and height are captured together automatically, so this
        // button is informational/navigation only.
        if (action === "start-live") {
          pushFeed("Waiting", "Press Start Measurement on the kiosk to begin.", "warn");
        }

        if (action === "back-select") setStep("select");
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

  setStep("welcome");
  if (heroNote) heroNote.textContent = "Select a child, then start the measurement.";
  refreshMeasurementStatus(false);
})();