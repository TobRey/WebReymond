/**
 * Zusammenspiel von Kamera, Erkennung, Verfolgung, ReyRey und Oberfläche.
 *
 * Es laufen zwei voneinander unabhängige Schleifen:
 *
 *   Zeichenschleife  – requestAnimationFrame, ~60 Bilder/s.
 *                      Zeichnet nur, rechnet nie. Zwischen zwei Erkennungen
 *                      gleiten die Rahmen weiter, dadurch wirkt alles flüssig.
 *
 *   Erkennungsschleife – so oft wie das Gerät es hergibt, gedeckelt durch die
 *                      Intervalle in config.js. Gibt nach jedem Durchlauf die
 *                      Kontrolle an den Browser zurück, damit das Bild nicht
 *                      einfriert.
 *
 * Die teuren Zusatzfunktionen (Texterkennung, Scan) laufen ausserhalb dieser
 * Schleifen und nur auf Zuruf – sie würden den Takt sonst sprengen.
 */

import { CONFIG } from './config.js';
import { Camera, CameraError } from './camera.js';
import { Engine } from './engine.js';
import { Tracker } from './tracker.js';
import { Hud, Radar } from './hud.js';
import { Matcher, PeopleStore, makeId } from './store.js';
import { UI, $ } from './ui.js';
import { labelFor } from './labels.js';
import { Assistant } from './assistant.js';
import { Voice } from './voice.js';
import { Ocr, looksLikeText } from './ocr.js';
import { Cloud } from './cloud.js';
import { Translator } from './translate.js';
import { ObjectMemory, describeCrop, describeWhen } from './memory.js';
import { trackMotion, movingTargets } from './motion.js';
import { assessHazards, HazardVoice } from './hazard.js';
import { GestureWatcher, DEFAULT_BINDINGS } from './gestures.js';
import { runScan } from './scan.js';
import { Vault, wipeEverything } from './privacy.js';

class App {
  constructor() {
    this.video = $('video');
    this.stage = $('stage');

    this.ui = new UI();
    this.camera = new Camera(this.video);
    this.engine = new Engine();
    this.hud = new Hud($('overlay'), this.video);
    this.radar = new Radar($('radar'));

    this.vault = new Vault();
    this.store = new PeopleStore(this.vault);
    this.matcher = new Matcher();
    this.things = new ObjectMemory();
    this.things.threshold = CONFIG.memory.threshold;

    this.objects = new Tracker(CONFIG.tracking);
    this.faces = new Tracker(CONFIG.tracking);

    this.cloud = new Cloud(this.ui.settings);
    this.translator = new Translator(this.ui.settings, this.cloud);
    this.ocr = new Ocr();
    this.voice = new Voice();
    this.gestures = new GestureWatcher();
    this.hazardVoice = new HazardVoice(CONFIG.assistant.hazardQuietMs);
    this.rey = new Assistant(this.#skills());

    this.running = false;
    this.lastObjectRun = 0;
    this.lastFaceRun = 0;
    this.lastIdentify = 0;
    this.lastMemoryRun = 0;
    this.lastGestureRun = 0;
    this.lastFrame = 0;
    this.fps = 0;
    this.detectionRate = 0;
    this.detectionTicks = 0;
    this.detectionWindow = 0;

    /** Erkannte Wörter, die gerade im Bild markiert sind. */
    this.words = [];
    this.wordsUntil = 0;
    /** 0 = kein Scan, sonst Fortschritt 0 … 1. */
    this.scanProgress = 0;
    this.hazards = [];
    this.busy = false;

    /** Zustand der laufenden Gesichtserfassung. */
    this.capture = { active: false, samples: [], ages: [], lastAt: 0, quality: 0 };
    /** Gegenstand, der gerade gemerkt werden soll. */
    this.pendingObject = null;
  }

  /* =====================================================================
   * Start
   * ===================================================================== */

  async init() {
    for (const step of ['Kamera', 'Bibliothek', 'Rechenwerk', 'Gesichter', 'Objekte']) {
      this.ui.bootStep(step, 'wait');
    }

    this.ui.renderSettings();
    this.ui.onSettingChange = (key, value) => this.onSettingChange(key, value);
    this.ui.onAction = (key) => this.onSettingAction(key);
    this.ui.confirmSetting = (key, value) => this.confirmSetting(key, value);
    this.#wireControls();
    this.#wireVoice();
    this.#applyAllSettings();
    this.#registerServiceWorker();

    await this.store.open();
    await this.things.open();
    await this.reloadPeople();
    this.ui.setReyName(this.ui.settings.assistantName);
    this.rey.setName(this.ui.settings.assistantName);
    this.voice.setWakeWord(this.ui.settings.assistantWakeWord);

    try {
      Camera.checkSupport();
    } catch (error) {
      this.ui.showFault(error);
      return;
    }

    $('btnStart').addEventListener('click', () => this.start());
    $('btnRetry').addEventListener('click', () => {
      $('fault').hidden = true;
      $('boot').hidden = false;
      $('boot').classList.remove('is-leaving');
    });
  }

  async start() {
    const button = $('btnStart');
    button.disabled = true;
    button.textContent = 'Startet …';
    this.stage.classList.add('stage--booting');

    try {
      this.ui.bootStep('Kamera', 'run');
      this.ui.bootHint('Bitte den Kamerazugriff bestätigen.');
      await this.camera.start();
      const { width, height } = this.camera.size;
      this.ui.bootStep('Kamera', 'ok', `${width}×${height}`);
      this.ui.bootProgress(0.25);

      this.ui.bootHint('Lade Rechenwerk …');
      await this.engine.bootstrap((step, state, detail) => this.ui.bootStep(step, state, detail));
      this.ui.bootProgress(0.45);

      this.ui.bootHint('Lade Gesichtsmodelle (≈ 7 MB) …');
      await this.engine.loadFaceModels((step, state, detail) =>
        this.ui.bootStep(step, state, detail),
      );
      this.ui.bootProgress(0.7);

      this.#run();
      this.ui.hideBoot();
      this.stage.classList.remove('stage--booting');
      this.ui.setMode('AKTIV', true);
      this.ui.setResolution(`${width}×${height}`);

      // Hält die Kamera am Leben, wenn das Betriebssystem sie wegnimmt.
      this.camera.startWatchdog((state, detail) => this.#onCameraState(state, detail));

      this.#loadObjectsInBackground();
      this.#greet();
    } catch (error) {
      this.stage.classList.remove('stage--booting');
      button.disabled = false;
      button.textContent = 'Kamera freigeben';
      this.ui.bootStep('Kamera', 'fail', 'fehlgeschlagen');
      this.ui.showFault(
        error instanceof CameraError
          ? error
          : new CameraError('Start fehlgeschlagen', error?.message ?? String(error), [
              'Seite neu laden.',
              'Den Selbsttest öffnen: pruefung.html im selben Ordner.',
            ]),
      );
    }
  }

  #greet() {
    const name = this.ui.settings.assistantName;
    if (this.ui.settings.assistantListening && this.voice.supported) {
      this.voice.start();
      this.ui.say(
        `${name} hört zu. Sag „${this.ui.settings.assistantWakeWord}“ und dann deine Frage.`,
      );
    } else {
      this.ui.say(`Tippe auf die Kugel, um ${name} zu fragen.`, 6000);
    }
  }

  async #loadObjectsInBackground() {
    try {
      this.ui.toast('Objektmodell wird geladen …');
      await this.engine.loadObjectModel((step, state, detail) =>
        this.ui.bootStep(step, state, detail),
      );
      this.ui.bootProgress(1);
      this.ui.toast('Objekterkennung bereit', 'good');
    } catch (error) {
      /*
       * Der häufigste Grund ist ein Server, der die Modelldateien nicht
       * ausliefert. Die Meldung nennt deshalb den Selbsttest statt nur
       * „fehlgeschlagen“ zu sagen.
       */
      console.error('Objektmodell:', error);
      this.ui.applySetting('objects', false);
      $('btnObjects').setAttribute('aria-pressed', 'false');
      this.ui.toast('Objektmodell nicht ladbar – Selbsttest öffnen', 'bad');
      this.ui.say(
        `Das Objektmodell kommt vom Server nicht an (${error.message}). Öffne pruefung.html im selben Ordner – dort steht, welche Datei fehlt.`,
        0,
      );
    }
  }

