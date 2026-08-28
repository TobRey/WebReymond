/**
 * GrooveNet – Optimierer (das lernende Herz der KI).
 *
 * Der Ablauf entspricht dem, was grosse Audiomodelle tun, nur ohne
 * vortrainierte Gewichte: Ein Kandidat wird erzeugt, gemessen, bewertet und
 * gezielt verbessert – so lange, bis er zum Ziel passt.
 *
 *   1. Startbevoelkerung aus dem Prompt ableiten (genome.seedGenome).
 *   2. Jeden Kandidaten erzeugen und seinen Fingerabdruck messen.
 *   3. Abstand zum Zielfingerabdruck berechnen (die Verlustfunktion).
 *   4. Neue Kandidaten aus je drei bestehenden mischen
 *      (Differentielle Evolution) und nur behalten, was besser ist.
 *   5. Wiederholen, bis das Zeitbudget aufgebraucht ist.
 *
 * Differentielle Evolution braucht keine Ableitungen. Genau das ist hier
 * noetig, denn durch Filter, Saettigung und Resonatoren laesst sich der
 * Klang nicht sinnvoll differenzieren.
 *
 * Bewertet wird bei reduzierter Abtastrate. Das ist um ein Vielfaches
 * schneller und aendert an der Rangfolge der Kandidaten praktisch nichts.
 */

import { GENE_COUNT, seedGenome, differentialMix, rng, decode, applyBounds } from './genome.js';
import { renderMono } from './synth.js';
import { fingerprint, distance } from './features.js';
import { buildTarget, targetWeights } from './target.js';

/**
 * Waehlt die Abtastrate fuer die Bewertung.
 * Ziel sind rund 40 000 Abtastwerte je Kandidat – unabhaengig von der Laenge.
 */
function evaluationRate(seconds, sampleRate) {
  const wanted = 60000 / Math.max(0.05, seconds);
  return Math.min(sampleRate, Math.max(8000, Math.min(32000, Math.round(wanted / 100) * 100)));
}

/**
 * Zusatzstrafen fuer Ergebnisse, die messtechnisch passen, aber musikalisch
 * unbrauchbar waeren.
 */
function penalties(signal, fp) {
  let penalty = 0;
  const dsc = fp.descriptors;

  // Stille oder fast Stille.
  if (dsc.peak < 0.002) return 10;
  // Nur ein Knacken: sehr hoher Scheitelfaktor bei sehr kurzem Inhalt.
  if (dsc.crest > 28) penalty += (dsc.crest - 28) * 0.02;
  // Dauerhaft am Anschlag: klingt gepresst und verzerrt.
  let clipped = 0;
  for (let i = 0; i < signal.length; i += 7) if (Math.abs(signal[i]) > 0.995) clipped++;
  const clipRatio = clipped / Math.max(1, signal.length / 7);
  if (clipRatio > 0.02) penalty += (clipRatio - 0.02) * 3;
  // Gleichspannung kostet Pegel und ist auf Anlagen schaedlich.
  let sum = 0;
  for (let i = 0; i < signal.length; i += 7) sum += signal[i];
  const dc = Math.abs(sum / Math.max(1, signal.length / 7));
  if (dc > 0.02) penalty += (dc - 0.02) * 2;

  return penalty;
}

/** Abstand zweier Genome – wird gebraucht, damit Varianten sich unterscheiden. */
function geneDistance(a, b) {
  let sum = 0;
  for (let i = 0; i < GENE_COUNT; i++) {
    const d = a[i] - b[i];
    sum += d * d;
  }
  return Math.sqrt(sum / GENE_COUNT);
}

/**
 * Sucht die besten Genome fuer eine Prompt-Deutung.
 *
 * @param {object} opts
 * @param {object} opts.intent
 * @param {number} opts.seconds
 * @param {number} opts.sampleRate Zielabtastrate der spaeteren Ausgabe
 * @param {number} [opts.count] Anzahl gewuenschter Varianten
 * @param {number} [opts.seed]
 * @param {number} [opts.budgetMs] Rechenzeit in Millisekunden
 * @param {number} [opts.effort] 0..1, wird in Bevoelkerungsgroesse umgesetzt
 * @param {(p:number, label:string) => void} [opts.onProgress]
 * @param {() => boolean} [opts.shouldStop]
 * @returns {{genomes: Float32Array[], scores: number[], target: object, evaluations: number, generations: number}}
 */
