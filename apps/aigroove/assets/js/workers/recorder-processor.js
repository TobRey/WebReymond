/**
 * AI Groove – AudioWorklet fuer die Mikrofonaufnahme.
 *
 * Laeuft im Audio-Thread und schickt rohe PCM-Bloecke an den Haupt-Thread.
 * Dadurch bleibt die Aufnahme frei von Aussetzern, auch wenn die Oberflaeche
 * gerade viel zeichnet.
 */

class RecorderProcessor extends AudioWorkletProcessor {
  constructor() {
    super();
    this.recording = false;
    this.port.onmessage = (event) => {
      if (event.data === 'start') this.recording = true;
      else if (event.data === 'stop') this.recording = false;
    };
  }

  process(inputs) {
    const input = inputs[0];
    if (!input || input.length === 0) return true;

    // Pegel fuer die Anzeige (immer, auch ohne laufende Aufnahme).
    let peak = 0;
    const mono = input[0];
    for (let i = 0; i < mono.length; i++) {
      const v = Math.abs(mono[i]);
      if (v > peak) peak = v;
    }

    if (this.recording) {
      const chunk = input.map((ch) => new Float32Array(ch));
      this.port.postMessage({ type: 'data', channels: chunk, peak }, chunk.map((c) => c.buffer));
    } else {
      this.port.postMessage({ type: 'level', peak });
    }
    return true;
  }
}

registerProcessor('aig-recorder', RecorderProcessor);
