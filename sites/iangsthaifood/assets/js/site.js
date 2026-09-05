/*
  Iang's Thai Food — wenig JavaScript, nichts von aussen.
  1. Menü für schmale Bildschirme
  2. Ruhiges Einblenden beim Scrollen
  3. Sehr zurückhaltende Tiefenbewegung im Aufmacher
  4. Zeitfalle im Formular
  5. Zählung der Seitenaufrufe (eigener Zähler, ohne IP, ohne Cookie)
*/
(function () {
  "use strict";

  var script = document.currentScript;
  var ruhig = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* --- 1. Menü ------------------------------------------------ */
  var toggle = document.querySelector(".nav-toggle");
  var nav = document.querySelector(".nav");

  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      var offen = nav.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", offen ? "true" : "false");
    });

    nav.addEventListener("keydown", function (e) {
      if (e.key === "Escape") {
        nav.classList.remove("is-open");
        toggle.setAttribute("aria-expanded", "false");
        toggle.focus();
      }
    });
  }

  /* --- 2. Einblenden ------------------------------------------ */
  var ziele = document.querySelectorAll(".reveal");

  if (!ruhig && "IntersectionObserver" in window && ziele.length) {
    var beobachter = new IntersectionObserver(
      function (eintraege) {
        eintraege.forEach(function (eintrag) {
          if (eintrag.isIntersecting) {
            eintrag.target.classList.add("is-in");
            beobachter.unobserve(eintrag.target);
          }
        });
      },
      { rootMargin: "0px 0px -8% 0px", threshold: 0.08 }
    );
    Array.prototype.forEach.call(ziele, function (el) {
      beobachter.observe(el);
    });
  } else {
    Array.prototype.forEach.call(ziele, function (el) {
      el.classList.add("is-in");
    });
  }

  /* --- 3. Tiefenbewegung -------------------------------------- */
  var schichten = document.querySelectorAll(".js-parallax");

  if (!ruhig && schichten.length && window.matchMedia("(min-width: 48rem)").matches) {
    var laeuft = false;

    var zeichnen = function () {
      var y = window.pageYOffset || document.documentElement.scrollTop;
      Array.prototype.forEach.call(schichten, function (el) {
        var tempo = parseFloat(el.getAttribute("data-parallax")) || 0.12;
        el.style.transform = "translate3d(0," + (y * tempo).toFixed(2) + "px,0)";
      });
      laeuft = false;
    };

    window.addEventListener(
      "scroll",
      function () {
        if (!laeuft) {
          laeuft = true;
          window.requestAnimationFrame(zeichnen);
        }
      },
      { passive: true }
    );
    zeichnen();
  }

  /* --- 5. Zeitfalle im Formular -------------------------------- */
  /* Der Server prüft, wie viel Zeit zwischen Aufruf und Absenden lag.
     Ohne JavaScript bleibt das Feld leer und die Prüfung entfällt;
     Honigtopf und Begrenzung greifen dann trotzdem. */
  Array.prototype.forEach.call(
    document.querySelectorAll("form[data-zeitfalle] input[name='zeit']"),
    function (feld) {
      feld.value = String(Date.now());
    }
  );

  /* --- 6. Eigener Zähler -------------------------------------- */
  /* Gezählt wird nur, welche Seite wie oft aufgerufen wurde.
     Keine IP-Adresse, kein Cookie, keine Kennung, keine Weitergabe. */
  try {
    if (!script || !script.src) {
      return;
    }
    var ziel = new URL("../../zaehler.php", script.src).href;
    var pfad = window.location.pathname || "/";
    var daten = new Blob([JSON.stringify({ p: pfad })], {
      type: "application/json",
    });

    if (navigator.sendBeacon) {
      navigator.sendBeacon(ziel, daten);
    } else {
      var anfrage = new XMLHttpRequest();
      anfrage.open("POST", ziel, true);
      anfrage.setRequestHeader("Content-Type", "application/json");
      anfrage.send(JSON.stringify({ p: pfad }));
    }
  } catch (e) {
    /* Zählen ist nie wichtiger als die Seite selbst. */
  }
})();
