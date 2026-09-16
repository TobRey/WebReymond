/**
 * Zuhören und Sprechen.
 *
 * ZUR EINORDNUNG: Die Spracherkennung des Browsers (Web Speech API) arbeitet
 * nicht auf dem Gerät. Safari schickt den Ton an Apple, Chrome an Google. Das
 * ist eine Eigenschaft des Browsers, keine Entscheidung dieser Seite – eine
 * Webseite kann das nicht umgehen, ausser sie liefert ein eigenes
 * Spracherkennungsmodell von mehreren hundert Megabyte mit. Der Startbildschirm
 * sagt das einmal; danach hört die Seite dauerhaft zu.
 *
 * Die Sprachausgabe dagegen läuft vollständig lokal.
 *
 * Eigenheiten, die hier abgefangen werden:
 *  - Die Erkennung endet nach einigen Sekunden Stille von selbst. Für
 *    dauerhaftes Zuhören muss sie immer wieder neu gestartet werden.
 *  - iOS beendet sie zusätzlich alle 20–60 Sekunden hart. Ein Neustart ohne
 *    Pause führt dort zu einer Endlosschleife aus Start und Abbruch – deshalb
 *    wächst die Pause, wenn Läufe zu schnell hintereinander enden.
 *  - Während die Sprachausgabe spricht, hört das Mikrofon mit und ReyRey
 *    würde sich selbst zuhören. Deshalb pausiert die Erkennung beim Sprechen.
 *  - iOS spielt Sprachausgabe erst ab, nachdem die Seite einmal berührt wurde.
 *    Bis dahin wird die Antwort aufgehoben und beim ersten Tipp nachgesprochen.
 */

/** Normalisiert Gesprochenes für den Vergleich mit dem Aktivierungswort. */
function normalise(text) {
  return text
    .toLowerCase()
    .replace(/[äöü]/g, (c) => ({ ä: 'a', ö: 'o', ü: 'u' })[c])
    .replace(/[^a-z]/g, '');
}

/**
 * Erzeugt Schreibvarianten eines Aktivierungsworts.
 *
 * "ReyRey" versteht die Erkennung je nach Aussprache als "Rey Rey", "Rei Rei",
 * "Ray Ray" oder "Railey". Ohne solche Varianten reagiert das Wort gefühlt nie.
 */
export function wakeVariants(word) {
  const base = normalise(word);
  const variants = new Set([base]);

  // Gleichklingende Vokalfolgen gegeneinander austauschen.
  const swaps = [
    [/ey/g, 'ei'],
    [/ey/g, 'ay'],
    [/ei/g, 'ey'],
    [/ai/g, 'ei'],
    [/y/g, 'i'],
  ];
  for (const [pattern, replacement] of swaps) {
    variants.add(base.replace(pattern, replacement));
  }

  // Verdoppelte Silbe: "reyrey" → auch "rey" allein zulassen, wenn lang genug.
  const half = base.slice(0, Math.floor(base.length / 2));
  if (half.length >= 3 && base === half + half) variants.add(half);

  return [...variants].filter(Boolean);
}

/** Läuft die Seite auf einem iPhone oder iPad? */
export function isIOS() {
  const ua = navigator.userAgent ?? '';
  return (
    /iP(hone|ad|od)/.test(ua) ||
    (navigator.platform === 'MacIntel' && (navigator.maxTouchPoints ?? 0) > 1)
  );
}

export class Voice {
  constructor() {
    const Impl = globalThis.SpeechRecognition ?? globalThis.webkitSpeechRecognition;
    this.supported = Boolean(Impl);
    this.Impl = Impl;

    this.recognition = null;
    this.listening = false;
    /** Vom Nutzer gewollt – bleibt true, während automatisch neu gestartet wird. */
    this.wanted = false;
    this.speaking = false;
    this.language = 'de-DE';
    this.variants = wakeVariants('ReyRey');
    this.lastFinal = '';

    /** Rückrufe, die app.js setzt. */
    this.onWake = () => {};
    this.onCommand = () => {};
    this.onPartial = () => {};
    this.onState = () => {};
    this.onError = () => {};
    /** Wird gerufen, wenn iOS den Ton noch nicht freigegeben hat. */
    this.onNeedsUnlock = () => {};

    /** Zeitpunkt, bis zu dem ohne erneutes Aktivierungswort zugehört wird. */
    this.openUntil = 0;
    this.followUpMs = 9000;

    /** Neustart-Schutz: schnelle Abbrüche hintereinander → längere Pause. */
    this.startedAt = 0;
    this.rapidEnds = 0;

    /** Sprachausgabe: freigeschaltet erst nach der ersten Berührung (iOS). */
    this.unlocked = !isIOS();
    this.pendingSpeech = null;

    this.voices = [];
    this.#loadVoices();
  }

