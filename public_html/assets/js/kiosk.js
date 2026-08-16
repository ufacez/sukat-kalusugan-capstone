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

  const state = {
    step: "welcome",
    child: null,
    height: null,
    weight: null,
    processTimer: null,
    heightTimer: null,
    weightTimer: null,
    status: null,
    progress: 0,
  };

  const stageLabels = [
    "Validating sensor data...",
    "Applying WHO 2006 standards...",
    "Computing WAZ, HAZ, and WHZ...",
    "Classifying nutritional status...",
    "Syncing to eOPT+ / cloud endpoint...",
    "Complete!",
  ];

  const children = Array.isArray(data.children) ? data.children : [];
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
    sidebarChild: document.querySelector("[data-kiosk-sidebar-child]"),
    sidebarParent: document.querySelector("[data-kiosk-sidebar-parent]"),
    sidebarBarangay: document.querySelector("[data-kiosk-sidebar-barangay]"),
    sidebarResult: document.querySelector("[data-kiosk-sidebar-result]"),
    sidebarStatus: document.querySelector("[data-kiosk-sidebar-status]"),
    childGrid: document.querySelector("[data-kiosk-child-grid]"),
  };

  // Expose an API for external sensor feeds (Arduino / Firebase / ESP32)
  window.kioskUpdateSensor = function (payload = {}) {
    try {
      if (payload.height != null) {
        state.height = Number(payload.height);
        if (refs.heightReadout) refs.heightReadout.textContent = state.height.toFixed(1);
        if (refs.heightFinal) refs.heightFinal.textContent = `${state.height.toFixed(1)} cm`;
        if (refs.heightStatus) refs.heightStatus.textContent = 'Height received';
        pushFeed('Sensor', `Height ${state.height.toFixed(1)} cm`);
      }

      if (payload.weight != null) {
        state.weight = Number(payload.weight);
        if (refs.weightReadout) refs.weightReadout.textContent = state.weight.toFixed(2);
        if (refs.weightStatus) refs.weightStatus.textContent = 'Weight received';
        pushFeed('Sensor', `Weight ${state.weight.toFixed(2)} kg`);
      }

      if (payload.device_id) {
        // update device id used in results/source label
        // note: not persisted; prepared for future integration
        // eslint-disable-next-line no-param-reassign
        // deviceId = String(payload.device_id);
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
        const ok = json && (json.status === 'ok' || json.connected === true);
        setChip(pingChip, ok ? 'Connected' : 'Disconnected', ok);
        if (json.lidar_status != null) setChip(lidarChip, json.lidar_status === 'ok' ? 'LiDAR Active' : 'LiDAR', json.lidar_status === 'ok');
        if (json.loadcell_status != null) setChip(loadChip, json.loadcell_status === 'ok' ? 'Load Cell OK' : 'Load Cell', json.loadcell_status === 'ok');

        // If the ping returned sensor values, push them into the UI
        if (json.height || json.weight) {
          window.kioskUpdateSensor({ height: json.height, weight: json.weight, device_id: json.device_id });
        }
      } catch (e) {
        setChip(pingChip, 'Disconnected', false);
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

  function selectChild(childId) {
    state.child = children.find((entry) => String(entry.id) === String(childId)) || null;

    childCards.forEach((card) => {
      card.classList.toggle("is-selected", String(card.dataset.childId) === String(childId));
    });

    const child = getSelectedChild();

    if (refs.currentChildLabel) refs.currentChildLabel.textContent = formatLabel(child);
    if (refs.sidebarChild) refs.sidebarChild.textContent = formatLabel(child) || "None selected";
    if (refs.sidebarParent) refs.sidebarParent.textContent = child?.parent_name || "---";
    if (refs.sidebarBarangay) refs.sidebarBarangay.textContent = child?.barangay || "---";
    if (refs.sidebarStatus) refs.sidebarStatus.textContent = child ? "Ready" : "Waiting";
    if (refs.sidebarResult) refs.sidebarResult.textContent = child?.status || "---";
    if (refs.resultChild && child) refs.resultChild.textContent = formatLabel(child);
    if (refs.resultMeta && child) refs.resultMeta.textContent = `${child.age_months} months old`;

    if (child) {
      pushFeed("Child selected", `${child.child_code} · ${formatLabel(child)}`);
    }
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
    if (refs.resultSource) refs.resultSource.textContent = "demo";
    if (refs.sidebarResult) refs.sidebarResult.textContent = "---";
    if (refs.progressRing) refs.progressRing.style.strokeDashoffset = "427";
    setStep("welcome");
    selectChild(state.child?.id || (children[0] && children[0].id));
  }

  function computeAssessment(weight, height, ageMonths) {
    const wazMedian = 3.5 + ageMonths * 0.24;
    const hazMedian = 49 + ageMonths * 0.82;
    const whzMedian = 10.5 + (height - 65) * 0.09;
    const waz = ((weight - wazMedian) / 1.08).toFixed(2);
    const haz = ((height - hazMedian) / 2.3).toFixed(2);
    const whz = ((weight - whzMedian) / 1.05).toFixed(2);

    let status = "Normal";

    if (parseFloat(waz) < -3 || parseFloat(whz) < -3) {
      status = "Severely Underweight";
    } else if (parseFloat(waz) < -2 || parseFloat(whz) < -2) {
      status = "Underweight";
    } else if (parseFloat(haz) < -2) {
      status = "Stunted";
    } else if (parseFloat(whz) > 2) {
      status = "Overweight";
    }

    return { waz, haz, whz, status };
  }

  function simulateHeightScan() {
    const child = getSelectedChild();
    if (!child) return;

    clearInterval(state.heightTimer);
    state.heightTimer = null;
    let ticks = 0;
    const base = 55 + child.age_months * 0.9 + (Math.random() * 4 - 2);

    if (refs.heightStatus) refs.heightStatus.textContent = "Scanning... stand still";
    pushFeed("TF-Luna scan", `${child.child_code} height scan started`);

    state.heightTimer = setInterval(() => {
      ticks += 1;
      const noise = ticks < 20 ? (Math.random() * 4 - 2) : ticks < 40 ? (Math.random() * 1.2 - 0.6) : (Math.random() * 0.2 - 0.1);
      const reading = base + noise;
      state.height = Number(reading.toFixed(1));

      if (refs.heightReadout) refs.heightReadout.textContent = state.height.toFixed(1);
      if (refs.heightBar) refs.heightBar.style.width = `${Math.min((ticks / 45) * 100, 100)}%`;

      if (ticks === 25 && refs.heightStatus) refs.heightStatus.textContent = "Locking reading...";

      if (ticks >= 45) {
        clearInterval(state.heightTimer);
        state.heightTimer = null;
        state.height = Number(base.toFixed(1));
        if (refs.heightReadout) refs.heightReadout.textContent = state.height.toFixed(1);
        if (refs.heightStatus) refs.heightStatus.textContent = `Height locked at ${state.height.toFixed(1)} cm`;
        if (refs.heightFinal) refs.heightFinal.textContent = `${state.height.toFixed(1)} cm`;
        pushFeed("Height locked", `${child.child_code} · ${state.height.toFixed(1)} cm`);
      }
    }, 70);
  }

  function simulateWeightScan() {
    const child = getSelectedChild();
    if (!child) return;

    clearInterval(state.weightTimer);
    state.weightTimer = null;
    const bars = [];
    const base = 3.8 + child.age_months * 0.24 + (Math.random() * 1.1 - 0.55);
    let ticks = 0;

    if (refs.weightStatus) refs.weightStatus.textContent = "Stabilizing load cell...";
    if (refs.weightBars) {
      refs.weightBars.innerHTML = "";
      for (let i = 0; i < 12; i += 1) {
        const bar = document.createElement("span");
        bar.style.height = `${8 + Math.floor(Math.random() * 22)}%`;
        bar.style.animationDelay = `${i * 0.05}s`;
        refs.weightBars.appendChild(bar);
        bars.push(bar);
      }
    }

    pushFeed("HX711 sample", `${child.child_code} weight scan started`);

    state.weightTimer = setInterval(() => {
      ticks += 1;
      const noise = ticks < 18 ? (Math.random() * 1.6 - 0.8) : ticks < 34 ? (Math.random() * 0.45 - 0.22) : (Math.random() * 0.06 - 0.03);
      const reading = base + noise;
      state.weight = Number(reading.toFixed(2));

      if (refs.weightReadout) refs.weightReadout.textContent = state.weight.toFixed(2);

      if (ticks === 24 && refs.weightStatus) refs.weightStatus.textContent = "Locking value...";

      if (ticks >= 36) {
        clearInterval(state.weightTimer);
        state.weightTimer = null;
        state.weight = Number(base.toFixed(2));
        if (refs.weightReadout) refs.weightReadout.textContent = state.weight.toFixed(2);
        if (refs.weightStatus) refs.weightStatus.textContent = `Weight locked at ${state.weight.toFixed(2)} kg`;
        pushFeed("Weight locked", `${child.child_code} · ${state.weight.toFixed(2)} kg`);
      }
    }, 80);
  }

  function simulateProcessing() {
    const child = getSelectedChild();
    if (!child || state.height === null || state.weight === null) return;

    clearInterval(state.processTimer);
    state.progress = 0;
    let index = 0;

    if (refs.processStage) refs.processStage.textContent = stageLabels[0];
    pushFeed("Processing", `${child.child_code} preparing WHO result`);
    setStep("processing");

    state.processTimer = setInterval(() => {
      state.progress += 100 / 45;
      index = Math.min(Math.floor((state.progress / 100) * stageLabels.length), stageLabels.length - 1);

      if (refs.progressValue) refs.progressValue.textContent = `${Math.min(Math.round(state.progress), 100)}%`;
      if (refs.processStage) refs.processStage.textContent = stageLabels[index];
      if (refs.progressRing) refs.progressRing.style.strokeDashoffset = `${427 - Math.min(state.progress, 100) * 4.27}`;

      if (state.progress >= 100) {
        clearInterval(state.processTimer);
        state.processTimer = null;

        const result = computeAssessment(state.weight, state.height, child.age_months);
        state.status = result.status;

        if (refs.resultChild) refs.resultChild.textContent = `${child.first_name} ${child.last_name}`;
        if (refs.resultMeta) refs.resultMeta.textContent = `${child.age_months} months old · ${child.child_code}`;
        if (refs.resultStatus) refs.resultStatus.textContent = result.status;
        if (refs.resultHeight) refs.resultHeight.textContent = `${state.height.toFixed(1)} cm`;
        if (refs.resultWeight) refs.resultWeight.textContent = `${state.weight.toFixed(2)} kg`;
        if (refs.resultWaz) refs.resultWaz.textContent = result.waz;
        if (refs.resultHaz) refs.resultHaz.textContent = result.haz;
        if (refs.resultWhz) refs.resultWhz.textContent = result.whz;
        if (refs.resultSource) refs.resultSource.textContent = `demo → ${deviceId}`;
        if (refs.sidebarResult) refs.sidebarResult.textContent = result.status;
        if (refs.sidebarStatus) refs.sidebarStatus.textContent = "Complete";

        pushFeed("Result ready", `${child.child_code} classified as ${result.status}`);
        setTimeout(() => setStep("results"), 450);
      }
    }, 110);
  }

  function beginSession() {
    const child = getSelectedChild();
    if (child && refs.sidebarStatus) refs.sidebarStatus.textContent = "Ready";
    setStep("select");
    pushFeed("Kiosk ready", `Demo mode active for ${deviceId}`);
  }

  function copyPayload() {
    const child = getSelectedChild();
    const result = state.status ? computeAssessment(state.weight, state.height, child?.age_months || 0) : null;
    const payload = {
      device_id: deviceId,
      child_id: child?.id || null,
      child_code: child?.child_code || null,
      height_cm: state.height,
      weight_kg: state.weight,
      age_months: child?.age_months || null,
      waz: result?.waz || null,
      haz: result?.haz || null,
      whz: result?.whz || null,
      nutritional_status: result?.status || null,
      source_type: "kiosk",
      demo_mode: true,
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
      card.addEventListener("click", () => {
        selectChild(card.dataset.childId);
        const nextBtn = document.querySelector('[data-kiosk-action="proceed-height"]');
        if (nextBtn) nextBtn.disabled = false;
      });
    });

    stepButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const step = button.getAttribute("data-kiosk-step-jump");
        if (step === "select") {
          setStep("select");
        } else if (step === "height" && state.child) {
          setStep("height");
        } else if (step === "weight" && state.height !== null) {
          setStep("weight");
        } else if (step === "processing" && state.weight !== null) {
          setStep("processing");
        } else if (step === "results" && state.status) {
          setStep("results");
        }
      });
    });

    actionButtons.forEach((button) => {
      button.addEventListener("click", () => {
        const action = button.getAttribute("data-kiosk-action");

        if (action === "start") {
          requestFullscreen();
          setStep("select");
        }

        if (action === "demo-reset") {
          requestFullscreen();
          resetScanState();
          beginSession();
        }

        if (action === "proceed-height" && getSelectedChild()) {
          requestFullscreen();
          setStep("height");
        }

        if (action === "start-height") {
          simulateHeightScan();
        }

        if (action === "back-select") {
          setStep("select");
        }

        if (action === "start-weight") {
          if (state.height !== null) {
            simulateWeightScan();
          }
        }

        if (action === "back-height") {
          setStep("height");
        }

        if (action === "reset") {
          resetScanState();
          beginSession();
        }

        if (action === "export") {
          copyPayload();
        }
      });
    });
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
  resetScanState();
  selectChild(children[0]?.id);
  beginSession();
})();