export function optimize(opts) {
  const {
    intent,
    seconds,
    sampleRate,
    count = 4,
    seed = 1,
    budgetMs = 2500,
    effort = 0.7,
    onProgress = () => {},
    shouldStop = () => false,
  } = opts;

  const random = rng(seed);
  const evalRate = evaluationRate(seconds, sampleRate);
  const target = buildTarget(intent, seconds, evalRate);
  const weights = targetWeights(intent);
  const started = Date.now();

  // Bevoelkerung: gross genug fuer Vielfalt, klein genug fuer Tempo.
  const populationSize = Math.max(8, Math.min(28, Math.round(10 + effort * 18)));
  const population = [];
  const scores = new Float64Array(populationSize);

  const evaluate = (gene, evalSeed) => {
    const signal = renderMono(gene, {
      seconds,
      sampleRate: evalRate,
      seed: evalSeed,
      quality: 0.5,
    });
    const fp = fingerprint(signal, evalRate);
    return distance(fp, target, weights) + penalties(signal, fp);
  };

  // Der erste Kandidat ist die reine Ableitung aus dem Prompt, alle weiteren
  // streuen zunehmend darum herum. So bleibt die beste bekannte Deutung
  // immer erhalten, waehrend der Rest den Raum absucht.
  for (let i = 0; i < populationSize; i++) {
    const spread = i === 0 ? 0 : 0.04 + (i / populationSize) * 0.26;
    const gene = seedGenome(intent, random, spread);
    population.push(gene);
    scores[i] = evaluate(gene, seed + i);
  }

  let evaluations = populationSize;
  let generations = 0;
  const trial = new Float32Array(GENE_COUNT);

  // --- Differentielle Evolution ----------------------------------------------
  while (Date.now() - started < budgetMs && !shouldStop()) {
    generations++;
    let improved = false;

    for (let i = 0; i < populationSize; i++) {
      if (Date.now() - started >= budgetMs) break;

      // Drei verschiedene Eltern waehlen.
      let a = i;
      let b = i;
      let c = i;
      while (a === i) a = Math.floor(random() * populationSize);
      while (b === i || b === a) b = Math.floor(random() * populationSize);
      while (c === i || c === a || c === b) c = Math.floor(random() * populationSize);

      // Schrittweite und Kreuzungsrate leicht streuen: das verhindert,
      // dass die Suche in einem Nebenoptimum haengen bleibt.
      const factor = 0.45 + random() * 0.45;
      const crossRate = 0.55 + random() * 0.35;
      differentialMix(population[a], population[b], population[c], factor, crossRate, random, trial);
      // Ohne die Leitplanken findet die Suche Loesungen, die zum Ziel passen,
      // aber kein Instrument mehr sind (etwa eine Kick bei 20 Hz).
      applyBounds(trial, intent);

      const score = evaluate(trial, seed + i);
      evaluations++;
      if (score < scores[i]) {
        population[i].set(trial);
        scores[i] = score;
        improved = true;
      }
    }

    const elapsed = Date.now() - started;
    onProgress(Math.min(0.95, elapsed / budgetMs), `Klang wird verfeinert (Runde ${generations}) …`);

    // Nichts mehr zu holen: fruehzeitig beenden statt Rechenzeit verschwenden.
    if (!improved && generations > 3) break;
  }

  // --- Feinschliff bei voller Abtastrate -------------------------------------
  // Bewertet wurde bisher mit reduzierter Rate. Damit die Rangfolge stimmt und
  // der Klang auch in den obersten Hoehen passt, werden die aussichtsreichsten
  // Kandidaten noch einmal unter echten Bedingungen geprueft und der Beste
  // vorsichtig nachgebessert.
  const fullTarget = buildTarget(intent, seconds, sampleRate);
  const evaluateFull = (gene, evalSeed) => {
    const signal = renderMono(gene, { seconds, sampleRate, seed: evalSeed, quality: 1 });
    const fp = fingerprint(signal, sampleRate);
    return distance(fp, fullTarget, weights) + penalties(signal, fp);
  };

  const shortlist = [...population.keys()].sort((x, y) => scores[x] - scores[y]).slice(0, Math.min(populationSize, count + 3));
  // Zeitschranke: bei langen Klaengen kostet eine Pruefung bei voller Rate
  // spuerbar Zeit. Lieber weniger pruefen als das Budget sprengen.
  const verifyDeadline = Date.now() + budgetMs * 0.35;
  const verified = [];
  for (const idx of shortlist) {
    scores[idx] = evaluateFull(population[idx], seed + idx);
    evaluations++;
    verified.push(idx);
    if (Date.now() > verifyDeadline) break;
  }

  let bestIdx = verified[0];
  for (const idx of verified) if (scores[idx] < scores[bestIdx]) bestIdx = idx;

  const polishBudget = Math.max(120, budgetMs * 0.15);
  const polishStart = Date.now();
  const candidate = new Float32Array(GENE_COUNT);
  while (Date.now() - polishStart < polishBudget && !shouldStop()) {
    candidate.set(population[bestIdx]);
    // Kleine, gezielte Aenderungen an wenigen Genen.
    const changes = 1 + Math.floor(random() * 3);
    for (let c = 0; c < changes; c++) {
      const gi = Math.floor(random() * GENE_COUNT);
      candidate[gi] = Math.min(1, Math.max(0, candidate[gi] + (random() * 2 - 1) * 0.09));
    }
    applyBounds(candidate, intent);
    const score = evaluateFull(candidate, seed + bestIdx);
    evaluations++;
    if (score < scores[bestIdx]) {
      population[bestIdx].set(candidate);
      scores[bestIdx] = score;
    }
  }

  // --- Auswahl der Varianten -------------------------------------------------
  const order = [...population.keys()].sort((x, y) => scores[x] - scores[y]);
  const chosen = [];
  const chosenScores = [];

  for (const idx of order) {
    if (chosen.length >= count) break;
    // Varianten sollen hoerbar verschieden sein, nicht dreimal derselbe Klang.
    const tooSimilar = chosen.some((g) => geneDistance(g, population[idx]) < 0.045);
    if (tooSimilar) continue;
    chosen.push(population[idx]);
    chosenScores.push(scores[idx]);
  }

  // Reichen die Unterschiede nicht, wird der Beste gezielt leicht abgewandelt.
  let guard = 0;
  while (chosen.length < count && guard++ < count * 4) {
    const base = chosen[0] || population[order[0]];
    const variant = new Float32Array(base);
    for (let i = 0; i < GENE_COUNT; i++) {
      if (random() < 0.3) variant[i] = Math.min(1, Math.max(0, variant[i] + (random() * 2 - 1) * 0.12));
    }
    applyBounds(variant, intent);
    if (chosen.some((g) => geneDistance(g, variant) < 0.03)) continue;
    chosen.push(variant);
    chosenScores.push(evaluate(variant, seed + 900 + guard));
    evaluations++;
  }

  return {
    genomes: chosen,
    scores: chosenScores,
    target,
    weights,
    evaluations,
    generations,
    evalRate,
    elapsedMs: Date.now() - started,
  };
}

/** Bewertet ein fertiges Signal gegen das Ziel (fuer Anzeige und Diagnose). */
export function scoreSignal(signal, sampleRate, intent, seconds) {
  const target = buildTarget(intent, seconds, sampleRate);
  const fp = fingerprint(signal, sampleRate);
  const raw = distance(fp, target, targetWeights(intent));
  // In eine verstaendliche Passgenauigkeit von 0 bis 100 umrechnen.
  return { distance: raw, match: Math.round(Math.max(0, Math.min(100, 100 * Math.exp(-raw * 1.1)))) };
}

export { decode };
