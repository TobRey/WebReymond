/**
 * Alle Stellschrauben an einem Ort.
 *
 * Die Pfade sind bewusst *relativ*: Die Seite läuft in einem Unterordner
 * (z. B. example.com/kamera-hud/) und darf deshalb nirgends mit "/" beginnen.
 */

/** Pfade relativ zu index.html. */
export const PATHS = {
  vendor: 'assets/vendor/',
  faceModels: 'assets/models',
  detector: 'assets/models/detector/',
  classifier: 'assets/models/classifier/',
  /** Nur nötig, wenn kein TF.js-Modell vorliegt (Rückfall). */
  ort: 'assets/vendor/ort/',
  tesseract: 'assets/vendor/tesseract/',
  mediapipe: 'assets/vendor/mediapipe/',
  gestureModel: 'assets/models/gesture_recognizer.task',
};

export const CONFIG = {
  camera: {
    /** Wunschauflösung. Der Browser liefert die nächstbeste. */
    width: 1280,
    height: 720,
    frameRate: 30,
    /** 'environment' = Rückkamera, 'user' = Selfiekamera. */
    facingMode: 'environment',
  },

  objects: {
    /** Erkennung läuft höchstens so oft – schont Akku und Hitzeentwicklung. */
    intervalMs: 110,
    /** Ab hier ein voller Rahmen mit Name. */
    sure: 0.35,
    /** Zwischen faint und sure: blasser „?“-Rahmen. Darunter: nichts. */
    faint: 0.12,
    /** Höchstzahl gleichzeitig gezeichneter Ziele. */
    maxResults: 40,
  },

  classifier: {
    /** So oft höchstens ein Ausschnitt an die Zweitstufe (ms). */
    intervalMs: 260,
    /** Ergebnis bleibt so lange am Ziel, bevor es erneuert wird. */
    ttlMs: 6000,
    /** Ab dieser Sicherheit ersetzt der feinere Name den Detektornamen. */
    minScore: 0.5,
    /** Kleinere Ziele lohnen den Aufwand nicht. */
    minWidth: 40,
  },

  hands: {
    intervalMs: 80,
  },

  ocr: {
    /** Hintergrundlesen: Takt und Bedingung „Kamera ruhig“. */
    backgroundMs: 2500,
    /** Vergrösserung der Lupe. */
    magnify: 3,
  },

  faces: {
    /** Schnelldurchlauf: nur Position der Gesichter. */
    intervalMs: 120,
    workWidth: 416,
    /** Eingabegrösse des Tiny-Detektors (Vielfaches von 32). */
    inputSize: 416,
    minScore: 0.42,
    /**
     * Langsamer Durchlauf: Merkmalsvektor, Alter, Stimmung.
     * Läuft nur für ein Gesicht pro Runde und nur so oft wie nötig.
     */
    identifyIntervalMs: 700,
    /** So lange gilt eine einmal erkannte Person als bestätigt. */
    identityTtlMs: 4000,
    /** Zuschnitt um das Gesicht herum (Anteil der Boxgrösse). */
    cropMargin: 0.38,
    cropSize: 224,
    /*
     * Der Zuschnitt bekommt eigene Werte, nicht die des Vollbild-Durchlaufs:
     * Die Eingabegrösse entspricht genau der Zuschnittgrösse (kein sinnloses
     * Hochskalieren), und die Schwelle ist niedriger, weil auf einem Bild, das
     * fast nur aus einem Gesicht besteht, kaum etwas zu verwechseln ist.
     * Gemessen gegenüber 416/0.42: 3 von 3 statt 2 von 3 Gesichtern erkannt,
     * bei rund 40 % kürzerer Rechenzeit.
     */
    cropInputSize: 224,
    cropMinScore: 0.2,
  },

  recognition: {
    /**
     * Euklidischer Abstand zweier 128-Merkmal-Vektoren.
     * Kleiner = strenger. 0.6 ist der Standardwert von face-api,
     * 0.52 verwechselt deutlich seltener zwei ähnliche Gesichter.
     */
    threshold: 0.52,
    /** Abstand, ab dem die Anzeige 0 % zeigt. */
    maxDistance: 0.9,
    /** So viele Aufnahmen werden beim Erfassen gesammelt. */
    samples: 5,
    /** Mindestabstand zwischen zwei Aufnahmen in Millisekunden. */
    sampleGapMs: 320,
  },

  tracking: {
    /** Ab welcher Überlappung zwei Boxen als dasselbe Ziel gelten. */
    iouMatch: 0.28,
    /** So viele Durchläufe darf ein Ziel fehlen, bevor es verworfen wird. */
    maxMissed: 8,
    /** So oft muss ein Ziel gesehen werden, bis es gezeichnet wird. */
    minHits: 2,
    /** Glättung der Box: 0 = springt sofort, 0.9 = sehr träge. */
    smoothing: 0.62,
  },

  hud: {
    colors: {
      object: '#19d3c5',
      person: '#2bf5dd',
      faceKnown: '#66ffc2',
      faceUnknown: '#ffb648',
      text: '#dff6f4',
      shadow: 'rgba(4, 12, 16, 0.82)',
    },
    /** Länge der Eckwinkel als Anteil der kürzeren Boxseite. */
    cornerRatio: 0.22,
    cornerMin: 12,
    cornerMax: 34,
    lineWidth: 2,
    labelFont: '600 12px ui-monospace, "SF Mono", Menlo, Consolas, monospace',
    metaFont: '10px ui-monospace, "SF Mono", Menlo, Consolas, monospace',
  },

  ui: {
    toastMs: 2600,
    /** Schlüssel für die gespeicherten Einstellungen. */
    storageKey: 'visionhud.settings.v1',
  },

  assistant: {
    /** Vorgabe für Name und Aktivierungswort – beides umbenennbar. */
    name: 'ReyRey',
    wakeWord: 'ReyRey',
    /** So lange bleibt eine Antwort im Bild stehen. */
    answerMs: 9000,
    /** Mindestabstand zwischen zwei gesprochenen Gefahrenhinweisen. */
    hazardQuietMs: 9000,
  },

  memory: {
    /** Ähnlichkeit, ab der ein gespeicherter Gegenstand als wiedererkannt gilt. */
    threshold: 0.72,
    /** Takt, in dem laufende Objektziele gegen das Gedächtnis geprüft werden. */
    matchIntervalMs: 1400,
  },

  gestures: {
    /** Takt der Bildvergleiche. Häufiger bringt nichts, kostet aber Akku. */
    intervalMs: 90,
  },
};

