/**
 * GrooveNet – Endbearbeitung.
 *
 * Letzter Schritt vor der Ausgabe. Sorgt dafuer, dass jedes Ergebnis
 * gebrauchsfertig ist: kein Gleichspannungsanteil, kein Rumpeln, kein
 * Uebersteuern, ein sinnvoller Pegel und saubere Raender.
 *
 * Bewusst zurueckhaltend: Der Klang soll fertig, aber nicht totgedrueckt
 * sein – im Studio wird danach ohnehin weitergearbeitet.
 */

/**
 * Tiefenanhebung (Kuhschwanzfilter).
 *
 * Hebt alles unterhalb der Eckfrequenz an, ohne die Mitten zu beruehren.
 * Sorgt fuer den satten Unterbau, den Drums und Baesse brauchen.
 */
function lowShelf(channel, sampleRate, hz, gainDb) {
  if (Math.abs(gainDb) < 0.1) return;
  const gain = 10 ** (gainDb / 20) - 1;
  const c = 1 - Math.exp((-2 * Math.PI * hz) / sampleRate);
  let lp = 0;
  for (let i = 0; i < channel.length; i++) {
    lp += c * (channel[i] - lp);
    channel[i] += lp * gain;
  }
}

/** Entfernt Gleichspannung und Rumpeln unterhalb des Hoerbereichs. */
function highPass(channel, sampleRate, hz = 24) {
  const r = 1 - (2 * Math.PI * hz) / sampleRate;
  let x1 = 0;
  let y1 = 0;
  for (let i = 0; i < channel.length; i++) {
    const x = channel[i];
    y1 = x - x1 + r * y1;
    x1 = x;
    channel[i] = y1;
  }
}

/**
 * Begrenzer mit Vorausschau.
 *
 * Ohne Vorausschau wuerde der Begrenzer erst reagieren, wenn die Spitze schon
 * da ist – hoerbar als Knacken. Die kurze Verzoegerung erlaubt es, die
 * Verstaerkung rechtzeitig zurueckzunehmen.
 */
function limit(channels, sampleRate, ceiling = 0.97) {
  const lookahead = Math.max(4, Math.round(sampleRate * 0.0015));
  const attackCoeff = 1 - Math.exp(-1 / (sampleRate * 0.0008));
  const releaseCoeff = 1 - Math.exp(-1 / (sampleRate * 0.08));
  const length = channels[0].length;
  const delayed = channels.map((ch) => new Float32Array(ch.length));
  let gain = 1;

  for (let i = 0; i < length; i++) {
    // Groesster Pegel im Vorausschaufenster.
    let peak = 0;
    const end = Math.min(length, i + lookahead);
    for (let c = 0; c < channels.length; c++) {
      for (let j = i; j < end; j += 2) {
        const v = Math.abs(channels[c][j]);
        if (v > peak) peak = v;
      }
    }
    const wanted = peak > ceiling ? ceiling / peak : 1;
    // Schnell herunter, langsam wieder hinauf.
    gain += (wanted < gain ? attackCoeff : releaseCoeff) * (wanted - gain);

    for (let c = 0; c < channels.length; c++) {
      const src = i - lookahead >= 0 ? channels[c][i - lookahead] : 0;
      delayed[c][i] = src * gain;
    }
  }

  for (let c = 0; c < channels.length; c++) channels[c].set(delayed[c]);
}

/** Effektivwert ueber alle Kanaele. */
function rmsOf(channels) {
  let sum = 0;
  let count = 0;
  for (const ch of channels) {
    for (let i = 0; i < ch.length; i += 3) {
      sum += ch[i] * ch[i];
      count++;
    }
  }
  return Math.sqrt(sum / Math.max(1, count));
}

/** Spitzenwert ueber alle Kanaele. */
function peakOf(channels) {
  let peak = 0;
  for (const ch of channels) {
    for (let i = 0; i < ch.length; i++) {
      const v = Math.abs(ch[i]);
      if (v > peak) peak = v;
    }
  }
  return peak;
}

/**
 * Bringt das Ergebnis auf Ausgabequalitaet.
 *
 * @param {Float32Array[]} channels
 * @param {number} sampleRate
 * @param {{loudness?:number, ceiling?:number, fadeOut?:number}} [opts]
 *        loudness: angestrebter Effektivwert (Vorgabe -14 dBFS)
 */
export function master(channels, sampleRate, opts = {}) {
  const ceiling = opts.ceiling ?? 0.97;
  const targetRms = opts.loudness ?? 10 ** (-14 / 20);
  const seconds = channels[0].length / sampleRate;
  // Einzelklaenge sind kurz; alles ueber zwei Sekunden gilt als Schleife
  // oder Flaeche, wo Lautheit sinnvoll ist.
  const isOneShot = opts.oneShot ?? seconds < 2;

  // Rumpeln unterhalb des Hoerbaren kostet nur Aussteuerungsreserve.
  for (const ch of channels) highPass(ch, sampleRate, isOneShot ? 28 : 24);

  // Tiefenanhebung nach Wunsch: gibt Drums und Baessen den satten Unterbau.
  if (opts.bassLift) {
    for (const ch of channels) lowShelf(ch, sampleRate, opts.bassHz ?? 95, opts.bassLift);
  }

  // Pegel angleichen: erst grob ueber den Effektivwert, dann sicher begrenzen.
  const rms = rmsOf(channels);
  if (rms > 1e-6) {
    // Perkussive Einzelklaenge haben naturgemaess einen sehr niedrigen
    // Effektivwert. Wuerde man sie auf Schleifen-Lautheit ziehen, presste der
    // Begrenzer den Anschlag flach – gemessen ein Scheitelfaktor unter 5, und
    // genau das klingt nach Explosion statt nach Trommel. Deshalb hier nur
    // eine sanfte Angleichung; die Spitze macht den Pegel.
    const wanted = isOneShot
      ? Math.min(1.6, Math.max(0.5, targetRms / rms))
      : Math.min(6, Math.max(0.25, targetRms / rms));
    for (const ch of channels) for (let i = 0; i < ch.length; i++) ch[i] *= wanted;
  }

  // Einzelklaenge auf die Spitze normieren statt sie zu begrenzen: der
  // Anschlag bleibt unangetastet.
  if (isOneShot) {
    const peakNow = peakOf(channels);
    if (peakNow > 1e-6) {
      const f = ceiling / peakNow;
      for (const ch of channels) for (let i = 0; i < ch.length; i++) ch[i] *= f;
    }
  } else if (peakOf(channels) > ceiling) {
    limit(channels, sampleRate, ceiling);
  }

  // Sicherheitsnetz, falls der Begrenzer knapp daneben liegt.
  const peak = peakOf(channels);
  if (peak > ceiling) {
    const f = ceiling / peak;
    for (const ch of channels) for (let i = 0; i < ch.length; i++) ch[i] *= f;
  }

  // Saubere Raender. Die Einblendung bleibt bei wenigen Abtastwerten, damit
  // der Anschlag unangetastet bleibt (siehe applyFades in synth.js).
  const fadeIn = Math.min(channels[0].length >> 1, Math.max(4, Math.round(sampleRate * 0.0002)));
  const fadeOut = Math.min(channels[0].length >> 1, Math.round(sampleRate * (opts.fadeOut ?? 0.006)));
  for (const ch of channels) {
    for (let i = 0; i < fadeIn; i++) ch[i] *= i / fadeIn;
    for (let i = 0; i < fadeOut; i++) ch[ch.length - 1 - i] *= i / fadeOut;
  }

  return channels;
}

export { peakOf, rmsOf };
