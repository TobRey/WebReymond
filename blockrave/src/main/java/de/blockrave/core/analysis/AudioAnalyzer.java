package de.blockrave.core.analysis;

import de.blockrave.core.audio.AudioConstants;

/**
 * Echtzeit-Audioanalyse fuer die Lichtshow.
 *
 * <p>Arbeitet mit 10-ms-Schritten (480 Samples bei 48 kHz) auf einer Mono-Mischung:
 * <ul>
 *   <li>FFT (2048, Hann) &rarr; Frequenzbaender und spektraler Fluss (Onset-Staerke)</li>
 *   <li>Onset- und Bassimpuls-Erkennung mit adaptiven Schwellen</li>
 *   <li>Tempo-Schaetzung per Autokorrelation der Onset-Kurve (70&ndash;180 BPM)</li>
 *   <li>Beat-Tracking per Phasenregelschleife, die sich an erkannten Onsets ausrichtet</li>
 *   <li>Energie/Intensitaet relativ zum laufenden Track, Hoehepunkt-Erkennung</li>
 * </ul>
 * Es wird nichts zufaellig erzeugt: ohne sicheres Tempo werden keine Beats gemeldet.
 *
 * <p>Nicht threadsicher; eine Instanz pro Stream auf einem Hintergrund-Thread.
 */
public final class AudioAnalyzer {
    static final int HOP = 480;
    static final int HOPS_PER_SECOND = AudioConstants.SAMPLE_RATE / HOP; // 100
    private static final int N = 2048;
    private static final int BINS = N / 2 + 1;
    private static final double BIN_HZ = (double) AudioConstants.SAMPLE_RATE / N;
    private static final int ENV_LEN = 800; // 8 s Onset-Kurve
    private static final int TEMPO_WINDOW = 600; // 6 s fuer Autokorrelation
    private static final double MIN_BPM = 70, MAX_BPM = 180;

    private final Fft fft = new Fft(N);
    private final double[] window = new double[N];
    private final double[] ring = new double[N];
    private int ringPos;
    private final double[] re = new double[N];
    private final double[] im = new double[N];
    private final double[] prevLog = new double[BINS];
    private final int[][] bandBins;
    private final int fluxMaxBin;
    private final int bassFluxMaxBin;
    private final int midFluxMaxBin;

    // Zeitachse in Hops
    private long hop;

    // Onset-Kurven
    private final double[] env = new double[ENV_LEN];
    private final double[] bassEnv = new double[ENV_LEN];
    private double prevFlux, prevPrevFlux;
    private double prevBass, prevPrevBass;
    private long lastOnsetHop = -1000, lastBassHop = -1000;

    // Lautheit
    private double shortDb = -90, mediumDb = -90, refMax = -20;
    private double shortPow, mediumPow;
    private final double[] mediumHist = new double[ENV_LEN];
    private final double[] bandRef = {-30, -30, -30, -30};
    private final double[] bandDbNow = new double[4];
    private double onsetRate;
    private double intensity;
    private long lastPeakHop = -100_000;
    private long silentSinceHop = 0;

    // Tempo / Beat
    private double period = 0; // Hops pro Beat
    private boolean locked;
    private double nextBeat;
    private double lastBeat;
    private int beatIndex;
    private double tempoConfidence;
    private int lowConfidenceCount;
    private int relockVotes;

    // Szenensteuerung (mit Gedaechtnis, deshalb hier an der Quelle und nicht im Client)
    public static final int PATTERN_COUNT = 5;
    private int pattern;
    private int energyClass;
    private long sceneStartHop;
    private int sceneStartBeat;
    private long dropUntilHop = -1;
    private int dropUntilBeat = -1;

    // Frame-Aggregation
    private final float[] mono = new float[HOP];

