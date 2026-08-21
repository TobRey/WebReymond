/*
 * REYMOND TOBIAS – Audioplayer für die Musikseite.
 *
 * Was er kann:
 *   - Titel wechseln mit Bild- und Schriftanimation
 *   - Balkenanzeige, die auf die echte Musik reagiert (Web Audio API)
 *   - Fortschrittsbalken zum Springen, Lautstärke, Tastatursteuerung
 *   - Vorführmodus: fehlt eine Audiodatei, läuft die Anzeige trotzdem
 *     weiter, damit die Seite nie tot wirkt. Ein Hinweis sagt das offen.
 *
 * Neue Titel: im Dashboard unter „Musik“ anlegen. Sie kommen über
 * window.RC_TRACKS aus data/site.json hierher.
 */

(function () {
  'use strict';

  /* ----------------------------------------------------------------------
   * Titel
   *
   * Sie werden im Dashboard gepflegt und in der Fusszeile der Seite als
   * window.RC_TRACKS mitgegeben.
   * -------------------------------------------------------------------- */

  var TRACKS = Array.isArray(window.RC_TRACKS) ? window.RC_TRACKS : [];

  var root = document.querySelector('[data-player]');
  if (!root || !TRACKS.length) return;

  /* ----------------------------------------------------------------------
   * Bausteine im HTML einsammeln
   * -------------------------------------------------------------------- */

  var elTitle = root.querySelector('.player__title');
  var elTitleText = root.querySelector('.player__title span');
  var elArt = root.querySelector('.player__art');
  var elArtImg = root.querySelector('.player__art img');
  var elIndex = root.querySelector('.player__index');
  var elTag = root.querySelector('[data-meta-tag]');
  var elOf = root.querySelector('[data-meta-count]');
  var elCanvas = root.querySelector('.player__viz');
  var elScrub = root.querySelector('.player__scrub');
  var elFill = root.querySelector('.player__scrub-fill');
  var elKnob = root.querySelector('.player__scrub-knob');
  var elNow = root.querySelector('[data-time-now]');
  var elTotal = root.querySelector('[data-time-total]');
  var elPlay = root.querySelector('.ctrl--play');
  var elPrev = root.querySelector('[data-prev]');
  var elNext = root.querySelector('[data-next]');
  var elVolume = root.querySelector('[data-volume]');
  var elList = document.querySelector('[data-tracklist]');

  var audio = new window.Audio();
  audio.preload = 'metadata';
  audio.volume = 0.85;

  var current = 0;
  var playing = false;
  var demo = false; // true, wenn keine Audiodatei geladen werden konnte
  var demoTime = 0;
  var demoLast = 0;

  /* ----------------------------------------------------------------------
   * Hilfsfunktionen
   * -------------------------------------------------------------------- */

  function toSeconds(text) {
    var parts = String(text || '').split(':');
    var seconds = Number(parts[0]) * 60 + Number(parts[1] || 0);
    // Ohne Angabe im Backend nehmen wir fünf Minuten an, damit die
    // Anzeige im Vorschaumodus nicht durch null teilt.
    return seconds > 0 ? seconds : 300;
  }

  function format(seconds) {
    if (!isFinite(seconds) || seconds < 0) seconds = 0;
    var m = Math.floor(seconds / 60);
    var s = Math.floor(seconds % 60);
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function pad(number) {
    return (number < 10 ? '0' : '') + number;
  }

  function duration() {
    if (!demo && isFinite(audio.duration) && audio.duration > 0) return audio.duration;
    return toSeconds(TRACKS[current].duration);
  }

  function position() {
    return demo ? demoTime : audio.currentTime;
  }

  /* ----------------------------------------------------------------------
   * Titelliste aufbauen
   * -------------------------------------------------------------------- */

  function buildList() {
    if (!elList) return;

    TRACKS.forEach(function (track, index) {
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'track';
      row.setAttribute('data-index', String(index));
      row.innerHTML =
        '<span class="track__num">' +
        pad(index + 1) +
        '</span>' +
        '<span class="track__title">' +
        track.title +
        '</span>' +
        '<span class="track__tag">' +
        track.tag +
        '</span>' +
        '<span class="track__time">' +
        track.duration +
        '</span>' +
        '<span class="track__bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>';

      row.addEventListener('click', function () {
        if (index === current) {
          toggle();
        } else {
          load(index, true);
        }
      });

      elList.appendChild(row);
    });
  }

  function markList() {
    if (!elList) return;
    elList.querySelectorAll('.track').forEach(function (row, index) {
      row.classList.toggle('is-current', index === current);
    });
  }

  /* ----------------------------------------------------------------------
   * Titel laden
   * -------------------------------------------------------------------- */

  function load(index, autoplay) {
    current = (index + TRACKS.length) % TRACKS.length;
    var track = TRACKS[current];

    // Erst ausblenden, dann Inhalt tauschen, dann wieder einblenden.
    if (elTitle) elTitle.classList.add('is-swapping');
    if (elArt) elArt.classList.add('is-swapping');

    window.setTimeout(function () {
      if (elTitleText) elTitleText.textContent = track.title;
      if (elArtImg) {
        elArtImg.src = track.cover;
        elArtImg.alt = 'Cover: ' + track.title;
      }
      if (elIndex) elIndex.textContent = pad(current + 1);
      if (elTag) elTag.textContent = track.tag;
      if (elOf) elOf.textContent = pad(current + 1) + ' / ' + pad(TRACKS.length);
      if (elTitle) elTitle.classList.remove('is-swapping');
      if (elArt) elArt.classList.remove('is-swapping');
    }, 320);

    demo = false;
    demoTime = 0;
    root.classList.remove('is-demo');
    audio.src = track.audio;
    audio.load();

    if (elTotal) elTotal.textContent = track.duration;
    if (elNow) elNow.textContent = '0:00';
    setProgress(0);
    markList();
    updateMediaSession(track);

    if (autoplay) play();
  }

  function updateMediaSession(track) {
    if (!('mediaSession' in window.navigator)) return;
    try {
      window.navigator.mediaSession.metadata = new window.MediaMetadata({
        title: track.title,
        artist: 'Reymond Tobias',
        album: 'reymond-tobias.ch',
      });
    } catch (error) {
      // Ältere Browser kennen MediaMetadata nicht – kein Grund zum Abbruch.
    }
  }

  /* ----------------------------------------------------------------------
   * Abspielen, Pause, Weiter
   * -------------------------------------------------------------------- */

  function play() {
    connectAnalyser();

    var promise = audio.play();

    if (promise && typeof promise.catch === 'function') {
      promise.catch(function () {
        // Keine Datei vorhanden oder Browser blockt: Vorführmodus.
        startDemo();
      });
    }

    playing = true;
    root.classList.add('is-playing');
    if (elPlay) elPlay.setAttribute('aria-label', 'Pause');
  }

  function pause() {
    audio.pause();
    playing = false;
    root.classList.remove('is-playing');
    if (elPlay) elPlay.setAttribute('aria-label', 'Abspielen');
  }

  function toggle() {
    if (playing) {
      pause();
    } else {
      play();
    }
  }

  function startDemo() {
    demo = true;
    demoLast = window.performance.now();
    root.classList.add('is-demo');
  }

  audio.addEventListener('error', function () {
    if (playing) startDemo();
  });

  audio.addEventListener('loadedmetadata', function () {
    if (elTotal && isFinite(audio.duration) && audio.duration > 0) {
      elTotal.textContent = format(audio.duration);
    }
  });

  audio.addEventListener('ended', function () {
    load(current + 1, true);
  });

  /* ----------------------------------------------------------------------
   * Fortschritt
   * -------------------------------------------------------------------- */

  function setProgress(ratio) {
    var percent = Math.max(0, Math.min(1, ratio)) * 100;
    if (elFill) elFill.style.width = percent + '%';
    if (elKnob) elKnob.style.left = percent + '%';
    if (elScrub) elScrub.setAttribute('aria-valuenow', String(Math.round(percent)));
  }

  function seekFromEvent(event) {
    var box = elScrub.getBoundingClientRect();
    var pointX = event.touches ? event.touches[0].clientX : event.clientX;
    var ratio = Math.max(0, Math.min(1, (pointX - box.left) / box.width));

    if (demo) {
      demoTime = ratio * duration();
    } else {
      audio.currentTime = ratio * duration();
    }

    setProgress(ratio);
  }

  if (elScrub) {
    var dragging = false;

    elScrub.addEventListener('pointerdown', function (event) {
      dragging = true;
      elScrub.setPointerCapture(event.pointerId);
      seekFromEvent(event);
    });

    elScrub.addEventListener('pointermove', function (event) {
      if (dragging) seekFromEvent(event);
    });

    elScrub.addEventListener('pointerup', function () {
      dragging = false;
    });

    elScrub.addEventListener('keydown', function (event) {
      var step = duration() / 20;
      if (event.key === 'ArrowRight') {
        seekTo(position() + step);
        event.preventDefault();
      } else if (event.key === 'ArrowLeft') {
        seekTo(position() - step);
        event.preventDefault();
      }
    });
  }

  function seekTo(seconds) {
    var value = Math.max(0, Math.min(duration(), seconds));
    if (demo) {
      demoTime = value;
    } else {
      audio.currentTime = value;
    }
    setProgress(value / duration());
  }

  /* ----------------------------------------------------------------------
   * Balkenanzeige
   * -------------------------------------------------------------------- */

  var context = null;
  var analyser = null;
  var data = null;
  var source = null;
  var canvasCtx = elCanvas ? elCanvas.getContext('2d') : null;
  var smooth = [];

  function connectAnalyser() {
    if (context || !window.AudioContext) return;

    try {
      context = new window.AudioContext();
      analyser = context.createAnalyser();
      analyser.fftSize = 128;
      analyser.smoothingTimeConstant = 0.82;
      data = new window.Uint8Array(analyser.frequencyBinCount);
      source = context.createMediaElementSource(audio);
      source.connect(analyser);
      analyser.connect(context.destination);
    } catch (error) {
      // Ohne Web Audio zeichnen wir die Vorführ-Animation.
      analyser = null;
    }

    if (context && context.state === 'suspended') context.resume();
  }

  function sizeCanvas() {
    if (!elCanvas) return;
    var ratio = window.devicePixelRatio || 1;
    var box = elCanvas.getBoundingClientRect();
    elCanvas.width = Math.max(1, Math.floor(box.width * ratio));
    elCanvas.height = Math.max(1, Math.floor(box.height * ratio));
  }

  function draw(now) {
    window.requestAnimationFrame(draw);
    if (!canvasCtx || !elCanvas) return;

    var width = elCanvas.width;
    var height = elCanvas.height;
    var count = 48;
    var values = new Array(count);

    if (analyser && data && !demo) {
      analyser.getByteFrequencyData(data);
      for (var i = 0; i < count; i += 1) {
        var slot = Math.floor((i / count) * data.length);
        values[i] = data[slot] / 255;
      }
    } else {
      // Vorführ-Wellen: drei überlagerte Sinuskurven ergeben eine
      // Bewegung, die zufällig aussieht, aber ruhig läuft.
      var t = now / 1000;
      for (var j = 0; j < count; j += 1) {
        var wave =
          Math.sin(t * 2.1 + j * 0.35) * 0.34 +
          Math.sin(t * 3.7 + j * 0.17) * 0.24 +
          Math.sin(t * 1.3 + j * 0.62) * 0.22;
        values[j] = Math.abs(wave) * (playing ? 1 : 0.18) + 0.05;
      }
    }

    canvasCtx.clearRect(0, 0, width, height);

    var gap = Math.max(1, width / count / 5);
    var barWidth = width / count - gap;
    var middle = height / 2;

    for (var k = 0; k < count; k += 1) {
      smooth[k] = smooth[k] === undefined ? 0 : smooth[k];
      smooth[k] += (values[k] - smooth[k]) * 0.25;

      // Zur Mitte hin höhere Balken: das wirkt wie eine Welle.
      var shape = 0.45 + 0.55 * Math.sin((k / (count - 1)) * Math.PI);
      var barHeight = Math.max(2, smooth[k] * height * 0.92 * shape);
      var x = k * (barWidth + gap);

      canvasCtx.fillStyle = 'rgba(255,255,255,' + (0.28 + smooth[k] * 0.72).toFixed(3) + ')';
      canvasCtx.fillRect(x, middle - barHeight / 2, barWidth, barHeight);
    }
  }

  /* ----------------------------------------------------------------------
   * Laufende Aktualisierung von Zeit und Balken
   * -------------------------------------------------------------------- */

  function tick(now) {
    window.requestAnimationFrame(tick);

    if (demo && playing) {
      var delta = (now - demoLast) / 1000;
      demoLast = now;
      demoTime += delta;
      if (demoTime >= duration()) {
        demoTime = 0;
        load(current + 1, true);
        return;
      }
    } else {
      demoLast = now;
    }

    var total = duration();
    if (elNow) elNow.textContent = format(position());
    if (total > 0) setProgress(position() / total);
  }

  /* ----------------------------------------------------------------------
   * Bedienung
   * -------------------------------------------------------------------- */

  if (elPlay) elPlay.addEventListener('click', toggle);
  if (elPrev)
    elPrev.addEventListener('click', function () {
      load(current - 1, playing);
    });
  if (elNext)
    elNext.addEventListener('click', function () {
      load(current + 1, playing);
    });

  if (elVolume) {
    elVolume.addEventListener('input', function () {
      audio.volume = Number(elVolume.value) / 100;
    });
  }

  document.addEventListener('keydown', function (event) {
    var tag = document.activeElement ? document.activeElement.tagName : '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

    if (event.code === 'Space') {
      event.preventDefault();
      toggle();
    } else if (event.key === 'ArrowRight' && event.shiftKey) {
      load(current + 1, playing);
    } else if (event.key === 'ArrowLeft' && event.shiftKey) {
      load(current - 1, playing);
    }
  });

  window.addEventListener('resize', sizeCanvas);

  /* ----------------------------------------------------------------------
   * Start
   * -------------------------------------------------------------------- */

  buildList();
  sizeCanvas();
  load(0, false);
  window.requestAnimationFrame(draw);
  window.requestAnimationFrame(tick);
})();
