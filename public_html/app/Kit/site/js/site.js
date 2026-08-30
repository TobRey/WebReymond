/**
 * Kundenwebsite – Bedienung.
 *
 * Knapp gehalten: unter 3 KB, keine Abhängigkeiten. Alles hier ist
 * Zugabe. Ohne dieses Skript bleibt die Seite vollständig bedienbar –
 * Navigation, Formular und Fragen funktionieren auch ohne.
 */

(function () {
  'use strict';

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.documentElement.classList.add('js');

  /* ---------------------------------------------------------------- */
  /* Navigation auf schmalen Bildschirmen                              */
  /* ---------------------------------------------------------------- */

  var burger = document.querySelector('.s-header__burger');
  var mobile = document.getElementById('s-mobile-nav');

  if (burger && mobile) {
    burger.addEventListener('click', function () {
      var open = burger.getAttribute('aria-expanded') === 'true';
      burger.setAttribute('aria-expanded', open ? 'false' : 'true');
      mobile.hidden = open;
    });
  }

  /* ---------------------------------------------------------------- */
  /* Kopfzeile beim Scrollen                                           */
  /* ---------------------------------------------------------------- */

  var header = document.querySelector('.s-header');
  if (header) {
    var onScroll = function () {
      header.classList.toggle('is-stuck', window.scrollY > 8);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---------------------------------------------------------------- */
  /* Einblenden beim Scrollen                                          */
  /* ---------------------------------------------------------------- */

  if (!reduced && 'IntersectionObserver' in window) {
    var targets = document.querySelectorAll('[data-section] > .s-shell > *, [data-section] > .s-shell');
    var seen = new WeakSet();
    var index = 0;

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-in');
        observer.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.08 });

    Array.prototype.forEach.call(targets, function (el) {
      if (seen.has(el) || el.closest('[data-section="header"]')) return;
      seen.add(el);
      el.setAttribute('data-reveal', '');
      el.style.setProperty('--i', String(index++ % 6));

      // Was beim Laden schon sichtbar ist, wird sofort gezeigt.
      if (el.getBoundingClientRect().top < window.innerHeight) {
        el.classList.add('is-in');
      } else {
        observer.observe(el);
      }
    });
  }

  /* ---------------------------------------------------------------- */
  /* Bilder weich einblenden                                           */
  /* ---------------------------------------------------------------- */

  Array.prototype.forEach.call(document.querySelectorAll('img[loading="lazy"]'), function (img) {
    if (img.complete) {
      img.classList.add('is-loaded');
    } else {
      img.addEventListener('load', function () { img.classList.add('is-loaded'); }, { once: true });
      img.addEventListener('error', function () { img.classList.add('is-loaded'); }, { once: true });
    }
  });

  /* ---------------------------------------------------------------- */
  /* Nur eine Frage gleichzeitig offen                                 */
  /* ---------------------------------------------------------------- */

  var faqItems = document.querySelectorAll('.s-faq__item');
  Array.prototype.forEach.call(faqItems, function (item) {
    item.addEventListener('toggle', function () {
      if (!item.open) return;
      Array.prototype.forEach.call(faqItems, function (other) {
        if (other !== item) other.open = false;
      });
    });
  });

  /* ---------------------------------------------------------------- */
  /* Zahlen hochzählen                                                 */
  /* ---------------------------------------------------------------- */

  var counters = document.querySelectorAll('[data-count]');
  if (counters.length && !reduced && 'IntersectionObserver' in window) {
    var countObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        countObserver.unobserve(el);

        var raw = el.getAttribute('data-count') || '';
        var digits = raw.replace(/[^\d]/g, '');
        if (!digits) return;

        // Vor- und Nachsatz bleiben erhalten (z.B. "über 250+")
        var target = parseInt(digits, 10);
        var parts = raw.split(digits);
        var start = performance.now();

        var step = function (now) {
          var t = Math.min((now - start) / 1400, 1);
          var eased = 1 - Math.pow(1 - t, 3);
          el.textContent = parts[0] + Math.round(target * eased) + (parts[1] || '');
          if (t < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      });
    }, { threshold: 0.5 });

    Array.prototype.forEach.call(counters, function (el) { countObserver.observe(el); });
  }

  /* ---------------------------------------------------------------- */
  /* Laufband nahtlos machen                                           */
  /* ---------------------------------------------------------------- */

  Array.prototype.forEach.call(document.querySelectorAll('[data-marquee]'), function (track) {
    if (track.dataset.cloned === '1') return;
    track.dataset.cloned = '1';
    var copy = track.innerHTML;
    track.innerHTML = copy + copy;
  });

  /* ---------------------------------------------------------------- */
  /* Karte erst auf Wunsch laden                                       */
  /*                                                                   */
  /* Ohne Klick verlässt keine Angabe des Besuchers den Server – das    */
  /* erspart einen Hinweis auf einen fremden Dienst beim blossen        */
  /* Seitenaufruf.                                                     */
  /* ---------------------------------------------------------------- */

  Array.prototype.forEach.call(document.querySelectorAll('[data-map-query]'), function (box) {
    var button = box.querySelector('.s-map__load');
    if (!button) return;

    button.addEventListener('click', function () {
      var query = box.getAttribute('data-map-query') || '';
      var frame = document.createElement('iframe');
      frame.src = 'https://www.openstreetmap.org/export/embed.html?bbox=&layer=mapnik&marker='
        + '&query=' + encodeURIComponent(query);
      frame.title = 'Karte';
      frame.loading = 'lazy';
      frame.referrerPolicy = 'no-referrer';
      box.innerHTML = '';
      box.appendChild(frame);
    });
  });

  /* ---------------------------------------------------------------- */
  /* Kontaktformular ohne Neuladen                                     */
  /* ---------------------------------------------------------------- */

  Array.prototype.forEach.call(document.querySelectorAll('.s-contact__form'), function (form) {
    var status = form.querySelector('.s-form__status');
    var button = form.querySelector('button[type="submit"]');

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      if (button) { button.disabled = true; }
      if (status) { status.className = 's-form__status'; status.textContent = ''; }

      fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: { Accept: 'application/json' }
      })
        .then(function (response) { return response.json().catch(function () { return {}; }); })
        .then(function (data) {
          if (data && data.ok) {
            form.innerHTML = '<div class="s-form__status is-ok">'
              + (data.message || 'Danke, deine Nachricht ist angekommen.') + '</div>';
            return;
          }
          if (status) {
            status.className = 's-form__status is-error';
            status.textContent = (data && data.error) || 'Das hat nicht geklappt. Bitte noch einmal versuchen.';
          }
          if (button) { button.disabled = false; }
        })
        .catch(function () {
          if (status) {
            status.className = 's-form__status is-error';
            status.textContent = 'Keine Verbindung. Bitte noch einmal versuchen.';
          }
          if (button) { button.disabled = false; }
        });
    });
  });
})();
