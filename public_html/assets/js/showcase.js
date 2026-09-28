/* Sukat Kalusugan public showcase — vanilla, no dependencies.
 * Team deck + meet-the-kiosk hotspot modal + feature cards + lightbox. */
(function () {
  /* Theme-aware hero logos, same as auth login: forlight on light bg, fordark on dark bg. */
  function swapShowcaseLogos() {
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    Array.prototype.forEach.call(document.querySelectorAll('[data-logo-light]'), function (img) {
      img.src = isDark ? img.getAttribute('data-logo-dark') : img.getAttribute('data-logo-light');
    });
  }
  swapShowcaseLogos();
  window.addEventListener('storage', function (e) {
    if (e.key === 'theme') swapShowcaseLogos();
  });

  /* ---- Meet-the-kiosk part modal ----
   * EDIT TEXT HERE ONLY. Keys match data-part in showcase.php; dot
   * positions (--hx/--hy) live in the markup, layout in showcase.css.
   * Drop photos into assets/img/kiosk-parts/ to replace placeholders. */
  /* Small stroke icons for the part stat blocks (same style as auth icons).
   * Keys are referenced by KIOSK_PARTS specs below — add new ones here. */
  var SK_SVG_OPEN = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
  function skIcon(inner) { return SK_SVG_OPEN + inner + '</svg>'; }
  var PART_ICONS = {
    zap: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    height: '<polyline points="8 18 12 22 16 18"/><polyline points="8 6 12 2 16 6"/><line x1="12" y1="2" x2="12" y2="22"/>',
    eye: '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    tablet: '<rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
    list: '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    chart: '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    cpu: '<rect x="6" y="6" width="12" height="12" rx="2"/><rect x="10" y="10" width="4" height="4"/><line x1="9" y1="2" x2="9" y2="6"/><line x1="15" y1="2" x2="15" y2="6"/><line x1="9" y1="18" x2="9" y2="22"/><line x1="15" y1="18" x2="15" y2="22"/><line x1="2" y1="9" x2="6" y2="9"/><line x1="2" y1="15" x2="6" y2="15"/><line x1="18" y1="9" x2="22" y2="9"/><line x1="18" y1="15" x2="22" y2="15"/>',
    activity: '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    wifi: '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
    layers: '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 12 12 17 22 12"/><polyline points="2 17 12 22 22 17"/>',
    check: '<polyline points="20 6 9 17 4 12"/>'
  };
  var KIOSK_PARTS = {
    '1': {
      title: 'TF-Luna LiDAR sensor',
      img: 'assets/img/kiosk-parts/tfluna.jpg',
      file: 'kiosk-parts/tfluna.jpg',
      specs: [
        { icon: 'eye', label: 'Eye-safe sensor' },
        { icon: 'height', label: '150cm mount' },
        { icon: 'zap', label: 'Non-contact reading' }
      ],
      text: 'Uses Time-of-Flight (ToF) technology it sends out a quick, eye-safe pulse of infrared light and measures how long it takes to bounce back, then converts that into a height reading. No tape, no stadiometer, nothing touching the child. It is calibrated against the empty platform first so every reading stays consistent.'
    },
    '2': {
      title: 'Tablet Kiosk screen',
      img: 'assets/img/kiosk-parts/ipad.jpg',
      file: 'kiosk-parts/ipad.jpg',
      specs: [
        { icon: 'tablet', label: 'Touchscreen display' },
        { icon: 'list', label: 'Step-by-step guide' },
        { icon: 'chart', label: 'WHO result shown' }
      ],
      text: 'Walks the family through the whole session start, find the child record, watch live height and weight, then see the growth result on screen.'
    },
    '3': {
      title: 'ESP32 microcontroller',
      img: 'assets/img/kiosk-parts/esp32.jpg',
      file: 'kiosk-parts/esp32.jpg',
      specs: [
        { icon: 'cpu', label: 'ESP32 brain' },
        { icon: 'activity', label: '10-sample reads' },
        { icon: 'wifi', label: 'HTTPS data push' }
      ],
      text: 'The brain behind the screen mount. It reads both sensors, checks that measurements are stable, then pushes the snapshot straight into the system.'
    },
    '4': {
      title: 'Load cells + HX711',
      img: 'assets/img/kiosk-parts/loadcell.jpg',
      file: 'kiosk-parts/loadcell.jpg',
      specs: [
        { icon: 'layers', label: 'Four 50 kg cells' },
        { icon: 'activity', label: 'Live calibration' },
        { icon: 'check', label: 'Stable-weight check' }
      ],
      text: 'Weighs the child standing on the platform. It re-zeroes automatically and only records once the weight holds steady, so wiggles do not skew results.'
    }
  };
  var stage = document.getElementById('kioskStage');
  if (stage) {
    var modal = document.getElementById('kioskModal');
    var modalImg = document.getElementById('kioskModalImg');
    var modalPh = document.getElementById('kioskModalPh');
    var modalPhFile = document.getElementById('kioskModalPhFile');
    var modalTitle = document.getElementById('kioskModalTitle');
    var modalSpecs = document.getElementById('kioskModalSpecs');
    var modalText = document.getElementById('kioskModalText');
    var dots = Array.prototype.slice.call(stage.querySelectorAll('.sk-hotspot'));
    function closeKioskModal() {
      modal.hidden = true;
      dots.forEach(function (d) { d.setAttribute('aria-expanded', 'false'); });
    }
    modalImg.addEventListener('error', function () {
      modalImg.hidden = true;
      modalPh.hidden = false;
    });
    dots.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var wasOpen = btn.getAttribute('aria-expanded') === 'true' && !modal.hidden;
        var info = KIOSK_PARTS[btn.getAttribute('data-part')];
        closeKioskModal();
        if (wasOpen || !info) return;
        modalTitle.textContent = info.title;
        modalSpecs.innerHTML = '';
        (info.specs || []).slice(0, 3).forEach(function (stat) {
          var cell = document.createElement('div');
          cell.className = 'sk-part-stat';
          var icon = document.createElement('span');
          icon.setAttribute('aria-hidden', 'true');
          icon.innerHTML = skIcon(PART_ICONS[stat.icon] || '');
          var label = document.createElement('span');
          label.textContent = stat.label;
          cell.appendChild(icon);
          cell.appendChild(label);
          modalSpecs.appendChild(cell);
        });
        modalText.textContent = info.text;
        if (info.img) {
          modalPhFile.textContent = info.file || info.img;
          modalImg.hidden = false;
          modalPh.hidden = true;
          modalImg.alt = info.title;
          if (modalImg.getAttribute('src') !== info.img) {
            modalImg.setAttribute('src', info.img);
          } else if (!modalImg.complete || modalImg.naturalWidth === 0) {
            modalImg.hidden = true;
            modalPh.hidden = false;
          }
        } else {
          modalImg.hidden = true;
          modalPh.hidden = false;
        }
        modal.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
      });
    });
    document.getElementById('kioskModalClose').addEventListener('click', closeKioskModal);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeKioskModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) closeKioskModal();
    });
  }
  /* ---- Feature cards ("What it does") ----
   * Swiping is native scroll-snap (touch and trackpad). This keeps the dots
   * and the "active" card in step with the scroll position, and adds
   * click-and-drag for mouse users since a mouse can't swipe. */
  var featTrack = document.getElementById('skFeatTrack');
  if (featTrack) {
    var featCards = Array.prototype.slice.call(featTrack.children);
    var featDots = document.getElementById('skFeatDots');
    var featReduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    var featIdx = -1;
    var featRaf = 0;
    var featDrag = null;

    function featGo(i) {
      i = Math.max(0, Math.min(featCards.length - 1, i));
      var c = featCards[i];
      featTrack.scrollTo({
        left: c.offsetLeft - (featTrack.clientWidth - c.offsetWidth) / 2,
        behavior: featReduce.matches ? 'auto' : 'smooth'
      });
    }
    function featShow(i) {
      if (i === featIdx) return;
      featIdx = i;
      featCards.forEach(function (c, k) { c.classList.toggle('is-active', k === i); });
      Array.prototype.forEach.call(featDots.children, function (d, k) {
        d.setAttribute('aria-current', k === i ? 'true' : 'false');
      });
    }
    function featSync() {
      var mid = featTrack.scrollLeft + featTrack.clientWidth / 2;
      var best = 0, bestD = Infinity;
      featCards.forEach(function (c, k) {
        var d = Math.abs(c.offsetLeft + c.offsetWidth / 2 - mid);
        if (d < bestD) { bestD = d; best = k; }
      });
      featShow(best);
    }

    featCards.forEach(function (_, i) {
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('aria-label', 'Go to feature ' + (i + 1));
      b.setAttribute('aria-current', 'false');
      b.addEventListener('click', function () { featGo(i); });
      featDots.appendChild(b);
    });
    featTrack.addEventListener('scroll', function () {
      if (featRaf) return;
      featRaf = window.requestAnimationFrame(function () { featRaf = 0; featSync(); });
    }, { passive: true });
    window.addEventListener('resize', featSync);

    /* Mouse only: touch and trackpad already scroll natively. */
    featTrack.addEventListener('pointerdown', function (e) {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      featDrag = { x: e.clientX, left: featTrack.scrollLeft, moved: false };
    });
    window.addEventListener('pointermove', function (e) {
      if (!featDrag) return;
      var dx = e.clientX - featDrag.x;
      if (!featDrag.moved && Math.abs(dx) > 5) {
        featDrag.moved = true;
        featTrack.classList.add('is-dragging');
      }
      if (featDrag.moved) featTrack.scrollLeft = featDrag.left - dx;
    });
    function featDragEnd() {
      if (!featDrag) return;
      var moved = featDrag.moved;
      featDrag = null;
      if (!moved) return;
      markSwiped(); /* so releasing the drag doesn't open the lightbox */
      featTrack.classList.remove('is-dragging');
      featGo(featIdx);
    }
    window.addEventListener('pointerup', featDragEnd);
    window.addEventListener('pointercancel', featDragEnd);

    featSync();
  }

  var lastSwipeAt = 0;
  function markSwiped() { lastSwipeAt = Date.now(); }
  function justSwiped() { return Date.now() - lastSwipeAt < 350; }
  function makeSwipeable(el, onLeft, onRight) {
    var startX = 0;
    el.addEventListener('touchstart', function (e) {
      startX = e.touches[0].clientX;
    }, { passive: true });
    el.addEventListener('touchend', function (e) {
      var dx = e.changedTouches[0].clientX - startX;
      if (Math.abs(dx) > 40) { markSwiped(); if (dx < 0) onLeft(); else onRight(); }
    }, { passive: true });
    var sx = null;
    el.addEventListener('mousedown', function (e) { sx = e.clientX; });
    el.addEventListener('mouseup', function (e) {
      if (sx === null) return;
      var dx = e.clientX - sx;
      sx = null;
      if (Math.abs(dx) > 40) { markSwiped(); if (dx < 0) onLeft(); else onRight(); }
    });
  }
  var deck = document.getElementById('teamDeck');
  if (!deck) return;
  var track = deck.querySelector('.sk-deck-track');
  var cards = Array.prototype.slice.call(track.children);
  var prev = document.getElementById('teamPrev');
  var next = document.getElementById('teamNext');
  var dotsWrap = document.getElementById('teamDots');
  var posEl = document.getElementById('teamPos');
  var idx = 0;

  function renderDots() {
    dotsWrap.innerHTML = '';
    cards.forEach(function (_, i) {
      var b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('aria-label', 'Go to photo ' + (i + 1));
      b.setAttribute('aria-current', i === idx ? 'true' : 'false');
      b.addEventListener('click', function () { go(i); });
      dotsWrap.appendChild(b);
    });
  }

  function go(i) {
    idx = (i + cards.length) % cards.length;
    track.style.transform = 'translateX(' + (-idx * 100) + '%)';
    Array.prototype.forEach.call(dotsWrap.children, function (d, di) {
      d.setAttribute('aria-current', di === idx ? 'true' : 'false');
    });
    if (posEl) posEl.textContent = (idx + 1) + ' / ' + cards.length;
  }

  if (prev) prev.addEventListener('click', function () { go(idx - 1); });
  if (next) next.addEventListener('click', function () { go(idx + 1); });

  makeSwipeable(deck, function () { go(idx + 1); }, function () { go(idx - 1); });

  renderDots();
  go(0);

  /* ---- Click-to-enlarge lightbox (tap any team photo) ---- */
  var lb = document.getElementById('skLightbox');
  if (lb) {
    var lbImg = document.getElementById('skLbImg');
    var lbCap = document.getElementById('skLbCap');
    var lbItems = [];
    var lbIdx = 0;

    function lbShow() {
      var it = lbItems[lbIdx];
      if (!it) return;
      lbImg.src = it.src;
      lbImg.alt = it.alt;
      lbCap.textContent = it.label + ' — ' + (lbIdx + 1) + ' / ' + lbItems.length;
      lb.hidden = false;
      document.body.style.overflow = 'hidden';
    }
    function lbHide() {
      lb.hidden = true;
      lbImg.src = '';
      document.body.style.overflow = '';
    }
    function lbNav(d) {
      if (!lbItems.length) return;
      lbIdx = (lbIdx + d + lbItems.length) % lbItems.length;
      lbShow();
    }

    document.getElementById('skLbClose').addEventListener('click', lbHide);
    document.getElementById('skLbBackdrop').addEventListener('click', lbHide);
    document.getElementById('skLbPrev').addEventListener('click', function (e) { e.stopPropagation(); lbNav(-1); });
    document.getElementById('skLbNext').addEventListener('click', function (e) { e.stopPropagation(); lbNav(1); });
    document.addEventListener('keydown', function (e) {
      if (lb.hidden) return;
      if (e.key === 'Escape') lbHide();
      if (e.key === 'ArrowLeft') lbNav(-1);
      if (e.key === 'ArrowRight') lbNav(1);
    });
    makeSwipeable(lb, function () { lbNav(1); }, function () { lbNav(-1); });

    function collect(track, slideSel, label) {
      var out = [];
      Array.prototype.forEach.call(track.querySelectorAll(slideSel), function (s) {
        var img = s.querySelector('img');
        if (img && img.src) out.push({ src: img.src, alt: img.alt || label, label: label });
      });
      return out;
    }
    function bindSlides(track, slideSel, label) {
      var slides = Array.prototype.slice.call(track.querySelectorAll(slideSel));
      slides.forEach(function (s) {
        s.addEventListener('click', function () {
          if (justSwiped()) return;
          var items = collect(track, slideSel, label);
          if (!items.length) return;
          var img = s.querySelector('img');
          lbItems = items;
          lbIdx = img ? items.findIndex(function (x) { return x.src === img.src; }) : 0;
          if (lbIdx < 0) lbIdx = 0;
          lbShow();
        });
      });
    }
    if (track) bindSlides(track, '.sk-card', 'Team photo');
    function bindSingle(imgSel, label) {
      Array.prototype.forEach.call(document.querySelectorAll(imgSel), function (one) {
        one.addEventListener('click', function () {
          if (justSwiped()) return;
          lbItems = [{ src: one.src, alt: one.alt || label, label: label }];
          lbIdx = 0;
          lbShow();
        });
      });
    }
    bindSingle('#kioskUnitSolo img', 'Kiosk unit');
    bindSingle('.sk-feature-visuals img', 'Showcase photo');
    bindSingle('.sk-spec .sensor-shot img', 'Sensor');
    bindSingle('.device-cluster .screen-slot img', 'Dashboard');
    if (featTrack) bindSlides(featTrack, '.sk-feat-media', 'Feature screenshot');
  }
})();