package de.blockrave.harness;

import de.blockrave.client.audio.RavePlayer;
import de.blockrave.core.analysis.AnalysisFrame;
import de.blockrave.core.audio.AudioConstants;
import de.blockrave.core.audio.OpusFrameEncoder;
import de.blockrave.core.sync.ClockSync;
import de.blockrave.core.sync.TimedFrame;

import java.io.RandomAccessFile;
import java.nio.ByteBuffer;
import java.nio.ByteOrder;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.List;

/**
 * Headless-Pruefstand fuer die echte Client-Wiedergabe (RavePlayer + OpenAL Soft).
 * OpenAL Soft schreibt dabei ueber sein "wave"-Backend in Echtzeit in eine WAV-Datei statt auf
 * eine Soundkarte. Anschliessend wird geprueft, WANN die Testtoene tatsaechlich ausgegeben wurden.
 *
 * <p>Ablauf: alle 500 ms ein 15-ms-Ton (links 1 kHz, rechts 2 kHz) mit bekanntem Abspielzeitpunkt.
 * <ul>
 *   <li>0&ndash;6 s: durchgehend</li>
 *   <li>Schnitt bei 6 s (wie Pause), neues Segment ab 7 s (wie Fortsetzen)</li>
 *   <li>Ab 10 s: Uhrenschaetzung springt um 30 ms (wie neuer Zeitabgleich) &rarr; weiche Korrektur</li>
 * </ul>
 * Aufruf: siehe core-check/build.gradle (Task runAudioHarness).
 */
public final class AudioSyncHarness {
    static final int RATE = AudioConstants.SAMPLE_RATE;

    public static void main(String[] args) throws Exception {
        Path wav = Path.of(args[0]);
        ClockSync clock = new ClockSync();
        long t = ClockSync.nowMicros();
        clock.addSample(t, t, t); // Serveruhr = lokale Uhr
        RavePlayer player = new RavePlayer(clock);
        long openedAt = ClockSync.nowMicros();
        player.start("");
        player.setListener(new float[]{0, 0, 0, 0, 0, -1, 0, 1, 0});
        player.setSpeakers(new float[]{-3, 0, -5, 3, 0, -5});
        player.setGain(1f);

        long t0 = ClockSync.nowMicros() + 600_000; // Abspielzeit von Sample 0
        OpusFrameEncoder enc = new OpusFrameEncoder(160_000, 8);
        int totalFrames = 16 * 50; // 16 s
        Thread mon = new Thread(() -> {
            try {
                while (true) {
                    Thread.sleep(500);
                    System.out.printf("  t=%5.1fs  Fehler %+6.1f ms  Rate %.4f  spielt=%s%n", (ClockSync.nowMicros() - t0) / 1e6,
                            player.syncErrorMicros() / 1000.0, player.playbackRate(), player.isPlaying());
                }
            } catch (InterruptedException ignored) {
            }
        });
        mon.setDaemon(true);
        mon.start();
        List<Long> expected = new ArrayList<>();
        int stream = 1;
        long sampleClock = 0;
        boolean jumped = false;
        for (int k = 0; k < totalFrames; k++) {
            double sec = k * 0.02;
            if (sec >= 6.0 && sec < 7.0) {
                if (sec == 6.0 || Math.abs(sec - 6.0) < 1e-9) player.cut(stream, t0 + 6_000_000);
                continue; // Pause: keine Frames
            }
            if (Math.abs(sec - 7.0) < 1e-9) {
                stream = 2;
                enc.reset();
            }
            long playout = t0 + k * 20_000L;
            // Netzwerk simulieren: Frame ca. 1 s vor Abspielzeit anbieten
            while (ClockSync.nowMicros() < playout - 1_000_000) Thread.sleep(2);
            if (!jumped && sec >= 10.0) {
                // Uhrenschaetzung aendert sich um +30 ms (Server scheint 30 ms weiter)
                long n = ClockSync.nowMicros();
                for (int i = 0; i < 20; i++) clock.addSample(n, n + 30_000, n);
                jumped = true;
                System.out.printf("  Uhrensprung angewendet: Serveruhr - lokale Uhr = %+.1f ms%n", (clock.toServer(n) - n) / 1000.0);
            }
            float[] pcm = new float[AudioConstants.FRAME_SAMPLES * 2];
            for (int i = 0; i < AudioConstants.FRAME_SAMPLES; i++) {
                long s = (long) k * AudioConstants.FRAME_SAMPLES + i;
                long inBurst = s % (RATE / 2);
                if (inBurst < RATE * 15 / 1000) {
                    double env = Math.sin(Math.PI * inBurst / (RATE * 0.015));
                    pcm[i * 2] = (float) (0.6 * env * Math.sin(2 * Math.PI * 1000 * s / RATE));
                    pcm[i * 2 + 1] = (float) (0.6 * env * Math.sin(2 * Math.PI * 2000 * s / RATE));
                }
                if (inBurst == 0) expected.add(t0 + s * 1_000_000L / RATE);
            }
            player.offer(new TimedFrame(stream, k, playout, enc.encode(pcm), AnalysisFrame.SILENCE));
            sampleClock += AudioConstants.FRAME_SAMPLES;
        }
        Thread.sleep(1500);
        mon.interrupt();
        String err = player.error();
        player.shutdown();
        System.out.printf("Echtzeit von start() bis Ende: %.3f s%n", (ClockSync.nowMicros() - openedAt) / 1e6);
        if (!err.isEmpty()) {
            System.out.println("FEHLER: " + err);
            System.exit(2);
        }
        analyze(wav, openedAt, expected);
    }