  #registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !globalThis.isSecureContext) return;
    // Relativ registrieren: Der Geltungsbereich ist dann der Unterordner.
    navigator.serviceWorker.register('sw.js').catch(() => {
      /* Ohne Service Worker läuft alles weiter, nur eben nicht offline. */
    });
  }

  #onCameraState(state, detail) {
    if (state === 'verloren') {
      this.ui.setMode('WIEDERANLAUF');
      this.ui.toast(detail ?? 'Kamera unterbrochen – starte neu', 'warn');
    } else if (state === 'zurück') {
      this.ui.setMode('AKTIV', true);
      this.ui.toast('Kamera läuft wieder', 'good');
      this.objects.reset();
      this.faces.reset();
      this.gestures.reset();
    } else {
      this.ui.setMode('GESTOPPT');
      this.ui.toast(detail ?? 'Kamera nicht mehr erreichbar', 'bad');
    }
  }

  #run() {
    if (this.running) return;
    this.running = true;
    this.lastFrame = performance.now();
    requestAnimationFrame((t) => this.#renderLoop(t));
    this.#detectionLoop();
  }

  /* =====================================================================
   * Zeichnen
   * ===================================================================== */

  #renderLoop(now) {
    if (!this.running) return;

    const dt = Math.min(64, now - this.lastFrame);
    this.lastFrame = now;
    this.fps = this.fps * 0.9 + (1000 / Math.max(1, dt)) * 0.1;

    this.objects.advance(dt);
    this.faces.advance(dt);

    const objectTracks = this.ui.settings.objects ? this.objects.visible() : [];
    const faceTracks = this.ui.settings.faces ? this.faces.visible() : [];

    // Bewegungsrichtung braucht einen Verlauf – deshalb bei jedem Bild.
    trackMotion([...objectTracks, ...faceTracks], this.video.videoWidth, now);

    this.hazards = this.ui.settings.hazardWarnings
      ? assessHazards(objectTracks, this.video.videoWidth)
      : [];

    if (now > this.wordsUntil) this.words = [];

    this.hud.render({
      objects: objectTracks,
      faces: faceTracks,
      settings: this.ui.settings,
      time: now,
      hazards: this.hazards,
      words: this.words,
      scanning: this.scanProgress,
    });

    this.radar.render([...objectTracks, ...faceTracks], this.video.videoWidth);
    this.#updateReadouts(objectTracks, faceTracks);
    this.#updateAlarm();

    requestAnimationFrame((t) => this.#renderLoop(t));
  }

  #updateAlarm() {
    const worst = this.hazards.find((hazard) => hazard.level === 'hoch') ?? this.hazards[0];
    if (!worst) {
      this.ui.hideAlarm();
      return;
    }
    this.ui.showAlarm(worst.text);

    if (this.ui.settings.hazardSpeak && this.ui.settings.assistantSpeak) {
      const spoken = this.hazardVoice.next(this.hazards, performance.now());
      if (spoken) this.voice.speak(spoken, { rate: 1.12 });
    }
  }

  #updateReadouts(objectTracks, faceTracks) {
    this.ui.setStats({
      fps: Math.round(this.fps),
      objects: objectTracks.length,
      faces: faceTracks.length,
      known: this.matcher.size,
    });
    this.ui.setLevel(objectTracks.length + faceTracks.length);

    const privacy = this.ui.settings.privacy;
    const contacts = [];

    for (const track of faceTracks) {
      const identity = track.identity;
      if (identity?.person && !privacy) {
        contacts.push({
          kind: 'known',
          text: `${identity.person.name} · ${identity.person.age} J · ${Math.round(identity.confidence * 100)}%`,
        });
      } else if (identity?.person) {
        contacts.push({ kind: 'known', text: 'Bekannt · Privatmodus' });
      } else if (identity) {
        contacts.push({ kind: 'unknown', text: `Unbekannt · ~${Math.round(identity.age)} J` });
      }
    }
    for (const track of objectTracks) {
      if (track.kind === 'person') continue;
      if (track.memory?.item) {
        contacts.push({ kind: 'known', text: `${track.memory.item.name} (gemerkt)` });
      } else {
        contacts.push({
          kind: 'object',
          text: `${labelFor(track.label)} ${Math.round(track.score * 100)}%`,
        });
      }
    }
    this.ui.setContacts(contacts);
  }

  /* =====================================================================
   * Erkennen
   * ===================================================================== */

  async #detectionLoop() {
    while (this.running) {
      const now = performance.now();
      let didWork = false;

      if (document.hidden) {
        await sleep(250);
        continue;
      }

      try {
        if (
          this.ui.settings.objects &&
          this.engine.ready.objects &&
          now - this.lastObjectRun >= CONFIG.objects.intervalMs
        ) {
          this.lastObjectRun = now;
          const found = await this.engine.detectObjects(this.video, this.ui.settings.minScore);
          this.objects.update(found);
          didWork = true;
        }

        if (
          this.ui.settings.faces &&
          this.engine.ready.faces &&
          now - this.lastFaceRun >= CONFIG.faces.intervalMs
        ) {
          this.lastFaceRun = now;
          const found = await this.engine.detectFaces(this.video);
          this.faces.update(found);
          didWork = true;
        }

        if (
          this.ui.settings.gesturesEnabled &&
          now - this.lastGestureRun >= CONFIG.gestures.intervalMs
        ) {
          this.lastGestureRun = now;
          const gesture = this.gestures.update(this.video, now);
          if (gesture) await this.#onGesture(gesture);
        }

        if (this.capture.active) {
          await this.#captureStep();
        } else if (this.ui.settings.faces) {
          await this.#identifyStep();
        }

        if (this.things.size > 0 && now - this.lastMemoryRun >= CONFIG.memory.matchIntervalMs) {
          this.lastMemoryRun = now;
          this.#memoryStep();
        }
      } catch (error) {
        console.error('Erkennung:', error);
        await sleep(400);
      }

      if (didWork) this.#countDetection();
      await nextFrame();
      if (!didWork) await sleep(30);
    }
  }

  #countDetection() {
    this.detectionTicks += 1;
    const now = performance.now();
    if (now - this.detectionWindow >= 1000) {
      this.detectionRate = this.detectionTicks;
      this.detectionTicks = 0;
      this.detectionWindow = now;
    }
  }

  /**
   * Beantwortet für höchstens ein Gesicht pro Runde die Frage "wer ist das?".
   *
   * Ausgewählt wird das Ziel mit dem grössten Bedarf: noch nie ausgewertet
   * schlägt "Antwort veraltet".
   */
  async #identifyStep() {
    const now = performance.now();
    if (now - this.lastIdentify < CONFIG.faces.identifyIntervalMs) return;

    const candidates = this.faces
      .visible()
      .filter((track) => !track.identifyPending && track.target.w > 48);
    if (candidates.length === 0) return;

    // Dringlichkeit: noch nie ausgewertet zuerst, dann die älteste Antwort.
    // Wiederholt gescheiterte Ziele gelten nicht mehr als "neu".
    const isNew = (track) => !track.identity && (track.identifyFails ?? 0) < 2;
    candidates.sort((a, b) => {
      if (isNew(a) !== isNew(b)) return isNew(a) ? -1 : 1;
      return a.identifiedAt - b.identifiedAt;
    });

    const track = candidates[0];
    if (track.identity && now - track.identifiedAt < CONFIG.faces.identityTtlMs) return;

    this.lastIdentify = now;
    track.identifyPending = true;

    try {
      const result = await this.engine.identify(this.video, track.target);

      if (result) {
        const { person, distance } = this.matcher.match(
          result.descriptor,
          this.ui.settings.matchThreshold,
        );

        const wasUnknown = !track.identity?.person;
        track.identity = {
          person,
          distance,
          confidence: confidenceFrom(distance, this.ui.settings.matchThreshold),
          age: result.age,
          gender: result.gender,
          expression: result.expression,
        };
        track.identifyFails = 0;

        if (person && wasUnknown && !this.ui.settings.privacy) {
          this.ui.toast(`Erkannt: ${person.name}`, 'good');
        }
      } else {
        track.identifyFails = (track.identifyFails ?? 0) + 1;
      }
    } finally {
      /*
       * Der Zeitstempel wird auch dann gesetzt, wenn nichts herauskam.
       *
       * Andernfalls bliebe ein Gesicht, dessen Ausschnitt sich nicht auswerten
       * lässt (verdeckt, stark seitlich, zu dunkel), für immer der dringendste
       * Fall: Es stünde bei jeder Runde wieder vorn und würde alle anderen
       * Gesichter dauerhaft von der Auswertung verdrängen.
       */
      track.identifiedAt = performance.now();
      track.identifyPending = false;
    }
  }

  /** Prüft ein Objektziel pro Runde gegen die gemerkten Gegenstände. */
  #memoryStep() {
    const candidates = this.objects
      .visible()
      .filter((track) => track.kind !== 'person' && track.target.w > 56)
      .sort((a, b) => (a.memoryCheckedAt ?? 0) - (b.memoryCheckedAt ?? 0));

    const track = candidates[0];
    if (!track) return;
    track.memoryCheckedAt = performance.now();

    const crop = this.#cropTrack(track);
    if (!crop) return;

    const { item, score } = this.things.match(describeCrop(crop), track.label);
    const wasKnown = Boolean(track.memory?.item);
    track.memory = item ? { item, score } : null;

    if (item && !wasKnown) {
      this.things.touch(item.id, this.lastPlace ?? null);
      if (!this.ui.settings.privacy) this.ui.toast(`Wiedergefunden: ${item.name}`, 'good');
    }
  }

  /** Schneidet ein Ziel aus dem Kamerabild – für Merkmalsvektoren und Vorschau. */
  #cropTrack(track, size = 128) {
    const vw = this.video.videoWidth;
    const vh = this.video.videoHeight;
    if (!vw || !vh) return null;

    const box = track.target;
    const canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;

    const side = Math.max(box.w, box.h);
    const cx = box.x + box.w / 2;
    const cy = box.y + box.h / 2;
    const sx = Math.max(0, Math.min(vw - 1, cx - side / 2));
    const sy = Math.max(0, Math.min(vh - 1, cy - side / 2));
    const sw = Math.min(side, vw - sx);
    const sh = Math.min(side, vh - sy);
    if (sw < 16 || sh < 16) return null;

    canvas.getContext('2d').drawImage(this.video, sx, sy, sw, sh, 0, 0, size, size);
    return canvas;
  }

  /* =====================================================================
   * ReyRey
   * ===================================================================== */

  /** Die Fähigkeiten, die der Assistent aufrufen darf. */
  #skills() {
    return {
      people: () =>
        this.faces.visible().map((track) => ({
          name: this.ui.settings.privacy ? null : (track.identity?.person?.name ?? null),
          age: track.identity?.person?.age ?? Math.round(track.identity?.age ?? 0),
          expression: track.identity?.expression ?? null,
        })),

      objects: () =>
        this.objects
          .visible()
          .filter((track) => track.kind !== 'person')
          .map((track) => ({ label: track.label, name: track.memory?.item?.name ?? null })),

      motion: () => movingTargets([...this.objects.visible(), ...this.faces.visible()], 3),
      hazards: () => this.hazards,
      scan: () => this.runScan(),
      readText: (options) => this.readText(options),
      rememberObject: (name) => this.rememberObject(name),
      findObject: (name) => this.findObject(name),
      registerFace: () => this.openRegister(),
      roster: () => this.store.all(),
      flipCamera: () => this.flipCamera(),
      setSetting: (key, value) => {
        this.ui.applySetting(key, value);
        this.onSettingChange(key, value);
      },
      wipeAll: () => this.wipeAll(true),
      stopSpeaking: () => this.voice.shutUp(),
      cloud: this.cloud,
    };
  }

  #wireVoice() {
    this.voice.onState = (state) => {
      const map = { hört: 'hört', pause: 'hört', spricht: 'spricht', aus: 'aus' };
      if (!this.thinking) this.ui.setReyState(map[state] ?? 'aus');
    };

    this.voice.onWake = () => {
      this.ui.setReyState('wach');
      this.ui.setHeard('');
      // Kurzer Piep als Rückmeldung, dass das Wort angekommen ist.
      beep();
    };

    this.voice.onPartial = (text, isFinal) => {
      if (!isFinal) this.ui.setHeard(text);
    };

    this.voice.onCommand = (text) => this.handle(text, 'stimme');

    this.voice.onError = (error) => {
      this.ui.toast(error.message, 'warn');
      this.ui.applySetting('assistantListening', false);
      this.ui.setReyState('aus');
      this.ui.renderSettings();
    };
  }

  /**
   * Nimmt eine Frage entgegen – gesprochen oder getippt – und antwortet.
   * @param {string} text
   * @param {'stimme'|'text'} source
   */
  async handle(text, source) {
    if (!text.trim()) return;

    this.thinking = true;
    this.ui.setReyState('denkt');
    this.ui.setHeard(text);
    if (source === 'text') this.ui.pushChat('user', text);

    let answer;
    try {
      answer = await this.rey.ask(text);
    } catch (error) {
      answer = { text: `Da ist etwas schiefgelaufen: ${error.message}`, tone: 'bad' };
    }

    this.thinking = false;
    if (this.ui.settings.assistantOverlay) this.ui.say(answer.text, answer.ask ? 0 : undefined);
    if (source === 'text') this.ui.pushChat('assistant', answer.text);

    if (this.ui.settings.assistantSpeak && !answer.silent) {
      await this.voice.speak(answer.text);
    }
    // Nach einer Rückfrage bleibt das Mikrofon offen, ohne Aktivierungswort.
    if (answer.ask) this.voice.holdOpen();
    this.ui.setReyState(this.voice.wanted ? 'hört' : 'aus');
  }

  /* =====================================================================
   * Fähigkeiten
   * ===================================================================== */

  /** Vollständige Analyse der sichtbaren Umgebung. */
  async runScan() {
    if (this.busy) return 'Ich bin gerade beschäftigt.';
    this.busy = true;
    this.ui.setMode('SCAN');

    // Der Balken läuft sichtbar durchs Bild, während gerechnet wird.
    const started = performance.now();
    const animate = setInterval(() => {
      this.scanProgress = Math.min(0.97, (performance.now() - started) / 2600);
    }, 60);

    try {
      const { report } = await runScan({
        engine: this.engine,
        video: this.video,
        faces: this.faces,
        objects: this.objects,
        matcher: this.matcher,
        settings: this.ui.settings,
        ocr: this.ui.settings.privacy ? null : this.ocr,
        hazards: this.hazards,
      });
      return report;
    } finally {
      clearInterval(animate);
      this.scanProgress = 0;
      this.busy = false;
      this.ui.setMode('AKTIV', true);
    }
  }

  /**
   * Liest Text im Bild, blendet ihn ein und übersetzt ihn auf Wunsch.
   * @returns {Promise<{found: boolean, text: string, translated?: string}>}
   */
  async readText({ translate = false, target } = {}) {
    if (this.busy) return { found: false, text: '' };
    this.busy = true;
    this.ui.setMode('TEXT');

    try {
      if (!this.ocr.ready) this.ui.toast('Texterkennung wird geladen …');
      const result = await this.ocr.read(this.video);

      if (result.confidence < 0.45 || !looksLikeText(result.text)) {
        return { found: false, text: '' };
      }

      this.words = result.words;
      this.wordsUntil = performance.now() + 12000;

      if (!translate) return { found: true, text: result.text };

      if (!this.translator.available()) {
        return { found: true, text: result.text, translated: null };
      }

      const to = target ?? this.ui.settings.translateTarget;
      const translated = await this.translator.translate(result.text, to);

      // Übersetzte Wörter direkt über das Original legen. Bei stark
      // abweichender Wortzahl wäre das Kauderwelsch – dann nur der Fliesstext.
      const source = result.text.split(/\s+/);
      const parts = translated.split(/\s+/);
      if (Math.abs(source.length - parts.length) <= Math.max(2, source.length * 0.3)) {
        this.words = result.words.map((word, index) => ({
          ...word,
          replacement: parts[index] ?? '',
        }));
      }

      return { found: true, text: result.text, translated };
    } catch (error) {
      this.ui.toast(error.message, 'bad');
      return { found: false, text: '' };
    } finally {
      this.busy = false;
      this.ui.setMode('AKTIV', true);
    }
  }

  /** Merkt sich den grössten sichtbaren Gegenstand unter einem Namen. */
  async rememberObject(name) {
    if (this.ui.settings.privacy) {
      return { ok: false, reason: 'Im Privatmodus speichere ich nichts.' };
    }

    const track = this.objects
      .visible()
      .filter((t) => t.kind !== 'person')
      .sort((a, b) => b.target.w * b.target.h - a.target.w * a.target.h)[0];

    if (!track) {
      return { ok: false, reason: 'Ich sehe gerade keinen Gegenstand, den ich merken könnte.' };
    }

    const crop = this.#cropTrack(track);
    if (!crop) return { ok: false, reason: 'Der Gegenstand ist zu klein im Bild.' };

    const item = await this.things.add({
      name,
      cocoClass: track.label,
      descriptor: describeCrop(crop),
      thumb: crop.toDataURL('image/jpeg', 0.7),
      place: await this.#place(),
    });
    track.memory = { item, score: 1 };
    return { ok: true, name: item.name };
  }

  /** Beantwortet "Wo ist mein …?" aus dem Gedächtnis. */
  async findObject(name) {
    const item = this.things.findByName(name);
    if (!item) {
      return {
        found: false,
        text: `„${name}“ habe ich nicht gemerkt. Halte es ins Bild und sag „merk dir das als ${name}“.`,
      };
    }

    const visible = this.objects.visible().some((track) => track.memory?.item?.id === item.id);
    if (visible) return { found: true, text: `${item.name} ist gerade im Bild.` };

    const parts = [`${item.name} habe ich zuletzt ${describeWhen(item.lastSeenAt)} gesehen`];
    if (item.lastPlace) {
      parts.push(`ungefähr bei ${item.lastPlace.lat.toFixed(4)}, ${item.lastPlace.lon.toFixed(4)}`);
    }
    if (item.note) parts.push(`Notiz: ${item.note}`);
    return { found: true, text: `${parts.join('. ')}.` };
  }

  /** Standort – nur wenn ausdrücklich erlaubt. */
  async #place() {
    if (!this.ui.settings.rememberPlaces || !navigator.geolocation) return null;
    return new Promise((resolve) => {
      navigator.geolocation.getCurrentPosition(
        (position) => {
          this.lastPlace = {
            lat: position.coords.latitude,
            lon: position.coords.longitude,
            at: new Date().toISOString(),
          };
          resolve(this.lastPlace);
        },
        () => resolve(null),
        { timeout: 5000, maximumAge: 120000 },
      );
    });
  }

  /* =====================================================================
   * Gesten
   * ===================================================================== */

  async #onGesture(gesture) {
    const bindings = { ...DEFAULT_BINDINGS, ...(this.ui.settings.gestureBindings ?? {}) };
    const action = bindings[gesture];
    if (!action || action === 'none') return;

    this.ui.toast(`Geste: ${gesture}`);

    switch (action) {
      case 'scan':
        await this.handle('scanne die umgebung', 'geste');
        break;
      case 'readText':
        await this.handle('lies das vor', 'geste');
        break;
      case 'translate':
        await this.handle('übersetze das', 'geste');
        break;
      case 'registerFace':
        this.openRegister();
        break;
      case 'describe':
        await this.handle('was siehst du', 'geste');
        break;
      case 'toggleHud':
        this.ui.applySetting('hudVisible', !this.ui.settings.hudVisible);
        break;
      case 'flipCamera':
        await this.flipCamera();
        break;
      case 'listen':
        if (this.voice.supported && this.ui.settings.assistantVoiceConsent) {
          this.voice.start();
          this.voice.holdOpen();
          this.ui.setReyState('wach');
        }
        break;
      case 'silence':
        this.voice.shutUp();
        this.ui.hideSay();
        break;
      default:
        break;
    }
  }

  /* =====================================================================
   * Gesicht erfassen
   * ===================================================================== */

  openRegister() {
    if (!this.engine.ready.faces) {
      this.ui.toast('Gesichtsmodelle noch nicht bereit', 'warn');
      return;
    }

    this.capture = { active: true, samples: [], ages: [], lastAt: 0, quality: 0 };
    $('registerForm').reset();
    $('registerError').hidden = true;
    $('btnSaveFace').disabled = true;
    $('btnUseEstimate').disabled = true;
    $('captureAge').textContent = '–';
    $('captureQuality').textContent = '–';
    this.#updateCaptureUi();
    $('capturePreview').getContext('2d').clearRect(0, 0, 200, 200);
    this.ui.openSheet('sheetRegister');
    this.ui.setMode('ERFASSUNG');
  }

  async #captureStep() {
    const now = performance.now();
    if (now - this.capture.lastAt < CONFIG.recognition.sampleGapMs) return;
    if (this.capture.samples.length >= CONFIG.recognition.samples) return;

    const target = this.faces
      .visible()
      .slice()
      .sort((a, b) => b.target.w - a.target.w)[0];

    if (!target) {
      $('captureHint').textContent = 'Kein Gesicht im Bild – näher herangehen.';
      return;
    }
    if (target.target.w < 70) {
      $('captureHint').textContent = 'Gesicht zu klein – bitte näher an die Kamera.';
      return;
    }

    this.capture.lastAt = now;
    const result = await this.engine.identify(this.video, target.target);
    if (!result) return;

    this.capture.samples.push(result.descriptor);
    this.capture.ages.push(result.age);
    this.capture.quality = Math.max(this.capture.quality, result.score);

    const preview = $('capturePreview');
    preview.getContext('2d').drawImage(result.thumb, 0, 0, preview.width, preview.height);

    if (this.capture.samples.length === CONFIG.recognition.samples) {
      this.capture.thumb = this.engine.snapshotThumb(96);
      $('captureHint').textContent = 'Aufnahmen vollständig. Name und Alter eintragen.';
    } else {
      $('captureHint').textContent = 'Kopf leicht bewegen – das macht die Erkennung sicherer.';
    }

    this.#updateCaptureUi();
  }

  #updateCaptureUi() {
    const taken = this.capture.samples.length;
    const total = CONFIG.recognition.samples;
    const complete = taken === total;

    $('captureCount').textContent = `${taken} / ${total}`;
    $('captureBar').style.width = `${(taken / total) * 100}%`;
    $('btnSaveFace').disabled = !complete;
    $('btnUseEstimate').disabled = taken === 0;

    if (taken > 0) {
      const average = this.capture.ages.reduce((a, b) => a + b, 0) / taken;
      $('captureAge').textContent = `${Math.round(average)} Jahre`;
      $('captureQuality').textContent = `${Math.round(this.capture.quality * 100)} %`;
    }
  }

  async saveRegistration(event) {
    event.preventDefault();
    $('registerError').hidden = true;

    const name = $('fieldName').value.trim();
    const age = Number.parseInt($('fieldAge').value, 10);
    const note = $('fieldNote')?.value.trim() ?? '';

    if (name.length < 2) return this.#registerError('Bitte einen Namen mit mindestens 2 Zeichen.');
    if (!Number.isFinite(age) || age < 1 || age > 120) {
      return this.#registerError('Bitte ein Alter zwischen 1 und 120 eintragen.');
    }
    if (this.capture.samples.length < CONFIG.recognition.samples) {
      return this.#registerError('Es fehlen noch Aufnahmen.');
    }

    // Gesichtsmerkmale sind biometrische Daten – das wird einmal ausdrücklich
    // bestätigt, bevor irgendetwas gespeichert wird.
    const agreed = await this.ui.askConsent({
      title: 'Einverständnis der Person',
      body: `<h3>${escapeHtml(name)} erfassen</h3>
        <p>Gespeichert werden ein Gesichtsabdruck (128 Zahlen), Name, Alter und ein kleines Vorschaubild –
        ausschliesslich im Speicher dieses Browsers auf diesem Gerät.</p>
        <ul>
          <li>Nichts davon wird übertragen oder hochgeladen.</li>
          <li>Jederzeit über die Kartei löschbar.</li>
          <li><strong>Gesichtsmerkmale sind biometrische Daten.</strong> Fremde Personen dürfen
          nur mit ihrem Einverständnis erfasst werden (DSGVO Art. 9, revDSG Art. 5).</li>
        </ul>
        <p>Liegt das Einverständnis von ${escapeHtml(name)} vor?</p>`,
      confirm: 'Ja, Einverständnis liegt vor',
    });
    if (!agreed) return this.#registerError('Ohne Einverständnis wird nichts gespeichert.');

    const person = {
      id: makeId(),
      name,
      age,
      note,
      registeredAt: new Date().toISOString(),
      thumb: this.capture.thumb ?? null,
      descriptors: this.capture.samples,
    };

    try {
      await this.store.put(person);
      await this.reloadPeople();
      this.capture.active = false;
      this.ui.closeSheet('sheetRegister');
      this.ui.setMode('AKTIV', true);
      this.ui.toast(`${name} gespeichert`, 'good');

      for (const track of this.faces.tracks) track.identifiedAt = 0;
    } catch {
      this.#registerError('Speichern fehlgeschlagen. Ist der Browser-Speicher voll?');
    }
    return undefined;
  }

  #registerError(message) {
    const error = $('registerError');
    error.textContent = message;
    error.hidden = false;
    return undefined;
  }

  /* =====================================================================
   * Gegenstand merken (über die Oberfläche)
   * ===================================================================== */

  openObjectSheet() {
    const track = this.objects
      .visible()
      .filter((t) => t.kind !== 'person')
      .sort((a, b) => b.target.w * b.target.h - a.target.w * a.target.h)[0];

    if (!track) {
      this.ui.toast('Kein Gegenstand im Bild', 'warn');
      return;
    }

    const crop = this.#cropTrack(track);
    if (!crop) {
      this.ui.toast('Gegenstand zu klein', 'warn');
      return;
    }

    this.pendingObject = { track, crop, descriptor: describeCrop(crop) };
    const preview = $('objectPreview');
    preview.getContext('2d').drawImage(crop, 0, 0, preview.width, preview.height);
    $('objectClass').textContent = labelFor(track.label);
    $('objectScore').textContent = `${Math.round(track.score * 100)} %`;
    $('objectForm').reset();
    $('objectError').hidden = true;
    this.ui.openSheet('sheetObject');
  }

  async saveObject(event) {
    event.preventDefault();
    const error = $('objectError');
    error.hidden = true;

    const name = $('objectName').value.trim();
    if (name.length < 2) {
      error.textContent = 'Bitte einen Namen mit mindestens 2 Zeichen.';
      error.hidden = false;
      return;
    }
    if (!this.pendingObject) {
      error.textContent = 'Der Gegenstand ist nicht mehr im Bild.';
      error.hidden = false;
      return;
    }

    const item = await this.things.add({
      name,
      note: $('objectNote').value.trim(),
      cocoClass: this.pendingObject.track.label,
      descriptor: this.pendingObject.descriptor,
      thumb: this.pendingObject.crop.toDataURL('image/jpeg', 0.7),
      place: await this.#place(),
    });

    this.pendingObject.track.memory = { item, score: 1 };
    this.pendingObject = null;
    this.ui.closeSheet('sheetObject');
    this.ui.toast(`${name} gemerkt`, 'good');
  }

  /* =====================================================================
   * Kartei
   * ===================================================================== */

  async reloadPeople() {
    const people = await this.store.all();
    this.matcher.load(people);
    this.ui.renderRoster(people, (person) => this.deletePerson(person));
    return people;
  }

  async deletePerson(person) {
    const sure = await this.ui.askConsent({
      title: 'Löschen bestätigen',
      body: `<h3>${escapeHtml(person.name)} löschen</h3>
             <p>Gesichtsabdruck, Name, Alter und Bild werden endgültig entfernt.
             Das lässt sich nicht rückgängig machen.</p>`,
      confirm: 'Endgültig löschen',
    });
    if (!sure) return;

    await this.store.remove(person.id);
    await this.reloadPeople();
    for (const track of this.faces.tracks) {
      if (track.identity?.person?.id === person.id) track.identity = null;
      track.identifiedAt = 0;
    }
    this.ui.toast(`${person.name} gelöscht`);
  }

  async deleteThing(item) {
    const sure = await this.ui.askConsent({
      title: 'Löschen bestätigen',
      body: `<h3>${escapeHtml(item.name)} löschen</h3>
             <p>Name, Notiz, Bild und Merkmale werden endgültig entfernt.</p>`,
      confirm: 'Endgültig löschen',
    });
    if (!sure) return;

    await this.things.remove(item.id);
    for (const track of this.objects.tracks) {
      if (track.memory?.item?.id === item.id) track.memory = null;
    }
    this.ui.renderThings(this.things.items, (next) => this.deleteThing(next));
    this.ui.toast(`${item.name} gelöscht`);
  }

  async exportPeople() {
    const people = await this.store.all();
    if (people.length === 0) {
      this.ui.toast('Die Kartei ist leer', 'warn');
      return;
    }

    const sure = await this.ui.askConsent({
      title: 'Sicherung erstellen',
      body: `<h3>${people.length} Einträge sichern</h3>
             <p>Die Datei enthält Namen, Alter, Vorschaubilder und Gesichtsabdrücke
             <strong>unverschlüsselt</strong>. Wer sie bekommt, bekommt alles darin.</p>
             <p>Bewahre sie so sorgfältig auf wie ein Passwort.</p>`,
      confirm: 'Trotzdem sichern',
    });
    if (!sure) return;

    const payload = {
      format: 'visionhud-people',
      version: 1,
      exportedAt: new Date().toISOString(),
      people: people.map((person) => ({
        ...person,
        descriptors: person.descriptors.map((d) => Array.from(d)),
      })),
    };

    const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `visionhud-kartei-${new Date().toISOString().slice(0, 10)}.json`;
    link.click();
    URL.revokeObjectURL(url);
    this.ui.toast(`${people.length} Einträge gesichert`, 'good');
  }

  async importPeople(file) {
    try {
      const payload = JSON.parse(await file.text());
      if (payload?.format !== 'visionhud-people' || !Array.isArray(payload.people)) {
        throw new Error('Unbekanntes Format');
      }

      const existing = new Set((await this.store.all()).map((person) => person.id));
      let added = 0;

      for (const row of payload.people) {
        if (!row?.name || !Array.isArray(row.descriptors) || row.descriptors.length === 0) continue;
        if (existing.has(row.id)) continue;
        await this.store.put({
          id: row.id ?? makeId(),
          name: String(row.name).slice(0, 32),
          age: Number(row.age) || 0,
          note: typeof row.note === 'string' ? row.note : '',
          registeredAt: row.registeredAt ?? new Date().toISOString(),
          thumb: typeof row.thumb === 'string' ? row.thumb : null,
          descriptors: row.descriptors.map((d) => Float32Array.from(d)),
        });
        added += 1;
      }

      await this.reloadPeople();
      this.ui.toast(added > 0 ? `${added} Einträge eingelesen` : 'Nichts Neues gefunden', 'good');
    } catch {
      this.ui.toast('Datei konnte nicht gelesen werden', 'bad');
    }
  }

  /** Löscht restlos alles. Immer mit Rückfrage. */
  async wipeAll(alreadyConfirmed = false) {
    if (!alreadyConfirmed) {
      const sure = await this.ui.askConsent({
        title: 'Alles löschen',
        body: `<h3>Wirklich alles löschen?</h3>
               <p>Das entfernt endgültig:</p>
               <ul>
                 <li>alle erfassten Personen samt Gesichtsabdrücken</li>
                 <li>alle gemerkten Gegenstände</li>
                 <li>alle Einstellungen, auch das Kennwort</li>
                 <li>den Zwischenspeicher der App</li>
               </ul>
               <p><strong>Das lässt sich nicht rückgängig machen.</strong></p>`,
        confirm: 'Endgültig alles löschen',
      });
      if (!sure) return;
    }

    await this.things.clear();
    await this.store.clear();
    this.vault.forget();
    await wipeEverything();
    try {
      navigator.serviceWorker?.controller?.postMessage('visionhud:wipe-cache');
    } catch {
      /* egal */
    }

    this.matcher.load([]);
    for (const track of this.faces.tracks) track.identity = null;
    for (const track of this.objects.tracks) track.memory = null;
    await this.reloadPeople();
    this.ui.toast('Alles gelöscht', 'good');
  }

  /* =====================================================================
   * Bedienung
   * ===================================================================== */

  #wireControls() {
    $('btnObjects').addEventListener('click', (event) => {
      const value = !this.ui.settings.objects;
      event.currentTarget.setAttribute('aria-pressed', String(value));
      this.ui.applySetting('objects', value);
      if (!value) this.objects.reset();
    });

    $('btnFaces').addEventListener('click', (event) => {
      const value = !this.ui.settings.faces;
      event.currentTarget.setAttribute('aria-pressed', String(value));
      this.ui.applySetting('faces', value);
      if (!value) this.faces.reset();
    });

    $('btnRegister').addEventListener('click', () => this.openRegister());
    $('btnScan').addEventListener('click', () => this.handle('scanne die umgebung', 'geste'));

    $('btnText').addEventListener('click', () => this.handle('lies das vor', 'geste'));

    $('btnRoster').addEventListener('click', async () => {
      await this.reloadPeople();
      this.ui.openSheet('sheetRoster');
    });

    const openThings = async () => {
      await this.things.reload();
      this.ui.renderThings(this.things.items, (item) => this.deleteThing(item));
      this.ui.openSheet('sheetThings');
    };
    $('btnThings').addEventListener('click', openThings);

    for (const id of ['btnRememberObject', 'btnRememberObject2']) {
      $(id).addEventListener('click', () => {
        this.ui.closeSheet(this.ui.sheetOpen ?? '');
        this.openObjectSheet();
      });
    }

    $('btnSettings').addEventListener('click', () => {
      this.ui.setDiagnostics(this.#diagnostics());
      this.ui.renderSettings();
      this.ui.openSheet('sheetSettings');
    });

    $('btnFlip').addEventListener('click', () => this.flipCamera());
    $('registerForm').addEventListener('submit', (event) => this.saveRegistration(event));
    $('objectForm').addEventListener('submit', (event) => this.saveObject(event));

    $('btnUseEstimate').addEventListener('click', () => {
      if (this.capture.ages.length === 0) return;
      const average = this.capture.ages.reduce((a, b) => a + b, 0) / this.capture.ages.length;
      $('fieldAge').value = String(Math.round(average));
    });

    $('btnExport').addEventListener('click', () => this.exportPeople());
    $('btnImport').addEventListener('click', () => $('fileImport').click());
    $('fileImport').addEventListener('change', (event) => {
      const [file] = event.target.files;
      if (file) this.importPeople(file);
      event.target.value = '';
    });

    $('btnWipe').addEventListener('click', () => this.wipeAll());

    // --- ReyRey ---
    $('reyOrb').addEventListener('click', () => this.onOrb());
    $('askForm').addEventListener('submit', async (event) => {
      event.preventDefault();
      const input = $('askInput');
      const text = input.value.trim();
      if (!text) return;
      input.value = '';
      await this.handle(text, 'text');
    });
    $('btnAskMic').addEventListener('click', () => this.toggleListening());

    // Öffnet sich die Bildschirmtastatur, rutscht das Eingabefeld sonst
    // hinter sie. Ein verzögertes Heranscrollen holt es zurück.
    $('askInput').addEventListener('focus', () => {
      setTimeout(() => $('askForm').scrollIntoView({ block: 'end', behavior: 'smooth' }), 260);
    });
    $('reyBubble').addEventListener('click', () => this.ui.hideSay());

    this.ui.onSheetClose = (id) => {
      if (id === 'sheetRegister') {
        this.capture.active = false;
        if (this.running) this.ui.setMode('AKTIV', true);
      }
      if (id === 'sheetObject') this.pendingObject = null;
    };

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && this.running && this.ui.settings.keepAwake) {
        this.camera.requestWakeLock();
      }
    });
  }

  /** Tippen auf die Kugel: zuhören an/aus, oder das Textfenster öffnen. */
  async onOrb() {
    if (this.voice.supported && this.ui.settings.assistantVoiceConsent) {
      if (this.voice.wanted) {
        this.voice.stop();
        this.ui.applySetting('assistantListening', false);
        this.ui.toast('ReyRey hört nicht mehr zu');
      } else {
        this.voice.start();
        this.voice.holdOpen();
        this.ui.applySetting('assistantListening', true);
        this.ui.setReyState('wach');
        this.ui.say('Ich höre.');
      }
      return;
    }
    this.ui.openSheet('sheetAsk');
    setTimeout(() => $('askInput').focus(), 150);
  }

  async toggleListening() {
    if (!this.voice.supported) {
      this.ui.toast('Dieser Browser kann keine Spracherkennung', 'warn');
      return;
    }
    const allowed = await this.confirmSetting('assistantListening', true);
    if (!allowed) return;
    this.ui.applySetting('assistantListening', true);
    this.voice.start();
    this.voice.holdOpen();
    this.ui.closeSheet('sheetAsk');
  }

  async flipCamera() {
    if (!this.running) return;
    const button = $('btnFlip');
    button.disabled = true;
    try {
      const facing = await this.camera.flip();
      this.objects.reset();
      this.faces.reset();
      this.gestures.reset();
      this.#applyMirror();
      const { width, height } = this.camera.size;
      this.ui.setResolution(`${width}×${height}`);
      this.ui.toast(facing === 'user' ? 'Frontkamera' : 'Rückkamera');
    } catch (error) {
      this.ui.toast(error?.message ?? 'Kamerawechsel fehlgeschlagen', 'bad');
    } finally {
      button.disabled = false;
    }
  }

  /* =====================================================================
   * Einstellungen
   * ===================================================================== */

  /**
   * Holt für heikle Schalter vorher eine Zustimmung ein.
   * @returns {Promise<boolean>} false bricht das Umschalten ab
   */
  async confirmSetting(key, value) {
    if (!value) return true;

    if (key === 'assistantListening' && !this.ui.settings.assistantVoiceConsent) {
      const agreed = await this.ui.askConsent({
        title: 'Mikrofon freigeben',
        body: `<h3>Dauerhaft zuhören</h3>
          <p><strong>Die Spracherkennung läuft nicht auf diesem Gerät.</strong> Chrome überträgt
          den Ton an Google, Safari an Apple. Das ist eine Eigenschaft des Browsers, keine
          Entscheidung dieser App – eine Webseite kann das nicht umgehen.</p>
          <ul>
            <li>Übertragen wird der Ton, solange zugehört wird – nicht nur nach dem Aktivierungswort.</li>
            <li>Das Kamerabild ist davon nicht betroffen und bleibt auf dem Gerät.</li>
            <li>Du kannst das Zuhören jederzeit über die Kugel wieder abschalten.</li>
          </ul>
          <p>Ohne Mikrofon bleibt ReyRey vollständig nutzbar – du tippst die Frage dann.</p>`,
        confirm: 'Verstanden, Mikrofon freigeben',
      });
      if (!agreed) return false;
      this.ui.applySetting('assistantVoiceConsent', true);
      return true;
    }

    if (key === 'cloudEnabled' && !this.ui.settings.cloudConsent) {
      const agreed = await this.ui.askConsent({
        title: 'KI-Dienst freigeben',
        body: `<h3>Allgemeine Fragen beantworten</h3>
          <p>Damit ReyRey Fragen beantworten kann, die über das Kamerabild hinausgehen
          (etwa zu einer Marke auf einer Verpackung), muss er nach draussen fragen.</p>
          <ul>
            <li>Übertragen wird <strong>nur Text</strong>: deine Frage und ein kurzer Satz darüber,
            was die Erkennung gerade sieht.</li>
            <li><strong>Nie</strong> übertragen werden: das Kamerabild, Gesichtsabdrücke,
            gespeicherte Namen oder deine Gegenstände.</li>
            <li>Empfohlen ist die Betriebsart „Proxy“: Der Schlüssel bleibt auf deinem Webspace.</li>
          </ul>
          <p>Ohne diese Freigabe antwortet ReyRey nur zu dem, was er sieht.</p>`,
        confirm: 'Verstanden, freigeben',
      });
      if (!agreed) return false;
      this.ui.applySetting('cloudConsent', true);
      return true;
    }

    if (key === 'rememberPlaces') {
      const agreed = await this.ui.askConsent({
        title: 'Standort verwenden',
        body: `<h3>Orte zu Gegenständen merken</h3>
          <p>Beim Merken eines Gegenstands wird der ungefähre Standort mitgespeichert,
          damit ReyRey später sagen kann, wo er ihn zuletzt gesehen hat.</p>
          <ul>
            <li>Der Standort bleibt auf dem Gerät und wird nirgendwohin gesendet.</li>
            <li>Der Browser fragt zusätzlich um Erlaubnis.</li>
          </ul>`,
        confirm: 'Standort erlauben',
      });
      return agreed;
    }

    return true;
  }

  /** Knöpfe in den Einstellungen. */
  async onSettingAction(key) {
    if (key === '__wipe') {
      await this.wipeAll();
      return;
    }

    if (key === '__vault') {
      if (!Vault.available) {
        this.ui.toast('Dieser Browser kann nicht verschlüsseln', 'warn');
        return;
      }

      if (this.vault.enabled && !this.vault.key) {
        const pass = await this.ui.askPassphrase('öffnen');
        if (!pass) return;
        try {
          await this.vault.unlock(pass);
          await this.reloadPeople();
          this.ui.toast('Kartei entschlüsselt', 'good');
        } catch (error) {
          this.ui.toast(error.message, 'bad');
        }
        return;
      }

      if (this.vault.enabled) {
        this.ui.toast('Die Verschlüsselung ist bereits aktiv');
        return;
      }

      const pass = await this.ui.askPassphrase('einrichten');
      if (!pass) return;
      try {
        const people = await this.store.all();
        await this.vault.setUp(pass);
        // Bestehende Einträge sofort verschlüsselt neu ablegen.
        for (const person of people) await this.store.put(person);
        await this.reloadPeople();
        this.ui.toast('Kartei ist jetzt verschlüsselt', 'good');
      } catch (error) {
        this.ui.toast(error.message, 'bad');
      }
    }
  }

  onSettingChange(key, value) {
    if (key === 'objects') {
      $('btnObjects').setAttribute('aria-pressed', String(value));
      if (!value) this.objects.reset();
    }
    if (key === 'faces') {
      $('btnFaces').setAttribute('aria-pressed', String(value));
      if (!value) this.faces.reset();
    }
    if (key === 'mirrorFront') this.#applyMirror();
    if (key === 'showEffects') this.#applyEffects();
    if (key === 'keepAwake') {
      if (value) this.camera.requestWakeLock();
      else this.camera.releaseWakeLock();
    }
    if (key === 'assistantName') {
      this.rey.setName(value);
      this.ui.setReyName(value);
    }
    if (key === 'assistantWakeWord') this.voice.setWakeWord(value);
    if (key === 'assistantListening') {
      if (value && this.voice.supported) this.voice.start();
      else this.voice.stop();
    }
    if (key === 'gesturesEnabled' && !value) this.gestures.reset();
    if (key === 'privacy' && value) {
      // Im Privatmodus verschwinden laufende Zuordnungen sofort aus dem Bild.
      for (const track of this.faces.tracks) track.identity = null;
      this.ui.toast('Privatmodus an – keine Namen, nichts wird gespeichert', 'good');
    }
    if (key === 'hazardWarnings' && !value) {
      this.hazards = [];
      this.hazardVoice.reset();
    }
  }

  #applyAllSettings() {
    $('btnObjects').setAttribute('aria-pressed', String(this.ui.settings.objects));
    $('btnFaces').setAttribute('aria-pressed', String(this.ui.settings.faces));
    this.#applyMirror();
    this.#applyEffects();
  }

  #applyMirror() {
    const mirrored = this.ui.settings.mirrorFront && this.camera.isFront;
    this.stage.classList.toggle('stage--mirrored', mirrored);
    this.hud.mirrored = mirrored;
  }

  #applyEffects() {
    const fx = document.querySelector('.fx');
    if (fx) fx.style.display = this.ui.settings.showEffects ? '' : 'none';
  }

  #diagnostics() {
    const memory = this.engine.memory();
    const { width, height } = this.camera.size;
    return [
      `Rechenwerk: ${this.engine.tf?.getBackend?.() ?? '–'}`,
      `Kamera: ${width}×${height} · ${this.camera.isFront ? 'Front' : 'Rück'}`,
      `Bildrate: ${Math.round(this.fps)}/s · Analysen: ${this.detectionRate}/s`,
      `Modelle: Gesichter ${this.engine.ready.faces ? 'ja' : 'nein'} · Objekte ${this.engine.ready.objects ? 'ja' : 'nein'} · Text ${this.ocr.ready ? 'ja' : 'nicht geladen'}`,
      `Grafikspeicher: ${memory.mb} MB in ${memory.tensors} Tensoren`,
      `Kartei: ${this.matcher.size} Personen · ${this.things.size} Gegenstände`,
      `Ablage: ${this.store.usesFallback ? 'localStorage' : 'IndexedDB'} · Verschlüsselung ${this.vault.enabled ? (this.vault.key ? 'offen' : 'zu') : 'aus'}`,
      `Spracherkennung: ${this.voice.supported ? (this.voice.wanted ? 'hört' : 'bereit') : 'nicht verfügbar'}`,
      `KI-Dienst: ${this.cloud.status()}`,
      `Bildschirm: ${Math.round(globalThis.innerWidth)}×${Math.round(globalThis.innerHeight)} @ ${globalThis.devicePixelRatio ?? 1}×`,
    ];
  }
}