    public AudioAnalyzer() {
        for (int i = 0; i < N; i++) window[i] = 0.5 - 0.5 * Math.cos(2 * Math.PI * i / (N - 1));
        double[][] bandHz = {{25, 150}, {150, 800}, {800, 4000}, {4000, 16000}};
        bandBins = new int[4][2];
        for (int b = 0; b < 4; b++) {
            bandBins[b][0] = Math.max(1, (int) Math.round(bandHz[b][0] / BIN_HZ));
            bandBins[b][1] = Math.min(BINS - 1, (int) Math.round(bandHz[b][1] / BIN_HZ));
        }
        fluxMaxBin = (int) Math.round(8000 / BIN_HZ);
        bassFluxMaxBin = (int) Math.round(200 / BIN_HZ);
        midFluxMaxBin = (int) Math.round(2000 / BIN_HZ);
    }

    /**
     * Analysiert einen 20-ms-Frame (interleavtes Stereo, 960 Frames).
     */
    public AnalysisFrame analyze(float[] stereoFrame) {
        int flags = 0;
        double[] bandMax = new double[4];
        for (int h = 0; h < 2; h++) {
            for (int i = 0; i < HOP; i++) {
                int s = (h * HOP + i) * 2;
                mono[i] = (stereoFrame[s] + stereoFrame[s + 1]) * 0.5f;
            }
            flags |= processHop();
            for (int b = 0; b < 4; b++) bandMax[b] = Math.max(bandMax[b], bandLevel(b));
        }
        if (locked) flags |= AnalysisFrame.TEMPO_LOCKED;
        if (shortDb < -55) flags |= AnalysisFrame.SILENT;

        double phase;
        if (locked && period > 0) {
            phase = (hop - lastBeat) / period;
        } else {
            phase = Math.min(0.999, (hop - lastBassHop) / 50.0);
        }
        phase = phase - Math.floor(phase);
        byte[] bands = new byte[4];
        for (int b = 0; b < 4; b++) bands[b] = (byte) q(bandMax[b]);
        updateScene(flags);
        int scene = (pattern & 0x0F) | (energyClass << 4) | (isDropActive() ? 0x40 : 0);
        int bpm = locked && period > 0 ? (int) Math.round(60.0 * HOPS_PER_SECOND / period * 10) : 0;
        double energy = clamp01((mediumDb - (refMax - 20)) / 20);
        double loud = clamp01((shortDb + 60) / 60);
        return new AnalysisFrame(flags, beatIndex, (int) (phase * 256) & 0xFF, bpm, q(energy), q(intensity), q(loud),
                bands, scene);
    }

    private boolean isDropActive() {
        return locked ? beatIndex < dropUntilBeat : hop < dropUntilHop;
    }

    /**
     * Energieklasse mit Hysterese an Taktgrenzen, Musterwechsel alle 8 Takte (bzw. 16 s ohne Tempo).
     * Die Musterfolge ist deterministisch, keine Zufallszahlen.
     */
    private void updateScene(int flags) {
        boolean boundary;
        if (locked) {
            boundary = (flags & AnalysisFrame.BEAT) != 0 && beatIndex % 4 == 0;
        } else {
            boundary = hop % 200 < 2;
        }
        if (boundary) {
            int target = intensity < 0.33 ? 0 : intensity < 0.62 ? 1 : 2;
            if (target > energyClass && intensity > (energyClass == 0 ? 0.38 : 0.67)) energyClass = target;
            else if (target < energyClass && intensity < (energyClass == 2 ? 0.57 : 0.28)) energyClass = target;
        }
        boolean sceneChange = locked ? (flags & AnalysisFrame.BEAT) != 0 && beatIndex - sceneStartBeat >= 32
                : hop - sceneStartHop >= 16 * HOPS_PER_SECOND;
        if ((flags & AnalysisFrame.PEAK) != 0) {
            sceneChange = true;
            dropUntilBeat = beatIndex + 16;
            dropUntilHop = hop + 8 * HOPS_PER_SECOND;
        }
        if (sceneChange) {
            int step = 1 + (sceneCounter++ % 3);
            pattern = (pattern + step) % PATTERN_COUNT;
            if (pattern == 4 && energyClass < 2) pattern = 0; // "Ballyhoo" nur bei hoher Energie
            sceneStartBeat = beatIndex;
            sceneStartHop = hop;
        }
    }

