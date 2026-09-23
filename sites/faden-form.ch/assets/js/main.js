/* Faden & Form Atelier – Interaktion und Bewegung.
   Alles läuft über transform/clip-path in einem einzigen rAF-Takt und
   bleibt bei «Bewegung reduzieren» still. */
(function () {
  'use strict';
  var doc = document.documentElement;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Header: Zustand beim Scrollen, mobiles Menü */
  var header = document.querySelector('.header');
  var burger = document.querySelector('.burger');
  var nav = document.getElementById('nav');
  function closeMenu() {
    doc.classList.remove('menu-open');
    if (burger) { burger.setAttribute('aria-expanded', 'false'); burger.setAttribute('aria-label', 'Menü öffnen'); }
  }
  if (burger && nav) {
    burger.addEventListener('click', function () {
      var open = !doc.classList.contains('menu-open');
      doc.classList.toggle('menu-open', open);
      burger.setAttribute('aria-expanded', String(open));
      burger.setAttribute('aria-label', open ? 'Menü schliessen' : 'Menü öffnen');
    });
    nav.addEventListener('click', function (e) { if (e.target.closest('a')) closeMenu(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
    window.addEventListener('resize', function () { if (window.innerWidth > 960) closeMenu(); });
  }

  /* Parallaxe und Scroll-Bewegung */
  var speedEls = [].slice.call(document.querySelectorAll('[data-speed]'));
  var driftEls = [].slice.call(document.querySelectorAll('[data-drift]'));
  var threads = [].slice.call(document.querySelectorAll('[data-thread]'));
  var toTop = document.querySelector('.to-top');
  threads.forEach(function (p) {
    var len = p.getTotalLength();
    p.style.strokeDasharray = len;
    p.style.strokeDashoffset = reduce ? 0 : len;
    p._len = len;
  });

  var ticking = false;
  function frame() {
    ticking = false;
    var y = window.scrollY;
    var vh = window.innerHeight;
    if (header) header.classList.toggle('is-scrolled', y > 12);
    if (toTop) toTop.classList.toggle('is-visible', y > vh * 1.2);
    if (reduce) return;

    speedEls.forEach(function (el) {
      var box = (el.parentElement || el).getBoundingClientRect();
      if (box.bottom < -200 || box.top > vh + 200) return;
      var center = box.top + box.height / 2 - vh / 2;
      var s = parseFloat(el.getAttribute('data-speed')) || 0.1;
      el.style.transform = 'translate3d(0,' + (center * -s).toFixed(1) + 'px,0)';
    });
    driftEls.forEach(function (el) {
      var box = el.getBoundingClientRect();
      if (box.bottom < -200 || box.top > vh + 200) return;
      var p = (box.top + box.height / 2 - vh / 2) / vh;
      var d = el.getAttribute('data-drift').split(',');
      var dx = (parseFloat(d[0]) || 0) * p;
      var dy = (parseFloat(d[1]) || 0) * p;
      var rot = (parseFloat(d[2]) || 0) * p;
      el.style.transform = 'translate3d(' + dx.toFixed(1) + 'px,' + dy.toFixed(1) + 'px,0) rotate(' + rot.toFixed(2) + 'deg)';
    });
    threads.forEach(function (p) {
      var box = p.ownerSVGElement.getBoundingClientRect();
      var prog = Math.min(1, Math.max(0, (vh - box.top) / (box.height + vh * 0.35)));
      p.style.strokeDashoffset = (p._len * (1 - prog)).toFixed(1);
    });
  }
  function onScroll() { if (!ticking) { ticking = true; requestAnimationFrame(frame); } }
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll);
  frame();

  if (toTop) toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); });

  /* Vorher/Nachher-Regler */
  [].forEach.call(document.querySelectorAll('.compare'), function (c) {
    var range = c.querySelector('input[type="range"]');
    function set(v) { c.style.setProperty('--pos', v + '%'); }
    range.addEventListener('input', function () { set(range.value); });
    set(range.value);
    if (!reduce && 'IntersectionObserver' in window) {
      var shown = false;
      new IntersectionObserver(function (entries, obs) {
        if (shown || !entries[0].isIntersecting) return;
        shown = true; obs.disconnect();
        var t0 = null;
        function wiggle(ts) {
          if (!t0) t0 = ts;
          var k = (ts - t0) / 1600;
          if (k >= 1) { set(50); range.value = 50; return; }
          var v = 50 + Math.sin(k * Math.PI * 2) * 18 * (1 - k);
          set(v.toFixed(1));
          requestAnimationFrame(wiggle);
        }
        requestAnimationFrame(wiggle);
      }, { threshold: 0.6 }).observe(c);
    }
  });

  /* Galerie: Filter und Lightbox */
  var filter = document.querySelector('.gal-filter');
  if (filter) {
    filter.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      [].forEach.call(filter.querySelectorAll('button'), function (x) { x.setAttribute('aria-pressed', String(x === b)); });
      var cat = b.getAttribute('data-filter');
      [].forEach.call(document.querySelectorAll('.pair'), function (p) {
        p.hidden = cat !== 'alle' && p.getAttribute('data-cat') !== cat;
      });
    });
  }
  var lb = document.querySelector('.lightbox');
  if (lb) {
    var lbImg = lb.querySelector('.lightbox__img');
    var lbCap = lb.querySelector('.lightbox__cap');
    var items = [], idx = 0, lastFocus = null;
    function visibleItems() {
      return [].filter.call(document.querySelectorAll('[data-lb]'), function (b) { return !b.closest('.pair').hidden; });
    }
    function show(i) {
      idx = (i + items.length) % items.length;
      var b = items[idx];
      lbImg.innerHTML = b.querySelector('svg').outerHTML;
      lbImg.firstChild.setAttribute('aria-hidden', 'true');
      lbCap.innerHTML = '';
      lbCap.appendChild(document.createTextNode(b.getAttribute('data-caption')));
      var n = document.createElement('span');
      n.className = 'lightbox__count';
      n.textContent = (idx + 1) + ' / ' + items.length;
      lbCap.appendChild(n);
    }
    function open(btn) {
      items = visibleItems(); lastFocus = btn;
      show(items.indexOf(btn));
      lb.classList.add('is-open'); lb.setAttribute('aria-hidden', 'false');
      doc.style.overflow = 'hidden';
      lb.querySelector('.lb-close').focus();
    }
    function close() {
      lb.classList.remove('is-open'); lb.setAttribute('aria-hidden', 'true');
      doc.style.overflow = '';
      if (lastFocus) lastFocus.focus();
    }
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-lb]');
      if (b) open(b);
    });
    lb.querySelector('.lb-close').addEventListener('click', close);
    lb.querySelector('.lb-prev').addEventListener('click', function () { show(idx - 1); });
    lb.querySelector('.lb-next').addEventListener('click', function () { show(idx + 1); });
    lb.addEventListener('click', function (e) { if (e.target === lb) close(); });
    document.addEventListener('keydown', function (e) {
      if (!lb.classList.contains('is-open')) return;
      if (e.key === 'Escape') close();
      if (e.key === 'ArrowLeft') show(idx - 1);
      if (e.key === 'ArrowRight') show(idx + 1);
      if (e.key === 'Tab') {
        var f = lb.querySelectorAll('button');
        var first = f[0], last = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
      }
    });
    var sx = null;
    lb.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; }, { passive: true });
    lb.addEventListener('touchend', function (e) {
      if (sx === null) return;
      var dx = e.changedTouches[0].clientX - sx;
      if (Math.abs(dx) > 50) show(idx + (dx < 0 ? 1 : -1));
      sx = null;
    });
  }

  /* Öffnungszeiten: heutigen Tag markieren, offen/geschlossen anzeigen */
  var hours = { 2: [10, 18], 3: [10, 18], 4: [10, 18], 5: [10, 18], 6: [10, 16] };
  var now = new Date();
  var day = now.getDay();
  [].forEach.call(document.querySelectorAll('.hours tr[data-day]'), function (tr) {
    if (tr.getAttribute('data-day').split(',').indexOf(String(day)) > -1) tr.classList.add('is-today');
  });
  [].forEach.call(document.querySelectorAll('.open-state'), function (el) {
    var h = now.getHours() + now.getMinutes() / 60;
    var t = hours[day];
    var isOpen = t && h >= t[0] && h < t[1];
    el.classList.toggle('is-open', !!isOpen);
    el.textContent = isOpen ? 'Jetzt geöffnet – bis ' + t[1] + ':00 Uhr' : 'Gerade geschlossen';
  });

  /* Karte erst nach Zustimmung laden */
  [].forEach.call(document.querySelectorAll('[data-map]'), function (btn) {
    btn.addEventListener('click', function () {
      var box = btn.closest('.map');
      var f = document.createElement('iframe');
      f.src = box.getAttribute('data-src');
      f.title = 'Karte: Faden & Form Atelier, Musterstrasse 22, 4410 Liestal';
      f.loading = 'lazy';
      box.innerHTML = '';
      box.appendChild(f);
    });
  });

  [].forEach.call(document.querySelectorAll('[data-year]'), function (el) { el.textContent = new Date().getFullYear(); });

  /* Formular: Rückmeldung nach dem Absenden */
  var msg = document.getElementById('form-msg');
  if (msg) {
    var q = new URLSearchParams(window.location.search).get('anfrage');
    if (q === 'ok') {
      msg.className = 'form__msg form__msg--ok';
      msg.textContent = 'Danke für deine Anfrage! Wir melden uns innert zwei Arbeitstagen bei dir.';
      msg.hidden = false;
    } else if (q === 'fehler') {
      msg.className = 'form__msg form__msg--err';
      msg.textContent = 'Das hat leider nicht geklappt. Bitte prüf die Pflichtfelder oder ruf uns an: 061 000 00 00.';
      msg.hidden = false;
    }
    if (q) msg.scrollIntoView({ block: 'center' });
  }
})();