    private static void analyze(Path wav, long openedAt, List<Long> expected) throws Exception {
        byte[] data = Files.readAllBytes(wav);
        ByteBuffer b = ByteBuffer.wrap(data).order(ByteOrder.LITTLE_ENDIAN);
        // einfacher WAV-Parser
        int pos = 12, channels = 2, rate = RATE, bits = 16, dataStart = -1, dataLen = 0, fmtTag = 1;
        while (pos + 8 <= data.length) {
            String id = new String(data, pos, 4);
            int len = b.getInt(pos + 4);
            if (id.equals("fmt ")) {
                fmtTag = b.getShort(pos + 8) & 0xFFFF;
                channels = b.getShort(pos + 10);
                rate = b.getInt(pos + 12);
                bits = b.getShort(pos + 22);
            } else if (id.equals("data")) {
                dataStart = pos + 8;
                dataLen = Math.min(len <= 0 ? Integer.MAX_VALUE : len, data.length - dataStart);
                break;
            }
            pos += 8 + len + (len & 1);
        }
        int bytesPer = bits / 8;
        int frames = dataLen / (bytesPer * channels);
        System.out.printf("WAV: %d Hz, %d Kanaele, %d bit (Format %d), %.2f s%n", rate, channels, bits, fmtTag, frames / (double) rate);
        float[] l = new float[frames], r = new float[frames];
        for (int i = 0; i < frames; i++) {
            int o = dataStart + i * bytesPer * channels;
            if (bits == 16) {
                l[i] = b.getShort(o) / 32768f;
                r[i] = b.getShort(o + 2) / 32768f;
            } else {
                l[i] = b.getFloat(o);
                r[i] = b.getFloat(o + 4);
            }
        }
        // Burst-Anfaenge suchen (Energie in 1-ms-Fenstern)
        int win = rate / 1000;
        List<Double> onsets = new ArrayList<>();
        double lastOnset = -1;
        double peakL = 0, peakR = 0;
        for (int w = 0; w + win < frames; w += win) {
            double e = 0;
            for (int i = w; i < w + win; i++) e += l[i] * l[i] + r[i] * r[i];
            double sec = w / (double) rate;
            if (e / win > 0.002 && (lastOnset < 0 || sec - lastOnset > 0.3)) {
                onsets.add(sec);
                lastOnset = sec;
            }
        }
        for (int i = 0; i < frames; i++) {
            peakL = Math.max(peakL, Math.abs(l[i]));
            peakR = Math.max(peakR, Math.abs(r[i]));
        }
        System.out.printf("Gefundene Toene: %d (erwartet %d), Spitzenpegel L=%.2f R=%.2f%n", onsets.size(), expected.size(), peakL, peakR);
        if (onsets.isEmpty()) {
            System.out.println("FEHLER: keine Ausgabe");
            System.exit(3);
        }
        // Der Beginn der WAV-Datei ist nicht exakt bekannt (Geraetestart). Konstante c aus den ersten Toenen.
        List<Double> early = new ArrayList<>();
        for (int i = 0; i < Math.min(6, expected.size()); i++) {
            double expSec = (expected.get(i) - openedAt) / 1e6;
            early.add(nearest(onsets, expSec) - expSec);
        }
        early.sort(Double::compare);
        double c = early.get(early.size() / 2);
        System.out.printf("Konstante Verschiebung WAV-Beginn/Geraetelatenz c = %.1f ms%n", c * 1000);
        List<double[]> pairs = new ArrayList<>();
        for (long exp : expected) {
            double expSec = (exp - openedAt) / 1e6 + c;
            double o = nearest(onsets, expSec);
            if (Math.abs(o - expSec) < 0.2) pairs.add(new double[]{(exp - expected.get(0)) / 1e6, (o - expSec) * 1000});
        }
        StringBuilder sb = new StringBuilder("Abweichung je Ton relativ zu c (ms): ");
        for (double[] p : pairs) sb.append(String.format("%.1fs:%+.1f ", p[0], p[1]));
        System.out.println(sb);
        stats("Abschnitt 1 (0-6 s)", pairs, 0.0, 6.0);
        stats("Abschnitt 2 nach Pause (7-9 s)", pairs, 7.0, 9.0);
        stats("Nach Uhrenaenderung (9-12 s)", pairs, 9.0, 12.0);
        stats("Eingeschwungen (12-16 s)", pairs, 12.0, 16.0);
        double pauseFrom = (expected.get(0) - openedAt) / 1e6 + c + 6.05, pauseTo = pauseFrom + 0.9;
        System.out.printf("Toene waehrend der Pause gefunden: %d (erwartet 0)%n",
                onsets.stream().filter(o -> o > pauseFrom && o < pauseTo).count());
        System.out.printf("Gefundene/erwartete Toene: %d/%d%n", pairs.size(), expected.size());
    }

    private static double nearest(List<Double> onsets, double t) {
        double best = Double.NaN;
        for (double o : onsets) if (Double.isNaN(best) || Math.abs(o - t) < Math.abs(best - t)) best = o;
        return best;
    }

    private static void stats(String name, List<double[]> pairs, double from, double to) {
        double sum = 0, min = 1e9, max = -1e9;
        int n = 0;
        for (double[] p : pairs) {
            double rel = p[0];
            if (rel >= from && rel < to) {
                sum += p[1];
                min = Math.min(min, p[1]);
                max = Math.max(max, p[1]);
                n++;
            }
        }
        if (n == 0) System.out.printf("%s: keine Werte%n", name);
        else System.out.printf("%s: n=%d, Mittel %+.1f ms, Spanne %.1f ms (min %+.1f, max %+.1f)%n", name, n, sum / n, max - min, min, max);
    }
}
