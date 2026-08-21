/*
 * DJ ATZE – gemeinsames Verhalten aller Seiten.
 *
 * Enthält: Ladevorhang, eigener Mauszeiger, Menü, Kopfzeile beim Scrollen,
 * Einblenden von Abschnitten, Buchstaben-Animation, Laufband, Parallaxe.
 *
 * Grundsatz: Ohne JavaScript bleibt die Seite lesbar – alle Effekte sind
 * Zugaben, keine Voraussetzung.
 */

(function () {
  'use strict';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ----------------------------------------------------------------------
   * Ladevorhang
   * -------------------------------------------------------------------- */

  function initPreloader() {
    var done = function () {
      window.setTimeout(
        function () {
          document.body.classList.add('is-loaded');
          // Erste Sichtprüfung erst nach dem Vorhang, sonst laufen die
          // Animationen hinter der schwarzen Fläche ins Leere.
          window.setTimeout(revealInView, 120);
        },
        reduceMotion ? 0 : 900,
      );
    };

    if (document.readyState === 'complete') {
      done();
    } else {
      window.addEventListener('load', done);
    }
  }

  /* ----------------------------------------------------------------------
   * Eigener Mauszeiger (nur mit echter Maus)
   * -------------------------------------------------------------------- */

  function initCursor() {
    if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    var ring = document.querySelector('.cursor');
    var dot = document.querySelector('.cursor-dot');
    if (!ring || !dot) return;

    var mouseX = window.innerWidth / 2;
    var mouseY = window.innerHeight / 2;
    var ringX = mouseX;
    var ringY = mouseY;

    window.addEventListener('mousemove', function (event) {
      mouseX = event.clientX;
      mouseY = event.clientY;
      dot.style.transform = 'translate3d(' + (mouseX - 2.5) + 'px,' + (mouseY - 2.5) + 'px,0)';
      document.body.classList.add('cursor-ready');
    });

    // Der Ring folgt verzögert – das wirkt weicher als hartes Nachziehen.
    (function loop() {
      ringX += (mouseX - ringX) * 0.16;
      ringY += (mouseY - ringY) * 0.16;
      var size = ring.classList.contains('is-active') ? 42 : 21;
      ring.style.transform = 'translate3d(' + (ringX - size) + 'px,' + (ringY - size) + 'px,0)';
      window.requestAnimationFrame(loop);
    })();

    var targets = 'a, button, .track, .date-row, input, textarea, select, .player__scrub';
    document.querySelectorAll(targets).forEach(function (element) {
      element.addEventListener('mouseenter', function () {
        ring.classList.add('is-active');
      });
      element.addEventListener('mouseleave', function () {
        ring.classList.remove('is-active');
      });
    });

    document.addEventListener('mouseleave', function () {
      document.body.classList.remove('cursor-ready');
    });
  }

  /* ----------------------------------------------------------------------
   * Menü für kleine Bildschirme
   * -------------------------------------------------------------------- */

  function initMenu() {
    var burger = document.querySelector('.burger');
    var menu = document.querySelector('.menu');
    if (!burger || !menu) return;

    var toggle = function (open) {
      document.body.classList.toggle('menu-open', open);
      document.body.classList.toggle('is-locked', open);
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    burger.addEventListener('click', function () {
      toggle(!document.body.classList.contains('menu-open'));
    });

    menu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        toggle(false);
      });
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') toggle(false);
    });
  }

  /* ----------------------------------------------------------------------
   * Kopfzeile: versteckt sich beim Runterscrollen, kommt beim Hochscrollen
   * -------------------------------------------------------------------- */

  function initHeader() {
    var header = document.querySelector('.site-header');
    if (!header) return;

    var lastY = window.scrollY;
    var ticking = false;

    var update = function () {
      var y = window.scrollY;
      header.classList.toggle('is-stuck', y > 40);

      if (!document.body.classList.contains('menu-open')) {
        var goingDown = y > lastY && y > 260;
        header.classList.toggle('is-hidden', goingDown);
      }

      lastY = y;
      ticking = false;
    };

    window.addEventListener(
      'scroll',
      function () {
        if (!ticking) {
          window.requestAnimationFrame(update);
          ticking = true;
        }
      },
      { passive: true },
    );
  }

  /* ----------------------------------------------------------------------
   * Einblenden beim Scrollen
   * -------------------------------------------------------------------- */

  var observer = null;

  function initReveal() {
    var items = document.querySelectorAll('.reveal, .line-mask');
    if (!items.length) return;

    if (!('IntersectionObserver' in window)) {
      items.forEach(function (item) {
        item.classList.add('is-in');
      });
      return;
    }

    observer = new window.IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          entry.target.classList.add('is-in');
          observer.unobserve(entry.target);
        });
      },
      { threshold: 0.12, rootMargin: '0px 0px -8% 0px' },
    );

    items.forEach(function (item) {
      observer.observe(item);
    });
  }

  // Alles, was schon beim Laden sichtbar ist, sofort einblenden.
  function revealInView() {
    document.querySelectorAll('.reveal, .line-mask').forEach(function (item) {
      var box = item.getBoundingClientRect();
      if (box.top < window.innerHeight * 0.95) {
        item.classList.add('is-in');
        if (observer) observer.unobserve(item);
      }
    });
  }

  /* ----------------------------------------------------------------------
   * Buchstabenweiser Auftritt für grosse Titel
   * Markup: <span data-split>TEXT</span>
   * -------------------------------------------------------------------- */

  function initSplit() {
    document.querySelectorAll('[data-split]').forEach(function (element) {
      var text = element.textContent.trim();
      var html = '';
      var index = 0;

      for (var i = 0; i < text.length; i += 1) {
        var character = text[i];
        if (character === ' ') {
          html += ' ';
        } else {
          html +=
            '<span class="char" style="transition-delay:' +
            (index * 0.035).toFixed(3) +
            's">' +
            character +
            '</span>';
          index += 1;
        }
      }

      element.innerHTML = html;
      element.setAttribute('aria-label', text);
    });
  }

  /* ----------------------------------------------------------------------
   * Laufband: Inhalt wird verdoppelt, damit die Schleife nahtlos wirkt
   * -------------------------------------------------------------------- */

  function initMarquee() {
    document.querySelectorAll('.marquee').forEach(function (marquee) {
      var track = marquee.querySelector('.marquee__track');
      if (!track) return;
      var copy = track.cloneNode(true);
      copy.setAttribute('aria-hidden', 'true');
      marquee.appendChild(copy);
    });
  }

  /* ----------------------------------------------------------------------
   * Parallaxe: Bannerbild bewegt sich langsamer als der Rest
   * -------------------------------------------------------------------- */

  function initParallax() {
    if (reduceMotion) return;

    var layers = document.querySelectorAll('[data-parallax]');
    if (!layers.length) return;

    var ticking = false;

    var update = function () {
      var y = window.scrollY;
      layers.forEach(function (layer) {
        var speed = parseFloat(layer.getAttribute('data-parallax')) || 0.15;
        layer.style.transform = 'translate3d(0,' + (y * speed).toFixed(2) + 'px,0) scale(1.08)';
      });
      ticking = false;
    };

    window.addEventListener(
      'scroll',
      function () {
        if (!ticking) {
          window.requestAnimationFrame(update);
          ticking = true;
        }
      },
      { passive: true },
    );
  }

  /* ----------------------------------------------------------------------
   * Magnetische Knöpfe: der Knopf neigt sich leicht zum Zeiger
   * -------------------------------------------------------------------- */

  function initMagnetic() {
    if (reduceMotion) return;
    if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    document.querySelectorAll('[data-magnetic]').forEach(function (element) {
      element.addEventListener('mousemove', function (event) {
        var box = element.getBoundingClientRect();
        var x = event.clientX - box.left - box.width / 2;
        var y = event.clientY - box.top - box.height / 2;
        element.style.transform = 'translate3d(' + x * 0.22 + 'px,' + y * 0.32 + 'px,0)';
      });

      element.addEventListener('mouseleave', function () {
        element.style.transform = '';
      });
    });
  }

  /* ----------------------------------------------------------------------
   * Jahreszahl in der Fusszeile
   * -------------------------------------------------------------------- */

  function initYear() {
    document.querySelectorAll('[data-year]').forEach(function (element) {
      element.textContent = String(new Date().getFullYear());
    });
  }

  /* ----------------------------------------------------------------------
   * Start
   * -------------------------------------------------------------------- */

  function start() {
    initSplit();
    initMarquee();
    initReveal();
    initPreloader();
    initCursor();
    initMenu();
    initHeader();
    initParallax();
    initMagnetic();
    initYear();
    revealInView();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
