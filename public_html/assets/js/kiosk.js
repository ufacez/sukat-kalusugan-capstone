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
  const heroSubcopy = document.querySelector(".kiosk-hero-subcopy");
  const startButton = document.querySelector('[data-kiosk-action="start"]');
  const resetButton = document.querySelector('[data-kiosk-action="reset"]');
  const firebaseBaseUrl = typeof data?.firebase?.databaseUrl === 'string' ? data.firebase.databaseUrl.trim() : '';
  const firebaseEnabled = !!data?.firebase?.enabled && firebaseBaseUrl !== '';

  const state = {
    step: "welcome",
    child: null,
    session: null,
    phase: "idle",
    statusTimer: null,
    firebaseTimer: null,
    height: null,
    weight: null,
    processTimer: null,
    heightTimer: null,
    weightTimer: null,
    status: null,
    progress: 0,
    scanInFlight: false,
    submitting: false,
    awaitingLiveResult: false,
    firebaseSessionId: null,
    lastFirebaseTimestamp: '',
  };

  const stageLabels = [
    "Validating sensor data...",
    "Applying WHO 2006 standards...",
    "Computing WAZ, HAZ, and WHZ...",
    "Classifying nutritional status...",
    "Syncing to eOPT+ / cloud endpoint...",
    "Complete!",
  ];

  function buildChildrenFromDom() {
    return Array.from(document.querySelectorAll('[data-kiosk-child-card]')).map((card) => {
      const id = Number(card.dataset.childId || 0);
      const nameNode = card.querySelector('.kiosk-child-name');
      const metaNode = card.querySelector('.kiosk-child-meta');
      const codeNode = card.querySelector('.kiosk-child-code');
      const fullName = nameNode ? nameNode.textContent.trim() : '';
      const nameParts = fullName.split(/\s+/).filter(Boolean);
      const firstName = nameParts[0] || '';
      const lastName = nameParts.slice(1).join(' ');
      const metaText = metaNode ? metaNode.textContent.trim() : '';
      const ageMatch = metaText.match(/(\d+)\s*months/i);
      const sexMatch = metaText.match(/·\s*([^\n]+)/i);

      return {
        id,
        child_code: codeNode ? codeNode.textContent.trim() : '',
        first_name: firstName,
        last_name: lastName,
        sex: sexMatch ? sexMatch[1].trim() : 'Male',
        age_months: ageMatch ? Number(ageMatch[1]) : 0,
        barangay: '',
        parent_name: '',
        status: 'Pending',
      };
    }).filter((child) => child.id > 0);
  }

  const children = Array.isArray(data.children) && data.children.length ? data.children : buildChildrenFromDom();
  const deviceId = data?.defaults?.deviceId || "ESP32-KIOSK-01";
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
    processList: document.querySelector("[data-kiosk-process-list]"),
    sidebarChild: document.querySelector("[data-kiosk-sidebar-child]"),
    sidebarParent: document.querySelector("[data-kiosk-sidebar-parent]"),
    sidebarBarangay: document.querySelector("[data-kiosk-sidebar-barangay]"),
    sidebarResult: document.querySelector("[data-kiosk-sidebar-result]"),
    sidebarStatus: document.querySelector("[data-kiosk-sidebar-status]"),
    sessionId: document.querySelector('[data-kiosk-session-id]'),
    sessionStatus: document.querySelector('[data-kiosk-session-status]'),
    sessionStarted: document.querySelector('[data-kiosk-session-started]'),
    childGrid: document.querySelector("[data-kiosk-child-grid]"),
  };

  function updateSessionInfo(session) {
    if (!session) {
      if (refs.sessionId) refs.sessionId.textContent = '—';
      if (refs.sessionStatus) refs.sessionStatus.textContent = 'Idle';
      if (refs.sessionStarted) refs.sessionStarted.textContent = '—';
      return;
    }

    if (refs.sessionId) refs.sessionId.textContent = String(session.session_id || session.sessionId || session.id || '—');
    if (refs.sessionStatus) refs.sessionStatus.textContent = String(session.status || session.state || 'IDLE');
    if (refs.sessionStarted) refs.sessionStarted.textContent = session.started_at ? new Date(session.started_at).toLocaleString() : '—';
  }

  // Expose an API for external sensor feeds (Arduino / Firebase / ESP32)
  window.kioskUpdateSensor = function (payload = {}) {
    try {
      if (payload.height != null) {
        const value = Number(payload.height);
        if (!Number.isFinite(value) || value < 40 || value > 140) {
          if (refs.heightStatus) refs.heightStatus.textContent = 'Invalid height — retry scan';
          return;
        }

        state.height = value;
        if (refs.heightReadout) refs.heightReadout.textContent = state.height.toFixed(1);
        if (refs.heightFinal) refs.heightFinal.textContent = `${state.height.toFixed(1)} cm`;
        if (refs.heightStatus) refs.heightStatus.textContent = 'Height received';
        pushFeed('Sensor', `Height ${state.height.toFixed(1)} cm`);
      }

      if (payload.weight != null) {
        const value = Number(payload.weight);
        if (!Number.isFinite(value) || value < 2 || value > 80) {
          if (refs.weightStatus) refs.weightStatus.textContent = 'Invalid weight — retry scan';
          return;
        }

        state.weight = value;
        if (refs.weightReadout) refs.weightReadout.textContent = state.weight.toFixed(2);
        if (refs.weightStatus) refs.weightStatus.textContent = 'Weight received';
        pushFeed('Sensor', `Weight ${state.weight.toFixed(2)} kg`);
      }

      if (payload.device_id) {
        // update device id used in results/source label
        // note: not persisted; prepared for future integration
      }
    } catch (e) {
      // noop
    }
  };

  // Simple helper to update a header chip safely
  function setChip(chipEl, text, ok) {
    if (!chipEl) return;
    chipEl.innerHTML = `<span class="kiosk-dot"></span> ${text}`;
    chipEl.classList.toggle('is-success', !!ok);
  }

  function firebaseLatestMeasurementUrl() {
    if (!firebaseEnabled) {
      return '';
    }

    const normalizedBase = firebaseBaseUrl.replace(/\/$/, '');
    return `${normalizedBase}/latest_measurements/${encodeURIComponent(deviceId)}.json`;
  }

  // Poll the ESP32 ping endpoint (if provided) to update connection status and optionally receive sensor readings
  if (data.endpoints && data.endpoints.ping) {
    const pingUrl = data.endpoints.ping;
    const pingChip = document.querySelector('[data-kiosk-chip-connected]');
    const lidarChip = document.querySelector('[data-kiosk-chip-lidar]');
    const loadChip = document.querySelector('[data-kiosk-chip-loadcell]');

    async function doPing() {
      try {
        const res = await fetch(pingUrl + '?device=' + encodeURIComponent(deviceId), { cache: 'no-store' });
        const json = await res.json();
        const payload = json && json.data ? json.data : {};
        const ok = json && json.success === true && (payload.status === 'online' || payload.connected === true);
        // update live device online state for UI checks
        state.deviceOnline = !!ok;

        setChip(pingChip, ok ? 'Device online' : 'Waiting for device', ok);
        if (payload.lidar_status != null) {
          setChip(lidarChip, payload.lidar_status === 'ready' ? 'LiDAR ready' : 'Waiting for LiDAR', payload.lidar_status === 'ready');
        } else {
          setChip(lidarChip, 'Waiting for LiDAR', false);
        }
        if (payload.loadcell_status != null) {
          setChip(loadChip, payload.loadcell_status === 'ready' ? 'Scale ready' : 'Waiting for scale', payload.loadcell_status === 'ready');
        } else {
          setChip(loadChip, 'Waiting for scale', false);
        }

        if (payload.height != null || payload.weight != null) {
          window.kioskUpdateSensor({ height: payload.height, weight: payload.weight, device_id: payload.device_id });
        }
      } catch (e) {
        setChip(pingChip, 'Waiting for device', false);
        setChip(lidarChip, 'Waiting for LiDAR', false);
        setChip(loadChip, 'Waiting for scale', false);
        state.deviceOnline = false;
      }
    }

    setTimeout(doPing, 800);
    setInterval(doPing, (data.defaults?.syncSeconds || 15) * 1000);
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

  function formatLabel(child) {
    if (!child) {
      return "Choose a child";
    }

    return `${child.first_name} ${child.last_name}`;
  }

  function pushFeed(action, detail, level = "info") {
    if (!feed) return;

    const row = document.createElement("div");
    row.className = "kiosk-feed-row";
    row.innerHTML = `
      <span class="kiosk-feed-time">${formatNow()}</span>
      <strong>${escapeHtml(action)}</strong>
      <span>${escapeHtml(detail)}</span>
    `;

    feed.prepend(row);

    while (feed.children.length > 8) {
      feed.removeChild(feed.lastElementChild);
    }

    row.dataset.level = level;
  }

  function requestFullscreen() {
    const target = document.documentElement;

    if (document.fullscreenElement || !target.requestFullscreen) {
      return;
    }

    target.requestFullscreen().catch(() => {});
  }

  function escapeHtml(text) {
    return String(text)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function setStep(step) {
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
      const isActive = screen.getAttribute("data-kiosk-screen") === step;
      screen.hidden = !isActive;
      screen.classList.toggle("is-visible", isActive);
      screen.setAttribute("aria-hidden", String(!isActive));
    });

    stepButtons.forEach((button) => {
      const target = button.getAttribute("data-kiosk-step-jump");
      const isActive = target === step;
      button.classList.toggle("is-active", isActive);
      button.classList.toggle("is-complete", ["height", "weight", "processing", "results"].includes(step) && ["select", "height", "weight", "processing", "results"].indexOf(target) < ["select", "height", "weight", "processing", "results"].indexOf(step));
    });
  }

  function getSelectedChild() {
    return state.child || children[0] || null;
  }

  function syncContinueButton() {
    const continueBtn = document.querySelector('[data-kiosk-action="proceed-height"]');
    if (!continueBtn) return;
    continueBtn.disabled = !getSelectedChild();
  }

  function selectChild(childId) {
    if (isMeasurementActive() && state.child && String(state.child.id) !== String(childId)) {
      pushFeed('Selection locked', 'Wait for the current measurement to finish before choosing another child.', 'warn');
      return;
    }

    state.child = children.find((entry) => String(entry.id) === String(childId)) || null;

    childCards.forEach((card) => {
      card.classList.toggle('is-selected', String(card.dataset.childId) === String(childId));
    });

    const child = getSelectedChild();
    syncContinueButton();

    if (refs.currentChildLabel) refs.currentChildLabel.textContent = formatLabel(child);
    if (refs.sidebarChild) refs.sidebarChild.textContent = formatLabel(child) || 'None selected';
    if (refs.sidebarParent) refs.sidebarParent.textContent = child?.parent_name || '---';
    if (refs.sidebarBarangay) refs.sidebarBarangay.textContent = child?.barangay || '---';
    if (refs.sidebarStatus) refs.sidebarStatus.textContent = child ? 'Ready' : 'Waiting';
    if (refs.sidebarResult) refs.sidebarResult.textContent = child?.status || '---';
    if (refs.resultChild && child) refs.resultChild.textContent = formatLabel(child);
    if (refs.resultMeta && child) refs.resultMeta.textContent = `${child.age_months} months old`;

    if (child) {
      pushFeed('Child selected', `${child.child_code || 'Child'} · ${formatLabel(child)}`);
    }
  }

  window.kioskSelectChild = selectChild;

  function isMeasurementActive(session = state.session) {
    return !!session && ['START_REQUESTED', 'MEASURING'].includes(String(session.status || ''));
  }

  function isWaitingForLiveResult() {
    return !!state.awaitingLiveResult;
  }

  function syncStartButtonState() {
    if (!startButton) {
      return;
    }

    startButton.disabled = state.submitting || isMeasurementActive();
    startButton.textContent = state.submitting ? 'Starting...' : 'Start Measurement';
  }

  function clearStatusTimer() {
    if (state.statusTimer) {
      clearTimeout(state.statusTimer);
      state.statusTimer = null;
    }
  }

  function setProgress(progress, message) {
    const nextProgress = Math.max(0, Math.min(100, Number(progress) || 0));
    state.progress = nextProgress;

    if (refs.progressValue) {
      refs.progressValue.textContent = `${Math.round(nextProgress)}%`;
    }

    if (refs.progressRing) {
      refs.progressRing.style.strokeDashoffset = `${427 - (nextProgress * 4.27)}`;
    }

    if (refs.processStage && message) {
      refs.processStage.textContent = message;
    }

    if (heroNote && message) {
      heroNote.textContent = message;
    }
  }

  function updateMeasurementPanel(sessionData) {
    const status = String(sessionData?.status || 'IDLE');

    if (status === 'START_REQUESTED') {
      state.phase = 'starting';
      setStep('processing');
      setProgress(28, 'Please stand on the platform.');
      if (refs.resultSource) refs.resultSource.textContent = 'waiting for ESP32';
      return;
    }

    if (status === 'MEASURING') {
      state.phase = 'measuring';
      setStep('processing');
      setProgress(68, 'Measuring...');
      if (refs.resultSource) refs.resultSource.textContent = 'measuring on ESP32';
      return;
    }

    if (status === 'COMPLETE') {
      state.phase = 'complete';
      setProgress(100, 'Measurement complete');
      if (refs.resultSource) refs.resultSource.textContent = 'Firebase live mirror';
      return;
    }

    if (status === 'ERROR' || status === 'CANCELLED') {
      state.phase = 'error';
      setProgress(100, sessionData?.error_message || 'Measurement error');
      if (refs.resultSource) refs.resultSource.textContent = 'session error';
      return;
    }

    if (status === 'IDLE' && isWaitingForLiveResult()) {
      state.phase = 'measuring';
      setStep('processing');
      setProgress(84, 'Waiting for live result...');
      if (refs.resultSource) refs.resultSource.textContent = 'Firebase live mirror';
      return;
    }

    state.phase = 'idle';
    setProgress(0, 'Ready to measure');
  }

  function applyMeasurementResult(payload) {
    const measurement = payload?.measurement || payload || {};
    const childName = payload?.child_name || formatLabel(getSelectedChild());
    const childMonths = getSelectedChild()?.age_months || 0;
    const status = measurement.nutritional_status || payload?.nutritional_status || 'Normal';
    const height = Number(measurement.height_cm ?? payload?.height_cm ?? 0);
    const weight = Number(measurement.weight_kg ?? payload?.weight_kg ?? 0);

    state.status = status;
    state.height = Number.isFinite(height) ? height : null;
    state.weight = Number.isFinite(weight) ? weight : null;

    if (refs.resultChild) refs.resultChild.textContent = childName || 'Name';
    if (refs.resultMeta) refs.resultMeta.textContent = `${childMonths} months old`;
    if (refs.resultStatus) refs.resultStatus.textContent = status;
    if (refs.resultHeight) refs.resultHeight.textContent = Number.isFinite(height) ? `${height.toFixed(1)} cm` : '--.- cm';
    if (refs.resultWeight) refs.resultWeight.textContent = Number.isFinite(weight) ? `${weight.toFixed(2)} kg` : '--.-- kg';
    if (refs.resultWaz) refs.resultWaz.textContent = measurement.waz != null ? Number(measurement.waz).toFixed(2) : '--';
    if (refs.resultHaz) refs.resultHaz.textContent = measurement.haz != null ? Number(measurement.haz).toFixed(2) : '--';
    if (refs.resultWhz) refs.resultWhz.textContent = measurement.whz != null ? Number(measurement.whz).toFixed(2) : '--';
    if (refs.resultSource) refs.resultSource.textContent = `Firebase live mirror → ${deviceId}`;
    if (refs.sidebarResult) refs.sidebarResult.textContent = status;
    if (refs.sidebarStatus) refs.sidebarStatus.textContent = 'Complete';

    if (heroNote) {
      heroNote.textContent = 'Measurement complete';
    }

    pushFeed('Measurement complete', `${payload?.child_code || 'Child'} classified as ${status}`);
    setStep('results');
  }

  function showSessionError(message) {
    state.phase = 'error';
    state.submitting = false;
    clearStatusTimer();
    if (state.firebaseTimer) {
      clearInterval(state.firebaseTimer);
      state.firebaseTimer = null;
    }
    syncStartButtonState();
    setProgress(100, message || 'Measurement error');
    if (refs.resultStatus) refs.resultStatus.textContent = 'Error';
    if (refs.resultSource) refs.resultSource.textContent = 'session error';
    if (heroNote) heroNote.textContent = message || 'Measurement error';
    if (refs.sidebarStatus) refs.sidebarStatus.textContent = 'Error';
    setStep('processing');
  }

  async function refreshMeasurementStatus(scheduleNext = true) {
    clearStatusTimer();

    try {
      const endpoint = data?.endpoints?.measurementStatus || '../api/kiosk/measurement_status.php';
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set('device_id', deviceId);

      const response = await fetch(url.toString(), { cache: 'no-store' });
      const json = await response.json().catch(() => ({}));
      const payload = json?.data || {};

      if (!response.ok || json?.success !== true) {
        throw new Error(json?.message || 'Unable to load measurement status');
      }

      state.session = payload;
      updateMeasurementPanel(payload);
      updateSessionInfo(payload);

      if (payload.status === 'COMPLETE') {
        state.submitting = false;
        return payload;
      }

      if (payload.status === 'ERROR' || payload.status === 'CANCELLED') {
        showSessionError(payload.error_message || 'Measurement failed');
        return payload;
      }

      if (scheduleNext && isMeasurementActive(payload)) {
        state.statusTimer = setTimeout(() => refreshMeasurementStatus(true), Number(data?.defaults?.pollSeconds || 2) * 1000);
      }

      if (state.awaitingLiveResult && !state.firebaseTimer) {
        startFirebasePolling();
      }

      syncStartButtonState();
      return payload;
    } catch (error) {
      if (scheduleNext && isMeasurementActive(state.session)) {
        state.statusTimer = setTimeout(() => refreshMeasurementStatus(true), Number(data?.defaults?.pollSeconds || 2) * 1000);
      }

      if (!isMeasurementActive(state.session)) {
        showSessionError(error.message || 'Unable to load measurement status');
      }

      return null;
    }
  }

  async function startMeasurementFlow() {
    const child = getSelectedChild();
    if (!child) {
      pushFeed('Start blocked', 'Choose a child before starting.', 'warn');
      return false;
    }

    if (isMeasurementActive()) {
      pushFeed('Start blocked', 'A measurement session is already active.', 'warn');
      return false;
    }

    // Quick client-side check: if ping endpoint is available and device is offline, block start
    if (data.endpoints && data.endpoints.ping && state.deviceOnline === false) {
      pushFeed('Device offline', 'Cannot start measurement: ESP32 is offline.', 'warn');
      showSessionError('Device offline — please check ESP32 connection');
      return false;
    }

    state.submitting = true;
    syncStartButtonState();
    setStep('processing');
    setProgress(10, 'Starting measurement...');
    if (refs.sidebarStatus) refs.sidebarStatus.textContent = 'Starting';
    state.awaitingLiveResult = false;
    state.firebaseSessionId = null;
    state.lastFirebaseTimestamp = '';

    try {
      const endpoint = data?.endpoints?.startMeasurement || '../api/kiosk/start_measurement.php';
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          device_id: deviceId,
          child_id: child.id,
          location: 'Kiosk',
        }),
        cache: 'no-store',
      });

      const json = await response.json().catch(() => ({}));
      const payload = json?.data || {};

      if (!response.ok || json?.success !== true) {
        throw new Error(json?.message || 'Could not start measurement');
      }

      state.session = payload;
      updateSessionInfo(payload);
      state.submitting = false;
      state.firebaseSessionId = Number(payload.session_id || 0) || null;
      state.awaitingLiveResult = true;
      syncStartButtonState();

      pushFeed('Start queued', payload.duplicate ? 'Measurement already in progress.' : `${child.child_code} queued for measurement`);
      updateMeasurementPanel(payload);
      startFirebasePolling();
      await refreshMeasurementStatus(true);
      return true;
    } catch (error) {
      state.submitting = false;
      syncStartButtonState();
      showSessionError(error.message || 'Unable to contact the server');
      pushFeed('Start failed', error.message || 'Unable to contact the server', 'error');
      return false;
    }
  }

  async function refreshFirebaseLatestMeasurement() {
    if (!firebaseEnabled || !state.awaitingLiveResult) {
      return null;
    }

    const url = firebaseLatestMeasurementUrl();
    if (!url) {
      return null;
    }

    try {
      const response = await fetch(url, { cache: 'no-store' });
      if (!response.ok) {
        return null;
      }

      const payload = await response.json().catch(() => null);
      if (!payload || typeof payload !== 'object') {
        return null;
      }

      const payloadSessionId = Number(payload.session_id || 0) || null;
      if (state.firebaseSessionId && payloadSessionId && payloadSessionId !== state.firebaseSessionId) {
        return null;
      }

      const timestamp = String(payload.timestamp || '');
      if (timestamp && timestamp === state.lastFirebaseTimestamp) {
        return null;
      }

      const height = Number(payload.height_cm);
      const weight = Number(payload.weight_kg);
      if (!Number.isFinite(height) || !Number.isFinite(weight) || height <= 0 || weight <= 0) {
        return null;
      }

      state.lastFirebaseTimestamp = timestamp;
      applyMeasurementResult(payload);
      state.awaitingLiveResult = false;
      state.submitting = false;
      syncStartButtonState();

      if (state.firebaseTimer) {
        clearInterval(state.firebaseTimer);
        state.firebaseTimer = null;
      }

      return payload;
    } catch (error) {
      return null;
    }
  }

  function startFirebasePolling() {
    if (!firebaseEnabled) {
      return;
    }

    if (state.firebaseTimer) {
      clearInterval(state.firebaseTimer);
      state.firebaseTimer = null;
    }

    state.firebaseTimer = setInterval(() => {
      refreshFirebaseLatestMeasurement();
    }, Number(data?.defaults?.pollSeconds || 2) * 1000);

    refreshFirebaseLatestMeasurement();
  }

  function resetKioskToIdle() {
    if (isMeasurementActive()) {
      pushFeed('Reset blocked', 'Wait for the active session to finish.', 'warn');
      return;
    }

    clearStatusTimer();
    if (state.firebaseTimer) {
      clearInterval(state.firebaseTimer);
      state.firebaseTimer = null;
    }
    state.session = null;
    state.phase = 'idle';
    state.submitting = false;
    state.awaitingLiveResult = false;
    state.firebaseSessionId = null;
    state.lastFirebaseTimestamp = '';
    state.height = null;
    state.weight = null;
    state.status = null;
    state.progress = 0;
    syncStartButtonState();

    if (heroNote) heroNote.textContent = 'Ready to measure';
    if (refs.processStage) refs.processStage.textContent = 'Ready to measure';
    if (refs.progressValue) refs.progressValue.textContent = '0%';
    if (refs.progressRing) refs.progressRing.style.strokeDashoffset = '427';
    if (refs.resultStatus) refs.resultStatus.textContent = 'Normal';
    if (refs.resultHeight) refs.resultHeight.textContent = '--.- cm';
    if (refs.resultWeight) refs.resultWeight.textContent = '--.-- kg';
    if (refs.resultWaz) refs.resultWaz.textContent = '--';
    if (refs.resultHaz) refs.resultHaz.textContent = '--';
    if (refs.resultWhz) refs.resultWhz.textContent = '--';
    if (refs.resultSource) refs.resultSource.textContent = 'Firebase live mirror';
    if (refs.sidebarStatus) refs.sidebarStatus.textContent = 'Waiting';
    if (refs.sidebarResult) refs.sidebarResult.textContent = '---';
    updateSessionInfo(null);
    setStep('welcome');
    pushFeed('Kiosk reset', 'Ready for the next measurement');
  }

  function resetScanState() {
    clearInterval(state.processTimer);
    clearInterval(state.heightTimer);
    clearInterval(state.weightTimer);
    state.processTimer = null;
    state.heightTimer = null;
    state.weightTimer = null;
    state.height = null;
    state.weight = null;
    state.progress = 0;
    state.status = null;

    const continueBtn = document.querySelector('[data-kiosk-action="proceed-height"]');
    if (continueBtn) continueBtn.disabled = true;

    if (refs.heightReadout) refs.heightReadout.textContent = "--.-";
    if (refs.weightReadout) refs.weightReadout.textContent = "--.--";
    if (refs.heightStatus) refs.heightStatus.textContent = "Ready to measure height";
    if (refs.weightStatus) refs.weightStatus.textContent = "Ready to measure weight";
    if (refs.heightBar) refs.heightBar.style.width = "0%";
    if (refs.heightFinal) refs.heightFinal.textContent = "--.- cm";
    if (refs.progressValue) refs.progressValue.textContent = "0%";
    if (refs.processStage) refs.processStage.textContent = stageLabels[0];
    if (refs.resultStatus) refs.resultStatus.textContent = "Normal";
    if (refs.resultChild) refs.resultChild.textContent = "Name";
    if (refs.resultMeta) refs.resultMeta.textContent = "-- months old";
    if (refs.resultHeight) refs.resultHeight.textContent = "--.- cm";
    if (refs.resultWeight) refs.resultWeight.textContent = "--.-- kg";
    if (refs.resultWaz) refs.resultWaz.textContent = "--";
    if (refs.resultHaz) refs.resultHaz.textContent = "--";
    if (refs.resultWhz) refs.resultWhz.textContent = "--";
    if (refs.resultSource) refs.resultSource.textContent = "live";
    if (refs.sidebarResult) refs.sidebarResult.textContent = "---";
    if (refs.progressRing) refs.progressRing.style.strokeDashoffset = "427";
    setStep("welcome");
    selectChild(state.child?.id || (children[0] && children[0].id));
  }

  function copyPayload() {
    const child = getSelectedChild();
    const payload = {
      device_id: deviceId,
      child_id: child?.id || null,
      child_code: child?.child_code || null,
      height_cm: state.height,
      weight_kg: state.weight,
      age_months: child?.age_months || null,
      waz: null,
      haz: null,
      whz: null,
      nutritional_status: null,
      source_type: "kiosk",
      demo_mode: false,
    };

    const text = JSON.stringify(payload, null, 2);

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(() => {
        pushFeed("Payload copied", `${child?.child_code || 'No child'} measurement payload copied to clipboard`);
      });
    } else {
      window.prompt("Copy kiosk payload", text);
    }
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
      card.addEventListener('click', () => {
        selectChild(card.dataset.childId);
        if (refs.currentChildLabel) {
          const selectedName = card.querySelector('.kiosk-child-name');
          if (selectedName) {
            refs.currentChildLabel.textContent = selectedName.textContent.trim();
          }
        }
        syncStartButtonState();
      });
    });

    if (startButton) {
      startButton.addEventListener('click', () => {
        requestFullscreen();
        startMeasurementFlow();
      });
    }

    if (resetButton) {
      resetButton.addEventListener('click', () => {
        resetKioskToIdle();
      });
    }

    actionButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const action = button.getAttribute('data-kiosk-action');

        // Primary start action on welcome / header
        if (action === 'start') {
          requestFullscreen();
          startMeasurementFlow();
        }

        // Continue from child selection: treat as Start Measurement
        if (action === 'proceed-height') {
          requestFullscreen();
          startMeasurementFlow();
        }

        // Start scan buttons on height/weight screens should trigger the measurement
        if (action === 'start-height' || action === 'start-weight') {
          if (!isMeasurementActive()) {
            // If no session has been requested yet, create one
            requestFullscreen();
            startMeasurementFlow();
          } else {
            pushFeed('Measurement active', 'Measurement already in progress.');
          }
        }

        // Navigation/back actions - simple UI step changes (no server side effect)
        if (action === 'back-select') {
          setStep('select');
        }

        if (action === 'back-height') {
          setStep('height');
        }

        if (action === 'reset') {
          resetKioskToIdle();
        }

        if (action === 'export') {
          copyPayload();
        }
      });
    });

    if (refs.sidebarStatus) {
      refs.sidebarStatus.textContent = 'Waiting';
    }

    syncStartButtonState();
  }

  if (clock) {
    const tick = () => {
      clock.textContent = formatNow();
    };

    tick();
    setInterval(tick, 1000);
  }

  if (welcomeClock) {
    const updateWelcomeClock = () => {
      welcomeClock.textContent = formatNow();
      if (welcomeDate) {
        welcomeDate.textContent = formatDate();
      }
    };

    updateWelcomeClock();
    setInterval(updateWelcomeClock, 1000);
  }

  bindEvents();
  selectChild(children[0]?.id);
  syncStartButtonState();
  if (heroNote) heroNote.textContent = 'Ready to measure';
  if (refs.resultSource) refs.resultSource.textContent = firebaseEnabled ? 'Firebase live mirror' : 'Live backend';
  refreshMeasurementStatus(true);
})();