    private int sceneCounter;

    private static int q(double v) {
        return (int) Math.round(clamp01(v) * 255);
    }

    private static double clamp01(double v) {
        return v < 0 ? 0 : v > 1 ? 1 : v;
    }

    private double bandLevel(int b) {
        return clamp01((bandDbNow[b] - (bandRef[b] - 30)) / 30);
    }

    private int processHop() {
        int flags = 0;
        // Ringpuffer fuellen + Lautheit
        double ms = 0;
        for (int i = 0; i < HOP; i++) {
            double v = mono[i];
            ms += v * v;
            ring[ringPos] = v;
            ringPos = (ringPos + 1) % N;
        }
        ms /= HOP;
        // Leistung mitteln (nicht dB): sonst ziehen stille Luecken zwischen Kicks den Pegel gegen -120 dB
        shortPow += (ms - shortPow) / 25.0;
        mediumPow += (ms - mediumPow) / 100.0;
        shortDb = 10 * Math.log10(shortPow + 1e-12) + 3.01; // Vollaussteuerungs-Sinus = 0 dB
        mediumDb = 10 * Math.log10(mediumPow + 1e-12) + 3.01;
        if (shortDb > refMax) refMax = shortDb;
        else refMax = Math.max(-45, refMax - 0.002);
        if (shortDb < -55) {
            if (silentSinceHop == 0) silentSinceHop = hop;
        } else {
            silentSinceHop = 0;
        }

        // FFT
        for (int i = 0; i < N; i++) {
            re[i] = ring[(ringPos + i) % N] * window[i];
            im[i] = 0;
        }
        fft.transform(re, im);
        // Spektraler Fluss getrennt nach Bereichen, jeweils pro Bin gemittelt. Fuer das Beat-Tracking
        // wird der Tiefton stark gewichtet (Kickdrum), sonst dominieren breitbandige Hi-Hats.
        double fluxLow = 0, fluxMid = 0, fluxHigh = 0;
        int nLow = 0, nMid = 0, nHigh = 0;
        double[] bandPow = new double[4];
        double norm = 4.0 / N;
        for (int k = 1; k < BINS - 1; k++) {
            double mag = Math.sqrt(re[k] * re[k] + im[k] * im[k]) * norm;
            double lm = Math.log1p(1000 * mag);
            double d = lm - prevLog[k];
            prevLog[k] = lm;
            double pos = d > 0 ? d : 0;
            if (k <= bassFluxMaxBin) { fluxLow += pos; nLow++; }
            else if (k <= midFluxMaxBin) { fluxMid += pos; nMid++; }
            else if (k <= fluxMaxBin) { fluxHigh += pos; nHigh++; }
            for (int b = 0; b < 4; b++) {
                if (k >= bandBins[b][0] && k <= bandBins[b][1]) bandPow[b] += mag * mag;
            }
        }
        fluxLow /= Math.max(1, nLow);
        fluxMid /= Math.max(1, nMid);
        fluxHigh /= Math.max(1, nHigh);
        double bassFlux = fluxLow;
        double flux = 0.6 * fluxLow + 0.25 * fluxMid + 0.15 * fluxHigh;
        for (int b = 0; b < 4; b++) {
            double bdb = 10 * Math.log10(bandPow[b] + 1e-12);
            bandDbNow[b] = bdb;
            if (bdb > bandRef[b]) bandRef[b] = bdb;
            else bandRef[b] = Math.max(-60, bandRef[b] - 0.003);
        }

        int ei = (int) (hop % ENV_LEN);
        env[ei] = flux;
        bassEnv[ei] = bassFlux;
        mediumHist[ei] = mediumDb;
        boolean silent = shortDb < -55;

        // Onset: lokales Maximum im vorherigen Hop ueber adaptiver Schwelle
        if (!silent && hop > 2) {
            double thr = adaptiveThreshold(env, 50, 1.4, 0.02);
            if (prevFlux > thr && prevFlux > prevPrevFlux && prevFlux >= flux && hop - 1 - lastOnsetHop >= 10) {
                lastOnsetHop = hop - 1;
                flags |= AnalysisFrame.ONSET;
                onsetRate += 1.0;
                onBeatEvidence(hop - 1, prevFlux / Math.max(1e-6, thr));
            }
            double bthr = adaptiveThreshold(bassEnv, 100, 1.5, 0.03);
            if (prevBass > bthr && prevBass > prevPrevBass && prevBass >= bassFlux && hop - 1 - lastBassHop >= 20
                    && bandLevel(0) > 0.45) {
                lastBassHop = hop - 1;
                flags |= AnalysisFrame.BASS_HIT;
            }
        }
        prevPrevFlux = prevFlux;
        prevFlux = flux;
        prevPrevBass = prevBass;
        prevBass = bassFlux;

        onsetRate *= Math.exp(-1.0 / (2.0 * HOPS_PER_SECOND)); // ~ Onsets der letzten 2 s
        double energy = clamp01((mediumDb - (refMax - 20)) / 20);
        double target = silent ? 0 : clamp01(0.65 * energy + 0.35 * Math.min(1, onsetRate / 2.0 / 6.0));
        intensity += (target - intensity) / 150.0;

        // Tempo alle 0,5 s neu schaetzen
        if (hop % 50 == 0 && hop >= TEMPO_WINDOW / 2) estimateTempo();

        // Beat-Ausgabe
        if (locked) {
            if (hop >= nextBeat) {
                lastBeat = nextBeat;
                nextBeat += period;
                beatIndex++;
                flags |= AnalysisFrame.BEAT;
            }
            if (silentSinceHop != 0 && hop - silentSinceHop > 200) unlock();
        }

        // Hoehepunkt: deutlicher Lautheitssprung gegenueber den letzten Sekunden + Bass
        if (!silent && hop - lastPeakHop > 15 * HOPS_PER_SECOND && hop > 6 * HOPS_PER_SECOND) {
            double before = 0;
            int cnt = 0;
            for (int i = 100; i < 600; i += 5) {
                before += mediumHist[(int) ((hop - i + ENV_LEN) % ENV_LEN)];
                cnt++;
            }
            before /= cnt;
            if (shortDb - before > 5 && energy > 0.55 && hop - lastBassHop < 30) {
                lastPeakHop = hop;
                flags |= AnalysisFrame.PEAK;
            }
        }
        hop++;
        return flags;
    }

