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
    /** Aufräumfunktion der Überwachung, siehe startWatchdog(). */
    this.watchdog = null;
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
    // Nur den Strom lösen – die Überwachung soll den Neustart überleben.
    this.stream?.getTracks().forEach((track) => track.stop());
    this.stream = null;

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
    this.watchdog?.();
    this.watchdog = null;
    this.stream?.getTracks().forEach((track) => track.stop());
    this.stream = null;
    this.video.srcObject = null;
  }

  /* ------------------------------------------------------------------
   * Wiederanlauf
   *
   * Der Kamerastrom endet nicht nur, wenn man ihn beendet: Ein Anruf, der
   * Sperrbildschirm, eine andere App oder ein Browser, der einen Hintergrundtab
   * einfriert, beenden die Spur ebenfalls. Das Bild steht dann still, ohne dass
   * ein Fehler auftritt – die Seite sieht aus, als wäre sie abgestürzt.
   *
   * Deshalb wird der Zustand überwacht und der Strom neu angefordert, sobald
   * die Seite wieder sichtbar ist. Neu anfordern geht nur mit gültiger
   * Berechtigung; hat der Nutzer sie entzogen, meldet die Schleife das und gibt
   * auf, statt endlos zu fragen.
   * ------------------------------------------------------------------ */

  /**
   * @param {(state: 'verloren'|'zurück'|'aufgegeben', detail?: string) => void} report
   */
  startWatchdog(report) {
    if (this.watchdog) return;

    let attempts = 0;
    let busy = false;

    const revive = async (why) => {
      if (busy || !this.facingMode) return;
      busy = true;
      report('verloren', why);

      while (attempts < 6) {
        attempts += 1;
        // Wartezeit wächst: 0,4 s, 0,8 s, 1,6 s … höchstens 8 s.
        const wait = Math.min(8000, 400 * 2 ** (attempts - 1));
        await new Promise((resolve) => setTimeout(resolve, wait));
        if (document.hidden) continue;

        try {
          await this.start(this.facingMode);
          attempts = 0;
          busy = false;
          report('zurück');
          return;
        } catch (error) {
          if (error?.name === 'CameraError' && /verweigert/i.test(error.title ?? '')) {
            busy = false;
            report('aufgegeben', 'Der Kamerazugriff wurde entzogen.');
            return;
          }
        }
      }
      busy = false;
      report('aufgegeben', 'Die Kamera antwortet nicht mehr.');
    };

    const onTrackEnded = () => revive('Die Kamera wurde von aussen beendet.');

    const attach = () => {
      this.stream?.getVideoTracks().forEach((track) => {
        track.removeEventListener('ended', onTrackEnded);
        track.addEventListener('ended', onTrackEnded);
      });
    };
    attach();

    // Zweiter Wächter: Manche Systeme beenden die Spur nicht, liefern aber
    // auch keine Bilder mehr. Ein stehender Zeitstempel verrät das.
    let lastTime = -1;
    let stalled = 0;
    const timer = setInterval(() => {
      if (document.hidden || busy) {
        stalled = 0;
        return;
      }
      const track = this.stream?.getVideoTracks()[0];
      if (!this.stream || !track || track.readyState === 'ended') {
        revive('Die Kamera liefert kein Bild mehr.');
        return;
      }
      if (this.video.currentTime === lastTime) {
        stalled += 1;
        if (stalled >= 4) {
          stalled = 0;
          revive('Das Bild steht still.');
        }
      } else {
        stalled = 0;
        lastTime = this.video.currentTime;
      }
      attach();
    }, 1200);

    const onVisible = () => {
      if (document.hidden) return;
      // Nach dem Zurückkehren muss das Video oft angestossen werden.
      this.video.play?.().catch(() => {});
      this.requestWakeLock();
      const track = this.stream?.getVideoTracks()[0];
      if (!track || track.readyState === 'ended') revive('Zurück aus dem Hintergrund.');
    };
    document.addEventListener('visibilitychange', onVisible);

    this.watchdog = () => {
      clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }
}
