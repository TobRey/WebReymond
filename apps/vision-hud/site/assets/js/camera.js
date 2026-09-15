/**
 * Kamerazugriff: starten, wechseln, stoppen – samt verständlicher Fehlertexte.
 *
 * Browser geben Kamerabilder nur in einem "sicheren Kontext" frei, also über
 * https:// oder auf localhost. Auf http:// existiert navigator.mediaDevices
 * schlicht nicht – das wird hier vor dem ersten Zugriff abgefangen, damit die
 * Seite eine Erklärung zeigt statt eines nichtssagenden Fehlers.
 */

import { CONFIG } from './config.js';

/** Ein Fehler, dessen Text direkt angezeigt werden darf. */
export class CameraError extends Error {
  constructor(title, message, hints = []) {
    super(message);
    this.name = 'CameraError';
    this.title = title;
    this.hints = hints;
  }
}

function describe(error) {
  switch (error?.name) {
    case 'NotAllowedError':
    case 'SecurityError':
      return new CameraError('Zugriff verweigert', 'Der Browser hat den Kamerazugriff blockiert.', [
        'Auf das Schloss- oder Kamerasymbol in der Adresszeile tippen und die Kamera erlauben.',
        'iPhone: Einstellungen → Safari → Kamera → „Fragen“ oder „Erlauben“.',
        'Android: Einstellungen → Apps → Browser → Berechtigungen → Kamera.',
        'Danach die Seite neu laden.',
      ]);
    case 'NotFoundError':
    case 'OverconstrainedError':
      return new CameraError(
        'Keine passende Kamera',
        'Es wurde keine Kamera gefunden, die zu den Anforderungen passt.',
        [
          'Ein anderes Gerät oder die zweite Kamera versuchen.',
          'Externe Webcams: Kabel prüfen und die Seite neu laden.',
        ],
      );
    case 'NotReadableError':
    case 'AbortError':
      return new CameraError('Kamera belegt', 'Eine andere Anwendung benutzt die Kamera gerade.', [
        'Andere Kamera-Apps, Videokonferenzen und weitere Browser-Tabs schliessen.',
        'Hilft das nicht: Gerät neu starten.',
      ]);
    default:
      return new CameraError(
        'Kamera nicht verfügbar',
        error?.message || 'Der Kamerazugriff ist unerwartet fehlgeschlagen.',
        ['Seite neu laden.', 'Einen anderen Browser versuchen (Chrome, Safari, Firefox).'],
      );
  }
}

export class Camera {
  /** @param {HTMLVideoElement} video */
  constructor(video) {
    this.video = video;
    this.stream = null;
    this.facingMode = CONFIG.camera.facingMode;
    this.wakeLock = null;
  }

  /** Läuft die Seite in einem Kontext, in dem der Browser die Kamera freigibt? */
  static checkSupport() {
    if (!globalThis.isSecureContext) {
      throw new CameraError(
        'Unsichere Verbindung',
        'Kamerazugriff ist nur über https:// möglich.',
        [
          'Die Seite mit https:// statt http:// öffnen.',
          'Auf dem eigenen Rechner funktioniert auch http://localhost.',
          'Beim Hoster ein kostenloses SSL-Zertifikat aktivieren (cPanel → SSL/TLS Status).',
        ],
      );
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      throw new CameraError(
        'Browser zu alt',
        'Dieser Browser kennt die Kamera-Schnittstelle nicht.',
        ['Chrome, Safari, Edge oder Firefox in einer aktuellen Version verwenden.'],
      );
    }
  }

  /**
   * Fordert einen Kamerastream an und verbindet ihn mit dem Videoelement.
   * @param {'environment'|'user'} [facingMode]
   */
  async start(facingMode = this.facingMode) {
    Camera.checkSupport();
    this.stop();

    const constraints = {
      audio: false,
      video: {
        facingMode: { ideal: facingMode },
        width: { ideal: CONFIG.camera.width },
        height: { ideal: CONFIG.camera.height },
        frameRate: { ideal: CONFIG.camera.frameRate },
      },
    };

    try {
      this.stream = await navigator.mediaDevices.getUserMedia(constraints);
    } catch (error) {
      // Manche Geräte lehnen die Wunschauflösung ab. Zweiter Versuch ohne Wünsche.
      if (error?.name === 'OverconstrainedError' || error?.name === 'NotFoundError') {
        try {
          this.stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: true });
        } catch (fallbackError) {
          throw describe(fallbackError);
        }
      } else {
        throw describe(error);
      }
    }

    this.facingMode = facingMode;
    this.video.srcObject = this.stream;

    await new Promise((resolve, reject) => {
      const ready = () => {
        this.video.removeEventListener('loadedmetadata', ready);
        resolve();
      };
      this.video.addEventListener('loadedmetadata', ready);
      setTimeout(() => reject(describe({ name: 'AbortError' })), 12000);
    });

    // iOS verlangt play() nach einer Nutzeraktion; der Aufruf steckt in der
    // Kette hinter dem Start-Knopf und ist deshalb erlaubt.
    await this.video.play();
    await this.requestWakeLock();
    return this.stream;
  }

  /** Wechselt zwischen Front- und Rückkamera. */
  async flip() {
    const next = this.facingMode === 'environment' ? 'user' : 'environment';
    await this.start(next);
    return next;
  }

  get isFront() {
    return this.facingMode === 'user';
  }

  /** Auflösung des tatsächlich gelieferten Bildes. */
  get size() {
    return { width: this.video.videoWidth || 0, height: this.video.videoHeight || 0 };
  }

  /** Hält den Bildschirm an, solange das HUD läuft. */
  async requestWakeLock() {
    if (!navigator.wakeLock || this.wakeLock) return;
    try {
      this.wakeLock = await navigator.wakeLock.request('screen');
      this.wakeLock.addEventListener('release', () => {
        this.wakeLock = null;
      });
    } catch {
      /* Nicht überall erlaubt – der Bildschirm geht dann eben aus. */
    }
  }

  async releaseWakeLock() {
    try {
      await this.wakeLock?.release();
    } catch {
      /* egal */
    }
    this.wakeLock = null;
  }

  stop() {
    this.stream?.getTracks().forEach((track) => track.stop());
    this.stream = null;
    this.video.srcObject = null;
  }
}
