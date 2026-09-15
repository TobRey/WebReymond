/**
 * Zusammenspiel von Kamera, Erkennung, Verfolgung und Oberfläche.
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
 * Diese Trennung ist der Grund, warum das HUD auch dann ruhig aussieht, wenn
 * die Erkennung nur fünf- bis zehnmal pro Sekunde zu einem Ergebnis kommt.
 */

import { CONFIG } from './config.js';
import { Camera, CameraError } from './camera.js';
import { Engine } from './engine.js';
import { Tracker } from './tracker.js';
import { Hud, Radar } from './hud.js';
import { Matcher, PeopleStore, makeId } from './store.js';
import { UI, $ } from './ui.js';
import { labelFor } from './labels.js';

class App {
  constructor() {
    this.video = $('video');
    this.stage = $('stage');

    this.ui = new UI();
    this.camera = new Camera(this.video);
    this.engine = new Engine();
    this.hud = new Hud($('overlay'), this.video);
    this.radar = new Radar($('radar'));
    this.store = new PeopleStore();
    this.matcher = new Matcher();

    this.objects = new Tracker(CONFIG.tracking);
    this.faces = new Tracker(CONFIG.tracking);

    this.running = false;
    this.lastObjectRun = 0;
    this.lastFaceRun = 0;
    this.lastIdentify = 0;
    this.lastFrame = 0;
    this.fps = 0;
    this.detectionRate = 0;
    this.detectionTicks = 0;
    this.detectionWindow = 0;

    /** Zustand der laufenden Gesichtserfassung. */
    this.capture = { active: false, samples: [], ages: [], lastAt: 0, quality: 0 };
  }

  /* =====================================================================
   * Start
   * ===================================================================== */

