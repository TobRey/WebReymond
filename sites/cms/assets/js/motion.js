/*
 * DJ Atze – Bewegung.
 *
 * Drei Dinge in einer Datei, weil sie denselben Herzschlag teilen:
 *
 *   1. Hintergrund   – Wellen aus einem WebGL-Shader, schwarz auf schwarz
 *   2. Takt          – liest die Frequenzen aus dem Player und legt sie als
 *                      CSS-Variablen ab; damit tanzt die ganze Seite mit
 *   3. Tiefe         – beim Scrollen kippen die Abschnitte leicht im Raum
 *
 * Alles läuft in einer einzigen Schleife (requestAnimationFrame) und rührt
 * nur transform und opacity an – das bleibt flüssig. Wer im Betriebssystem
 * weniger Bewegung eingestellt hat, bekommt gar nichts davon.
 */

(function () {
  'use strict';

  // Im Baukasten bleibt die Seite ruhig: dort wird gearbeitet, nicht getanzt.
  if (document.body.classList.contains('rc-canvas')) return;

  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) return;

  var root = document.documentElement;

  /* ======================================================================
   * 1. Hintergrund: Wellen
   * ==================================================================== */

  var VERT = ['attribute vec2 p;', 'void main(){ gl_Position = vec4(p, 0.0, 1.0); }'].join('\n');

  var FRAG = [
    'precision highp float;',
    'uniform vec2 res;',
    'uniform float time;',
    'uniform float scroll;',
    'uniform float beat;',
    'uniform float energy;',
    'uniform vec2 pointer;',

    // Billiges Rauschen: reicht für weiche Wellen und kostet fast nichts.
    'float hash(vec2 v){ return fract(sin(dot(v, vec2(127.1, 311.7))) * 43758.5453); }',
    'float noise(vec2 v){',
    '  vec2 i = floor(v); vec2 f = fract(v);',
    '  vec2 u = f * f * (3.0 - 2.0 * f);',
    '  return mix(mix(hash(i), hash(i + vec2(1.0, 0.0)), u.x),',
    '             mix(hash(i + vec2(0.0, 1.0)), hash(i + vec2(1.0, 1.0)), u.x), u.y);',
    '}',
    'float fbm(vec2 v){',
    '  float sum = 0.0; float amp = 0.5;',
    '  for (int i = 0; i < 3; i++) { sum += amp * noise(v); v *= 2.03; amp *= 0.5; }',
    '  return sum;',
    '}',

    'void main(){',
    '  vec2 uv = gl_FragCoord.xy / res;',
    '  vec2 p = (gl_FragCoord.xy - 0.5 * res) / res.y;',

    // Der Blickpunkt folgt langsam der Maus – das gibt Tiefe ohne Aufwand.
    '  p -= pointer * 0.06;',

    '  float t = time * 0.06;',
    '  float y = p.y + scroll * 0.55;',

    // Domänenverzerrung: die Wellen schieben sich ineinander.
    '  vec2 warp = vec2(fbm(vec2(p.x * 1.4 + t, y * 1.1 - t)), fbm(vec2(p.x * 1.1 - t, y * 1.3 + t)));',
    '  float field = fbm(vec2(p.x * 1.6, y * 2.2) + warp * (1.1 + energy * 0.9) + t);',

    // Aus dem Feld werden Höhenlinien – wie Wellenkämme im Scheinwerfer.
    '  float lines = abs(sin(field * 9.0 + time * 0.5 + beat * 1.6));',
    '  float crest = smoothstep(0.92, 1.0, 1.0 - lines);',
    '  crest *= 0.10 + energy * 0.35 + beat * 0.25;',

    // Ein Ring, der bei jedem Schlag nach aussen läuft.
    '  float r = length(p);',
    '  float ring = smoothstep(0.06, 0.0, abs(r - (1.0 - beat) * 0.9)) * beat * 0.16;',

    // Lichtkegel oben, damit der Kopf der Seite nicht flach wirkt.
    '  float cone = smoothstep(1.1, 0.0, length(p - vec2(-0.25, 0.85))) * 0.06;',

    '  float v = crest + ring + cone;',
    '  v *= smoothstep(1.25, 0.15, r);', // Rand abdunkeln
    '  v += (hash(gl_FragCoord.xy + time) - 0.5) * 0.015;', // feines Korn
    '  gl_FragColor = vec4(vec3(v), 1.0);',
    '}',
  ].join('\n');

  var gl = null;
  var uniforms = {};
  var canvas = document.getElementById('rc-bg');

  function compile(type, source) {
    var shader = gl.createShader(type);
    gl.shaderSource(shader, source);
    gl.compileShader(shader);

    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
      return null;
    }

    return shader;
  }

  function initBackground() {
    if (!canvas) return;

    try {
      gl =
        canvas.getContext('webgl', { antialias: false, alpha: false, depth: false }) ||
        canvas.getContext('experimental-webgl');
    } catch (error) {
      gl = null;
    }

    if (!gl) {
      canvas.style.display = 'none';
      return;
    }

    var vs = compile(gl.VERTEX_SHADER, VERT);
    var fs = compile(gl.FRAGMENT_SHADER, FRAG);

    if (!vs || !fs) {
      canvas.style.display = 'none';
      gl = null;
      return;
    }

    var program = gl.createProgram();
    gl.attachShader(program, vs);
    gl.attachShader(program, fs);
    gl.linkProgram(program);

    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
      canvas.style.display = 'none';
      gl = null;
      return;
    }

    gl.useProgram(program);

    var buffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);

    var attribute = gl.getAttribLocation(program, 'p');
    gl.enableVertexAttribArray(attribute);
    gl.vertexAttribPointer(attribute, 2, gl.FLOAT, false, 0, 0);

    ['res', 'time', 'scroll', 'beat', 'energy', 'pointer'].forEach(function (name) {
      uniforms[name] = gl.getUniformLocation(program, name);
    });

    sizeBackground();
  }

  function sizeBackground() {
    if (!gl || !canvas) return;

    // Halbe Auflösung genügt: die Wellen sind weich, niemand sieht den
    // Unterschied, und das Notebook bleibt kühl.
    var ratio = Math.min(window.devicePixelRatio || 1, 1.5) * 0.45;
    var width = Math.max(2, Math.floor(window.innerWidth * ratio));
    var height = Math.max(2, Math.floor(window.innerHeight * ratio));

    canvas.width = width;
    canvas.height = height;
    gl.viewport(0, 0, width, height);
    gl.uniform2f(uniforms.res, width, height);
  }

  /* ======================================================================
   * 2. Takt
   * ==================================================================== */

  var pulse = { bass: 0, energy: 0, beat: 0, playing: false };
  var beatValue = 0;
  var lastPulse = '';
  var bassAverage = 0;

  function readPulse() {
    var live = window.RC_PULSE;

    if (!live || !live.playing) {
      // Sanft auslaufen lassen, statt abrupt stehen zu bleiben.
      pulse.playing = false;
      pulse.bass += (0 - pulse.bass) * 0.06;
      pulse.energy += (0 - pulse.energy) * 0.06;
      beatValue *= 0.9;
    } else {
      pulse.playing = true;
      pulse.bass += (live.bass - pulse.bass) * 0.35;
      pulse.energy += (live.energy - pulse.energy) * 0.12;

      // Ein Schlag ist ein Ausschlag über dem gleitenden Mittel.
      bassAverage += (live.bass - bassAverage) * 0.04;
      var over = live.bass - bassAverage * 1.28;

      if (over > 0.02) {
        beatValue = Math.min(1, beatValue + over * 2.2);
      }

      beatValue *= 0.86;
    }

    pulse.beat = Math.max(0, Math.min(1, beatValue));

    var key = pulse.beat.toFixed(2) + ',' + pulse.bass.toFixed(2) + ',' + pulse.energy.toFixed(2);

    if (key !== lastPulse) {
      lastPulse = key;
      root.style.setProperty('--beat', pulse.beat.toFixed(2));
      root.style.setProperty('--bass', pulse.bass.toFixed(2));
      root.style.setProperty('--energy', pulse.energy.toFixed(2));
    }

    document.body.classList.toggle('rc-playing', pulse.playing);
  }

  /* ======================================================================
   * 3. Tiefe beim Scrollen
   * ==================================================================== */

  var layers = [];

  function collectLayers() {
    layers = Array.prototype.slice
      .call(document.querySelectorAll('[data-depth]'))
      .map(function (node) {
        return {
          node: node,
          strength: parseFloat(node.getAttribute('data-depth')) || 1,
          box: null,
        };
      });
  }

  function measure() {
    var top = window.scrollY;

    layers.forEach(function (layer) {
      var box = layer.node.getBoundingClientRect();
      layer.box = { top: box.top + top, height: box.height };
    });
  }

  function depth() {
    var view = window.innerHeight;
    var scroll = window.scrollY;

    layers.forEach(function (layer) {
      if (!layer.box) return;

      var middle = layer.box.top + layer.box.height / 2 - scroll;
      var offset = (middle - view / 2) / view; // -1 oben, 0 Mitte, 1 unten

      var value = 0;

      if (offset > -1.3 && offset < 1.3) {
        value = Math.max(-1, Math.min(1, offset)) * layer.strength;
      }

      // Auf drei Stellen runden und nur bei echter Änderung schreiben:
      // das erspart dem Browser unnötige Neuberechnungen.
      var next = value.toFixed(3);

      if (layer.last !== next) {
        layer.last = next;
        layer.node.style.setProperty('--p', next);
      }
    });
  }

  /* Zeiger: leichte Neigung der Karten und des Covers -------------------- */

  var pointer = { x: 0, y: 0, tx: 0, ty: 0 };

  if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
    window.addEventListener(
      'mousemove',
      function (event) {
        pointer.tx = (event.clientX / window.innerWidth) * 2 - 1;
        pointer.ty = (event.clientY / window.innerHeight) * 2 - 1;
      },
      { passive: true },
    );
  }

  var tilts = [];

  function collectTilts() {
    tilts = Array.prototype.slice.call(document.querySelectorAll('[data-tilt]'));
  }

  var lastTilt = '';

  function tilt() {
    var key = pointer.x.toFixed(3) + ',' + pointer.y.toFixed(3);
    if (key === lastTilt) return;
    lastTilt = key;

    tilts.forEach(function (node) {
      node.style.setProperty('--tx', pointer.x.toFixed(3));
      node.style.setProperty('--ty', pointer.y.toFixed(3));
    });

    root.style.setProperty('--px', pointer.x.toFixed(3));
    root.style.setProperty('--py', pointer.y.toFixed(3));
  }

  /* ======================================================================
   * Stufen: die Seite misst sich selbst
   *
   * Räumliche Bewegung kostet Rechenzeit. Statt sie allen aufzuzwingen,
   * schaut die Seite auf ihre eigene Bildrate und nimmt zurück, wenn das
   * Gerät nicht mitkommt:
   *
   *   Stufe 2  Wellen, Tiefe, Takt   (starke Geräte)
   *   Stufe 1  Tiefe flach, keine Wellen
   *   Stufe 0  nur der Takt, keine räumliche Bewegung
   *
   * Mit ?vollgas=1 in der Adresse bleibt immer Stufe 2 – zum Vorführen.
   * -------------------------------------------------------------------- */

  var forced = window.location.search.indexOf('vollgas=1') >= 0;
  var level = 2;

  // Schwache Geräte fangen gleich eine Stufe tiefer an.
  var cores = window.navigator.hardwareConcurrency || 8;
  var memory = window.navigator.deviceMemory || 8;

  if (!forced && (cores <= 4 || memory <= 4)) {
    level = 1;
  }

  function applyLevel() {
    root.classList.toggle('rc-q1', level === 1);
    root.classList.toggle('rc-q0', level === 0);

    if (canvas) {
      canvas.style.display = level >= 2 && gl ? '' : 'none';
    }
  }

  var samples = 0;
  var elapsed = 0;
  var lastFrame = 0;
  var steps = 0;
  var recovered = false;
  var ceiling = level;

  function watch(now) {
    if (forced) return;

    if (lastFrame) {
      elapsed += now - lastFrame;
      samples += 1;
    }

    lastFrame = now;

    if (samples < 100) return;

    var fps = 1000 / (elapsed / samples);
    samples = 0;
    elapsed = 0;

    if (fps < 42 && level > 0) {
      level -= 1;
      steps += 1;
      applyLevel();
      return;
    }

    // Waren die ersten Sekunden nur wegen des Ladens zäh, darf die Seite
    // einmal wieder hochschalten – aber nur einmal, sonst pendelt sie.
    if (fps > 56 && level < ceiling && steps > 0 && !recovered) {
      recovered = true;
      level += 1;
      applyLevel();
    }
  }

  /* ======================================================================
   * Die Schleife
   * ==================================================================== */

  var start = window.performance.now();

  function frame(now) {
    window.requestAnimationFrame(frame);
    watch(now);

    pointer.x += (pointer.tx - pointer.x) * 0.06;
    pointer.y += (pointer.ty - pointer.y) * 0.06;

    readPulse();
    depth();
    tilt();

    if (gl && level >= 2) {
      var seconds = (now - start) / 1000;
      var page = Math.max(1, document.body.scrollHeight - window.innerHeight);

      gl.uniform1f(uniforms.time, seconds);
      gl.uniform1f(uniforms.scroll, window.scrollY / page);
      gl.uniform1f(uniforms.beat, pulse.beat);
      gl.uniform1f(uniforms.energy, pulse.energy);
      gl.uniform2f(uniforms.pointer, pointer.x, pointer.y);
      gl.drawArrays(gl.TRIANGLES, 0, 3);
    }
  }

  /* ======================================================================
   * Start
   * ==================================================================== */

  function refresh() {
    collectLayers();
    collectTilts();
    measure();
    depth();
  }

  window.addEventListener('resize', function () {
    sizeBackground();
    measure();
  });

  window.addEventListener('load', measure);

  initBackground();
  applyLevel();
  refresh();
  window.requestAnimationFrame(frame);

  // Der Player tauscht Bilder aus; danach stimmen die Masse wieder.
  window.setTimeout(refresh, 1200);

  // Nach aussen sichtbar, damit der Player nach dem Titelwechsel neu messen kann.
  window.RC_MOTION = { refresh: refresh };
})();
