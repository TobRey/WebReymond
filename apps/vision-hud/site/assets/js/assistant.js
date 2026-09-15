/**
 * ReyRey – der Assistent.
 *
 * Arbeitsweise in zwei Stufen:
 *
 *   1. Örtlich: Eine Tabelle aus Mustern erkennt Absichten und ruft eine
 *      Fähigkeit auf. Das funktioniert ohne Internet, ohne Konto und ohne
 *      dass irgendetwas das Gerät verlässt. Alles, was die Seite selbst kann
 *      – zählen, beschreiben, scannen, vorlesen, registrieren, umschalten –
 *      läuft hier.
 *
 *   2. Auswärts (abschaltbar, standardmässig aus): Bleibt eine Frage übrig,
 *      die nur mit Weltwissen zu beantworten ist ("was ist das für eine
 *      Marke"), kann sie an ein Sprachmodell geschickt werden. Das ist eine
 *      bewusste Entscheidung des Nutzers und wird vorher abgefragt.
 *
 * Die Trennung ist der Kern: Ohne Stufe 2 bleibt ReyRey vollständig
 * benutzbar – nur eben ohne Weltwissen.
 */

import { labelFor, moodFor } from './labels.js';

/* ==========================================================================
 * Absichten
 *
 * Reihenfolge zählt: Das erste passende Muster gewinnt. Spezielles steht
 * deshalb vor Allgemeinem ("wie viele personen" vor "personen").
 * ========================================================================== */

const INTENTS = [
  {
    id: 'stop',
    patterns: [/\b(sei still|ruhe|halt (die )?klappe|stopp?|aufhören|schweig)\b/],
  },
  {
    id: 'help',
    patterns: [/\b(hilfe|was kannst du|was geht|befehle|kommandos|funktionen)\b/],
  },
  {
    id: 'countPeople',
    patterns: [/\bwie viele (personen|leute|menschen|gesichter)\b/],
  },
  {
    id: 'countObjects',
    patterns: [/\bwie viele (objekte|gegenstände|dinge|sachen)\b/],
  },
  {
    id: 'who',
    patterns: [
      /\bwer (ist|sind) (das|die|hier|dort)\b/,
      /\bwen (siehst|erkennst) du\b/,
      /\bkennst du (den|die|das|ihn|sie)\b/,
      /\bwer steht (da|dort|hier)\b/,
    ],
  },
  {
    id: 'scan',
    patterns: [/\b(scan|scanne|scannen|analysiere|analyse|rundum|umgebung prüfen)\b/],
  },
  {
    id: 'hazards',
    patterns: [/\b(gefahr|gefährlich|achtung|passe? auf|risiko|sicher hier)\b/],
  },
  {
    id: 'translate',
    patterns: [/\b(übersetz|translate|auf (deutsch|englisch|französisch|spanisch|italienisch))\b/],
  },
  {
    id: 'readText',
    patterns: [
      /\b(lies|vorlesen|lese|was steht (da|dort|hier|drauf)|text erkennen|welcher text)\b/,
    ],
  },
  {
    id: 'rememberObject',
    patterns: [
      /\b(merk(e)? dir (das|dies)|speicher(e)? das|registrier(e)? (das|dieses)|das ist mein)\b/,
    ],
    slot: /(?:als|namens|unter dem namen|das ist mein[e]?)\s+(.+)$/,
  },
  {
    id: 'findObject',
    patterns: [
      /\bwo (ist|war|sind|hast du) \b/,
      /\bwann (hast du|war|habe ich) .* (gesehen|zuletzt)\b/,
      /\bfinde? (mein|meine)\b/,
    ],
    slot: /\bwo (?:ist|war|sind|hast du)\s+(?:mein|meine|den|die|das)?\s*(.+?)(?:\s+(?:zuletzt|gesehen))?[?.]?$/,
  },
  {
    id: 'registerFace',
    patterns: [
      /\b(gesicht (erfassen|registrieren|speichern)|person (erfassen|registrieren)|merk dir (die |den )?person)\b/,
    ],
  },
  {
    id: 'roster',
    patterns: [/\b(kartei|wen kennst du|gespeicherte personen|wen hast du gespeichert)\b/],
  },
  {
    id: 'flip',
    patterns: [
      /\b(kamera (wechseln|umschalten|drehen)|andere kamera|selfie|frontkamera|rückkamera)\b/,
    ],
  },
  {
    id: 'hudOff',
    patterns: [/\b(hud (aus|weg|ausblenden)|anzeige (aus|weg)|blende? (alles )?aus)\b/],
  },
  {
    id: 'hudOn',
    patterns: [/\b(hud (an|ein|einblenden)|anzeige (an|ein)|blende? (alles )?ein)\b/],
  },
  {
    id: 'objectsOn',
    patterns: [/\bobjekt(erkennung)?\s*(an|ein|einschalten)\b/],
  },
  {
    id: 'objectsOff',
    patterns: [/\bobjekt(erkennung)?\s*(aus|abschalten|ausschalten)\b/],
  },
  {
    id: 'facesOn',
    patterns: [/\bgesicht(serkennung)?\s*(an|ein|einschalten)\b/],
  },
  {
    id: 'facesOff',
    patterns: [/\bgesicht(serkennung)?\s*(aus|abschalten|ausschalten)\b/],
  },
  {
    id: 'privacyOn',
    patterns: [/\b(privat(sphäre)?(modus)?\s*(an|ein)|privatmodus|niemand (speichern|merken))\b/],
  },
  {
    id: 'privacyOff',
    patterns: [/\bprivat(sphäre)?(modus)?\s*(aus|beenden)\b/],
  },
  {
    id: 'wipe',
    patterns: [/\b(lösch(e)? alles|alles löschen|vergiss alles|daten löschen)\b/],
  },
  {
    id: 'describeScene',
    patterns: [
      /\bwas (siehst|erkennst) du\b/,
      /\bwas ist (das|hier|dort|vor mir|zu sehen)\b/,
      /\bbeschreib/,
      /\bwas (passiert|ist los)\b/,
      /\bumgebung\b/,
    ],
  },
];

