/* Sukat Kalusugan public showcase — vanilla, no dependencies.
 * Team deck + kiosk iPad slider with synced side description. */
(function () {
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

  /* ---- Kiosk iPad slider: swipe screenshots, side description follows ---- */
  var kDeck = document.getElementById('kioskDeck');
  if (kDeck) {
    var kTrack = document.getElementById('kioskTrack');
    var kSlides = Array.prototype.slice.call(kTrack.children);
    var kPrev = document.getElementById('kioskPrev');
    var kNext = document.getElementById('kioskNext');
    var kDots = document.getElementById('kioskDots');
    var kPos = document.getElementById('kioskPos');
    var kBox = document.getElementById('kioskDesc');
    var kN = document.getElementById('kioskStepN');
    var kTitle = document.getElementById('kioskTitle');
    var kText = document.getElementById('kioskText');
    var kIdx = 0;
    var copy = [
      { t: 'Welcome — Simulan', d: 'Live clock and device online check. Tap Simulan to start. Privacy notice follows before measuring.' },
      { t: 'Find the child', d: 'Search by Child ID or name, confirm the record. Scoped to the kiosk barangay; double-check override if not due.' },
      { t: 'Live height + weight', d: 'TF-Luna LiDAR reads height while the HX711 load cell reads weight. Stability bars turn green, then I-process.' },
      { t: 'Resulta', d: 'Weight, height, and WHO status — WFA / HFA / WFH. Session closes as COMPLETE and syncs to dashboards.' }
    ];

    function kRenderDots() {
      kDots.innerHTML = '';
      kSlides.forEach(function (_, i) {
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('aria-label', 'Go to kiosk step ' + (i + 1));
        b.setAttribute('aria-current', i === kIdx ? 'true' : 'false');
        b.addEventListener('click', function () { kGo(i); });
        kDots.appendChild(b);
      });
    }

    function kGo(i) {
      kIdx = (i + kSlides.length) % kSlides.length;
      kTrack.style.transform = 'translateX(' + (-kIdx * 100) + '%)';
      Array.prototype.forEach.call(kDots.children, function (dt, di) {
        dt.setAttribute('aria-current', di === kIdx ? 'true' : 'false');
      });
      if (kPos) kPos.textContent = (kIdx + 1) + ' / ' + kSlides.length;
      var c = copy[kIdx % copy.length];
      if (kBox && c) {
        kBox.classList.add('is-switching');
        setTimeout(function () {
          kN.textContent = String(kIdx + 1);
          kTitle.textContent = c.t;
          kText.textContent = c.d;
          kBox.classList.remove('is-switching');
        }, 160);
      }
    }

    if (kPrev) kPrev.addEventListener('click', function () { kGo(kIdx - 1); });
    if (kNext) kNext.addEventListener('click', function () { kGo(kIdx + 1); });
    makeSwipeable(kDeck, function () { kGo(kIdx + 1); }, function () { kGo(kIdx - 1); });
    kRenderDots();
    kTrack.style.transform = 'translateX(0%)';
    if (kPos) kPos.textContent = '1 / ' + kSlides.length;
  }

  /* ---- Click-to-enlarge lightbox (tap any kiosk / team photo) ---- */
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
    var kT = document.getElementById('kioskTrack');
    if (kT) bindSlides(kT, '.tablet-slide', 'Kiosk screen');
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
    bindSingle('.sk-spec .sensor-shot img', 'Sensor');
    bindSingle('.device-cluster .screen-slot img', 'Dashboard');
  }
})();
