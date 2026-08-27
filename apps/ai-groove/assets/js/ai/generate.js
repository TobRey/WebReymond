/**
 * AI Groove – gemeinsame Erzeugungslogik fuer KI-Samples.
 *
 * Wird sowohl von der Oberflaeche („Neues Sample → Mit KI erstellen“) als auch
 * vom Assistenten benutzt, damit beide Wege identisch funktionieren.
 */

import { activeProvider, getProvider, AIError } from './providers.js';
import { analysePrompt } from './localsynth.js';
import { engine } from '../audio/engine.js';
import { samples as sampleCache, decodeAudio } from '../audio/sampler.js';
import { store } from '../core/store.js';
import { audioStore } from '../core/idb.js';
import { guessInstrumentName, clamp } from '../core/util.js';
import { getContext } from '../audio/context.js';

/** Laengenvorgaben fuer die Erzeugung. */
export const LENGTH_PRESETS = [
  { id: 'oneshot', label: 'One Shot', bars: 0 },
  { id: '1', label: '1 Takt', bars: 1 },
  { id: '2', label: '2 Takte', bars: 2 },
  { id: '4', label: '4 Takte', bars: 4 },
  { id: '8', label: '8 Takte', bars: 8 },
  { id: 'custom', label: 'Eigene Länge', bars: -1 },
];

/** Sinnvolle One-Shot-Laenge je Klangart. */
function oneShotSeconds(prompt) {
  const a = analysePrompt(prompt);
  const table = {
    kick: 0.9,
    sub: 1.6,
    bass: 1.2,
    snare: 0.7,
    clap: 0.8,
    hat: 0.35,
    openhat: 1.0,
    tom: 0.8,
    perc: 0.6,
    stab: 1.4,
    pad: 4,
    lead: 1.6,
    vocal: 1.6,
    riser: 4,
    impact: 3,
    noise: 4,
  };
  let base = table[a.kind] ?? 1;
  if (a.long) base *= 1.8;
  if (a.short) base *= 0.6;
  return clamp(base, 0.15, 12);
}

/** Rechnet eine Laengenvorgabe in Sekunden um. */
export function resolveDuration({ preset, customSeconds, bars, bpm, prompt }) {
  if (preset === 'oneshot') return oneShotSeconds(prompt);
  if (preset === 'custom') return clamp(customSeconds || 4, 0.1, 190);
  const b = bars ?? Number(preset) ?? 1;
  return clamp((b * 4 * 60) / Math.max(40, bpm), 0.2, 190);
}

/**
 * Erzeugt Varianten und dekodiert sie zum Vorhoeren.
 *
 * @returns {Promise<Array<{bytes:ArrayBuffer, mime:string, label:string, buffer:AudioBuffer}>>}
 */
export async function generateVariants({
  prompt,
  duration,
  count = 4,
  providerId = null,
  inputSampleId = null,
  strength = 0.65,
  onProgress = () => {},
  signal,
}) {
  const provider = providerId ? getProvider(providerId) : activeProvider();
  if (!provider) throw new AIError('no_provider', 'Es ist kein KI-Anbieter verfügbar.');

  const ctx = getContext();
  let input = null;

  if (inputSampleId) {
    const sample = store.getSample(inputSampleId);
    if (!sample) throw new AIError('no_sample', 'Das Ausgangs-Sample wurde nicht gefunden.');
    if (!provider.capabilities.audioToAudio) {
      throw new AIError(
        'unsupported',
        `${provider.label} kann vorhandene Samples nicht weiterverarbeiten. Für die Bearbeitung bitte den lokalen Generator oder Stability AI verwenden.`,
      );
    }
    const row = await audioStore.get(sample.dataId);
    if (!row) throw new AIError('no_data', 'Die Audiodaten des Samples fehlen.');
    const buffer = await sampleCache.load(ctx, sample.dataId);
    const channels = [];
    for (let c = 0; c < buffer.numberOfChannels; c++) channels.push(buffer.getChannelData(c));
    input = {
      bytes: row.bytes,
      mime: row.mime,
      channels,
      sampleRate: buffer.sampleRate,
      strength,
    };
  }

  const raw = await provider.generate({
    prompt,
    duration,
    count,
    input,
    onProgress,
    signal,
  });

  onProgress(0.96, 'Varianten vorbereiten …');
  const results = [];
  for (const item of raw) {
    try {
      const buffer = await decodeAudio(ctx, item.bytes.slice(0));
      results.push({ ...item, buffer });
    } catch (_) {
      // Eine unbrauchbare Variante darf die anderen nicht verhindern.
      console.warn('[ai] Variante konnte nicht dekodiert werden');
    }
  }
  if (!results.length) {
    throw new AIError('decode_failed', 'Die erzeugten Klänge konnten nicht gelesen werden. Bitte erneut versuchen.');
  }
  onProgress(1, 'Fertig');
  return results;
}

/** Uebernimmt eine Variante als neues Sample im Projekt. */
export async function addVariantAsSample(variant, { prompt, name, source = 'ai' }) {
  const finalName = name || guessInstrumentName(prompt) || 'KI-Sample';
  const buffer = variant.buffer;
  const sample = await store.addSample({
    bytes: variant.bytes,
    mime: variant.mime,
    name: finalName,
    duration: buffer.duration,
    sampleRate: buffer.sampleRate,
    channels: buffer.numberOfChannels,
    source,
    prompt,
  });
  // Buffer direkt in den Zwischenspeicher legen: kein erneutes Dekodieren.
  sampleCache.set(sample.dataId, buffer);
  engine.invalidate();
  return sample;
}

/** Ersetzt ein bestehendes Sample durch eine Variante. */
export async function replaceSampleWithVariant(sampleId, variant) {
  const buffer = variant.buffer;
  const newDataId = await store.replaceSampleAudio(sampleId, {
    bytes: variant.bytes,
    mime: variant.mime,
    duration: buffer.duration,
    sampleRate: buffer.sampleRate,
    channels: buffer.numberOfChannels,
  });
  sampleCache.set(newDataId, buffer);
  engine.invalidate();
  return newDataId;
}

export { AIError };