  async init() {
    // Die Schritte schon vor dem Start anzeigen: Sonst steht auf dem
    // Startbildschirm ein leerer Kasten, der wie ein Ladefehler aussieht.
    for (const step of ['Kamera', 'Bibliothek', 'Rechenwerk', 'Gesichter', 'Objekte']) {
      this.ui.bootStep(step, 'wait');
    }

    this.ui.renderSettings();
    this.ui.onSettingChange = (key, value) => this.onSettingChange(key, value);
    this.#wireControls();
    this.#applyAllSettings();

    await this.store.open();
    await this.reloadPeople();

    // Ohne https:// gibt es gar kein Kamerabild – das gleich sagen, nicht erst
    // nachdem der Nutzer auf "Kamera freigeben" getippt hat.
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
      // 1 Kamera – zuerst, weil hier die Rückfrage des Browsers kommt.
      this.ui.bootStep('Kamera', 'run');
      this.ui.bootHint('Bitte den Kamerazugriff bestätigen.');
      await this.camera.start();
      const { width, height } = this.camera.size;
      this.ui.bootStep('Kamera', 'ok', `${width}×${height}`);
      this.ui.bootProgress(0.25);

      // 2 Bibliotheken und Rechenwerk
      this.ui.bootHint('Lade Rechenwerk …');
      await this.engine.bootstrap((step, state, detail) => this.ui.bootStep(step, state, detail));
      this.ui.bootProgress(0.45);

      // 3 Gesichtsmodelle – klein, deshalb vor den Objekten.
      this.ui.bootHint('Lade Gesichtsmodelle (≈ 7 MB) …');
      await this.engine.loadFaceModels((step, state, detail) =>
        this.ui.bootStep(step, state, detail),
      );
      this.ui.bootProgress(0.7);

      // Ab hier ist das HUD benutzbar. Das grosse Objektmodell kommt im
      // Hintergrund nach, damit niemand 18 MB lang auf ein schwarzes Bild sieht.
      this.#run();
      this.ui.hideBoot();
      this.stage.classList.remove('stage--booting');
      this.ui.setMode('AKTIV', true);
      this.ui.setResolution(`${width}×${height}`);
      this.ui.toast('Gesichtserkennung bereit', 'good');

      this.#loadObjectsInBackground();
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
              'Prüfen, ob die Ordner assets/vendor und assets/models vollständig hochgeladen sind.',
            ]),
      );
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
      this.ui.toast('Objektmodell nicht ladbar', 'bad');
      this.ui.applySetting('objects', false);
      $('btnObjects').setAttribute('aria-pressed', 'false');
      // Die Gesichtserkennung läuft trotzdem weiter – kein Grund abzubrechen.
      console.error('Objektmodell:', error);
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

    this.hud.render({
      objects: objectTracks,
      faces: faceTracks,
      settings: this.ui.settings,
      time: now,
    });

    this.radar.render([...objectTracks, ...faceTracks], this.video.videoWidth);
    this.#updateReadouts(objectTracks, faceTracks);

    requestAnimationFrame((t) => this.#renderLoop(t));
  }

  #updateReadouts(objectTracks, faceTracks) {
    this.ui.setStats({
      fps: Math.round(this.fps),
      objects: objectTracks.length,
      faces: faceTracks.length,
      known: this.matcher.size,
    });
    this.ui.setLevel(objectTracks.length + faceTracks.length);

    const contacts = [];
    for (const track of faceTracks) {
      const identity = track.identity;
      if (identity?.person) {
        contacts.push({
          kind: 'known',
          text: `${identity.person.name} · ${identity.person.age} J · ${Math.round(identity.confidence * 100)}%`,
        });
      } else if (identity) {
        contacts.push({ kind: 'unknown', text: `Unbekannt · ~${Math.round(identity.age)} J` });
      }
    }
    for (const track of objectTracks) {
      if (track.kind === 'person') continue;
      contacts.push({
        kind: 'object',
        text: `${labelFor(track.label)} ${Math.round(track.score * 100)}%`,
      });
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
        // Im Hintergrund liefert das Video ohnehin keine neuen Bilder.
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

        if (this.capture.active) {
          await this.#captureStep();
        } else if (this.ui.settings.faces) {
          await this.#identifyStep();
        }
      } catch (error) {
        console.error('Erkennung:', error);
        await sleep(400);
      }

      if (didWork) this.#countDetection();
      // Ohne diese Pause bekommt der Browser keine Gelegenheit zu zeichnen.
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
   * schlägt "Antwort veraltet". Dadurch bekommt ein neu aufgetauchtes Gesicht
   * sofort einen Namen, während bekannte Gesichter nur gelegentlich
   * nachgeprüft werden.
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

        if (person && wasUnknown) {
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

  /** Sammelt nacheinander mehrere Aufnahmen desselben Gesichts. */
  async #captureStep() {
    const now = performance.now();
    if (now - this.capture.lastAt < CONFIG.recognition.sampleGapMs) return;
    if (this.capture.samples.length >= CONFIG.recognition.samples) return;

    // Das grösste Gesicht ist fast immer das gemeinte.
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

    // Vorschau aus dem gerade verwendeten Ausschnitt.
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
    const error = $('registerError');
    error.hidden = true;

    const name = $('fieldName').value.trim();
    const age = Number.parseInt($('fieldAge').value, 10);

    if (name.length < 2) return this.#registerError('Bitte einen Namen mit mindestens 2 Zeichen.');
    if (!Number.isFinite(age) || age < 1 || age > 120) {
      return this.#registerError('Bitte ein Alter zwischen 1 und 120 eintragen.');
    }
    if (this.capture.samples.length < CONFIG.recognition.samples) {
      return this.#registerError('Es fehlen noch Aufnahmen.');
    }

    const person = {
      id: makeId(),
      name,
      age,
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

      // Laufende Ziele neu bewerten, damit der Name sofort erscheint.
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
   * Kartei
   * ===================================================================== */

  async reloadPeople() {
    const people = await this.store.all();
    this.matcher.load(people);
    this.ui.renderRoster(people, (person) => this.deletePerson(person));
    return people;
  }

  async deletePerson(person) {
    if (!confirm(`„${person.name}“ wirklich löschen?`)) return;
    await this.store.remove(person.id);
    await this.reloadPeople();
    for (const track of this.faces.tracks) {
      if (track.identity?.person?.id === person.id) track.identity = null;
      track.identifiedAt = 0;
    }
    this.ui.toast(`${person.name} gelöscht`);
  }

  async exportPeople() {
    const people = await this.store.all();
    if (people.length === 0) {
      this.ui.toast('Die Kartei ist leer', 'warn');
      return;
    }

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

    $('btnRoster').addEventListener('click', async () => {
      await this.reloadPeople();
      this.ui.openSheet('sheetRoster');
    });

    $('btnSettings').addEventListener('click', () => {
      this.ui.setDiagnostics(this.#diagnostics());
      this.ui.openSheet('sheetSettings');
    });

    $('btnFlip').addEventListener('click', () => this.flipCamera());
    $('registerForm').addEventListener('submit', (event) => this.saveRegistration(event));

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

    $('btnWipe').addEventListener('click', async () => {
      if (!confirm('Wirklich alle erfassten Gesichter löschen?')) return;
      await this.store.clear();
      await this.reloadPeople();
      for (const track of this.faces.tracks) {
        track.identity = null;
        track.identifiedAt = 0;
      }
      this.ui.toast('Kartei geleert');
    });

    // Schublade "Erfassen" geschlossen → Aufnahme beenden.
    this.ui.onSheetClose = (id) => {
      if (id === 'sheetRegister') {
        this.capture.active = false;
        if (this.running) this.ui.setMode('AKTIV', true);
      }
    };

    // Nach dem Zurückkehren aus dem Hintergrund den Wachhalter neu anfordern.
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && this.running && this.ui.settings.keepAwake) {
        this.camera.requestWakeLock();
      }
    });
  }

  async flipCamera() {
    if (!this.running) return;
    const button = $('btnFlip');
    button.disabled = true;
    try {
      const facing = await this.camera.flip();
      this.objects.reset();
      this.faces.reset();
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
      `Modelle: Gesichter ${this.engine.ready.faces ? 'ja' : 'nein'} · Objekte ${this.engine.ready.objects ? 'ja' : 'nein'}`,
      `Grafikspeicher: ${memory.mb} MB in ${memory.tensors} Tensoren`,
      `Kartei: ${this.matcher.size} Personen · Ablage ${this.store.usesFallback ? 'localStorage' : 'IndexedDB'}`,
      `Bildschirm: ${Math.round(globalThis.innerWidth)}×${Math.round(globalThis.innerHeight)} @ ${globalThis.devicePixelRatio ?? 1}×`,
    ];
  }
}

/* --------------------------------------------------------------------------
 * Hilfen
 * -------------------------------------------------------------------------- */

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const nextFrame = () => new Promise((resolve) => requestAnimationFrame(() => resolve()));

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