    private double adaptiveThreshold(double[] e, int len, double k, double floor) {
        double sum = 0, sq = 0;
        int n = 0;
        for (int i = 1; i <= len && i <= hop; i++) {
            double v = e[(int) ((hop - i + ENV_LEN) % ENV_LEN)];
            sum += v;
            sq += v * v;
            n++;
        }
        if (n == 0) return Double.MAX_VALUE;
        double mean = sum / n;
        double sd = Math.sqrt(Math.max(0, sq / n - mean * mean));
        return Math.max(floor, mean + k * sd);
    }

    /** Ein erkannter Onset zieht die Beat-Phase sanft in seine Richtung. */
    private void onBeatEvidence(long onsetHop, double strength) {
        if (!locked) return;
        double prev = nextBeat - period;
        double errNext = onsetHop - nextBeat;
        double errPrev = onsetHop - prev;
        double err = Math.abs(errNext) < Math.abs(errPrev) ? errNext : errPrev;
        if (Math.abs(err) < 0.15 * period) {
            double gain = Math.min(0.25, 0.1 * strength);
            nextBeat += gain * err;
            if (Math.abs(err) < period * 0.5) lastBeat = Math.min(lastBeat + gain * err, hop);
        }
    }

    private void estimateTempo() {
        int len = Math.min(TEMPO_WINDOW, (int) hop);
        double[] x = new double[len];
        double mean = 0;
        for (int i = 0; i < len; i++) {
            x[i] = env[(int) ((hop - len + i + ENV_LEN) % ENV_LEN)];
            mean += x[i];
        }
        mean /= len;
        for (int i = 0; i < len; i++) x[i] = Math.max(0, x[i] - mean);
        int minLag = (int) Math.floor(60.0 * HOPS_PER_SECOND / MAX_BPM);
        int maxLag = (int) Math.ceil(60.0 * HOPS_PER_SECOND / MIN_BPM);
        double ac0 = 0;
        for (int i = 0; i < len; i++) ac0 += x[i] * x[i];
        if (ac0 <= 1e-9) {
            noteLowConfidence();
            return;
        }
        double[] ac = new double[2 * maxLag + 2];
        for (int l = minLag - 1; l < ac.length && l < len; l++) {
            double s = 0;
            for (int i = l; i < len; i++) s += x[i] * x[i - l];
            ac[l] = s / ac0;
        }
        int best = -1;
        double bestScore = 0;
        for (int l = minLag; l <= maxLag; l++) {
            double bpm = 60.0 * HOPS_PER_SECOND / l;
            double prior = Math.exp(-0.5 * Math.pow(Math.log(bpm / 125.0) / Math.log(2) / 0.7, 2));
            double s = ac[l] + (2 * l < ac.length ? 0.5 * ac[2 * l] : 0);
            s *= prior;
            if (s > bestScore) {
                bestScore = s;
                best = l;
            }
        }
        if (best < 0) {
            noteLowConfidence();
            return;
        }
        // Parabolische Interpolation fuer Sub-Hop-Genauigkeit
        double y0 = ac[best - 1], y1 = ac[best], y2 = best + 1 < ac.length ? ac[best + 1] : y1;
        double denom = y0 - 2 * y1 + y2;
        double lag = best + (Math.abs(denom) > 1e-9 ? 0.5 * (y0 - y2) / denom : 0);
        tempoConfidence = ac[best];
        if (tempoConfidence < 0.12) {
            noteLowConfidence();
            return;
        }
        lowConfidenceCount = 0;
        if (!locked) {
            lock(lag, x);
        } else if (Math.abs(lag - period) / period > 0.06) {
            if (++relockVotes >= 3) lock(lag, x);
        } else {
            relockVotes = 0;
            period += 0.15 * (lag - period);
        }
    }

