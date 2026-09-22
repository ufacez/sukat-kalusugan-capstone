/* Sukat Kalusugan 3D showcase — procedural kiosk + optional kiosk.glb override. Vanilla JS, no build. */
(function () {
  'use strict';
  var statusEl = document.getElementById('viewer-status');
  var fallbackEl = document.getElementById('viewer-fallback');
  var noteEl = document.getElementById('model-note');
  var canvas = document.getElementById('kiosk-canvas');
  var btnSpin = document.getElementById('btn-spin');
  var btnReset = document.getElementById('btn-reset');

  function setStatus(t) { if (statusEl) statusEl.textContent = t; }
  function showFallback(reason) { if (fallbackEl) fallbackEl.hidden = false; if (canvas) canvas.style.display = 'none'; if (reason) { var r = document.getElementById('viewer-fallback-reason'); if (r) r.textContent = reason; } }

  /* ---- scroll reveal + tilt cards (works even if WebGL fails) ---- */
  try {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: 0.15 });
    document.querySelectorAll('.card,.role').forEach(function (el) { io.observe(el); });
  } catch (e) { document.querySelectorAll('.card,.role').forEach(function (el) { el.classList.add('in'); }); }

  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
  if (finePointer && !reduceMotion) {
    document.querySelectorAll('[data-tilt]').forEach(function (card) {
      card.addEventListener('mousemove', function (ev) {
        var r = card.getBoundingClientRect();
        var x = (ev.clientX - r.left) / r.width - 0.5;
        var y = (ev.clientY - r.top) / r.height - 0.5;
        card.style.transform = 'rotateY(' + (x * 10).toFixed(2) + 'deg) rotateX(' + (-y * 10).toFixed(2) + 'deg) translateZ(6px)';
      });
      card.addEventListener('mouseleave', function () { card.style.transform = ''; });
    });
  } else {
    document.querySelectorAll('.card,.role').forEach(function (el) { el.classList.add('in'); });
  }

  /* ---- Three.js scene (waits briefly for the CDN fallback on slow networks) ---- */
  if (!canvas) { setStatus('3D unavailable — showing info'); showFallback('Viewer element missing — please reload the page.'); return; }
  var threeWaits = 0;
  (function waitForThree() {
    if (window.THREE) { initThree(); return; }
    if (++threeWaits > 25) { setStatus('3D library failed to load — showing info'); showFallback('The 3D library (assets/js/vendor/three.min.js) failed to load — check the file is deployed.'); return; }
    setStatus('Loading 3D library…');
    setTimeout(waitForThree, 200);
  })();
  function initThree() {

  var renderer;
  try {
    renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true, alpha: true });
  } catch (e) { setStatus('WebGL blocked — showing info'); showFallback('WebGL is disabled in this browser — enable hardware acceleration and reload.'); return; }
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));

  var scene = new THREE.Scene();
  var camera = new THREE.PerspectiveCamera(42, 1, 0.1, 100);
  var camDist = 9.2, camDistTarget = 9.2;

  scene.add(new THREE.HemisphereLight(0xffffff, 0x0b6e4f, 0.95));
  var key = new THREE.DirectionalLight(0xffffff, 0.9); key.position.set(5, 8, 6); scene.add(key);
  var rim = new THREE.PointLight(0xf2a93b, 1.1, 30); rim.position.set(-6, 3, -4); scene.add(rim);

  var world = new THREE.Group(); scene.add(world);       // user-rotated
  var concept = new THREE.Group(); world.add(concept);   // replaced if GLB loads

  var GREEN = 0x0b6e4f, GREEN_LT = 0x2ec57a, DARK = 0x0e3a2b, GOLD = 0xf2a93b, WHITE = 0xffffff;

  function mesh(geo, color, x, y, z, opts) {
    var m = new THREE.Mesh(geo, new THREE.MeshStandardMaterial(Object.assign({ color: color, roughness: 0.55, metalness: 0.25 }, opts || {})));
    m.position.set(x, y, z); concept.add(m); return m;
  }

  // ground
  var ground = new THREE.Mesh(new THREE.CircleGeometry(3.4, 48), new THREE.MeshStandardMaterial({ color: 0xffffff, transparent: true, opacity: 0.14, roughness: 1 }));
  ground.rotation.x = -Math.PI / 2; ground.position.y = -1.7; concept.add(ground);
  var grid = new THREE.PolarGridHelper(3.4, 12, 6, 48, 0xffffff, 0xffffff);
  grid.position.y = -1.69; grid.material.transparent = true; grid.material.opacity = 0.25; concept.add(grid);

  // platform + scale plate + column + head + screen
  mesh(new THREE.CylinderGeometry(1.5, 1.65, 0.28, 40), DARK, 0, -1.55, 0);
  var plate = mesh(new THREE.BoxGeometry(1.7, 0.12, 1.1), GREEN, 0, -1.32, 0.15);
  plate.material.emissive = new THREE.Color(GREEN); plate.material.emissiveIntensity = 0.25;
  mesh(new THREE.BoxGeometry(0.5, 2.6, 0.4), WHITE, 0, 0.05, -0.55, { roughness: 0.35 });
  mesh(new THREE.BoxGeometry(0.56, 0.1, 0.44), GREEN_LT, 0, 1.36, -0.55, { emissive: GREEN_LT, emissiveIntensity: 0.5 });
  var head = mesh(new THREE.BoxGeometry(1.35, 0.85, 0.28), DARK, 0, 1.95, -0.5);
  head.rotation.x = -0.08;

  // screen texture: live-looking reading
  function screenTexture() {
    var c = document.createElement('canvas'); c.width = 512; c.height = 320;
    var g = c.getContext('2d');
    g.fillStyle = '#0f2a21'; g.fillRect(0, 0, 512, 320);
    g.fillStyle = '#2ec57a'; g.font = '900 44px Nunito, sans-serif'; g.fillText('12.4 kg', 40, 100);
    g.fillStyle = '#ffffff'; g.font = '900 44px Nunito, sans-serif'; g.fillText('89.2 cm', 40, 170);
    g.fillStyle = '#f2a93b'; g.font = '800 30px Nunito, sans-serif'; g.fillText('● MEASURING', 40, 240);
    g.fillStyle = 'rgba(255,255,255,.55)'; g.font = '700 24px Nunito, sans-serif'; g.fillText('WHO: WFA · HFA · WFH', 40, 285);
    var t = new THREE.CanvasTexture(c); return t;
  }
  var screen = new THREE.Mesh(new THREE.PlaneGeometry(1.15, 0.7), new THREE.MeshBasicMaterial({ map: screenTexture() }));
  screen.position.set(0, 1.95, -0.35); screen.rotation.x = -0.08; concept.add(screen);

  // height ruler
  mesh(new THREE.BoxGeometry(0.09, 2.4, 0.09), GOLD, 0.85, 0.1, -0.55, { metalness: 0.6, roughness: 0.3 });
  for (var i = 0; i < 7; i++) mesh(new THREE.BoxGeometry(0.22, 0.035, 0.035), WHITE, 0.85, -0.9 + i * 0.35, -0.55);

  // floating chips: weight sphere, height torus, accent octahedron
  var floaters = [];
  var wBall = mesh(new THREE.SphereGeometry(0.34, 28, 28), GOLD, -1.9, 0.6, 0.4, { roughness: 0.3, metalness: 0.5 });
  var hRing = mesh(new THREE.TorusGeometry(0.32, 0.11, 16, 40), GREEN_LT, 1.95, 1.1, 0.2, { emissive: GREEN_LT, emissiveIntensity: 0.35 });
  var aGem = mesh(new THREE.OctahedronGeometry(0.26), WHITE, 1.7, -0.7, 0.7, { roughness: 0.2, metalness: 0.1 });
  floaters.push({ m: wBall, y: 0.6, s: 1.1, p: 0 }, { m: hRing, y: 1.1, s: 1.4, p: 2 }, { m: aGem, y: -0.7, s: 1.7, p: 4 });

  // particles
  var pGeo = new THREE.BufferGeometry(), pCount = 160, pos = new Float32Array(pCount * 3);
  for (var k = 0; k < pCount; k++) { pos[k * 3] = (Math.random() - 0.5) * 10; pos[k * 3 + 1] = (Math.random() - 0.5) * 6; pos[k * 3 + 2] = (Math.random() - 0.5) * 8; }
  pGeo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
  var particles = new THREE.Points(pGeo, new THREE.PointsMaterial({ color: 0xffffff, size: 0.045, transparent: true, opacity: 0.7 }));
  scene.add(particles);

  /* ---- interaction: drag rotate + wheel zoom + auto-spin ---- */
  var autoSpin = !reduceMotion, rotY = 0.6, rotYTarget = 0.6, tiltX = 0.12, tiltXTarget = 0.12;
  var dragging = false, lastX = 0, lastY = 0, idleTimer = null;

  function pauseSpinTemp() { if (reduceMotion) return; autoSpin = false; syncSpinBtn(); clearTimeout(idleTimer); idleTimer = setTimeout(function () { autoSpin = true; syncSpinBtn(); }, 3000); }
  canvas.addEventListener('pointerdown', function (e) { dragging = true; lastX = e.clientX; lastY = e.clientY; canvas.setPointerCapture(e.pointerId); pauseSpinTemp(); });
  canvas.addEventListener('pointermove', function (e) {
    if (!dragging) return;
    rotYTarget += (e.clientX - lastX) * 0.008;
    tiltXTarget = Math.max(-0.5, Math.min(0.7, tiltXTarget + (e.clientY - lastY) * 0.004));
    lastX = e.clientX; lastY = e.clientY;
  });
  function endDrag() { dragging = false; }
  canvas.addEventListener('pointerup', endDrag); canvas.addEventListener('pointercancel', endDrag);
  canvas.addEventListener('wheel', function (e) { e.preventDefault(); camDistTarget = Math.max(6, Math.min(14, camDistTarget + (e.deltaY > 0 ? 0.6 : -0.6))); }, { passive: false });

  function syncSpinBtn() { if (btnSpin) { btnSpin.textContent = autoSpin ? '⏸ Pause spin' : '▶ Auto-spin'; btnSpin.setAttribute('aria-pressed', autoSpin ? 'true' : 'false'); } }
  if (btnSpin) btnSpin.addEventListener('click', function () { autoSpin = !autoSpin; syncSpinBtn(); });
  if (btnReset) btnReset.addEventListener('click', function () { rotYTarget = 0.6; tiltXTarget = 0.12; camDistTarget = 9.2; });
  syncSpinBtn();

  function resize() {
    var w = canvas.clientWidth || canvas.parentElement.clientWidth, h = parseFloat(getComputedStyle(canvas).height) || 460;
    renderer.setSize(w, h, false); camera.aspect = w / h; camera.updateProjectionMatrix();
  }
  window.addEventListener('resize', resize); resize();

  var clock = new THREE.Clock();
  (function animate() {
    requestAnimationFrame(animate);
    var t = clock.getElapsedTime();
    if (autoSpin && !dragging) rotYTarget += 0.006;
    rotY += (rotYTarget - rotY) * 0.08; tiltX += (tiltXTarget - tiltX) * 0.08;
    camDist += (camDistTarget - camDist) * 0.1;
    world.rotation.y = rotY;
    camera.position.set(Math.sin(0) * camDist, 1.4 + tiltX * 4, camDist);
    camera.lookAt(0, 0.3, 0);
    floaters.forEach(function (f) { f.m.position.y = f.y + Math.sin(t * f.s + f.p) * 0.16; f.m.rotation.y += 0.01; f.m.rotation.x += 0.005; });
    particles.rotation.y = t * 0.03;
    renderer.render(scene, camera);
  })();

  setStatus('Interactive — drag to rotate');

  /* ---- your model overrides the concept (auto-framed to fit any export size) ---- */
  function frameModel(model) {
    var keepRot = rotY;
    world.rotation.y = 0; world.updateMatrixWorld(true);
    var box = new THREE.Box3().setFromObject(model);
    var size = box.getSize(new THREE.Vector3());
    var maxDim = Math.max(size.x, size.y, size.z);
    if (maxDim && isFinite(maxDim)) {
      model.scale.setScalar(3.4 / maxDim);
      world.updateMatrixWorld(true);
      box.setFromObject(model);
      var c = box.getCenter(new THREE.Vector3());
      model.position.x -= c.x;
      model.position.z -= c.z;
      model.position.y += (0.35 - c.y);
    }
    rotY = keepRot; rotYTarget = keepRot;
  }

  function loaderFail(msg) {
    setStatus(msg);
    if (window.console && console.warn) console.warn('[showcase] ' + msg);
  }

  function loadUserModel() {
    setStatus('Loading your 3D model…');
    var s = document.createElement('script');
    s.src = 'assets/js/vendor/GLTFLoader.js?v=128';
    s.onload = startModelLoad;
    s.onerror = function () {
      // Vendored copy missing (e.g. partial upload to production) — try CDN once.
      var c = document.createElement('script');
      c.src = 'https://unpkg.com/three@0.128.0/examples/js/loaders/GLTFLoader.js';
      c.onload = startModelLoad;
      c.onerror = function () { loaderFail('3D model loader failed — showing concept'); };
      document.head.appendChild(c);
    };
    document.head.appendChild(s);
    function startModelLoad() {
      if (!THREE.GLTFLoader) { loaderFail('3D loader blocked — showing concept'); return; }
      // No HEAD pre-check: request the model directly so a missing/blocked
      // file lands in the error callback with a visible message.
      new THREE.GLTFLoader().load('assets/models/kiosk.glb?v=2', function (gltf) {
        try {
          while (concept.children.length) concept.remove(concept.children[0]);
          floaters.length = 0;
          var model = gltf.scene;
          concept.add(model);
          frameModel(model);
          // re-add the stage floor under the framed model
          var ground2 = new THREE.Mesh(new THREE.CircleGeometry(3.4, 48), new THREE.MeshStandardMaterial({ color: 0xffffff, transparent: true, opacity: 0.14, roughness: 1 }));
          ground2.rotation.x = -Math.PI / 2; ground2.position.y = -1.7; concept.add(ground2);
          var grid2 = new THREE.PolarGridHelper(3.4, 12, 6, 48, 0xffffff, 0xffffff);
          grid2.position.y = -1.69; grid2.material.transparent = true; grid2.material.opacity = 0.25; concept.add(grid2);
          setStatus('Interactive — your model loaded');
          if (noteEl) noteEl.innerHTML = 'Showing <code>assets/models/kiosk.glb</code> — your upload. Drag to rotate.';
        } catch (err) { loaderFail('Model parse issue — showing concept'); }
      }, undefined, function (err) {
        if (window.console && console.error) console.error('[showcase] kiosk.glb load error', err);
        loaderFail('Could not load kiosk.glb (missing or blocked) — showing concept');
      });
    }
  }
  loadUserModel();
  } /* end initThree */
})();
