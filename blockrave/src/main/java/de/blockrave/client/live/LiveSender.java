package de.blockrave.client.live;

import de.blockrave.BlockRave;
import de.blockrave.BlockRaveConfig;
import de.blockrave.core.analysis.AnalysisPipeline;
import de.blockrave.core.audio.AudioConstants;
import de.blockrave.core.audio.OpusFrameEncoder;
import de.blockrave.core.audio.SincResampler;
import de.blockrave.network.Payloads;
import net.minecraft.network.chat.Component;
import de.blockrave.client.ClientNet;

import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.ArrayBlockingQueue;
import java.util.concurrent.BlockingQueue;
import java.util.concurrent.TimeUnit;

/**
 * Live-Senden des eigenen DJ-Mixes. Die Aufnahme startet ausschliesslich nach einem bewussten
 * Klick dieses Spielers und nach Freigabe durch den Server. Andere Spieler koennen sie weder
 * starten noch die Quelle wechseln.
 *
 * <p>Pipeline: Aufnahme (Geraeterate) &rarr; Stereo/48 kHz &rarr; Analyse &rarr; Opus &rarr; Server.
 */
public final class LiveSender {
    public enum State { IDLE, REQUESTED, SENDING, ERROR }

    private final CaptureBackend backend = CaptureBackend.create();
    private volatile List<CaptureSource> sources = List.of();
    private volatile CaptureSource selected;
    private volatile State state = State.IDLE;
    private volatile Component status = Component.empty();
    private volatile int sessionId;
    private volatile int serverDelayMs;
    private volatile float level;
    private volatile long startedAtMs;
    private volatile long lastSignalMs;

    private CaptureBackend.Stream stream;
    private Thread encoderThread;
    private final BlockingQueue<float[]> pcmQueue = new ArrayBlockingQueue<>(200);
    private volatile boolean encoderRunning;

    public CaptureBackend backend() {
        return backend;
    }

    public List<CaptureSource> sources() {
        return sources;
    }

    public CaptureSource selected() {
        return selected;
    }

    public State state() {
        return state;
    }

    public Component status() {
        return status;
    }

    public float level() {
        return level;
    }

    public long sendingSinceMs() {
        return state == State.SENDING ? startedAtMs : 0;
    }

    /** Geschaetzte Verzoegerung vom Mischpult bis zu den Zuhoerern. */
    public int estimatedDelayMs() {
        int capture = stream != null ? stream.latencyMillis() : 30;
        return serverDelayMs + capture + AnalysisPipeline.DELAY_FRAMES * AudioConstants.FRAME_MILLIS + 40;
    }

    public boolean noSignal() {
        return state == State.SENDING && System.currentTimeMillis() - Math.max(lastSignalMs, startedAtMs) > 5000;
    }

    /** Quellenliste im Hintergrund aktualisieren. */
    public void refreshSources(Runnable done) {
        if (!backend.isSupported()) {
            status = Component.literal(backend.unsupportedReason());
            return;
        }
        Thread t = new Thread(() -> {
            try {
                List<CaptureSource> list = backend.listSources();
                sources = list;
                String last = BlockRaveConfig.LIVE_SOURCE.get();
                CaptureSource pick = null;
                for (CaptureSource s : list) if (s.id().equals(last)) pick = s;
                // Nie automatisch ein Eingangsgeraet (Mikrofon) vorauswaehlen
                if (pick == null) for (CaptureSource s : list) if (s.kind() != CaptureSource.Kind.INPUT) { pick = s; break; }
                if (selected == null || !list.contains(selected)) selected = pick;
            } catch (Exception e) {
                status = Component.translatable("blockrave.live.list_error", e.getMessage());
                BlockRave.LOG.warn("BlockRave: Audioquellen konnten nicht ermittelt werden", e);
            }
            if (done != null) done.run();
        }, "BlockRave-DeviceList");
        t.setDaemon(true);
        t.start();
    }

    public void select(CaptureSource s) {
        if (state == State.SENDING || state == State.REQUESTED) return; // kein Quellenwechsel waehrend des Sendens
        selected = s;
        if (s != null) {
            BlockRaveConfig.LIVE_SOURCE.set(s.id());
        }
    }

    /** Bewusster Start durch diesen Spieler: Freigabe beim Server anfragen. */
    public void requestStart() {
        if (!backend.isSupported() || selected == null || state == State.SENDING || state == State.REQUESTED) return;
        state = State.REQUESTED;
        status = Component.translatable("blockrave.live.requesting");
        ClientNet.send(new Payloads.LiveStart(selected.name()));
    }

    /** Server hat freigegeben: jetzt erst wird die Aufnahme geoeffnet. */
    public void onGranted(int session, int delayMs) {
        if (state != State.REQUESTED || selected == null) {
            // Unerwartete Freigabe (z.B. nach Abbruch): sofort zuruecknehmen
            ClientNet.send(new Payloads.LiveStop(session));
            return;
        }
        sessionId = session;
        serverDelayMs = delayMs;
        CaptureSource src = selected;
        Thread opener = new Thread(() -> {
            try {
                startEncoder();
                stream = backend.open(src, new Sink());
                startedAtMs = System.currentTimeMillis();
                state = State.SENDING;
                status = Component.translatable("blockrave.live.sending", src.name());
            } catch (Exception e) {
                BlockRave.LOG.warn("BlockRave: Live-Quelle konnte nicht geoeffnet werden", e);
                fail(Component.translatable("blockrave.live.open_error", String.valueOf(e.getMessage())));
                ClientNet.send(new Payloads.LiveStop(session));
            }
        }, "BlockRave-CaptureOpen");
        opener.setDaemon(true);
        opener.start();
    }

