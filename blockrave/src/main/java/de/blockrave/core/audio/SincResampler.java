package de.blockrave.core.audio;

/**
 * Bandbegrenzter Resampler (gefensterte Sinc-Interpolation mit Phasentabelle) fuer
 * interleavte Float-Samples. Das Verhaeltnis darf zur Laufzeit leicht veraendert werden;
 * das nutzt der Client fuer die sanfte Driftkorrektur.
 *
 * <p>Nicht threadsicher; jede Instanz gehoert genau einem Audio-Thread.
 */
public final class SincResampler {
    private static final int PHASES = 512;

    private final int channels;
    private final int halfTaps;
    private final float[] table; // [PHASES + 1][2 * halfTaps]
    private final double cutoff;

    /** Eingangsfifo (interleavt). */
    private float[] buf;
    private int bufFrames;
    /** Position des naechsten Ausgabesamples relativ zum Fifo-Anfang (in Eingangsframes). */
    private double pos;
    /** Eingangsframes pro Ausgangsframe. */
    private double step;

    /**
     * @param channels Kanalzahl
     * @param inRate   Eingangsrate
     * @param outRate  Ausgangsrate
     * @param halfTaps halbe Filterlaenge (z.B. 16 fuer gute Qualitaet)
     */
    public SincResampler(int channels, double inRate, double outRate, int halfTaps) {
        this.channels = channels;
        this.halfTaps = halfTaps;
        this.step = inRate / outRate;
        // Beim Herunterrechnen Grenzfrequenz absenken, um Aliasing zu vermeiden.
        this.cutoff = Math.min(1.0, outRate / inRate) * 0.95;
        this.table = buildTable(halfTaps, cutoff);
        this.buf = new float[4096 * channels];
        // Startet so, dass das erste Ausgabesample auf dem ersten Eingangssample liegt,
        // fehlende Vergangenheit wird als Stille behandelt.
        this.bufFrames = halfTaps;
        this.pos = halfTaps;
    }

    private static float[] buildTable(int halfTaps, double cutoff) {
        int taps = 2 * halfTaps;
        float[] t = new float[(PHASES + 1) * taps];
        for (int p = 0; p <= PHASES; p++) {
            double frac = (double) p / PHASES;
            double sum = 0;
            for (int k = 0; k < taps; k++) {
                // Tap k entspricht Eingangssample floor(pos) - halfTaps + 1 + k
                double x = (k - halfTaps + 1) - frac;
                double v = sinc(x * cutoff) * cutoff * blackman(x, halfTaps);
                t[p * taps + k] = (float) v;
                sum += v;
            }
            // Normieren, damit Gleichanteil exakt erhalten bleibt.
            for (int k = 0; k < taps; k++) {
                t[p * taps + k] /= (float) sum;
            }
        }
        return t;
    }

    private static double sinc(double x) {
        if (Math.abs(x) < 1e-9) return 1.0;
        double px = Math.PI * x;
        return Math.sin(px) / px;
    }

    private static double blackman(double x, int halfTaps) {
        double n = (x + halfTaps) / (2.0 * halfTaps);
        if (n < 0 || n > 1) return 0;
        return 0.42 - 0.5 * Math.cos(2 * Math.PI * n) + 0.08 * Math.cos(4 * Math.PI * n);
    }

    public int channels() {
        return channels;
    }

    /** Setzt das Verhaeltnis Eingangs- zu Ausgangsrate neu (z.B. fuer Driftkorrektur). */
    public void setRatio(double inRate, double outRate) {
        this.step = inRate / outRate;
    }

    public double step() {
        return step;
    }

    /** Fuegt Eingangsframes (interleavt) an. */
    public void push(float[] in, int offsetFrames, int frames) {
        ensureCapacity(bufFrames + frames);
        System.arraycopy(in, offsetFrames * channels, buf, bufFrames * channels, frames * channels);
        bufFrames += frames;
    }

    private void ensureCapacity(int frames) {
        if (frames * channels > buf.length) {
            float[] n = new float[Math.max(buf.length * 2, frames * channels)];
            System.arraycopy(buf, 0, n, 0, bufFrames * channels);
            buf = n;
        }
    }

    /** Anzahl Ausgabeframes, die mit dem aktuellen Fifo-Inhalt sofort erzeugt werden koennen. */
    public int available() {
        double last = bufFrames - halfTaps - 1; // letzte Position mit vollstaendigem Vorausfenster
        if (pos > last) return 0;
        return (int) Math.floor((last - pos) / step) + 1;
    }

    /**
     * Erzeugt bis zu {@code maxFrames} Ausgabeframes.
     *
     * @return tatsaechlich erzeugte Frames
     */
    public int pull(float[] out, int offsetFrames, int maxFrames) {
        int taps = 2 * halfTaps;
        int produced = 0;
        while (produced < maxFrames) {
            int base = (int) Math.floor(pos);
            if (base + halfTaps >= bufFrames) break;
            double frac = pos - base;
            double phaseF = frac * PHASES;
            int phase = (int) phaseF;
            float mix = (float) (phaseF - phase);
            int t0 = phase * taps;
            int t1 = t0 + taps;
            int first = base - halfTaps + 1;
            int o = (offsetFrames + produced) * channels;
            for (int c = 0; c < channels; c++) {
                float acc = 0f;
                int idx = first * channels + c;
                for (int k = 0; k < taps; k++, idx += channels) {
                    float coef = table[t0 + k] + (table[t1 + k] - table[t0 + k]) * mix;
                    acc += buf[idx] * coef;
                }
                out[o + c] = acc;
            }
            produced++;
            pos += step;
        }
        compact();
        return produced;
    }

    /** Verwirft verbrauchte Eingangsframes, behaelt die noetige Historie. */
    private void compact() {
        int keepFrom = (int) Math.floor(pos) - halfTaps + 1;
        if (keepFrom <= 1024) return;
        int remove = keepFrom;
        System.arraycopy(buf, remove * channels, buf, 0, (bufFrames - remove) * channels);
        bufFrames -= remove;
        pos -= remove;
    }

    /** Setzt den Zustand auf Stille zurueck. */
    public void reset() {
        java.util.Arrays.fill(buf, 0f);
        bufFrames = halfTaps;
        pos = halfTaps;
    }
}
