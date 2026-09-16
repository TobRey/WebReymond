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
 *                      einfriert. Stufen: Objekte (601 Klassen) → Gesichter →
 *                      Hände → Zweitstufe (1000 Klassen, ein Ausschnitt) →
 *                      Wer ist das? (ein Gesicht) → Gedächtnis.
 *
 * Die Texterkennung läuft daneben in einem eigenen Arbeiter, nur wenn die
 * Kamera ruhig gehalten wird; der Scan läuft auf Zuruf.
 */

import { CONFIG } from './config.js';
import { Camera, CameraError } from './camera.js';
import { Engine } from './engine.js';
import { Tracker } from './tracker.js';
import { Hud, Radar } from './hud.js';
import { Matcher, PeopleStore, makeId } from './store.js';
import { UI, $, DEFAULT_BINDINGS } from './ui.js';
import { Assistant } from './assistant.js';
import { Voice } from './voice.js';
import { Ocr, Stillness, groupBlocks, looksLikeText } from './ocr.js';
import { Cloud } from './cloud.js';
import { Translator } from './translate.js';
import { ObjectMemory, describeCrop, describeWhen } from './memory.js';
import { trackMotion, movingTargets } from './motion.js';
import { assessHazards, HazardVoice } from './hazard.js';
import { Hands } from './hands.js';
import { SkillStore, classesFor, actionName } from './skills.js';
import { OIV7 } from './labels-oiv7.js';
import { IMAGENET } from './labels-imagenet.js';
import { refine } from './classifier.js';
import { withArticle } from './labels.js';
import { runScan } from './scan.js';
import { Vault, wipeEverything } from './privacy.js';

/** Merker: Die Seite wurde schon einmal von Hand gestartet → Autostart. */
const AUTOSTART_KEY = 'visionhud.autostart';

