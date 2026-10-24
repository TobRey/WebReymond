package de.blockrave.core;

import de.blockrave.core.analysis.AnalysisFrame;
import de.blockrave.core.analysis.AnalysisPipeline;
import de.blockrave.core.analysis.AudioAnalyzer;
import de.blockrave.core.audio.AudioConstants;
import de.blockrave.core.audio.Mp3PcmSource;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

import java.nio.file.Path;
import java.util.ArrayList;
import java.util.List;

import static org.junit.jupiter.api.Assertions.*;

class AnalyzerTest {
    @TempDir
    Path dir;

    record Run(List<AnalysisFrame> frames) {
        List<Integer> beatFrames() {
            List<Integer> r = new ArrayList<>();
            for (int i = 0; i < frames.size(); i++) if (frames.get(i).has(AnalysisFrame.BEAT)) r.add(i);
            return r;
        }

        List<Integer> flagFrames(int flag) {
            List<Integer> r = new ArrayList<>();
            for (int i = 0; i < frames.size(); i++) if (frames.get(i).has(flag)) r.add(i);
            return r;
        }
    }

    private Run analyzeFile(String name) throws Exception {
        Path p = TestAudio.resource(dir, name);
        AnalysisPipeline a = new AnalysisPipeline();
        List<AnalysisFrame> frames = new ArrayList<>();
        try (Mp3PcmSource src = new Mp3PcmSource(p)) {
            float[] buf = new float[AudioConstants.FRAME_SAMPLES * 2];
            while (src.read(buf, AudioConstants.FRAME_SAMPLES) == AudioConstants.FRAME_SAMPLES) {
                // Analyse gehoert zum ausgegebenen (verzoegerten) Audioframe
                frames.add(a.process(buf.clone()).analysis());
            }
        }
        // Index i der Liste beschreibt den Audioframe i - DELAY_FRAMES
        for (int i = 0; i < AnalysisPipeline.DELAY_FRAMES; i++) frames.remove(0);
        return new Run(frames);
    }

    @Test
    void erkenntTempoUndBeatsEinesTechnoLoops() throws Exception {
        Run run = analyzeFile("techno128_44k.mp3");
        AnalysisFrame last = run.frames().get(run.frames().size() - 1);
        assertTrue(last.has(AnalysisFrame.TEMPO_LOCKED), "Tempo nicht eingerastet");
        assertEquals(128.0, last.bpmTenths() / 10.0, 2.0, "BPM");
        // Beats der zweiten Haelfte muessen auf den Kicks liegen (Kick alle 468,75 ms ab t=0,
        // im decodierten Audio um die MP3-Encoderverzoegerung von 1105 Samples = 25 ms verschoben).
        double beatMs = 60000.0 / 128;
        double encoderDelayMs = 1105 / 44.1;
        int checked = 0, onGrid = 0;
        for (int f : run.beatFrames()) {
            double tMs = f * 20.0 - encoderDelayMs; // Frameanfang im Quellsignal
            if (tMs < 10_000) continue;
            double rel = (tMs % beatMs) / beatMs;
            double dist = Math.min(rel, 1 - rel) * beatMs;
            checked++;
            if (dist <= 30) onGrid++;
            System.out.printf("beat t=%.0f rel=%.3f%n", tMs, rel > 0.5 ? rel - 1 : rel);
        }
        System.out.printf("Beats geprueft: %d, auf dem Raster (+-30 ms): %d, BPM %.1f%n", checked, onGrid, last.bpmTenths() / 10.0);
        assertTrue(checked >= 18, "zu wenige Beats: " + checked);
        assertTrue(onGrid >= checked * 0.9, "Beats nicht auf den Kicks: " + onGrid + "/" + checked);
        assertFalse(run.flagFrames(AnalysisFrame.BASS_HIT).isEmpty(), "keine Bassimpulse");
    }

    @Test
    void erkenntHoehepunktNachLeisemTeil() throws Exception {
        Run run = analyzeFile("drop124_48k.mp3");
        List<Integer> peaks = run.flagFrames(AnalysisFrame.PEAK);
        System.out.println("Hoehepunkte bei Frames " + peaks);
        assertFalse(peaks.isEmpty(), "kein Hoehepunkt erkannt");
        double t = peaks.get(0) * 0.02;
        assertEquals(12.0, t, 1.0, "Hoehepunkt sollte beim Einsatz bei 12 s liegen");
        // Energie im leisen Teil deutlich niedriger als im lauten
        AnalysisFrame quiet = run.frames().get(450); // 9 s
        AnalysisFrame loud = run.frames().get(1300); // 26 s
        assertTrue(loud.intensity() > quiet.intensity(), "Intensitaet steigt nicht");
        assertTrue(loud.energyClass() >= quiet.energyClass());
    }

    @Test
    void stilleErzeugtKeineBeats() {
        AudioAnalyzer a = new AudioAnalyzer();
        float[] silence = new float[AudioConstants.FRAME_SAMPLES * 2];
        int beats = 0;
        AnalysisFrame f = null;
        for (int i = 0; i < 1000; i++) {
            f = a.analyze(silence);
            if (f.has(AnalysisFrame.BEAT) || f.has(AnalysisFrame.ONSET) || f.has(AnalysisFrame.BASS_HIT)) beats++;
        }
        assertEquals(0, beats);
        assertTrue(f.has(AnalysisFrame.SILENT));
    }

    @Test
    void frameKodierungRundreise() {
        AnalysisFrame f = new AnalysisFrame(AnalysisFrame.BEAT | AnalysisFrame.PEAK, 123456, 200, 1281, 10, 20, 30,
                new byte[]{1, 2, 3, (byte) 250}, 0x52);
        AnalysisFrame g = AnalysisFrame.decode(f.encode());
        assertEquals(f.flags(), g.flags());
        assertEquals(f.beatIndex(), g.beatIndex());
        assertEquals(f.bpmTenths(), g.bpmTenths());
        assertEquals(f.scene(), g.scene());
        assertEquals(250 / 255f, g.band(3), 1e-6);
        assertEquals(2, g.pattern());
        assertEquals(1, g.energyClass());
        assertTrue(g.dropActive());
    }

    /** Kicks mit digitaler Stille dazwischen (sparsame Intros, Live-Loopback): darf nicht als "still" gelten. */
    @Test
    void kicksMitEchterStilleDazwischen() {
        de.blockrave.core.analysis.AnalysisPipeline p = new de.blockrave.core.analysis.AnalysisPipeline();
        double beat = 48000 * 60.0 / 128.0;
        long sample = 0;
        de.blockrave.core.analysis.AnalysisFrame last = null;
        for (int k = 0; k < 50 * 10; k++) {
            float[] f = new float[1920];
            for (int i = 0; i < 960; i++, sample++) {
                double t = (sample % beat) / 48000;
                double kick = t < 0.12 ? Math.sin(2 * Math.PI * (50 + 90 * Math.exp(-t * 30)) * t) * Math.exp(-t * 18) : 0;
                f[2 * i] = (float) (0.7 * kick);
                f[2 * i + 1] = f[2 * i];
            }
            last = p.process(f).analysis();
        }
        assertFalse(last.has(de.blockrave.core.analysis.AnalysisFrame.SILENT), "Kick-Muster gilt faelschlich als Stille");
        assertTrue(last.has(de.blockrave.core.analysis.AnalysisFrame.TEMPO_LOCKED), "Tempo nicht eingerastet");
        assertEquals(128.0, last.bpmTenths() / 10.0, 1.0);
        assertTrue(last.energyF() > 0.5f, "Energie: " + last.energyF());
    }
}
