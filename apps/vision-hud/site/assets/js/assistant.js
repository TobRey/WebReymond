/**
 * ReyRey – der Assistent.
 *
 * Arbeitsweise in zwei Stufen:
 *
 *   1. Örtlich: Eine Tabelle aus Mustern erkennt Absichten und ruft eine
 *      Fähigkeit auf. Das funktioniert ohne Internet, ohne Konto und ohne
 *      dass irgendetwas das Gerät verlässt. Alles, was die Seite selbst kann
 *      – zählen, beschreiben, scannen, vorlesen, Handzeichen und Skills
 *      anlegen, umschalten – läuft hier.
 *
 *   2. Auswärts (abschaltbar, standardmässig aus): Bleibt eine Frage übrig,
 *      die nur mit Weltwissen zu beantworten ist ("was ist das für eine
 *      Marke"), kann sie an ein Sprachmodell geschickt werden.
 *
 * Geführte Dialoge (Handzeichen erfassen, Skill anlegen, Löschen bestätigen)
 * laufen über `pending`: Die nächste Äusserung geht nicht durch die
 * Mustertabelle, sondern an die offene Rückfrage.
 */

import { moodFor, withArticle } from './labels.js';
import { matchAction, actionName } from './skills.js';

/* ==========================================================================
 * Absichten
 *
 * Reihenfolge zählt: Das erste passende Muster gewinnt. Spezielles steht
 * deshalb vor Allgemeinem ("wie viele personen" vor "personen").
 * ========================================================================== */