/* --------------------------------------------------------------------------
 * Hilfen
 * -------------------------------------------------------------------------- */

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const nextFrame = () => new Promise((resolve) => requestAnimationFrame(() => resolve()));

function escapeHtml(text) {
  return String(text).replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
  );
}

/** Kurzer Bestätigungston, wenn das Aktivierungswort erkannt wurde. */
function beep() {
  try {
    const Ctx = globalThis.AudioContext ?? globalThis.webkitAudioContext;
    if (!Ctx) return;
    const ctx = new Ctx();
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();
    oscillator.frequency.value = 880;
    gain.gain.setValueAtTime(0.0001, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.12, ctx.currentTime + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.16);
    oscillator.connect(gain).connect(ctx.destination);
    oscillator.start();
    oscillator.stop(ctx.currentTime + 0.18);
    setTimeout(() => ctx.close(), 400);
  } catch {
    /* Ton ist Beiwerk – ohne ihn läuft alles weiter. */
  }
}

/**
 * Abstand → Anzeigewert in Prozent.
 *
 * Das ist bewusst *keine* Wahrscheinlichkeit, sondern eine lineare Abbildung:
 * Abstand 0 ergibt 100 %, der Schwellwert ergibt 58 %. Alles darüber gilt
 * ohnehin als "unbekannt" und wird nie angezeigt.
 */
function confidenceFrom(distance, threshold) {
  if (!Number.isFinite(distance) || threshold <= 0) return 0;
  return Math.max(0, Math.min(1, 1 - (distance / threshold) * 0.42));
}

const app = new App();
app.init().catch((error) => {
  console.error(error);
  document.body.innerHTML = `<pre style="color:#ffb648;padding:24px;font:13px monospace">Start fehlgeschlagen:\n${String(error)}</pre>`;
});
