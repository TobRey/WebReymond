package de.blockrave.client.live;

import java.util.List;
import java.util.concurrent.locks.LockSupport;

/**
 * Kuenstliche Live-Quelle fuer den automatischen Mehrspieler-Test (nur mit
 * {@code -Dblockrave.e2e.testTone=true}): erzeugt in Echtzeit einen 128-BPM-Kick mit Hi-Hat, in 10-ms-Bloecken
 * wie ein WASAPI-Loopback. Damit laesst sich der Live-Weg (Senden, Server-Puffer, Wiedergabe bei Zuhoerern)
 * auch ohne Windows pruefen.
 */
final class TestToneBackend implements CaptureBackend {
    private static final int RATE = 48_000;
    private static final int BLOCK = RATE / 100;
    private static final CaptureSource SOURCE = new CaptureSource("testtone", "Testton (nur Testumgebung)",
            CaptureSource.Kind.ENDPOINT_LOOPBACK, "");

    @Override
    public boolean isSupported() {
        return true;
    }

    @Override
    public String unsupportedReason() {
        return "";
    }

    @Override
    public List<CaptureSource> listSources() {
        return List.of(SOURCE);
    }

    @Override
    public Stream open(CaptureSource source, Sink sink) {
        Thread t = new Thread(() -> generate(sink), "BlockRave-TestTone");
        t.setDaemon(true);
        t.start();
        return new Stream() {
            @Override
            public void close() {
                t.interrupt();
            }

            @Override
            public int latencyMillis() {
                return 10;
            }
        };
    }

    private static void generate(Sink sink) {
        long start = System.nanoTime();
        long sample = 0;
        double beat = RATE * 60.0 / 128.0;
        while (!Thread.currentThread().isInterrupted()) {
            float[] data = new float[BLOCK * 2];
            for (int i = 0; i < BLOCK; i++, sample++) {
                double inBeat = sample % beat;
                double t = inBeat / RATE;
                double kick = t < 0.12 ? Math.sin(2 * Math.PI * (50 + 90 * Math.exp(-t * 30)) * t) * Math.exp(-t * 18) : 0;
                double offBeat = (sample + beat / 2) % beat / RATE;
                double hat = offBeat < 0.03 ? (Math.random() * 2 - 1) * Math.exp(-offBeat * 120) * 0.3 : 0;
                float v = (float) (0.7 * kick + hat);
                data[i * 2] = v;
                data[i * 2 + 1] = v;
            }
            sink.onAudio(data, BLOCK, 2, RATE);
            long due = start + sample * 1_000_000_000L / RATE;
            long wait = due - System.nanoTime();
            if (wait > 0) LockSupport.parkNanos(wait);
        }
    }
}
