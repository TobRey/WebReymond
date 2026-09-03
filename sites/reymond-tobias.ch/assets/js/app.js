/* =============================================================================
   Reymond Tobias — Interaktion und 3D-Bewegung
   Laeuft ohne Abhaengigkeiten. Faellt ohne JavaScript sauber zurueck: die
   Texte stehen bereits fertig in der Seite, weil sie auf dem Server
   eingesetzt werden (siehe lib/page.php).
   ========================================================================== */
(function () {
  'use strict';

  var root = document.documentElement;
  var motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  var calm = motionQuery.matches;
  var coarse = window.matchMedia('(hover: none)').matches;

  function on(el, ev, fn, opts) { if (el) el.addEventListener(ev, fn, opts || false); }
  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }

  root.classList.add('js');
  if (calm) root.classList.add('calm');

  /* ---------------------------------------------------------------------
     1. Kopfzeile: Hintergrund ab dem ersten Scrollen, Fortschrittsbalken
     ------------------------------------------------------------------ */
  var header = $('.site-header');
  var progress = $('.progress');

  /* ---------------------------------------------------------------------
     2. Menue fuer schmale Bildschirme
     ------------------------------------------------------------------ */
  var navToggle = $('.nav-toggle');
  var mobileNav = $('#mobile-nav');

  function setNav(open) {
    if (!navToggle || !mobileNav) return;
    navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    mobileNav.setAttribute('data-open', open ? 'true' : 'false');
    mobileNav.setAttribute('aria-hidden', open ? 'false' : 'true');
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) {
      /* Die Blende wird ohne Verzögerung sichtbar (siehe Stylesheet),
         deshalb lässt sich der erste Verweis sofort anspringen. */
      var first = $('a', mobileNav);
      if (first) { first.focus(); }
    }
  }

  on(navToggle, 'click', function () {
    setNav(navToggle.getAttribute('aria-expanded') !== 'true');
  });
  on(mobileNav, 'click', function (e) {
    if (e.target.tagName === 'A') setNav(false);
  });
  on(document, 'keydown', function (e) {
    if (e.key === 'Escape' && navToggle && navToggle.getAttribute('aria-expanded') === 'true') {
      setNav(false);
      navToggle.focus();
    }
  });
  if (mobileNav) mobileNav.setAttribute('aria-hidden', 'true');

  /* ---------------------------------------------------------------------
     3. Auftritt beim Scrollen
     ------------------------------------------------------------------ */
  var revealables = $$('[data-reveal]');
  if (calm || !('IntersectionObserver' in window)) {
    revealables.forEach(function (el) { el.classList.add('is-in'); });
  } else {
    var revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-in');
          revealObserver.unobserve(entry.target);
        }
      });
    }, { rootMargin: '0px 0px -12% 0px', threshold: 0.08 });
    revealables.forEach(function (el) { revealObserver.observe(el); });
  }

  /* ---------------------------------------------------------------------
     4. Abschnitt im Menue markieren
     ------------------------------------------------------------------ */
  var navLinks = $$('.nav__link[href^="#"]');
  var sections = navLinks.map(function (a) {
    return document.getElementById(a.getAttribute('href').slice(1));
  }).filter(Boolean);

  if (sections.length && 'IntersectionObserver' in window) {
    var spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        navLinks.forEach(function (a) {
          var match = a.getAttribute('href') === '#' + entry.target.id;
          if (match) { a.setAttribute('aria-current', 'true'); }
          else { a.removeAttribute('aria-current'); }
        });
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    sections.forEach(function (s) { spy.observe(s); });
  }

  /* ---------------------------------------------------------------------
     5. Scrollschleife: Tiefenversatz, Kopfzeile, Fortschritt, 3D-Bahn
     ------------------------------------------------------------------ */
  var depthEls = $$('[data-depth]');
  var hero = $('.hero');
  var heroInner = $('.hero__inner');
  var rails = $$('.rail-outer');
  var ticking = false;
  var pointer = { x: 0, y: 0, tx: 0, ty: 0 };
  var vh = window.innerHeight || 800;

  function measure() { vh = window.innerHeight || 800; }
  measure();

  function frame() {
    ticking = false;
    var y = window.pageYOffset || root.scrollTop || 0;

    if (header) header.classList.toggle('is-stuck', y > 12);

    if (progress) {
      var max = (document.documentElement.scrollHeight - vh) || 1;
      progress.style.setProperty('--p', clamp(y / max, 0, 1).toFixed(4));
    }

    if (calm) return;

    /* weiche Zeigerbewegung */
    pointer.x += (pointer.tx - pointer.x) * 0.08;
    pointer.y += (pointer.ty - pointer.y) * 0.08;

    /* Aufmacher: leichte Drehung aus Zeiger und Scrollstand */
    if (heroInner && y < vh * 1.2) {
      var p = clamp(y / vh, 0, 1);
      heroInner.style.transform =
        'translate3d(0,' + (y * 0.16).toFixed(2) + 'px,0) ' +
        'rotateX(' + (pointer.y * -3.2 + p * 7).toFixed(3) + 'deg) ' +
        'rotateY(' + (pointer.x * 4.2).toFixed(3) + 'deg) ' +
        'scale(' + (1 - p * 0.06).toFixed(4) + ')';
      heroInner.style.opacity = (1 - p * 0.85).toFixed(3);
    }

    /* Tiefenversatz einzelner Bausteine */
    for (var i = 0; i < depthEls.length; i++) {
      var el = depthEls[i];
      var rect = el.getBoundingClientRect();
      if (rect.bottom < -200 || rect.top > vh + 200) continue;
      var mid = rect.top + rect.height / 2;
      var rel = (mid - vh / 2) / vh;            /* -1 .. 1 */
      var d = parseFloat(el.getAttribute('data-depth')) || 0;
      var rot = parseFloat(el.getAttribute('data-depth-rot')) || 0;
      el.style.transform =
        'translate3d(0,' + (rel * d * -60).toFixed(2) + 'px,0)' +
        (rot ? ' rotateX(' + (rel * rot).toFixed(2) + 'deg)' : '');
    }

    /* Waagrechte 3D-Bahn */
    for (var r = 0; r < rails.length; r++) {
      var outer = rails[r];
      var track = $('.rail', outer);
      if (!track) continue;
      var ro = outer.getBoundingClientRect();
      if (ro.bottom < 0 || ro.top > vh) continue;
      var span = outer.offsetHeight - vh;
      if (span <= 0) continue;
      var t = clamp(-ro.top / span, 0, 1);
      var travel = Math.max(0, track.scrollWidth - window.innerWidth + 48);
      track.style.transform =
        'translate3d(' + (-travel * t).toFixed(1) + 'px,0,0) ' +
        'rotateY(' + ((t - 0.5) * -7).toFixed(2) + 'deg)';
    }
  }

  function requestFrame() {
    if (!ticking) { ticking = true; window.requestAnimationFrame(frame); }
  }

  on(window, 'scroll', requestFrame, { passive: true });
  on(window, 'resize', function () { measure(); requestFrame(); });

  if (!calm && !coarse) {
    on(window, 'pointermove', function (e) {
      pointer.tx = (e.clientX / window.innerWidth) * 2 - 1;
      pointer.ty = (e.clientY / window.innerHeight) * 2 - 1;
      requestFrame();
    }, { passive: true });
  }
  requestFrame();

  /* Sanfte Dauerbewegung im Aufmacher, solange er sichtbar ist */
  if (!calm && !coarse && hero) {
    var heroVisible = true;
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (e) { heroVisible = e[0].isIntersecting; }, { threshold: 0 })
        .observe(hero);
    }
    (function idle() {
      if (heroVisible && (Math.abs(pointer.tx - pointer.x) > 0.001 || Math.abs(pointer.ty - pointer.y) > 0.001)) {
        requestFrame();
      }
      window.requestAnimationFrame(idle);
    })();
  }

  /* ---------------------------------------------------------------------
     6. Karten neigen (3D) und Lichtreflex
     ------------------------------------------------------------------ */
  if (!calm && !coarse) {
    $$('.tilt').forEach(function (card) {
      var raf = null, rect = null;

      function apply(e) {
        raf = null;
        if (!rect) rect = card.getBoundingClientRect();
        var px = (e.clientX - rect.left) / rect.width;
        var py = (e.clientY - rect.top) / rect.height;
        var max = parseFloat(card.getAttribute('data-tilt')) || 8;
        card.style.transform =
          'perspective(900px) rotateY(' + ((px - 0.5) * max * 2).toFixed(2) + 'deg) ' +
          'rotateX(' + ((0.5 - py) * max * 2).toFixed(2) + 'deg) translateZ(14px)';
        card.style.setProperty('--mx', (px * 100).toFixed(1) + '%');
        card.style.setProperty('--my', (py * 100).toFixed(1) + '%');
      }

      on(card, 'pointerenter', function () { rect = card.getBoundingClientRect(); });
      on(card, 'pointermove', function (e) {
        if (raf) return;
        raf = window.requestAnimationFrame(function () { apply(e); });
      }, { passive: true });
      on(card, 'pointerleave', function () {
        rect = null;
        card.style.transform = '';
      });
    });
  }

  /* ---------------------------------------------------------------------
     7. Bewegter Hintergrund im Aufmacher (3D-Punktwolke)
     ------------------------------------------------------------------ */
  var canvas = $('.hero__canvas');
  if (canvas && !calm && canvas.getContext) {
    (function () {
      var ctx = canvas.getContext('2d');
      var dpr = Math.min(window.devicePixelRatio || 1, 2);
      var w = 0, h = 0;
      var pts = [];
      var running = true;
      var visible = true;
      var rot = { x: 0, y: 0 };
      var accent = [124, 152, 255];

      function size() {
        w = canvas.clientWidth || canvas.offsetWidth || window.innerWidth;
        h = canvas.clientHeight || canvas.offsetHeight || window.innerHeight;
        canvas.width = Math.round(w * dpr);
        canvas.height = Math.round(h * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      }

      function build() {
        pts = [];
        var count = w < 700 ? 46 : (w < 1200 ? 74 : 104);
        for (var i = 0; i < count; i++) {
          /* gleichmaessig auf einer Kugelschale, mit etwas Streuung */
          var u = Math.random() * 2 - 1;
          var th = Math.random() * Math.PI * 2;
          var s = Math.sqrt(1 - u * u);
          var r = 240 + Math.random() * 190;
          pts.push({
            x: s * Math.cos(th) * r,
            y: u * r * 0.72,
            z: s * Math.sin(th) * r,
            s: 0.7 + Math.random() * 1.6,
            sp: 0.0004 + Math.random() * 0.0008
          });
        }
      }

      function draw(now) {
        if (!running) return;
        window.requestAnimationFrame(draw);
        if (!visible) return;

        var scrollY = window.pageYOffset || 0;
        rot.y = now * 0.00006 + pointer.x * 0.22 + scrollY * 0.0012;
        rot.x = Math.sin(now * 0.00004) * 0.16 + pointer.y * -0.16;

        ctx.clearRect(0, 0, w, h);

        var cx = w / 2, cy = h * 0.46;
        var f = 620;
        var cosY = Math.cos(rot.y), sinY = Math.sin(rot.y);
        var cosX = Math.cos(rot.x), sinX = Math.sin(rot.x);
        var proj = [];

        for (var i = 0; i < pts.length; i++) {
          var p = pts[i];
          p.y += Math.sin(now * p.sp + i) * 0.06;

          var x1 = p.x * cosY - p.z * sinY;
          var z1 = p.x * sinY + p.z * cosY;
          var y1 = p.y * cosX - z1 * sinX;
          var z2 = p.y * sinX + z1 * cosX;

          var d = f + z2;
          if (d < 60) continue;
          var k = f / d;
          proj.push({ x: cx + x1 * k, y: cy + y1 * k, k: k, s: p.s });
        }

        /* Verbindungslinien in der Naehe */
        var maxDist = w < 700 ? 108 : 138;
        ctx.lineWidth = 1;
        for (var a = 0; a < proj.length; a++) {
          for (var b = a + 1; b < proj.length; b++) {
            var dx = proj[a].x - proj[b].x;
            var dy = proj[a].y - proj[b].y;
            var dist = dx * dx + dy * dy;
            if (dist > maxDist * maxDist) continue;
            var alpha = (1 - Math.sqrt(dist) / maxDist) * 0.2 * Math.min(proj[a].k, proj[b].k);
            if (alpha <= 0.004) continue;
            ctx.strokeStyle = 'rgba(' + accent[0] + ',' + accent[1] + ',' + accent[2] + ',' + alpha.toFixed(3) + ')';
            ctx.beginPath();
            ctx.moveTo(proj[a].x, proj[a].y);
            ctx.lineTo(proj[b].x, proj[b].y);
            ctx.stroke();
          }
        }

        /* Punkte */
        for (var c = 0; c < proj.length; c++) {
          var q = proj[c];
          var rad = q.s * q.k;
          if (rad < 0.15) continue;
          ctx.fillStyle = 'rgba(' + accent[0] + ',' + accent[1] + ',' + accent[2] + ',' + (0.16 + q.k * 0.42).toFixed(3) + ')';
          ctx.beginPath();
          ctx.arc(q.x, q.y, rad, 0, Math.PI * 2);
          ctx.fill();
        }
      }

      size();
      build();
      window.requestAnimationFrame(draw);

      var resizeTimer = null;
      on(window, 'resize', function () {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(function () { size(); build(); }, 180);
      });

      on(document, 'visibilitychange', function () { visible = !document.hidden; });
      if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (e) { visible = e[0].isIntersecting && !document.hidden; }, { threshold: 0 })
          .observe(canvas);
      }
      on(motionQuery, 'change', function (e) {
        if (e.matches) { running = false; ctx.clearRect(0, 0, w, h); }
      });
    })();
  }

  /* ---------------------------------------------------------------------
     8. Kontaktformular: Zeitfalle setzen, Absenden ohne Neuladen
     ------------------------------------------------------------------ */
  var form = $('#kontaktformular');
  if (form) {
    var started = form.querySelector('input[name="started"]');
    if (started) started.value = String(Math.floor(Date.now() / 1000));

    var box = $('#formular-meldung');
    var submit = form.querySelector('button[type="submit"]');

    function say(kind, text) {
      if (!box) return;
      box.className = 'notice notice--' + kind;
      box.textContent = text;
      box.hidden = false;
      box.setAttribute('tabindex', '-1');
      box.focus();
    }

    on(form, 'submit', function (e) {
      if (!window.fetch || !window.FormData) return; /* normaler Versand */
      e.preventDefault();
      if (submit) { submit.disabled = true; submit.dataset.label = submit.textContent; submit.textContent = form.getAttribute('data-sending') || 'Wird gesendet …'; }

      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }
      })
        .then(function (r) { return r.json().catch(function () { return { ok: false, message: '' }; }); })
        .then(function (res) {
          if (res && res.ok) {
            say('ok', res.message || form.getAttribute('data-ok') || 'Danke, deine Nachricht ist unterwegs.');
            form.reset();
            if (started) started.value = String(Math.floor(Date.now() / 1000));
          } else {
            say('bad', (res && res.message) || form.getAttribute('data-error') || 'Das hat nicht geklappt. Bitte versuch es noch einmal.');
          }
        })
        .catch(function () {
          say('bad', form.getAttribute('data-error') || 'Das hat nicht geklappt. Bitte versuch es noch einmal.');
        })
        .then(function () {
          if (submit) { submit.disabled = false; submit.textContent = submit.dataset.label || 'Nachricht senden'; }
        });
    });
  }

  /* ---------------------------------------------------------------------
     9. Besucherzaehlung (eigene, ohne IP-Adressen)
     ------------------------------------------------------------------ */
  (function () {
    if (!window.fetch) return;
    var base = root.getAttribute('data-base');
    if (base === null) { base = ''; }
    var path = window.location.pathname || '/';
    var isNew = '0';
    try {
      if (!window.sessionStorage.getItem('rt_seen')) {
        window.sessionStorage.setItem('rt_seen', '1');
        isNew = '1';
      }
    } catch (err) { /* Speicher gesperrt – dann eben nur Aufrufe */ }

    var send = function () {
      fetch(base + 'api/count.php?p=' + encodeURIComponent(path) + '&n=' + isNew, {
        method: 'GET', cache: 'no-store', credentials: 'omit', keepalive: true
      }).catch(function () {});
    };
    if ('requestIdleCallback' in window) { window.requestIdleCallback(send, { timeout: 2500 }); }
    else { window.setTimeout(send, 900); }
  })();

  /* ---------------------------------------------------------------------
     10. Jahreszahl in der Fusszeile
     ------------------------------------------------------------------ */
  $$('[data-year]').forEach(function (el) { el.textContent = String(new Date().getFullYear()); });
})();