  setWakeWord(word) {
    this.variants = wakeVariants(word || 'ReyRey');
  }

  /* ---------------- Zuhören ---------------- */

  start() {
    if (!this.supported) {
      this.onError(new Error('Dieser Browser kann keine Spracherkennung.'));
      return false;
    }
    this.wanted = true;
    this.rapidEnds = 0;
    this.#spinUp();
    return true;
  }

  stop() {
    this.wanted = false;
    this.openUntil = 0;
    try {
      this.recognition?.stop();
    } catch {
      /* war schon aus */
    }
    this.listening = false;
    this.onState('aus');
  }

  #spinUp() {
    if (!this.wanted || this.listening || this.speaking) return;

    const recognition = new this.Impl();
    recognition.lang = this.language;
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.maxAlternatives = 1;

    recognition.onstart = () => {
      this.listening = true;
      this.startedAt = Date.now();
      this.onState('hört');
    };

    recognition.onresult = (event) => {
      // Ein Ergebnis beweist, dass die Verbindung steht – Schutz zurücksetzen.
      this.rapidEnds = 0;
      this.#handleResult(event);
    };

    recognition.onerror = (event) => {
      // 'no-speech' und 'aborted' sind Normalbetrieb, keine Fehler.
      if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
        this.wanted = false;
        this.onError(new Error('Mikrofonzugriff wurde verweigert.'));
      } else if (event.error === 'network') {
        this.rapidEnds += 2;
        this.onError(new Error('Spracherkennung ohne Internetverbindung nicht möglich.'));
      }
    };

    recognition.onend = () => {
      this.listening = false;
      if (!this.wanted) {
        this.onState('aus');
        return;
      }
      this.onState('pause');
      if (this.speaking) return;

      /*
       * Endet ein Lauf innerhalb von zwei Sekunden nach dem Start, war es
       * kein Stille-Timeout, sondern ein Abbruch. Mehrere davon hintereinander
       * heisst: Der Dienst mag gerade nicht. Dann Pause statt Dauerfeuer –
       * 0,3 s, 1 s, 2 s, 4 s … höchstens 12 s.
       */
      const lived = Date.now() - this.startedAt;
      if (lived < 2000) this.rapidEnds += 1;
      else this.rapidEnds = 0;
      const wait = this.rapidEnds === 0 ? 260 : Math.min(12000, 500 * 2 ** (this.rapidEnds - 1));
      setTimeout(() => this.#spinUp(), wait);
    };

    this.recognition = recognition;
    try {
      recognition.start();
    } catch {
      // Ein bereits laufender Lauf wirft – dann einfach abwarten.
      this.listening = false;
    }
  }

  #handleResult(event) {
    let interim = '';
    let final = '';

    for (let i = event.resultIndex; i < event.results.length; i += 1) {
      const result = event.results[i];
      if (result.isFinal) final += result[0].transcript;
      else interim += result[0].transcript;
    }

    const live = (final || interim).trim();
    if (live) this.onPartial(live, Boolean(final));
    if (!final) return;

    const text = final.trim();
    if (!text || text === this.lastFinal) return;
    this.lastFinal = text;

    const hit = this.#findWake(text);
    const now = Date.now();

    if (hit) {
      // Alles nach dem Aktivierungswort ist der eigentliche Befehl.
      this.openUntil = now + this.followUpMs;
      this.onWake();
      if (hit.rest) this.onCommand(hit.rest, { wake: true });
    } else if (now < this.openUntil) {
      // Direkt nach einer Antwort darf ohne erneutes "ReyRey" weitergeredet werden.
      this.openUntil = now + this.followUpMs;
      this.onCommand(text, { wake: false });
    }
  }

  /** Sucht das Aktivierungswort und liefert den Rest des Satzes. */
  #findWake(text) {
    const flat = normalise(text);
    for (const variant of this.variants) {
      const at = flat.indexOf(variant);
      if (at === -1) continue;

      /*
       * Der Rest muss aus dem Originaltext kommen, nicht aus der
       * normalisierten Fassung – sonst gingen Umlaute und Zahlen verloren.
       * Dafür wird gezählt, wie viele Buchstaben vor dem Treffer lagen.
       */
      const skip = at + variant.length;
      let seen = 0;
      let cut = text.length;
      for (let i = 0; i < text.length; i += 1) {
        if (/[a-zäöüA-ZÄÖÜ]/.test(text[i])) seen += 1;
        if (seen >= skip) {
          cut = i + 1;
          break;
        }
      }
      return {
        rest: text
          .slice(cut)
          .replace(/^[\s,.:;!?-]+/, '')
          .trim(),
      };
    }
    return null;
  }

  /** Hält das Gespräch offen, ohne dass das Aktivierungswort nötig ist. */
  holdOpen() {
    this.openUntil = Date.now() + this.followUpMs;
  }

  /* ---------------- Sprechen ---------------- */

  #loadVoices() {
    if (!globalThis.speechSynthesis) return;
    const read = () => {
      this.voices = globalThis.speechSynthesis.getVoices();
    };
    read();
    globalThis.speechSynthesis.addEventListener?.('voiceschanged', read);
  }

  get canSpeak() {
    return Boolean(globalThis.speechSynthesis);
  }

  /**
   * Schaltet die Sprachausgabe frei. Muss aus einer Berührung heraus
   * aufgerufen werden (iOS-Regel). Spricht eine aufgehobene Antwort nach.
   */
  unlock() {
    if (this.unlocked) return;
    this.unlocked = true;
    try {
      const synth = globalThis.speechSynthesis;
      const silent = new SpeechSynthesisUtterance(' ');
      silent.volume = 0;
      synth?.speak(silent);
    } catch {
      /* egal */
    }
    if (this.pendingSpeech) {
      const { text, options } = this.pendingSpeech;
      this.pendingSpeech = null;
      this.speak(text, options);
    }
  }

  /** Bevorzugt eine deutsche Stimme, sonst die Standardstimme. */
  #pickVoice() {
    const wanted = this.language.slice(0, 2);
    const candidates = this.voices.filter((v) => v.lang?.replace('_', '-').startsWith(wanted));
    return (
      // Hochwertige Stimmen zuerst (Apple „Premium/Enhanced“, Google-Stimmen).
      candidates.find((v) => /premium|enhanced|natural|neural/i.test(v.name)) ??
      candidates.find((v) => v.lang?.replace('_', '-').startsWith(this.language)) ??
      candidates[0] ??
      null
    );
  }

  /**
   * Spricht einen Text. Pausiert dabei das Mikrofon, damit ReyRey nicht auf
   * die eigene Stimme reagiert.
   */
  speak(text, { rate = 1.02, pitch = 0.95, volume = 1 } = {}) {
    if (!this.canSpeak || !text) return Promise.resolve();

    // iOS ohne Berührung: aufheben statt lautlos scheitern.
    if (!this.unlocked) {
      this.pendingSpeech = { text, options: { rate, pitch, volume } };
      this.onNeedsUnlock();
      return Promise.resolve();
    }

    return new Promise((resolve) => {
      const synth = globalThis.speechSynthesis;
      synth.cancel();

      const utterance = new SpeechSynthesisUtterance(text);
      utterance.lang = this.language;
      utterance.rate = rate;
      utterance.pitch = pitch;
      utterance.volume = volume;
      const voice = this.#pickVoice();
      if (voice) utterance.voice = voice;

      this.speaking = true;
      this.onState('spricht');
      try {
        this.recognition?.stop();
      } catch {
        /* egal */
      }

      let finished = false;
      const done = () => {
        if (finished) return;
        finished = true;
        this.speaking = false;
        this.holdOpen();
        if (this.wanted) setTimeout(() => this.#spinUp(), 220);
        else this.onState('aus');
        resolve();
      };

      utterance.onend = done;
      utterance.onerror = done;
      synth.speak(utterance);

      // Safari verschluckt gelegentlich onend – Notbremse nach Textlänge.
      setTimeout(done, Math.min(30000, 2500 + text.length * 90));
    });
  }

  shutUp() {
    try {
      globalThis.speechSynthesis?.cancel();
    } catch {
      /* egal */
    }
    this.pendingSpeech = null;
    this.speaking = false;
  }
}