    public void onRejected(Component why) {
        if (state == State.REQUESTED) {
            state = State.IDLE;
            status = why;
        }
    }

    /** Bewusstes Beenden durch den Spieler. */
    public void stop() {
        if (state == State.SENDING || state == State.REQUESTED) ClientNet.send(new Payloads.LiveStop(sessionId));
        release();
        state = State.IDLE;
        status = Component.translatable("blockrave.live.stopped");
    }

    /** Server hat beendet (anderer Spieler, Zeitueberschreitung, Buehne eingepackt ...). */
    public void onServerStop(Component why) {
        release();
        state = State.IDLE;
        status = why;
    }

    private void fail(Component why) {
        release();
        state = State.ERROR;
        status = why;
    }

    /** Gibt Aufnahme, Encoder und Puffer frei (Stopp, Disconnect, Quellenwechsel). */
    public synchronized void release() {
        if (stream != null) {
            stream.close();
            stream = null;
        }
        encoderRunning = false;
        if (encoderThread != null) {
            encoderThread.interrupt();
            try {
                encoderThread.join(1000);
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
            }
            encoderThread = null;
        }
        pcmQueue.clear();
        level = 0;
    }

    // ------------------------------------------------------------------ Verarbeitung

    /** Wandelt beliebige Geraeteformate in Stereo-Bloecke fuer den Encoder-Thread. */
    private final class Sink implements CaptureBackend.Sink {
        @Override
        public void onAudio(float[] data, int frames, int channels, int rate) {
            float[] st = new float[frames * 2 + 1];
            st[frames * 2] = rate; // Abtastrate am Ende mitgeben
            float peak = 0;
            for (int i = 0; i < frames; i++) {
                float l = data[i * channels];
                float r = channels > 1 ? data[i * channels + 1] : l;
                st[i * 2] = l;
                st[i * 2 + 1] = r;
                peak = Math.max(peak, Math.max(Math.abs(l), Math.abs(r)));
            }
            level = Math.max(peak, level * 0.9f);
            if (peak > 0.003f) lastSignalMs = System.currentTimeMillis();
            if (!pcmQueue.offer(st)) {
                pcmQueue.poll(); // Ueberlauf: aeltesten Block verwerfen statt zu blockieren
                pcmQueue.offer(st);
            }
        }

        @Override
        public void onError(String message) {
            fail(Component.translatable("blockrave.live.capture_error", message));
            ClientNet.send(new Payloads.LiveStop(sessionId));
        }
    }

    private void startEncoder() {
        encoderRunning = true;
        pcmQueue.clear();
        encoderThread = new Thread(this::encodeLoop, "BlockRave-LiveEncoder");
        encoderThread.setDaemon(true);
        encoderThread.setPriority(Thread.MAX_PRIORITY - 1);
        encoderThread.start();
    }

    private void encodeLoop() {
        OpusFrameEncoder encoder = new OpusFrameEncoder(BlockRaveConfig.LIVE_BITRATE.get(), 8);
        AnalysisPipeline pipeline = new AnalysisPipeline();
        SincResampler resampler = null;
        int resRate = -1;
        float[] frame = new float[AudioConstants.FRAME_SAMPLES * 2];
        long pts = 0;
        int session = sessionId;
        long startNanos = System.nanoTime();
        List<byte[]> opus = new ArrayList<>();
        List<byte[]> analysis = new ArrayList<>();
        long packetPts = 0;
        while (encoderRunning) {
            float[] block;
            try {
                block = pcmQueue.poll(20, TimeUnit.MILLISECONDS);
            } catch (InterruptedException e) {
                break;
            }
            if (block != null) {
                int rate = (int) block[block.length - 1];
                int frames = (block.length - 1) / 2;
                if (rate != resRate) {
                    resRate = rate;
                    resampler = new SincResampler(2, rate, AudioConstants.SAMPLE_RATE, 16);
                }
                resampler.push(block, 0, frames);
            } else if (resampler != null) {
                // Loopback liefert bei Stille keine Daten: zeitrichtig mit Stille auffuellen
                long due = (System.nanoTime() - startNanos) / 1000 * AudioConstants.SAMPLE_RATE / 1_000_000;
                long behind = due - pts - resampler.available();
                if (behind > AudioConstants.FRAME_SAMPLES * 3) {
                    int fill = (int) Math.min(behind, AudioConstants.FRAME_SAMPLES * 5L) * resRate / AudioConstants.SAMPLE_RATE;
                    resampler.push(new float[fill * 2], 0, fill);
                }
            }
            while (resampler != null && resampler.available() >= AudioConstants.FRAME_SAMPLES) {
                resampler.pull(frame, 0, AudioConstants.FRAME_SAMPLES);
                AnalysisPipeline.Output out = pipeline.process(frame.clone());
                if (opus.isEmpty()) packetPts = pts;
                opus.add(encoder.encode(out.audio()));
                analysis.add(out.analysis().encode());
                pts += AudioConstants.FRAME_SAMPLES;
                if (opus.size() >= AudioConstants.FRAMES_PER_PACKET) {
                    ClientNet.send(new Payloads.LiveAudio(session, packetPts,
                            opus.toArray(new byte[0][]), analysis.toArray(new byte[0][])));
                    opus.clear();
                    analysis.clear();
                }
            }
        }
    }
}