/** Findet die erste passende Absicht. */
function classify(text) {
  const lower = text.toLowerCase();
  for (const intent of INTENTS) {
    if (intent.patterns.some((pattern) => pattern.test(lower))) {
      const slot = intent.slot ? lower.match(intent.slot)?.[1]?.trim() : undefined;
      return { id: intent.id, slot };
    }
  }
  return null;
}

/* ==========================================================================
 * Sprachbausteine
 * ========================================================================== */

/**
 * "drei Personen", "ein Gegenstand" – Zahlwort plus richtige Mehrzahl.
 *
 * Die Mehrzahl wird übergeben statt geraten: Im Deutschen gibt es dafür keine
 * Regel, die sich in eine Zeile fassen liesse (Person → Personen, Gegenstand →
 * Gegenstände, Auto → Autos). Ein angehängtes "e" ergibt "Persone".
 */
function countPhrase(count, singular, plural) {
  const words = ['kein', 'ein', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht'];
  const word = count < words.length ? words[count] : String(count);
  return `${word} ${count === 1 ? singular : plural}`;
}

/** Fügt eine Aufzählung mit „und“ vor dem letzten Glied zusammen. */
function joinList(parts) {
  if (parts.length === 0) return '';
  if (parts.length === 1) return parts[0];
  return `${parts.slice(0, -1).join(', ')} und ${parts[parts.length - 1]}`;
}

/* ==========================================================================
 * Der Assistent
 * ========================================================================== */

export class Assistant {
  /**
   * @param {object} skills Fähigkeiten, die app.js bereitstellt
   */
  constructor(skills) {
    this.skills = skills;
    this.name = 'ReyRey';
    /** Die letzten Wortwechsel – gibt Rückfragen einen Bezug. */
    this.history = [];
    /** Offene Rückfrage, auf die als Nächstes eine Antwort erwartet wird. */
    this.pending = null;
  }

  setName(name) {
    this.name = name || 'ReyRey';
  }

  /**
   * Nimmt einen Satz entgegen und liefert die Antwort.
   * @param {string} text
   * @returns {Promise<{text: string, tone?: string}>}
   */
  async ask(text) {
    const clean = text.trim();
    if (!clean) return { text: 'Ich habe nichts verstanden.' };

    this.history.push({ role: 'user', text: clean });
    if (this.history.length > 12) this.history.splice(0, this.history.length - 12);

    const answer = await this.#route(clean);
    this.history.push({ role: 'assistant', text: answer.text });
    return answer;
  }

  async #route(text) {
    // Eine offene Rückfrage hat Vorrang vor allem anderen.
    if (this.pending) {
      const pending = this.pending;
      this.pending = null;
      return pending.resume(text);
    }

    const intent = classify(text);
    if (!intent) return this.#fallback(text);

    try {
      return await this.#run(intent, text);
    } catch (error) {
      return { text: `Das hat nicht geklappt: ${error.message}`, tone: 'bad' };
    }
  }

  async #run(intent, text) {
    const s = this.skills;

    switch (intent.id) {
      case 'stop':
        s.stopSpeaking();
        return { text: 'Bin still.', silent: true };

      case 'help':
        return { text: this.#help() };

      case 'countPeople': {
        const people = s.people();
        if (people.length === 0) return { text: 'Ich sehe gerade niemanden.' };
        const known = people.filter((p) => p.name).length;
        let answer = `Ich sehe ${countPhrase(people.length, 'Person', 'Personen')}`;
        if (known > 0) answer += `, davon ${known === people.length ? 'alle' : known} bekannt`;
        return { text: `${answer}.` };
      }

      case 'countObjects': {
        const objects = s.objects();
        return {
          text:
            objects.length === 0
              ? 'Ich erkenne gerade keine Gegenstände.'
              : `Ich erkenne ${countPhrase(objects.length, 'Gegenstand', 'Gegenstände')}.`,
        };
      }

      case 'who': {
        const people = s.people();
        if (people.length === 0) return { text: 'Ich sehe gerade niemanden.' };

        const named = people.filter((p) => p.name);
        const unknown = people.length - named.length;
        const parts = named.map((p) => `${p.name}, ${p.age} Jahre`);
        if (unknown > 0) {
          parts.push(countPhrase(unknown, 'unbekannte Person', 'unbekannte Personen'));
        }

        let answer = `Ich sehe ${joinList(parts)}.`;
        if (named.length === 0) {
          answer += ` Soll ich jemanden erfassen? Sag „${this.name}, Gesicht erfassen“.`;
        }
        return { text: answer };
      }

      case 'describeScene':
        return { text: this.describeScene() };

      case 'scan': {
        const report = await s.scan();
        return { text: report };
      }

      case 'hazards': {
        const risks = s.hazards();
        if (risks.length === 0) {
          return {
            text: 'Ich sehe gerade nichts Auffälliges. Verlass dich aber nicht darauf – ich bin kein Sicherheitssystem.',
          };
        }
        return {
          text: `Achtung: ${joinList(risks.map((r) => r.text))}. Das ist nur ein Hinweis, keine Garantie.`,
          tone: 'warn',
        };
      }

      case 'readText': {
        const result = await s.readText({ translate: false });
        return result.found
          ? { text: `Da steht: ${result.text}` }
          : { text: 'Ich erkenne keinen lesbaren Text im Bild.' };
      }

      case 'translate': {
        const target = /englisch/.test(text.toLowerCase())
          ? 'en'
          : /französisch/.test(text.toLowerCase())
            ? 'fr'
            : /spanisch/.test(text.toLowerCase())
              ? 'es'
              : /italienisch/.test(text.toLowerCase())
                ? 'it'
                : 'de';
        const result = await s.readText({ translate: true, target });
        if (!result.found) return { text: 'Ich erkenne keinen lesbaren Text im Bild.' };
        if (result.translated) return { text: `Übersetzt: ${result.translated}` };
        return {
          text: `Ich habe gelesen: ${result.text}. Übersetzen kann ich erst, wenn du unter System einen Übersetzungsdienst freigibst – das braucht eine Internetverbindung.`,
          tone: 'warn',
        };
      }

      case 'rememberObject': {
        const name = intent.slot;
        if (!name) {
          this.pending = {
            resume: (reply) => this.#run({ id: 'rememberObject', slot: reply.trim() }, reply),
          };
          return { text: 'Wie soll ich den Gegenstand nennen?', ask: true };
        }
        const saved = await s.rememberObject(name);
        return saved.ok
          ? { text: `Gemerkt: ${saved.name}. Ich sage dir, wenn ich es wiedersehe.`, tone: 'good' }
          : { text: saved.reason ?? 'Ich sehe gerade keinen Gegenstand, den ich merken könnte.' };
      }

      case 'findObject': {
        const name = intent.slot;
        if (!name) return { text: 'Wonach soll ich suchen?' };
        const hit = await s.findObject(name);
        return { text: hit.text, tone: hit.found ? 'good' : 'warn' };
      }

      case 'registerFace':
        s.registerFace();
        return { text: 'Ich habe die Erfassung geöffnet. Halte das Gesicht mittig ins Bild.' };

      case 'roster': {
        const list = await s.roster();
        if (list.length === 0) return { text: 'Meine Kartei ist leer.' };
        return {
          text: `Ich kenne ${joinList(list.map((p) => `${p.name} (${p.age})`))}.`,
        };
      }

      case 'flip':
        await s.flipCamera();
        return { text: 'Kamera gewechselt.' };

      case 'hudOff':
        s.setSetting('hudVisible', false);
        return { text: 'Anzeige ausgeblendet.' };

      case 'hudOn':
        s.setSetting('hudVisible', true);
        return { text: 'Anzeige wieder da.' };

      case 'objectsOn':
        s.setSetting('objects', true);
        return { text: 'Objekterkennung an.' };

      case 'objectsOff':
        s.setSetting('objects', false);
        return { text: 'Objekterkennung aus.' };

      case 'facesOn':
        s.setSetting('faces', true);
        return { text: 'Gesichtserkennung an.' };

      case 'facesOff':
        s.setSetting('faces', false);
        return { text: 'Gesichtserkennung aus.' };

      case 'privacyOn':
        s.setSetting('privacy', true);
        return {
          text: 'Privatsphäre-Modus an. Ich speichere nichts und zeige keine Namen.',
          tone: 'good',
        };

      case 'privacyOff':
        s.setSetting('privacy', false);
        return { text: 'Privatsphäre-Modus aus.' };

      case 'wipe':
        // Löschen ist endgültig – das wird immer zurückgefragt.
        this.pending = {
          resume: async (reply) => {
            if (/^(ja|jawohl|bestätigen|mach|okay|ok|los)\b/i.test(reply.trim())) {
              await s.wipeAll();
              return { text: 'Alles gelöscht.', tone: 'good' };
            }
            return { text: 'Abgebrochen, ich habe nichts gelöscht.' };
          },
        };
        return {
          text: 'Das löscht alle erfassten Personen und Gegenstände endgültig. Sag „ja“, wenn ich das wirklich tun soll.',
          tone: 'warn',
          ask: true,
        };

      default:
        return this.#fallback(text);
    }
  }

  /**
   * Beschreibt, was gerade im Bild ist – rein aus den laufenden Erkennungen.
   */
  describeScene() {
    const people = this.skills.people();
    const objects = this.skills.objects();
    const motion = this.skills.motion?.() ?? [];

    if (people.length === 0 && objects.length === 0) {
      return 'Ich erkenne gerade nichts Eindeutiges. Vielleicht ist es zu dunkel oder die Kamera zu nah dran.';
    }

    const parts = [];

    if (people.length > 0) {
      const named = people.filter((p) => p.name).map((p) => p.name);
      const unknown = people.length - named.length;
      const bits = [];
      if (named.length > 0) bits.push(joinList(named));
      if (unknown > 0) bits.push(countPhrase(unknown, 'unbekannte Person', 'unbekannte Personen'));
      parts.push(`${joinList(bits)}`);

      const mood = people.find((p) => p.expression && p.expression !== 'neutral');
      if (mood) {
        parts.push(
          `der Gesichtsausdruck wirkt eher ${moodFor(mood.expression)} – sicher bin ich mir nicht`,
        );
      }
    }

    if (objects.length > 0) {
      // Gleiche Klassen zusammenfassen: "zwei Stühle" statt "Stuhl, Stuhl".
      const tally = new Map();
      for (const object of objects) {
        tally.set(object.label, (tally.get(object.label) ?? 0) + 1);
      }
      const listed = [...tally.entries()]
        .sort((a, b) => b[1] - a[1])
        .slice(0, 5)
        .map(([label, count]) =>
          count === 1 ? labelFor(label).toLowerCase() : `${count} ${labelFor(label).toLowerCase()}`,
        );
      parts.push(joinList(listed));
    }

    if (motion.length > 0) {
      parts.push(`${motion[0].label} bewegt sich nach ${motion[0].direction}`);
    }

    return `Ich sehe ${joinList(parts)}.`;
  }

  #help() {
    return [
      `Sag „${this.name}“ und dann zum Beispiel:`,
      '„Was siehst du?“, „Wer ist das?“, „Wie viele Personen?“,',
      '„Scanne die Umgebung“, „Lies das vor“, „Übersetze das“,',
      '„Merk dir das als Schlüssel“, „Wo ist mein Schlüssel?“,',
      '„Gesicht erfassen“, „Kamera wechseln“, „HUD aus“,',
      '„Privatmodus an“ oder „Sei still“.',
    ].join(' ');
  }

  /** Nichts Örtliches gefunden – notfalls auswärts fragen. */
  async #fallback(text) {
    const cloud = this.skills.cloud;

    if (cloud?.enabled()) {
      const answer = await cloud.ask(text, {
        scene: this.describeScene(),
        history: this.history.slice(-6),
      });
      return { text: answer, source: 'cloud' };
    }

    return {
      text: `Das kann ich auf dem Gerät nicht beantworten. Ich kenne nur, was die Kamera zeigt. Für allgemeine Fragen und Informationen aus dem Internet müsstest du unter System den KI-Dienst freigeben. Sag „${this.name}, Hilfe“ für meine Befehle.`,
      tone: 'warn',
    };
  }
}