const INTENTS = [
  { id: 'stop', patterns: [/\b(sei still|ruhe|halt (die )?klappe|stopp?|aufhören|schweig)\b/] },
  { id: 'cancel', patterns: [/\b(abbrechen|abbruch|vergiss es|egal|lass gut sein)\b/] },
  { id: 'help', patterns: [/\b(hilfe|was kannst du|was geht|befehle|kommandos|funktionen)\b/] },

  /* --- Handzeichen --- */
  {
    id: 'gestureRecord',
    patterns: [
      /\b(erfasse|erfass|lerne|lern|registrier\w*|neues?|speicher\w*)\b.*\bhandzeichen\b/,
      /\bhandzeichen\b.*\b(erfassen|lernen|registrieren|anlegen|hinzufügen)\b/,
      /\b(neue|neues) (geste|zeichen)\b/,
    ],
  },
  {
    id: 'gestureList',
    patterns: [
      /\b(welche|meine|alle|zeig\w*)\b.*\b(handzeichen|gesten)\b/,
      /\bhandzeichen (auflisten|liste)\b/,
    ],
  },
  {
    id: 'gestureDelete',
    patterns: [/\b(lösch\w*|entfern\w*|vergiss)\b.*\b(handzeichen|geste|zeichen)\b/],
    slot: /(?:handzeichen|geste|zeichen)\s+(?:namens\s+)?[„"]?([^„"]+?)[“"]?$/,
  },

  /* --- Skills --- */
  {
    id: 'skillCreate',
    patterns: [
      /\bskill\b.*\b(hinzufügen|anlegen|erstellen|neu)\b/,
      /\b(neuer|neuen|neue) skill\b/,
      /\bab jetzt (erkennst|erkenne|zeigst|zeige|siehst|markierst) du\b/,
      /\berkenn\w* ab jetzt\b/,
    ],
    slot: /(?:ab jetzt (?:erkennst|erkenne|zeigst|zeige|siehst|markierst) du|erkenn\w* ab jetzt)\s+(.+)$/,
  },
  {
    id: 'skillList',
    patterns: [/\b(welche|meine|alle|zeig\w*)\b.*\bskills?\b/, /\bskills? (auflisten|liste)\b/],
  },
  {
    id: 'skillOff',
    patterns: [/\bskill\b.*\b(aus|deaktivier\w*|abschalten|beenden)\b/],
    slot: /skill\s+[„"]?(.+?)[“"]?\s+(?:aus|deaktivier\w*|abschalten|beenden)/,
  },
  {
    id: 'skillOn',
    patterns: [/\bskill\b.*\b(an|ein|aktivier\w*|einschalten)\b/],
    slot: /skill\s+[„"]?(.+?)[“"]?\s+(?:an|ein|aktivier\w*|einschalten)/,
  },
  {
    id: 'skillDelete',
    patterns: [/\b(lösch\w*|entfern\w*)\b.*\bskill\b/],
    slot: /skill\s+[„"]?(.+?)[“"]?$/,
  },

  /* --- Zählen und Beschreiben --- */
  { id: 'countPeople', patterns: [/\bwie viele (personen|leute|menschen|gesichter)\b/] },
  { id: 'countObjects', patterns: [/\bwie viele (objekte|gegenstände|dinge|sachen)\b/] },
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
    id: 'whatIsThis',
    patterns: [
      /\bwas ist (das|dies|das da|das hier)\b/,
      /\bwas (halte|hab|habe) ich (da|hier|in der hand)\b/,
    ],
  },
  { id: 'time', patterns: [/\b(wie spät|uhrzeit|welcher tag|welches datum|datum)\b/] },
  { id: 'scan', patterns: [/\b(scan|scanne|scannen|analysiere|analyse|rundum|umgebung prüfen)\b/] },
  { id: 'hazards', patterns: [/\b(gefahr|gefährlich|achtung|passe? auf|risiko|sicher hier)\b/] },

  /* --- Text --- */
  {
    id: 'magnify',
    patterns: [/\b(lupe|vergrösser\w*|vergrößer\w*|zoom\w*|näher ran|grösser|größer)\b/],
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

  /* --- Gedächtnis --- */
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

  /* --- Steuerung --- */
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
    id: 'faintOn',
    patterns: [/\b(zeig|zeige) (auch )?unsicher\w*\b/, /\bunsicher\w* (an|ein|zeigen)\b/],
  },
  {
    id: 'faintOff',
    patterns: [/\bunsicher\w* (aus|weg|verstecken|ausblenden)\b/, /\bnur sicher\w*\b/],
  },
  { id: 'objectsOn', patterns: [/\bobjekt(erkennung)?\s*(an|ein|einschalten)\b/] },
  { id: 'objectsOff', patterns: [/\bobjekt(erkennung)?\s*(aus|abschalten|ausschalten)\b/] },
  { id: 'objectsToggle', patterns: [/\bobjekt(erkennung)?\s*(umschalten|wechseln)\b/] },
  { id: 'facesOn', patterns: [/\bgesicht(serkennung)?\s*(an|ein|einschalten)\b/] },
  { id: 'facesOff', patterns: [/\bgesicht(serkennung)?\s*(aus|abschalten|ausschalten)\b/] },
  {
    id: 'privacyOn',
    patterns: [/\b(privat(sphäre)?(modus)?\s*(an|ein)|privatmodus|niemand (speichern|merken))\b/],
  },
  { id: 'privacyOff', patterns: [/\bprivat(sphäre)?(modus)?\s*(aus|beenden)\b/] },
  { id: 'wipe', patterns: [/\b(lösch(e)? alles|alles löschen|vergiss alles|daten löschen)\b/] },
  {
    id: 'describeScene',
    patterns: [
      /\bwas (siehst|erkennst) du\b/,
      /\bwas ist (hier|dort|vor mir|zu sehen)\b/,
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

const YES =
  /^(ja|jawohl|jap|jo|genau|richtig|stimmt|passt|okay|ok|gut|mach|los|bestätigen|korrekt)\b/i;
const NO = /^(nein|nö|ne|falsch|nicht|abbrechen|abbruch|stopp?)\b/i;

/* ==========================================================================
 * Sprachbausteine
 * ========================================================================== */

/** „eine Person“, „drei Personen“ – der Artikel für die Einzahl kommt mit. */
function countPhrase(count, singular, plural, one = 'ein') {
  const words = ['kein', one, 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht'];
  if (count === 0) return `${one === 'eine' ? 'keine' : 'kein'} ${singular}`;
  const word = count < words.length ? words[count] : String(count);
  return `${word} ${count === 1 ? singular : plural}`;
}

function joinList(parts) {
  if (parts.length === 0) return '';
  if (parts.length === 1) return parts[0];
  return `${parts.slice(0, -1).join(', ')} und ${parts[parts.length - 1]}`;
}

/* ==========================================================================
 * Der Assistent
 * ========================================================================== */

export class Assistant {
  constructor(skills) {
    this.skills = skills;
    this.name = 'ReyRey';
    this.history = [];
    /** Offene Rückfrage: { resume(text) } */
    this.pending = null;
  }

  setName(name) {
    this.name = name || 'ReyRey';
  }

  get hasPending() {
    return Boolean(this.pending);
  }

  /**
   * Nimmt einen Satz entgegen und liefert die Antwort.
   * @returns {Promise<{text: string, tone?: string, ask?: boolean, silent?: boolean}>}
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
    // Abbrechen wirkt immer, auch mitten in einem Dialog.
    if (this.pending && /\b(abbrechen|abbruch|vergiss es)\b/i.test(text)) {
      this.pending = null;
      this.skills.cancelDialog?.();
      return { text: 'Abgebrochen.' };
    }

    if (this.pending) {
      const pending = this.pending;
      this.pending = null;
      try {
        return await pending.resume(text);
      } catch (error) {
        return { text: `Das hat nicht geklappt: ${error.message}`, tone: 'bad' };
      }
    }

    // Eigene Sprachbefehle des Nutzers kommen vor den eingebauten Mustern.
    const custom = this.skills.matchCommand?.(text);
    if (custom) {
      const result = await this.skills.runAction(custom.action);
      return { text: result?.text ?? `${actionName(custom.action)} – erledigt.` };
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

      case 'cancel':
        return { text: 'Okay.' };

      case 'help':
        return { text: this.#help() };

      /* ---------------- Handzeichen ---------------- */

      case 'gestureRecord':
        return this.#recordGesture();

      case 'gestureList': {
        const list = s.listGestures();
        if (list.length === 0) {
          return {
            text: `Noch keine eigenen Handzeichen. Sag „${this.name}, erfasse neues Handzeichen“.`,
          };
        }
        return {
          text: `Deine Handzeichen: ${joinList(list.map((g) => `${g.name} für ${actionName(g.action)}`))}.`,
        };
      }

      case 'gestureDelete': {
        const name = intent.slot;
        const found = name ? s.findGesture(name) : null;
        if (!found)
          return {
            text: name
              ? `Ein Handzeichen „${name}“ kenne ich nicht.`
              : 'Welches Handzeichen soll ich löschen?',
          };
        this.pending = {
          resume: async (reply) => {
            if (!YES.test(reply)) return { text: 'Nicht gelöscht.' };
            await s.deleteGesture(found.id);
            return { text: `${found.name} gelöscht.`, tone: 'good' };
          },
        };
        return { text: `${found.name} wirklich löschen?`, ask: true };
      }

      /* ---------------- Skills ---------------- */

      case 'skillCreate':
        return this.#createSkill(intent.slot);

      case 'skillList': {
        const list = s.listSkills();
        if (list.length === 0)
          return {
            text: 'Noch keine Skills. Sag zum Beispiel „ab jetzt erkennst du Bildschirme“.',
          };
        return {
          text: `Skills: ${joinList(list.map((k) => `${k.name}${k.enabled ? '' : ' (aus)'}`))}.`,
        };
      }

      case 'skillOn':
      case 'skillOff': {
        const on = intent.id === 'skillOn';
        const name = intent.slot;
        const found = name ? s.findSkill(name) : null;
        if (!found)
          return { text: name ? `Einen Skill „${name}“ habe ich nicht.` : 'Welcher Skill?' };
        await s.toggleSkill(found.id, on);
        return { text: `${found.name} ist ${on ? 'an' : 'aus'}.`, tone: 'good' };
      }

      case 'skillDelete': {
        const name = intent.slot;
        const found = name ? s.findSkill(name) : null;
        if (!found)
          return {
            text: name
              ? `Einen Skill „${name}“ habe ich nicht.`
              : 'Welchen Skill soll ich löschen?',
          };
        this.pending = {
          resume: async (reply) => {
            if (!YES.test(reply)) return { text: 'Nicht gelöscht.' };
            await s.deleteSkill(found.id);
            return { text: `Skill ${found.name} gelöscht.`, tone: 'good' };
          },
        };
        return { text: `Skill ${found.name} wirklich löschen?`, ask: true };
      }

      /* ---------------- Zählen, Beschreiben ---------------- */

      case 'countPeople': {
        const people = s.people();
        if (people.length === 0) return { text: 'Ich sehe gerade niemanden.' };
        const known = people.filter((p) => p.name).length;
        let answer = `Ich sehe ${countPhrase(people.length, 'Person', 'Personen', 'eine')}`;
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
        if (unknown > 0)
          parts.push(countPhrase(unknown, 'unbekannte Person', 'unbekannte Personen', 'eine'));
        let answer = `Ich sehe ${joinList(parts)}.`;
        if (named.length === 0)
          answer += ` Soll ich jemanden erfassen? Sag „${this.name}, Gesicht erfassen“.`;
        return { text: answer };
      }

      case 'whatIsThis': {
        const hit = s.whatIsThis();
        if (!hit)
          return {
            text: 'Ich sehe in der Bildmitte nichts, was ich benennen könnte. Halte es näher an die Kamera.',
          };
        const percent = Math.round(hit.score * 100);
        const what = `${hit.article} ${hit.name}`;
        let answer = hit.faint
          ? `Ich bin mir nicht sicher, aber das könnte ${what} sein, ${percent} Prozent.`
          : `Das ist vermutlich ${what}, ${percent} Prozent.`;
        if (hit.alsoDe) answer += ` Grob gesagt: ${hit.alsoDe}.`;
        return { text: answer };
      }

      case 'time':
        return { text: s.time() };

      case 'describeScene':
        return { text: this.describeScene() };

      case 'scan':
        return { text: await s.scan() };

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

      /* ---------------- Text ---------------- */

      case 'magnify': {
        const result = await s.magnify();
        if (!result.found)
          return {
            text: 'Ich habe die Bildmitte vergrössert, aber keinen lesbaren Text gefunden.',
          };
        return { text: `Vergrössert. Da steht: ${result.text}` };
      }

      case 'readText': {
        const result = await s.readText({ translate: false });
        return result.found
          ? { text: `Da steht: ${result.text}` }
          : { text: 'Ich erkenne keinen lesbaren Text im Bild.' };
      }

      case 'translate': {
        const lower = text.toLowerCase();
        const target = /englisch/.test(lower)
          ? 'en'
          : /französisch/.test(lower)
            ? 'fr'
            : /spanisch/.test(lower)
              ? 'es'
              : /italienisch/.test(lower)
                ? 'it'
                : 'de';
        const result = await s.readText({ translate: true, target });
        if (!result.found) return { text: 'Ich erkenne keinen lesbaren Text im Bild.' };
        if (result.translated) return { text: `Übersetzt: ${result.translated}` };
        return {
          text: `Ich habe gelesen: ${result.text}. Übersetzen kann ich erst, wenn unter System der KI-Dienst freigegeben ist.`,
          tone: 'warn',
        };
      }

      /* ---------------- Gedächtnis ---------------- */

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
        return { text: `Ich kenne ${joinList(list.map((p) => `${p.name} (${p.age})`))}.` };
      }

      /* ---------------- Steuerung ---------------- */

      case 'flip':
        await s.flipCamera();
        return { text: 'Kamera gewechselt.' };
      case 'hudOff':
        s.setSetting('hudVisible', false);
        return { text: 'Anzeige ausgeblendet.' };
      case 'hudOn':
        s.setSetting('hudVisible', true);
        return { text: 'Anzeige wieder da.' };
      case 'faintOn':
        s.setSetting('showFaint', true);
        return { text: 'Ich zeige jetzt auch, was ich nur vermute.' };
      case 'faintOff':
        s.setSetting('showFaint', false);
        return { text: 'Nur noch sichere Treffer.' };
      case 'objectsOn':
        s.setSetting('objects', true);
        return { text: 'Objekterkennung an.' };
      case 'objectsOff':
        s.setSetting('objects', false);
        return { text: 'Objekterkennung aus.' };
      case 'objectsToggle': {
        const result = await s.runAction('objects.toggle');
        return { text: result?.text ?? 'Umgeschaltet.' };
      }
      case 'facesOn':
        s.setSetting('faces', true);
        return { text: 'Gesichtserkennung an.' };
      case 'facesOff':
        s.setSetting('faces', false);
        return { text: 'Gesichtserkennung aus.' };
      case 'privacyOn':
        s.setSetting('privacy', true);
        return {
          text: 'Privatmodus an. Ich speichere nichts und zeige keine Namen.',
          tone: 'good',
        };
      case 'privacyOff':
        s.setSetting('privacy', false);
        return { text: 'Privatmodus aus.' };

      case 'wipe':
        this.pending = {
          resume: async (reply) => {
            if (YES.test(reply)) {
              await s.wipeAll();
              return { text: 'Alles gelöscht.', tone: 'good' };
            }
            return { text: 'Abgebrochen, ich habe nichts gelöscht.' };
          },
        };
        return {
          text: 'Das löscht alle erfassten Personen, Gegenstände, Handzeichen und Skills endgültig. Sag „ja“, wenn ich das wirklich tun soll.',
          tone: 'warn',
          ask: true,
        };

      default:
        return this.#fallback(text);
    }
  }

  /* ==========================================================================
   * Geführte Dialoge
   * ========================================================================== */

  /**
   * Neues Handzeichen: ansagen → aufnehmen → nach der Funktion fragen →
   * zuordnen → speichern. Alles Daten, nichts wird hochgeladen.
   */
  async #recordGesture() {
    const s = this.skills;
    if (!s.gesturesReady()) {
      return {
        text: 'Die Handerkennung ist noch nicht bereit. Warte einen Moment und versuch es nochmal.',
        tone: 'warn',
      };
    }

    await s.prompt('Zeig das Zeichen in die Kamera und halt es zwei Sekunden ruhig.');
    const recorded = await s.recordGesture();
    if (!recorded.ok) {
      return {
        text:
          recorded.reason ??
          'Ich habe keine Hand gesehen. Halte die Hand gut sichtbar ins Bild und versuch es nochmal.',
        tone: 'warn',
      };
    }

    const proposal = recorded;
    this.pending = {
      resume: (reply) => this.#assignGesture(proposal, reply),
    };
    return { text: 'Aufgenommen. Welche Funktion soll dieses Zeichen auslösen?', ask: true };
  }

  async #assignGesture(proposal, reply) {
    const match = matchAction(reply);
    if (!match) {
      this.pending = { resume: (again) => this.#assignGesture(proposal, again) };
      return {
        text: 'Das habe ich keiner Funktion zuordnen können. Sag zum Beispiel „Objekterkennung umschalten“, „Text vorlesen“, „Scan“ oder „Kamera wechseln“.',
        ask: true,
      };
    }

    if (match.ambiguous) {
      const base = match.action.id.replace(/\.(on|off|toggle)$/, '');
      this.pending = {
        resume: (answer) => {
          const lower = answer.toLowerCase();
          const id = /umschalt|wechsel|beides|beide/.test(lower)
            ? `${base}.toggle`
            : /aus/.test(lower)
              ? `${base}.off`
              : `${base}.on`;
          return this.#finishGesture(proposal, id);
        },
      };
      return { text: `Meinst du umschalten, nur einschalten oder nur ausschalten?`, ask: true };
    }

    return this.#finishGesture(proposal, match.action.id);
  }

  async #finishGesture(proposal, actionId) {
    const saved = await this.skills.saveGesture({
      vector: proposal.vector,
      samples: proposal.samples,
      action: actionId,
    });
    return {
      text: `Fertig. ${saved.name} löst ab jetzt „${actionName(actionId)}“ aus – halt das Zeichen kurz ruhig, dann passiert es.`,
      tone: 'good',
    };
  }

  /** Gruppen-Skill: Beschreibung → Klassen → Rückfrage → speichern. */
  async #createSkill(description) {
    const s = this.skills;
    if (!description) {
      this.pending = { resume: (reply) => this.#createSkill(reply.trim()) };
      return {
        text: 'Was soll ich ab jetzt erkennen? Zum Beispiel „Bildschirme und elektrische Geräte“.',
        ask: true,
      };
    }

    const preview = await s.previewSkill(description);
    if (!preview || preview.classes.length === 0) {
      return {
        text: `Zu „${description}“ finde ich keine passenden Klassen. Ich kenne 1600 Dinge – versuch es mit einem Oberbegriff wie Fahrzeuge, Möbel, Essen, Tiere, Werkzeuge, Elektrogeräte oder Bildschirme.`,
        tone: 'warn',
      };
    }

    const sample = preview.examples.slice(0, 6);
    const more =
      preview.classes.length > sample.length
        ? ` und ${preview.classes.length - sample.length} weitere`
        : '';
    this.pending = {
      resume: async (reply) => {
        if (!YES.test(reply)) {
          if (NO.test(reply)) return { text: 'Okay, nicht angelegt.' };
          // Eine neue Beschreibung statt ja/nein → nochmal versuchen.
          return this.#createSkill(reply.trim());
        }
        const saved = await s.saveSkill(preview);
        return {
          text: `Skill „${saved.name}“ ist aktiv. Treffer bekommen ab jetzt einen eigenen Rahmen, und ich sage sie an.`,
          tone: 'good',
        };
      },
    };
    return {
      text: `Ich nehme dafür: ${joinList(sample)}${more}. Passt das?`,
      ask: true,
    };
  }

  /* ==========================================================================
   * Beschreibung und Rückfall
   * ========================================================================== */

  describeScene() {
    const people = this.skills.people();
    const objects = this.skills.objects();
    const motion = this.skills.motion?.() ?? [];

    if (people.length === 0 && objects.length === 0) {
      return 'Ich erkenne gerade nichts Eindeutiges. Vielleicht ist es zu dunkel oder die Kamera zu nah dran.';
    }

    const seen = [];
    if (people.length > 0) {
      const named = people.filter((p) => p.name).map((p) => p.name);
      const unknown = people.length - named.length;
      if (named.length > 0) seen.push(joinList(named));
      if (unknown > 0)
        seen.push(countPhrase(unknown, 'unbekannte Person', 'unbekannte Personen', 'eine'));
    }

    if (objects.length > 0) {
      const tally = new Map();
      for (const object of objects) {
        const name = object.name ?? object.fine ?? object.labelDe ?? object.label;
        tally.set(name, (tally.get(name) ?? 0) + 1);
      }
      const listed = [...tally.entries()]
        .sort((a, b) => b[1] - a[1])
        .slice(0, 6)
        .map(([name, count]) => (count === 1 ? withArticle(name) : `${count} ${name}`));
      seen.push(listed.length === 1 ? listed[0] : joinList(listed));
    }

    const sentences = [`Ich sehe ${seen.join(' sowie ')}.`];

    const mood = people.find((p) => p.expression && p.expression !== 'neutral');
    if (mood) {
      sentences.push(
        `Der Gesichtsausdruck wirkt eher ${moodFor(mood.expression)} – sicher bin ich mir da nicht.`,
      );
    }
    if (motion.length > 0) sentences.push(`${motion[0].label} bewegt sich ${motion[0].direction}.`);
    return sentences.join(' ');
  }

  #help() {
    return [
      `Sag „${this.name}“ und dann zum Beispiel:`,
      '„Was ist das?“, „Was siehst du?“, „Wer ist das?“, „Wie viele Personen?“,',
      '„Scanne die Umgebung“, „Lies das vor“, „Lupe“, „Übersetze das“,',
      '„Erfasse neues Handzeichen“, „Ab jetzt erkennst du Bildschirme“, „Welche Skills?“,',
      '„Merk dir das als Schlüssel“, „Wo ist mein Schlüssel?“, „Gesicht erfassen“,',
      '„Kamera wechseln“, „HUD aus“, „Zeig auch Unsicheres“, „Privatmodus an“, „Sei still“.',
    ].join(' ');
  }

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
      text: `Das kann ich auf dem Gerät nicht beantworten. Ich kenne nur, was die Kamera zeigt. Für allgemeine Fragen müsstest du unter System den KI-Dienst freigeben. Sag „${this.name}, Hilfe“ für meine Befehle.`,
      tone: 'warn',
    };
  }
}