/** So lange wartet eine Handzeichen-Aufnahme auf eine Hand. */
const RECORD_TIMEOUT_MS = 14000;

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
    this.skillStore = new SkillStore();
    /** Klasse → aktiver Gruppen-Skill, aus skillStore abgeleitet. */
    this.skillIndex = new Map();
    this.skillAnnounced = new Map();

    this.objects = new Tracker(CONFIG.tracking);
    this.faces = new Tracker(CONFIG.tracking);

    this.cloud = new Cloud(this.ui.settings);
    this.translator = new Translator(this.ui.settings, this.cloud);
    this.ocr = new Ocr();
    this.stillness = new Stillness();
    this.voice = new Voice();
    this.hands = new Hands();
    this.hazardVoice = new HazardVoice(CONFIG.assistant.hazardQuietMs);
    this.rey = new Assistant(this.#skills());

    this.running = false;
    this.lastObjectRun = 0;
    this.lastFaceRun = 0;
    this.lastIdentify = 0;
    this.lastMemoryRun = 0;
    this.lastHandRun = 0;
    this.lastClassifyRun = 0;
    this.lastStillRun = 0;
    this.lastFrame = 0;
    this.fps = 0;
    this.detectionRate = 0;
    this.detectionTicks = 0;
    this.detectionWindow = 0;

    /** Erkannte Wörter und Textblöcke, die gerade im Bild markiert sind. */
    this.words = [];
    this.blocks = [];
    this.wordsUntil = 0;
    /** Zuletzt gesehene Hand (für das HUD). */
    this.hand = null;
    /** Laufende Aufnahme eines Handzeichens: { resolve, timer }. */
    this.recording = null;
    /** 0 = kein Scan, sonst Fortschritt 0 … 1. */
    this.scanProgress = 0;
    this.hazards = [];
    this.busy = false;
    this.thinking = false;
    /** Letztes Lupenergebnis, für Vorlesen/Kopieren/Übersetzen. */
    this.magnified = null;

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
    this.ui.startClock();
    this.#wireControls();
    this.#wireVoice();
    this.#applyAllSettings();
    this.#registerServiceWorker();

    await this.store.open();
    await this.things.open();
    await this.skillStore.open();
    this.#refreshSkills();
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

    $('btnStart').addEventListener('click', () => this.start({ auto: false }));
    $('btnRetry').addEventListener('click', () => {
      $('fault').hidden = true;
      $('boot').hidden = false;
      $('boot').classList.remove('is-leaving');
    });

    // Ab dem zweiten Besuch: kein Tipp mehr nötig, wenn der Browser die
    // Freigaben behalten hat.
    if (await this.#autostartPossible()) {
      this.start({ auto: true });
    }
  }

  /**
   * Darf die Seite ohne Klick starten?
   *
   * Der Browser merkt sich Kamera- und Mikrofonfreigabe nur, wenn der Nutzer
   * sie dauerhaft erteilt hat (Safari: aA → Website-Einstellungen → Erlauben).
   * Ist das so, startet alles von selbst; sonst bleibt der Startknopf.
   */
  async #autostartPossible() {
    try {
      if (!localStorage.getItem(AUTOSTART_KEY)) return false;
    } catch {
      return false;
    }
    if (!navigator.permissions?.query) return true;
    try {
      const camera = await navigator.permissions.query({ name: 'camera' });
      return camera.state === 'granted';
    } catch {
      // Der Browser kennt die Abfrage nicht – dann einfach versuchen.
      return true;
    }
  }

  async start({ auto = false } = {}) {
    if (this.running || this.starting) return;
    this.starting = true;
    const button = $('btnStart');
    button.disabled = true;
    button.textContent = auto ? 'Startet automatisch …' : 'Startet …';
    this.stage.classList.add('stage--booting');
    if (!auto) this.voice.unlock();

    try {
      this.ui.bootStep('Kamera', 'run');
      this.ui.bootHint(auto ? 'Kamera wird geöffnet …' : 'Bitte Kamera und Mikrofon bestätigen.');
      // Beim ersten Mal Kamera und Mikrofon in einem Zug – ein Dialog statt zwei.
      await this.camera.start(undefined, { withAudio: !auto && this.voice.supported });
      const { width, height } = this.camera.size;
      this.ui.bootStep('Kamera', 'ok', `${width}×${height}`);
      this.ui.bootProgress(0.25);
      try {
        localStorage.setItem(AUTOSTART_KEY, '1');
      } catch {
        /* Ohne Speicher eben jedes Mal ein Tipp. */
      }

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

      this.#loadModelsInBackground();
      this.#greet();
    } catch (error) {
      this.stage.classList.remove('stage--booting');
      button.disabled = false;
      button.textContent = 'Starten';
      this.ui.bootStep('Kamera', 'fail', 'fehlgeschlagen');
      if (auto) {
        // Ohne Klick keine Freigabe – dann eben mit Klick.
        this.ui.bootHint('Bitte einmal auf „Starten“ tippen.');
      } else {
        this.ui.showFault(
          error instanceof CameraError
            ? error
            : new CameraError('Start fehlgeschlagen', error?.message ?? String(error), [
                'Seite neu laden.',
                'Den Selbsttest öffnen: pruefung.html im selben Ordner.',
              ]),
        );
      }
    } finally {
      this.starting = false;
    }
  }

  #greet() {
    const name = this.ui.settings.assistantName;
    if (this.ui.settings.assistantListening && this.voice.supported) {
      this.voice.start();
      this.ui.say(
        `${name} hört zu. Sag „${this.ui.settings.assistantWakeWord}“ und dann deine Frage.`,
      );
    } else if (!this.voice.supported) {
      this.ui.say(`Tippe auf die Kugel, um ${name} zu fragen.`, 6000);
    }
  }

  /**
   * Lädt die schweren Modelle nacheinander, während die Kamera schon läuft.
   * Reihenfolge nach Nutzen: Objekte, Zweitstufe, Hände, Text.
   */
  async #loadModelsInBackground() {
    const report = (step, state, detail) => this.ui.bootStep(step, state, detail);

    try {
      this.ui.toast('Objektmodell wird geladen …');
      await this.engine.loadObjectModel(report);
      this.ui.bootProgress(0.85);
      this.ui.toast('Objekterkennung bereit · 601 Klassen', 'good');
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

    if (this.ui.settings.classifierEnabled) {
      try {
        await this.engine.loadClassifier(report);
      } catch (error) {
        console.warn('Zweitstufe:', error);
        report('Zweitstufe', 'fail', 'nicht ladbar');
      }
    }
    this.ui.bootProgress(1);

    if (this.ui.settings.gesturesEnabled) await this.#loadHands();
    if (this.ui.settings.ocrBackground) this.#startBackgroundOcr();
  }

  async #loadHands() {
    if (this.hands.ready || this.handsFailed) return;
    try {
      await this.hands.prepare();
      this.hands.setCustom(this.skillStore.customGestures);
      this.ui.toast('Handzeichen bereit', 'good');
    } catch (error) {
      console.warn('Handerkennung:', error);
      this.handsFailed = true;
      this.ui.toast('Handerkennung nicht ladbar – Selbsttest öffnen', 'warn');
    }
  }

  #startBackgroundOcr() {
    if (this.ocrBackgroundRunning) return;
    this.ocrBackgroundRunning = true;
    this.ocr.startBackground({
      video: this.video,
      intervalMs: CONFIG.ocr.backgroundMs,
      shouldRun: () =>
        this.running &&
        this.ui.settings.ocrBackground &&
        !this.ui.settings.privacy &&
        !this.busy &&
        !document.hidden &&
        this.stillness.still,
      onResult: (result) => this.#onBackgroundText(result),
    });
  }

  #onBackgroundText(result) {
    const usable = result.words.filter((word) => word.confidence >= 0.5);
    if (usable.length === 0 || !looksLikeText(result.text)) {
      // Nichts Lesbares mehr im Bild – Markierungen laufen aus.
      this.wordsUntil = Math.min(this.wordsUntil, performance.now() + 600);
      return;
    }
    this.words = usable;
    this.blocks = groupBlocks(usable);
    this.wordsUntil = performance.now() + CONFIG.ocr.backgroundMs * 2.2;
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
      this.#resetTracking();
    } else {
      this.ui.setMode('GESTOPPT');
      this.ui.toast(detail ?? 'Kamera nicht mehr erreichbar', 'bad');
    }
  }

  #resetTracking() {
    this.objects.reset();
    this.faces.reset();
    this.hand = null;
    this.words = [];
    this.blocks = [];
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

    const objectTracks = this.ui.settings.objects ? this.#objectTracks() : [];
    const faceTracks = this.ui.settings.faces ? this.faces.visible() : [];

    // Bewegungsrichtung braucht einen Verlauf – deshalb bei jedem Bild.
    trackMotion([...objectTracks, ...faceTracks], this.video.videoWidth, now);

    this.hazards = this.ui.settings.hazardWarnings
      ? assessHazards(
          objectTracks.filter((track) => !track.faint),
          this.video.videoWidth,
        )
      : [];

    if (now > this.wordsUntil) {
      this.words = [];
      this.blocks = [];
    }

    this.hud.render({
      objects: objectTracks,
      faces: faceTracks,
      settings: this.ui.settings,
      time: now,
      hazards: this.hazards,
      words: this.words,
      blocks: this.blocks,
      hands: this.hand ? [this.hand] : [],
      scanning: this.scanProgress,
    });

    this.radar.render([...objectTracks, ...faceTracks], this.video.videoWidth);
    this.#updateReadouts(objectTracks, faceTracks);
    this.#updateAlarm();

    requestAnimationFrame((t) => this.#renderLoop(t));
  }

  /** Sichtbare Objektziele, je nach Einstellung mit oder ohne Unsichere. */
  #objectTracks() {
    const tracks = this.objects.visible();
    return this.ui.settings.showFaint ? tracks : tracks.filter((track) => !track.faint);
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
      objects: objectTracks.filter((track) => !track.faint).length,
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
      } else if (track.skill) {
        contacts.push({ kind: 'known', text: `${displayName(track)} · ${track.skill.name}` });
      } else {
        const percent = Math.round((track.fine?.score ?? track.score) * 100);
        contacts.push({
          kind: 'object',
          text: `${track.faint ? '? ' : ''}${displayName(track)} ${percent}%`,
        });
      }
    }
    this.ui.setContacts(contacts.slice(0, 14));
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

      // Während ein Handzeichen aufgenommen wird, ruhen die teuren Stufen:
      // So kommen in den zwei Sekunden genug Bilder der Hand zusammen – auch
      // auf einem Gerät, das für den Detektor eine halbe Sekunde braucht.
      const recording = Boolean(this.recording);

      try {
        if (
          !recording &&
          this.ui.settings.objects &&
          this.engine.ready.objects &&
          now - this.lastObjectRun >= CONFIG.objects.intervalMs
        ) {
          this.lastObjectRun = now;
          const found = await this.engine.detectObjects(
            this.video,
            this.ui.settings.minScore,
            this.ui.settings.showFaint,
          );
          this.objects.update(found);
          this.#afterObjects();
          didWork = true;
        }

        if (
          !recording &&
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
          this.hands.ready &&
          now - this.lastHandRun >= CONFIG.hands.intervalMs
        ) {
          this.lastHandRun = now;
          this.#handStep(now);
        }

        if (
          !recording &&
          this.ui.settings.classifierEnabled &&
          this.engine.ready.classifier &&
          this.ui.settings.objects &&
          now - this.lastClassifyRun >= CONFIG.classifier.intervalMs
        ) {
          this.lastClassifyRun = now;
          await this.#classifyStep(now);
        }

        if (recording) {
          /* Nur Hände. */
        } else if (this.capture.active) {
          await this.#captureStep();
        } else if (this.ui.settings.faces) {
          await this.#identifyStep();
        }

        if (
          !recording &&
          this.things.size > 0 &&
          now - this.lastMemoryRun >= CONFIG.memory.matchIntervalMs
        ) {
          this.lastMemoryRun = now;
          this.#memoryStep();
        }

        if (now - this.lastStillRun >= 220) {
          this.lastStillRun = now;
          this.stillness.measure(this.video);
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
   * Nach jedem Detektorlauf: „unsicher“ aus dem geglätteten Wert ableiten
   * (mit Hysterese, sonst flackert der Rahmen an der Schwelle) und Skills
   * zuordnen.
   */
  #afterObjects() {
    const sure = this.ui.settings.minScore;
    const now = performance.now();

    for (const track of this.objects.tracks) {
      track.faint = track.faint ? track.score < sure + 0.03 : track.score < sure - 0.03;

      // Zweitstufe passt nicht mehr, wenn der Detektor die Klasse gewechselt hat
      // oder das Ziel inzwischen sicher ist und die Regel strenger greift.
      if (track.fine && track.fine.forLabel !== track.label) track.fine = null;
      if (
        track.fine &&
        track.fineTop &&
        !refine(track, track.fineTop, CONFIG.classifier.minScore)
      ) {
        track.fine = null;
      }

      const skill = this.#skillFor(track);
      if (skill && !track.skill) this.#announceSkill(skill, track, now);
      track.skill = skill;
    }
  }

  #skillFor(track) {
    if (this.skillIndex.size === 0 || track.kind === 'person') return null;
    const fine = track.fine ? this.skillIndex.get(`#${track.fine.index}`) : null;
    return fine ?? this.skillIndex.get(track.label) ?? null;
  }

  /** Sagt einen neuen Skill-Treffer an – gebremst, sonst plappert ReyRey. */
  #announceSkill(skill, track, now) {
    if (track.faint || skill.announce === false) return;
    const last = this.skillAnnounced.get(skill.id) ?? 0;
    if (now - last < 6000) return;
    this.skillAnnounced.set(skill.id, now);

    const text = `${skill.name}: ${displayName(track)}`;
    if (this.ui.settings.assistantOverlay) this.ui.toast(text, 'good');
    if (this.ui.settings.assistantSpeak && !this.thinking) this.voice.speak(text, { rate: 1.08 });
  }

  /** Klasse → Skill neu aufbauen (nach jeder Änderung an den Skills). */
  #refreshSkills() {
    this.skillIndex = new Map();
    for (const skill of this.skillStore.groups) {
      for (const classId of skill.classes) {
        if (!this.skillIndex.has(classId)) this.skillIndex.set(classId, skill);
      }
    }
    for (const track of this.objects.tracks) track.skill = this.#skillFor(track);
    this.hands.setCustom(this.skillStore.customGestures);
  }

  /**
   * Zweitstufe: ein Ausschnitt pro Runde. Vorrang haben Ziele ohne Antwort,
   * dann unsichere, dann die mit der ältesten Antwort.
   */
  async #classifyStep(now) {
    const candidates = this.objects
      .visible()
      .filter(
        (track) =>
          track.kind !== 'person' &&
          track.target.w >= CONFIG.classifier.minWidth &&
          track.target.h >= CONFIG.classifier.minWidth &&
          now - (track.fineAt ?? 0) >= CONFIG.classifier.ttlMs,
      );
    if (candidates.length === 0) return;

    candidates.sort((a, b) => {
      const newA = a.fineAt ? 1 : 0;
      const newB = b.fineAt ? 1 : 0;
      if (newA !== newB) return newA - newB;
      if (a.faint !== b.faint) return a.faint ? -1 : 1;
      return (a.fineAt ?? 0) - (b.fineAt ?? 0);
    });

    const track = candidates[0];
    track.fineAt = now;
    const top = await this.engine.classifyBox(this.video, track.target);
    if (top.length === 0) return;

    const result = refine(track, top, CONFIG.classifier.minScore);
    track.fineTop = top;
    track.fine = result ? { ...result, forLabel: track.label } : null;
    if (result) {
      const skill = this.#skillFor(track);
      if (skill && !track.skill) this.#announceSkill(skill, track, now);
      track.skill = skill;
    }
  }

  /** Hände: Zeichen erkennen, Aufnahme fortschreiben, Auslöser weiterreichen. */
  #handStep(now) {
    const { hand, fired, recorded } = this.hands.update(this.video, now);
    this.hand = hand;

    if (this.recording) {
      if (hand) this.ui.setGestureProgress(hand.hold);
      if (recorded) this.#finishRecording({ ok: true, ...recorded });
      return;
    }
    if (fired) this.#onGesture(fired);
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

    // Verglichen wird innerhalb der Gruppe (Behälter, Möbel …), nicht der
    // exakten Klasse: „Karton“ und „Paket“ sind derselbe Gegenstand.
    const { item, score } = this.things.match(describeCrop(crop), track.group ?? null);
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
        this.#objectTracks()
          .filter((track) => track.kind !== 'person' && !track.faint)
          .map((track) => ({
            label: track.label,
            labelDe: track.labelDe ?? track.label,
            fine: track.fine?.label ?? null,
            group: track.group ?? null,
            name: track.memory?.item?.name ?? null,
            skill: track.skill?.name ?? null,
          })),

      whatIsThis: () => this.whatIsThis(),
      time: () => timeText(),
      motion: () => movingTargets([...this.objects.visible(), ...this.faces.visible()], 3),
      hazards: () => this.hazards,
      scan: () => this.runScan(),
      readText: (options) => this.readText(options),
      magnify: () => this.magnify(),
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

      /* --- Handzeichen --- */
      gesturesReady: () => this.hands.ready,
      prompt: (text) => this.prompt(text),
      recordGesture: () => this.recordGesture(),
      saveGesture: (data) => this.saveGesture(data),
      listGestures: () => this.skillStore.gestures,
      findGesture: (name) => this.findGesture(name),
      deleteGesture: (id) => this.deleteGesture(id),

      /* --- Skills --- */
      previewSkill: (description) => this.previewSkill(description),
      saveSkill: (preview) => this.saveSkill(preview),
      listSkills: () => this.skillStore.items.filter((skill) => skill.kind === 'group'),
      findSkill: (name) => this.skillStore.findGroupByName(name),
      toggleSkill: (id, on) => this.toggleSkill(id, on),
      deleteSkill: (id) => this.deleteSkill(id),
      matchCommand: (text) => this.matchCommand(text),
      runAction: (id) => this.runAction(id),
      cancelDialog: () => this.#finishRecording({ ok: false, reason: 'Abgebrochen.' }),
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

    this.voice.onCommand = (text) => {
      // Während ReyRey noch arbeitet (z. B. ein Zeichen aufnimmt), wartet
      // der nächste Satz – sonst verhaken sich zwei Dialoge.
      if (this.thinking) return;
      this.handle(text, 'stimme');
    };

    this.voice.onError = (error) => {
      /*
       * Die Einstellung bleibt an: Beim nächsten Besuch soll es wieder
       * versucht werden. Sichtbar ist nur, dass gerade nicht zugehört wird –
       * ein Tipp auf die Kugel startet das Zuhören aus einer Berührung heraus,
       * was iOS auch ohne dauerhafte Freigabe erlaubt.
       */
      this.ui.toast(error.message, 'warn');
      this.ui.setReyState('aus');
      this.ui.setHint('Tippen zum Zuhören');
    };

    this.voice.onNeedsUnlock = () => this.ui.setNeedsUnlock(true);

    // iOS gibt die Sprachausgabe erst nach der ersten Berührung frei.
    document.addEventListener(
      'pointerdown',
      () => {
        if (!this.voice.unlocked) {
          this.voice.unlock();
          this.ui.setNeedsUnlock(false);
        }
      },
      { passive: true },
    );
  }

  /**
   * Nimmt eine Frage entgegen – gesprochen, getippt oder per Geste – und antwortet.
   * @param {string} text
   * @param {'stimme'|'text'|'geste'} source
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
    await this.announce(answer, source);
  }

  /** Zeigt und spricht eine Antwort; hält bei Rückfragen das Mikrofon offen. */
  async announce(answer, source = 'geste') {
    if (!answer?.text) return;
    if (this.ui.settings.assistantOverlay) this.ui.say(answer.text, answer.ask ? 0 : undefined);
    if (source === 'text') this.ui.pushChat('assistant', answer.text);

    if (this.ui.settings.assistantSpeak && !answer.silent) {
      await this.voice.speak(answer.text);
    }
    if (answer.ask) {
      // Nach einer Rückfrage bleibt das Mikrofon offen, ohne Aktivierungswort.
      this.voice.holdOpen();
      // Ohne Mikrofon muss die Antwort getippt werden können.
      if (!this.voice.wanted && this.ui.sheetOpen !== 'sheetAsk') this.openAsk();
    }
    this.ui.setReyState(this.voice.wanted ? 'hört' : 'aus');
  }

  /** Sagt einen Satz und wartet, bis er gesprochen ist (für geführte Dialoge). */
  async prompt(text) {
    this.ui.say(text, 0);
    if (this.ui.settings.assistantSpeak) await this.voice.speak(text);
  }

  /* =====================================================================
   * Aktionen – gemeinsame Sprache von Handzeichen, Befehlen und Dialogen
   * ===================================================================== */

  /**
   * Führt eine Aktion aus der Aktionsliste (skills.js) aus.
   * @returns {Promise<{text: string|null, silent?: boolean}>}
   */
  async runAction(id) {
    const s = this.ui.settings;
    const set = (key, value) => {
      this.ui.applySetting(key, value);
      this.onSettingChange(key, value);
    };

    switch (id) {
      case 'objects.toggle':
        set('objects', !s.objects);
        return { text: s.objects ? 'Objekterkennung an.' : 'Objekterkennung aus.' };
      case 'objects.on':
        set('objects', true);
        return { text: 'Objekterkennung an.' };
      case 'objects.off':
        set('objects', false);
        return { text: 'Objekterkennung aus.' };
      case 'faces.toggle':
        set('faces', !s.faces);
        return { text: s.faces ? 'Gesichtserkennung an.' : 'Gesichtserkennung aus.' };
      case 'faces.on':
        set('faces', true);
        return { text: 'Gesichtserkennung an.' };
      case 'faces.off':
        set('faces', false);
        return { text: 'Gesichtserkennung aus.' };
      case 'hud.toggle':
        set('hudVisible', !s.hudVisible);
        return { text: s.hudVisible ? 'Anzeige an.' : 'Anzeige aus.', silent: true };
      case 'privacy.toggle':
        set('privacy', !s.privacy);
        return { text: s.privacy ? 'Privatmodus an.' : 'Privatmodus aus.' };
      case 'listen.toggle':
        this.onOrb();
        return { text: null };
      case 'silence':
        this.voice.shutUp();
        this.ui.hideSay();
        return { text: null };
      case 'camera.flip':
        await this.flipCamera();
        return { text: 'Kamera gewechselt.', silent: true };
      case 'registerFace':
        this.openRegister();
        return { text: 'Erfassung geöffnet.' };
      case 'rememberObject':
        this.openObjectSheet();
        return { text: null };
      case 'scan':
        return { text: await this.runScan() };
      case 'describe':
        return { text: this.rey.describeScene() };
      case 'people': {
        const people = this.faces.visible();
        if (people.length === 0) return { text: 'Ich sehe gerade niemanden.' };
        const names = people
          .map((track) => (this.ui.settings.privacy ? null : track.identity?.person?.name))
          .filter(Boolean);
        const unknown = people.length - names.length;
        const bits = [...names];
        if (unknown > 0) bits.push(`${unknown} unbekannte`);
        return { text: `Ich sehe ${bits.join(' und ')}.` };
      }
      case 'readText': {
        const result = await this.readText({ translate: false });
        return {
          text: result.found ? `Da steht: ${result.text}` : 'Ich erkenne keinen lesbaren Text.',
        };
      }
      case 'translate': {
        const result = await this.readText({ translate: true });
        if (!result.found) return { text: 'Ich erkenne keinen lesbaren Text.' };
        return {
          text: result.translated ? `Übersetzt: ${result.translated}` : `Da steht: ${result.text}`,
        };
      }
      case 'magnify': {
        const result = await this.magnify();
        return {
          text: result.found ? `Da steht: ${result.text}` : 'Lupe geöffnet.',
          silent: !result.found,
        };
      }
      case 'time':
        return { text: timeText() };
      case 'snapshot':
        this.snapshot();
        return { text: 'Bild gespeichert.', silent: true };
      case 'none':
        return { text: null };
      default:
        return { text: `Die Aktion „${id}“ kenne ich nicht.` };
    }
  }

  /** Eigene Sprachbefehle (Skills der Art „command“). */
  matchCommand(text) {
    const lower = text.toLowerCase();
    for (const command of this.skillStore.commands) {
      if (command.phrase && lower.includes(command.phrase.toLowerCase())) return command;
    }
    return null;
  }

  /* =====================================================================
   * Handzeichen
   * ===================================================================== */

  async #onGesture(fired) {
    if (this.thinking || this.busy) return;
    let action;
    if (fired.custom) {
      action = this.skillStore.gestures.find((gesture) => gesture.id === fired.id)?.action;
    } else {
      const bindings = { ...DEFAULT_BINDINGS, ...(this.ui.settings.gestureBindings ?? {}) };
      action = bindings[fired.id];
    }
    if (!action || action === 'none') return;

    this.ui.toast(`✋ ${fired.name} → ${actionName(action)}`);
    beep(660);
    try {
      const result = await this.runAction(action);
      if (result?.text) await this.announce(result, 'geste');
    } catch (error) {
      this.ui.toast(error.message, 'bad');
    }
  }

  /**
   * Nimmt ein neues Zeichen auf (~2 s ruhig halten).
   * @returns {Promise<{ok: boolean, vector?: number[], samples?: number, reason?: string}>}
   */
  recordGesture() {
    if (!this.hands.ready) {
      return Promise.resolve({ ok: false, reason: 'Die Handerkennung ist noch nicht bereit.' });
    }
    if (this.recording) this.#finishRecording({ ok: false, reason: 'Neu gestartet.' });

    return new Promise((resolve) => {
      this.hands.startRecording(22);
      this.ui.setMode('AUFNAHME');
      const timer = setTimeout(() => {
        this.#finishRecording({
          ok: false,
          reason:
            'Ich habe keine Hand gesehen. Halte die Hand gut sichtbar ins Bild und versuch es nochmal.',
        });
      }, RECORD_TIMEOUT_MS);
      this.recording = { resolve, timer };
    });
  }

  #finishRecording(result) {
    if (!this.recording) return;
    const { resolve, timer } = this.recording;
    clearTimeout(timer);
    this.recording = null;
    this.hands.cancelRecording();
    if (this.running) this.ui.setMode('AKTIV', true);
    resolve(result);
  }

  /** Legt ein aufgenommenes Zeichen mit Aktion ab – wirkt sofort. */
  async saveGesture({ vector, samples, action }) {
    if (this.ui.settings.privacy) throw new Error('Im Privatmodus speichere ich nichts.');
    const count = this.skillStore.customGestures.length + 1;
    const item = await this.skillStore.add({
      kind: 'gesture',
      name: `Zeichen ${count}`,
      vector,
      samples,
      action,
    });
    this.#refreshSkills();
    return { id: item.id, name: item.name };
  }

  findGesture(name) {
    const needle = String(name).toLowerCase().trim();
    const list = this.skillStore.gestures;
    const number = needle.match(/\d+/)?.[0];
    return (
      list.find((gesture) => gesture.name.toLowerCase() === needle) ??
      list.find((gesture) => gesture.name.toLowerCase().includes(needle)) ??
      (number ? list.find((gesture) => gesture.name.endsWith(` ${number}`)) : null) ??
      list.find((gesture) => actionName(gesture.action).toLowerCase().includes(needle)) ??
      null
    );
  }

  async deleteGesture(id) {
    await this.skillStore.remove(id);
    this.#refreshSkills();
  }

  async openGestures() {
    await this.skillStore.reload();
    this.ui.renderGestures(this.skillStore.gestures, {
      actionName,
      onDelete: async (item) => {
        const sure = await this.ui.askConsent({
          title: 'Löschen bestätigen',
          body: `<h3>${escapeHtml(item.name)} löschen</h3><p>Das Zeichen und seine Zuordnung werden entfernt.</p>`,
          confirm: 'Löschen',
        });
        if (!sure) return;
        await this.deleteGesture(item.id);
        this.openGestures();
      },
      onRecord: () => {
        this.ui.closeSheet('sheetGestures');
        if (!this.hands.ready) {
          this.ui.toast('Handerkennung wird noch geladen', 'warn');
          this.#loadHands();
          return;
        }
        this.handle('erfasse neues handzeichen', 'geste');
      },
    });
    this.ui.openSheet('sheetGestures');
  }

  /* =====================================================================
   * Skills
   * ===================================================================== */

  /** Alle Klassen, auf die ein Skill zeigen kann: Detektor + Zweitstufe. */
  #catalogue() {
    if (!this.catalogue) {
      this.catalogue = [
        ...OIV7.filter((entry) => !entry.hidden).map(({ id, de, group }) => ({ id, de, group })),
        ...IMAGENET.map(({ index, de, group }) => ({ id: `#${index}`, de, group })),
      ];
    }
    return this.catalogue;
  }

  /**
   * Beschreibung → Vorschlag für einen Gruppen-Skill.
   * @returns {Promise<{name, description, classes, groups, examples, color}|null>}
   */
  async previewSkill(description) {
    const clean = description
      .replace(
        /^(ab jetzt|jetzt|bitte)?\s*(erkennst du|erkenne|erkenn|zeig mir|zeige mir|zeig|markiere|markier)?\s*(auch|alle|bitte|mir)?\s*/i,
        '',
      )
      .trim();
    if (!clean) return null;

    const catalogue = this.#catalogue();
    let { classes, groups, unmatched } = classesFor(clean, catalogue);

    // Reicht die Wortliste nicht, darf der KI-Dienst helfen – falls freigegeben.
    if ((classes.length === 0 || unmatched.length > 0) && this.cloud.enabled()) {
      try {
        const picked = await this.cloud.pickClasses(clean, catalogue);
        classes = [...new Set([...classes, ...picked])];
      } catch (error) {
        console.warn('KI-Zuordnung:', error.message);
      }
    }
    if (classes.length === 0) return null;

    // Beispiele in der Reihenfolge der genannten Gruppen: Bei „Bildschirme und
    // Geräte“ zuerst Fernseher und Monitor, nicht Wecker und Standmixer.
    const byId = new Map(catalogue.map((entry) => [entry.id, entry]));
    const rank = (id) => {
      const index = groups.indexOf(byId.get(id)?.group);
      return index === -1 ? groups.length : index;
    };
    const ordered = [...classes].sort((a, b) => rank(a) - rank(b));
    const examples = [...new Set(ordered.map((id) => byId.get(id)?.de).filter(Boolean))];

    return {
      name: titleCase(clean).slice(0, 40),
      description: clean,
      classes,
      groups,
      examples,
      unmatched,
      color: this.skillStore.nextColor(),
    };
  }

  async saveSkill(preview) {
    if (this.ui.settings.privacy) throw new Error('Im Privatmodus speichere ich nichts.');
    const item = await this.skillStore.add({
      kind: 'group',
      name: preview.name,
      description: preview.description,
      classes: preview.classes,
      color: preview.color,
      announce: true,
    });
    this.#refreshSkills();
    return { id: item.id, name: item.name };
  }

  async toggleSkill(id, on) {
    await this.skillStore.update(id, { enabled: on });
    this.#refreshSkills();
  }

  async deleteSkill(id) {
    await this.skillStore.remove(id);
    this.#refreshSkills();
  }

  async openSkills() {
    await this.skillStore.reload();
    const groups = this.skillStore.items.filter((skill) => skill.kind === 'group');
    this.ui.renderSkills(groups, {
      onToggle: async (item, on) => {
        await this.toggleSkill(item.id, on);
        this.openSkills();
      },
      onDelete: async (item) => {
        const sure = await this.ui.askConsent({
          title: 'Löschen bestätigen',
          body: `<h3>Skill „${escapeHtml(item.name)}“ löschen</h3><p>${item.classes.length} Klassen werden nicht mehr hervorgehoben.</p>`,
          confirm: 'Löschen',
        });
        if (!sure) return;
        await this.deleteSkill(item.id);
        this.openSkills();
      },
      onCreate: () => {
        this.ui.closeSheet('sheetSkills');
        this.handle('skill hinzufügen', 'geste');
      },
    });
    this.ui.openSheet('sheetSkills');
  }

  /* =====================================================================
   * Fähigkeiten
   * ===================================================================== */

  /** „Was ist das?“ – das Ziel, das der Bildmitte am nächsten liegt. */
  whatIsThis() {
    const vw = this.video.videoWidth;
    const vh = this.video.videoHeight;
    if (!vw || !vh) return null;

    const candidates = this.objects.visible().filter((track) => track.kind !== 'person');
    if (candidates.length === 0) return null;

    const centre = { x: vw / 2, y: vh / 2 };
    const scoreOf = (track) => {
      const box = track.target;
      const inside =
        centre.x >= box.x &&
        centre.x <= box.x + box.w &&
        centre.y >= box.y &&
        centre.y <= box.y + box.h;
      const dist = Math.hypot(box.x + box.w / 2 - centre.x, box.y + box.h / 2 - centre.y) / vw;
      const area = (box.w * box.h) / (vw * vh);
      // Mittig und nicht riesig gewinnt; die Wand hinter allem soll es nicht sein.
      return (inside ? 1 : 0) - dist - (area > 0.7 ? 0.5 : 0);
    };
    candidates.sort((a, b) => scoreOf(b) - scoreOf(a));
    const track = candidates[0];

    const name = track.memory?.item?.name ?? displayName(track);
    return {
      score: track.fine?.score ?? track.score,
      faint: Boolean(track.faint),
      article: track.memory?.item ? 'dein' : withArticle(name).split(' ')[0],
      name,
      alsoDe:
        track.fine && track.labelDe && track.fine.label !== track.labelDe ? track.labelDe : null,
    };
  }

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
      // Auf einen laufenden Hintergrundlauf warten statt zu scheitern.
      let waited = 0;
      while (this.ocr.busy && waited < 6000) {
        await sleep(100);
        waited += 100;
      }
      const result = await this.ocr.read(this.video);

      if (result.confidence < 0.45 || !looksLikeText(result.text)) {
        return { found: false, text: '' };
      }

      this.words = result.words;
      this.blocks = result.blocks ?? groupBlocks(result.words);
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

  /**
   * Lupe: vergrössert einen Textblock (oder die Bildmitte) und liest ihn neu.
   * @param {{x:number,y:number,w:number,h:number}} [box]  in Videopixeln
   * @returns {Promise<{found: boolean, text: string}>}
   */
  async magnify(box) {
    if (this.busy) return { found: false, text: '' };
    const vw = this.video.videoWidth;
    const vh = this.video.videoHeight;
    if (!vw || !vh) return { found: false, text: '' };

    let target = box;
    if (!target) {
      // Grösster bekannter Textblock, sonst die Bildmitte.
      const largest = [...this.blocks].sort((a, b) => b.box.w * b.box.h - a.box.w * a.box.h)[0];
      target = largest?.box ?? { x: vw * 0.2, y: vh * 0.3, w: vw * 0.6, h: vh * 0.4 };
    }

    this.busy = true;
    this.ui.setMode('LUPE');
    try {
      if (!this.ocr.ready) this.ui.toast('Texterkennung wird geladen …');
      const result = await this.ocr.magnify(this.video, target, CONFIG.ocr.magnify);
      const found = result.confidence >= 0.3 && looksLikeText(result.text);
      this.magnified = { ...result, text: found ? result.text : '' };
      this.ui.showMagnifier(this.magnified);
      return { found, text: found ? result.text : '' };
    } catch (error) {
      this.ui.toast(error.message, 'bad');
      return { found: false, text: '' };
    } finally {
      this.busy = false;
      this.ui.setMode('AKTIV', true);
    }
  }

  /** Tipp ins Bild: Textblock → Lupe, Gegenstand → Name. */
  async onStageTap(event) {
    if (!this.running || this.ui.sheetOpen) return;
    const rect = $('overlay').getBoundingClientRect();
    const x = event.clientX - rect.left;
    const y = event.clientY - rect.top;

    const block = this.hud.hitTest(x, y, this.blocks);
    if (block) {
      const result = await this.magnify(block.box);
      if (result.found && this.ui.settings.assistantSpeak) this.voice.speak(result.text);
      return;
    }

    const tracks = this.#objectTracks()
      .filter((track) => track.kind !== 'person')
      .map((track) => ({ box: track.target, track }));
    const hit = this.hud.hitTest(x, y, tracks);
    if (hit) {
      const { track } = hit;
      const percent = Math.round((track.fine?.score ?? track.score) * 100);
      const name = track.memory?.item?.name ?? displayName(track);
      const text = track.faint
        ? `Unsicher: vielleicht ${withArticle(name)}, ${percent} %.`
        : `${withArticle(name)}, ${percent} %${track.fine && track.labelDe && track.fine.label !== track.labelDe ? ` (${track.labelDe})` : ''}.`;
      this.ui.say(text, 5000);
    }
  }

  /** Speichert das aktuelle Kamerabild als Datei. */
  snapshot() {
    const vw = this.video.videoWidth;
    const vh = this.video.videoHeight;
    if (!vw || !vh) return;
    const canvas = document.createElement('canvas');
    canvas.width = vw;
    canvas.height = vh;
    canvas.getContext('2d').drawImage(this.video, 0, 0);
    const link = document.createElement('a');
    link.href = canvas.toDataURL('image/jpeg', 0.9);
    link.download = `visionhud-${new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19)}.jpg`;
    link.click();
    this.ui.toast('Bild gespeichert', 'good');
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
      cocoClass: track.group ?? null,
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
    $('objectClass').textContent = displayName(track).toUpperCase();
    $('objectScore').textContent = `${Math.round((track.fine?.score ?? track.score) * 100)} %`;
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
      cocoClass: this.pendingObject.track.group ?? null,
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
                 <li>alle eigenen Handzeichen und Skills</li>
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
    await this.skillStore.clear();
    this.vault.forget();
    await wipeEverything();
    try {
      navigator.serviceWorker?.controller?.postMessage('visionhud:wipe-cache');
    } catch {
      /* egal */
    }

    this.matcher.load([]);
    this.#refreshSkills();
    for (const track of this.faces.tracks) track.identity = null;
    for (const track of this.objects.tracks) {
      track.memory = null;
      track.skill = null;
    }
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
    $('btnGestures').addEventListener('click', () => this.openGestures());
    $('btnSkills').addEventListener('click', () => this.openSkills());

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

    // --- Lupe ---
    $('btnMagRead').addEventListener('click', () => {
      const text = this.magnified?.text;
      if (!text) return this.ui.toast('Kein Text erkannt', 'warn');
      return this.voice.speak(text);
    });
    $('btnMagCopy').addEventListener('click', async () => {
      const text = this.magnified?.text;
      if (!text) return this.ui.toast('Kein Text erkannt', 'warn');
      try {
        await navigator.clipboard.writeText(text);
        this.ui.toast('Kopiert', 'good');
      } catch {
        this.ui.toast('Kopieren nicht erlaubt', 'warn');
      }
      return undefined;
    });
    $('btnMagTranslate').addEventListener('click', async () => {
      const text = this.magnified?.text;
      if (!text) return this.ui.toast('Kein Text erkannt', 'warn');
      if (!this.translator.available()) {
        return this.ui.toast(
          this.translator.reason?.() ?? 'Übersetzung nicht eingerichtet',
          'warn',
        );
      }
      try {
        this.ui.setMagnifierText(text, 'Übersetze …');
        const translated = await this.translator.translate(text, this.ui.settings.translateTarget);
        this.ui.setMagnifierText(translated, `Übersetzt (${this.ui.settings.translateTarget})`);
        if (this.ui.settings.assistantSpeak) this.voice.speak(translated);
      } catch (error) {
        this.ui.toast(error.message, 'bad');
      }
      return undefined;
    });

    // --- Tipp ins Bild (die Zeichenfläche lässt Berührungen durch) ---
    this.video.addEventListener('click', (event) => this.onStageTap(event));

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
    if (this.voice.supported) {
      if (this.voice.wanted) {
        this.voice.stop();
        this.ui.applySetting('assistantListening', false);
        this.ui.toast('ReyRey hört nicht mehr zu');
      } else {
        this.ui.setHint(null);
        this.voice.start();
        this.voice.holdOpen();
        this.ui.applySetting('assistantListening', true);
        this.ui.setReyState('wach');
        this.ui.say('Ich höre.');
      }
      return;
    }
    this.openAsk();
  }

  openAsk() {
    this.ui.openSheet('sheetAsk');
    setTimeout(() => $('askInput').focus(), 150);
  }

  async toggleListening() {
    if (!this.voice.supported) {
      this.ui.toast('Dieser Browser kann keine Spracherkennung', 'warn');
      return;
    }
    this.ui.applySetting('assistantListening', true);
    this.ui.setHint(null);
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
      this.#resetTracking();
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

    if (key === 'cloudEnabled' && !this.ui.settings.cloudConsent) {
      const agreed = await this.ui.askConsent({
        title: 'KI-Dienst freigeben',
        body: `<h3>Allgemeine Fragen beantworten</h3>
          <p>Damit ReyRey Fragen beantworten kann, die über das Kamerabild hinausgehen
          (etwa zu einer Marke auf einer Verpackung), muss er nach draussen fragen.</p>
          <ul>
            <li>Übertragen wird <strong>nur Text</strong>: deine Frage und ein kurzer Satz darüber,
            was die Erkennung gerade sieht – bei Skills zusätzlich deine Beschreibung.</li>
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
    if (key === '__gestures') {
      this.ui.closeSheet('sheetSettings');
      await this.openGestures();
      return;
    }
    if (key === '__skills') {
      this.ui.closeSheet('sheetSettings');
      await this.openSkills();
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
    if (key === 'gesturesEnabled') {
      this.hand = null;
      if (value && this.running) this.#loadHands();
    }
    if (key === 'classifierEnabled') {
      if (value && this.running && !this.engine.ready.classifier) {
        this.engine
          .loadClassifier((step, state, detail) => this.ui.bootStep(step, state, detail))
          .catch((error) => this.ui.toast(`Zweitstufe: ${error.message}`, 'warn'));
      }
      if (!value) for (const track of this.objects.tracks) track.fine = null;
    }
    if (key === 'ocrBackground') {
      if (value && this.running) this.#startBackgroundOcr();
      if (!value) {
        this.words = [];
        this.blocks = [];
      }
    }
    if (key === 'privacy' && value) {
      // Im Privatmodus verschwinden laufende Zuordnungen sofort aus dem Bild.
      for (const track of this.faces.tracks) track.identity = null;
      this.words = [];
      this.blocks = [];
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
    const detector = this.engine.detector;
    return [
      `Rechenwerk: ${this.engine.tf?.getBackend?.() ?? '–'}`,
      `Kamera: ${width}×${height} · ${this.camera.isFront ? 'Front' : 'Rück'}`,
      `Bildrate: ${Math.round(this.fps)}/s · Analysen: ${this.detectionRate}/s`,
      `Detektor: ${this.engine.ready.objects ? `601 Klassen · ${detector?.runner?.name ?? '?'} · ${Math.round(detector?.lastMs ?? 0)} ms` : 'nicht geladen'}`,
      `Zweitstufe: ${this.engine.ready.classifier ? '1000 Klassen' : 'nicht geladen'} · Hände: ${this.hands.ready ? 'bereit' : 'nicht geladen'} · Text: ${this.ocr.ready ? (this.ocrBackgroundRunning ? 'läuft im Hintergrund' : 'bereit') : 'nicht geladen'}`,
      `Modelle: Gesichter ${this.engine.ready.faces ? 'ja' : 'nein'}`,
      `Grafikspeicher: ${memory.mb} MB in ${memory.tensors} Tensoren`,
      `Kartei: ${this.matcher.size} Personen · ${this.things.size} Gegenstände · ${this.skillStore.customGestures.length} Handzeichen · ${this.skillStore.groups.length} Skills`,
      `Ablage: ${this.store.usesFallback ? 'localStorage' : 'IndexedDB'} · Verschlüsselung ${this.vault.enabled ? (this.vault.key ? 'offen' : 'zu') : 'aus'}`,
      `Spracherkennung: ${this.voice.supported ? (this.voice.wanted ? 'hört' : 'bereit') : 'nicht verfügbar'} · Sprachausgabe ${this.voice.unlocked ? 'frei' : 'wartet auf Tipp'}`,
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

/** Anzeigename eines Ziels: Zweitstufe vor Detektor, deutsch vor englisch. */
function displayName(track) {
  return track.fine?.label ?? track.labelDe ?? track.label ?? 'Unbekannt';
}

/** „Es ist 21:04 Uhr, Montag, 15. September.“ */
function timeText() {
  const now = new Date();
  const days = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
  const months = [
    'Januar',
    'Februar',
    'März',
    'April',
    'Mai',
    'Juni',
    'Juli',
    'August',
    'September',
    'Oktober',
    'November',
    'Dezember',
  ];
  const two = (n) => String(n).padStart(2, '0');
  return `Es ist ${now.getHours()}:${two(now.getMinutes())} Uhr, ${days[now.getDay()]}, ${now.getDate()}. ${months[now.getMonth()]}.`;
}

function titleCase(text) {
  return text.replace(/(^|\s)(\p{L})/gu, (m, space, letter) => space + letter.toUpperCase());
}

function escapeHtml(text) {
  return String(text).replace(
    /[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
  );
}

/** Kurzer Bestätigungston, wenn das Aktivierungswort oder ein Zeichen erkannt wurde. */
function beep(frequency = 880) {
  try {
    const Ctx = globalThis.AudioContext ?? globalThis.webkitAudioContext;
    if (!Ctx) return;
    const ctx = new Ctx();
    const oscillator = ctx.createOscillator();
    const gain = ctx.createGain();
    oscillator.frequency.value = frequency;
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
// Für Selbsttest und Fehlersuche in der Browserkonsole erreichbar.
globalThis.visionhud = app;
app.init().catch((error) => {
  console.error(error);
  document.body.innerHTML = `<pre style="color:#ffb648;padding:24px;font:13px monospace">Start fehlgeschlagen:\n${String(error)}</pre>`;
});