    private void noteLowConfidence() {
        if (locked && ++lowConfidenceCount >= 8) unlock();
    }

    private void unlock() {
        locked = false;
        relockVotes = 0;
        lowConfidenceCount = 0;
    }

    /** Rastet auf ein Tempo ein; die Phase wird per Kammfilter ueber die Onset-Kurve bestimmt. */
    private void lock(double lag, double[] x) {
        period = lag;
        int len = x.length;
        int p = (int) Math.round(lag);
        double bestScore = -1;
        int bestPhase = 0;
        for (int phi = 0; phi < p; phi++) {
            double s = 0;
            for (int k = 0; k < 8; k++) {
                int idx = len - 1 - phi - (int) Math.round(k * lag);
                if (idx < 1) break;
                s += x[idx] + 0.5 * (x[idx - 1] + (idx + 1 < len ? x[idx + 1] : 0));
            }
            if (s > bestScore) {
                bestScore = s;
                bestPhase = phi;
            }
        }
        // x[len-1] entspricht Hop (hop - 1)
        lastBeat = (hop - 1) - bestPhase;
        nextBeat = lastBeat + period;
        while (nextBeat <= hop) {
            lastBeat = nextBeat;
            nextBeat += period;
        }
        locked = true;
        relockVotes = 0;
    }

    public boolean isTempoLocked() {
        return locked;
    }

    public double bpm() {
        return locked ? 60.0 * HOPS_PER_SECOND / period : 0;
    }

    public double tempoConfidence() {
        return tempoConfidence;
    }
}