/** Vom Nutzer umschaltbare Einstellungen und ihre Ausgangswerte. */
export const DEFAULT_SETTINGS = {
  objects: true,
  faces: true,
  mirrorFront: true,
  showEffects: true,
  showScores: true,
  showTrackIds: false,
  keepAwake: true,
  minScore: CONFIG.objects.sure,
  matchThreshold: CONFIG.recognition.threshold,

  /* --- ReyRey --- */
  /** Zuhören ist an: Der Startbildschirm sagt einmal, dass die
   *  Spracherkennung des Browsers über Apple bzw. Google läuft (voice.js). */
  assistantListening: true,
  assistantVoiceConsent: true,
  assistantSpeak: true,
  assistantOverlay: true,
  assistantName: CONFIG.assistant.name,
  assistantWakeWord: CONFIG.assistant.wakeWord,

  /* --- Wahrnehmung --- */
  motionArrows: true,
  hazardWarnings: true,
  hazardSpeak: true,
  showFaint: true,
  classifierEnabled: true,
  gesturesEnabled: true,
  gestureBindings: null,
  ocrBackground: true,

  /* --- Anzeige --- */
  hudVisible: true,

  /* --- Privatsphäre --- */
  privacy: false,
  rememberPlaces: false,

  /* --- Auswärtige Dienste, alle aus --- */
  cloudEnabled: false,
  cloudConsent: false,
  cloudMode: 'proxy',
  cloudProxyUrl: 'reyrey-proxy.php',
  cloudApiKey: '',
  translateMode: 'cloud',
  translateUrl: '',
  translateKey: '',
  translateTarget: 'de',
};
