/**
 * Übersetzung erkannter Texte.
 *
 * EHRLICHE EINORDNUNG: Es gibt kein brauchbares Übersetzungsmodell, das sich
 * in einer statischen Webseite mitliefern liesse – die kleinsten nützlichen
 * sind Hunderte Megabyte gross. Übersetzen braucht deshalb eine Verbindung
 * nach draussen und ist standardmässig aus.
 *
 * Zwei Wege, beide vom Nutzer einzuschalten:
 *   - der bereits eingerichtete KI-Dienst (cloud.js), oder
 *   - ein eigener LibreTranslate-Server.
 *
 * Ohne beides bleibt die Texterkennung nutzbar: lesen, vorlesen, kopieren.
 */

const LANGUAGE_NAMES = {
  de: 'Deutsch',
  en: 'Englisch',
  fr: 'Französisch',
  es: 'Spanisch',
  it: 'Italienisch',
  tr: 'Türkisch',
  pl: 'Polnisch',
  pt: 'Portugiesisch',
};

export function languageName(code) {
  return LANGUAGE_NAMES[code] ?? code;
}

export const LANGUAGES = Object.entries(LANGUAGE_NAMES).map(([code, name]) => ({ code, name }));

export class Translator {
  /**
   * @param {object} settings
   * @param {import('./cloud.js').Cloud} cloud
   */
  constructor(settings, cloud) {
    this.settings = settings;
    this.cloud = cloud;
  }

  available() {
    if (this.settings.translateMode === 'libre') return Boolean(this.settings.translateUrl);
    return this.cloud.enabled();
  }

  reason() {
    if (this.settings.translateMode === 'libre' && !this.settings.translateUrl) {
      return 'Es ist keine Adresse für den Übersetzungsdienst eingetragen.';
    }
    return 'Der KI-Dienst ist nicht eingerichtet.';
  }

  /**
   * @param {string} text
   * @param {string} target Zielsprache als Kürzel, z. B. "de"
   * @returns {Promise<string>}
   */
  async translate(text, target = 'de') {
    if (!text.trim()) return '';
    if (!this.available()) throw new Error(this.reason());

    if (this.settings.translateMode === 'libre') return this.#viaLibre(text, target);
    return this.#viaCloud(text, target);
  }

  async #viaCloud(text, target) {
    const answer = await this.cloud.ask(
      `Übersetze den folgenden Text nach ${languageName(target)}. Gib ausschliesslich die Übersetzung zurück, ohne Anführungszeichen und ohne Erklärung:\n\n${text}`,
      { scene: 'Der Text stammt aus der Texterkennung des Kamerabilds.', history: [] },
    );
    return answer.trim();
  }

  async #viaLibre(text, target) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(this.settings.translateUrl, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          q: text,
          source: 'auto',
          target,
          format: 'text',
          ...(this.settings.translateKey ? { api_key: this.settings.translateKey } : {}),
        }),
        signal: controller.signal,
      });
      if (!response.ok) throw new Error(`Übersetzungsdienst antwortet mit ${response.status}`);
      const data = await response.json();
      return (data.translatedText ?? '').trim();
    } finally {
      clearTimeout(timer);
    }
  }
}
