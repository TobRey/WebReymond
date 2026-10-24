package de.blockrave.client.audio;

import de.blockrave.core.audio.AudioConstants;
import de.blockrave.core.audio.OpusFrameDecoder;
import de.blockrave.core.audio.SincResampler;
import de.blockrave.core.sync.ClockSync;
import de.blockrave.core.sync.DriftController;
import de.blockrave.core.sync.JitterBuffer;
import de.blockrave.core.sync.TimedFrame;
import org.lwjgl.BufferUtils;
import org.lwjgl.openal.AL;
import org.lwjgl.openal.AL10;
import org.lwjgl.openal.ALC;
import org.lwjgl.openal.ALC10;
import org.lwjgl.openal.ALCCapabilities;
import org.lwjgl.openal.ALCapabilities;
import org.lwjgl.openal.EXTThreadLocalContext;
import org.lwjgl.openal.SOFTSourceLatency;

import java.nio.ShortBuffer;
import java.util.ArrayDeque;
import java.util.Deque;
import java.util.concurrent.locks.LockSupport;

/**
 * Synchronisierte, raeumliche Wiedergabe des Rave-Streams ueber einen eigenen OpenAL-Kontext auf
 * einem eigenen Thread (der Minecraft-Soundkontext bleibt unberuehrt).
 *
 * <p>Zwei Mono-Quellen an den Lautsprechertuermen: linker Kanal links, rechter Kanal rechts &ndash;
 * kein Signal wird doppelt abgespielt. Die Lautstaerke folgt einer eigenen, sanften Kurve bis zur
 * Hoerreichweite (danach stumm). Jeder Frame wird zu seinem Abspielzeitpunkt auf der Serveruhr
 * hoerbar; Abweichungen werden per minimaler Tempoanpassung (max. 0,6 %) ausgeglichen.
 */
public final class RavePlayer {
    private static final org.slf4j.Logger LOG = org.slf4j.LoggerFactory.getLogger("BlockRave");
    private static final long FRAME = AudioConstants.FRAME_MILLIS * 1000L;
    /** Angestrebte Fuellung der OpenAL-Warteschlange. */
    private static final long TARGET_QUEUE_MICROS = 120_000;
    private static final double MICROS_PER_SAMPLE = 1e6 / AudioConstants.SAMPLE_RATE;

    private final ClockSync clock;
    private final JitterBuffer buffer = new JitterBuffer(500);
    private final Thread thread;
    private volatile boolean running = true;

    // Vom Render-/Client-Thread gesetzte Werte
    private volatile float targetGain;
    private volatile float[] listener = {0, 0, 0, 0, 0, -1, 0, 1, 0};
    private volatile float[] speakers = {0, 0, 0, 0, 0, 0};
    private volatile String deviceName = "";

    // Status fuer die Oberflaeche
    private volatile String error = "";
    private volatile long bufferedMicros;
    private volatile boolean playing;
    private volatile long lastErrorMicros;
    private volatile double lastRate = 1.0;

    // Audio-Thread-Zustand
    private long device, context;
    private int srcL, srcR;
    private boolean hasLatencyExt;
    private final Deque<Chunk> queue = new ArrayDeque<>();
    private final Deque<int[]> freeBuffers = new ArrayDeque<>();
    private final OpusFrameDecoder decoder = new OpusFrameDecoder();
    private final SincResampler resampler = new SincResampler(2, 1, 1, 8);
    private final DriftController drift = new DriftController();
    private final float[] pcm = new float[AudioConstants.FRAME_SAMPLES * 2];
    private final float[] out = new float[AudioConstants.FRAME_SAMPLES * 2 * 2];
    private final ShortBuffer left = BufferUtils.createShortBuffer(AudioConstants.FRAME_SAMPLES * 2);
    private final ShortBuffer right = BufferUtils.createShortBuffer(AudioConstants.FRAME_SAMPLES * 2);
    private int currentStream = -1;
    private long nextInputPlayout;
    private double outPlayout;
    private double rate = 1.0;
    private float appliedGain;
    private int driftCounter;

    private record Chunk(int bufL, int bufR, int samples, double startPlayout, double step) {}

    public RavePlayer(ClockSync clock) {
        this.clock = clock;
        this.thread = new Thread(this::run, "BlockRave-Playback");
        this.thread.setDaemon(true);
        this.thread.setPriority(Thread.MAX_PRIORITY - 1);
    }

    public void start(String device) {
        this.deviceName = device == null ? "" : device;
        thread.start();
    }

    public void offer(TimedFrame f) {
        buffer.offer(f, clock.toServer(ClockSync.nowMicros()));
    }

    public void cut(int streamId, long fromMicros) {
        buffer.cut(streamId, fromMicros);
    }

    public void setGain(float g) {
        targetGain = Math.max(0f, Math.min(1f, g));
    }

    /** Hoererposition und Blickrichtung (x,y,z, vorne x,y,z, oben x,y,z). */
    public void setListener(float[] l) {
        listener = l;
    }

    /** Positionen linker/rechter Lautsprecherturm in Weltkoordinaten. */
    public void setSpeakers(float[] s) {
        speakers = s;
    }

    public String error() {
        return error;
    }

    public long bufferedMicros() {
        return bufferedMicros;
    }

    public boolean isPlaying() {
        return playing;
    }

    /** Zuletzt gemessene Abweichung gehoert - geplant (Mikrosekunden, Diagnose). */
    public long syncErrorMicros() {
        return lastErrorMicros;
    }

    /** Aktuelle Abspielrate der Driftkorrektur (1.0 = normal). */
    public double playbackRate() {
        return lastRate;
    }

    // ------------------------------------------------------------------ Thread

    private void run() {
        try {
            if (!init()) return;
            while (running) {
                try {
                    step();
                } catch (RuntimeException e) {
                    LOG.error("BlockRave: Fehler in der Wiedergabe", e);
                    error = "Wiedergabefehler: " + e.getMessage();
                    reset();
                }
                LockSupport.parkNanos(4_000_000L);
            }
        } finally {
            cleanup();
        }
    }

    private boolean init() {
        try {
            String name = deviceName;
            device = name.isEmpty() ? ALC10.alcOpenDevice((java.nio.ByteBuffer) null) : ALC10.alcOpenDevice(name);
            if (device == 0 && !name.isEmpty()) device = ALC10.alcOpenDevice((java.nio.ByteBuffer) null);
            if (device == 0) {
                error = "Kein Audiogeraet verfuegbar";
                return false;
            }
            ALCCapabilities alc = ALC.createCapabilities(device);
            if (!alc.ALC_EXT_thread_local_context) {
                error = "OpenAL ohne ALC_EXT_thread_local_context – Rave-Audio deaktiviert";
                return false;
            }
            context = ALC10.alcCreateContext(device, (java.nio.IntBuffer) null);
            if (context == 0 || !EXTThreadLocalContext.alcSetThreadContext(context)) {
                error = "OpenAL-Kontext konnte nicht erstellt werden";
                return false;
            }
            ALCapabilities al = AL.createCapabilities(alc);
            AL.setCurrentThread(al);
            hasLatencyExt = al.AL_SOFT_source_latency;
            AL10.alDistanceModel(AL10.AL_NONE); // Lautstaerkekurve rechnen wir selbst
            srcL = AL10.alGenSources();
            srcR = AL10.alGenSources();
            for (int s : new int[]{srcL, srcR}) {
                AL10.alSourcef(s, AL10.AL_ROLLOFF_FACTOR, 0f);
                AL10.alSourcei(s, AL10.AL_SOURCE_RELATIVE, AL10.AL_FALSE);
                AL10.alSourcef(s, AL10.AL_GAIN, 0f);
            }
            LOG.info("BlockRave: Rave-Audio bereit (OpenAL, Latenzabfrage {})", hasLatencyExt ? "ja" : "nein");
            return true;
        } catch (Throwable t) {
            LOG.error("BlockRave: OpenAL-Initialisierung fehlgeschlagen", t);
            error = "OpenAL-Fehler: " + t.getMessage();
            return false;
        }
    }

    private void step() {
        long now = clock.toServer(ClockSync.nowMicros());
        reclaim();
        updateSpatial();
        if (playing) {
            int st = AL10.alGetSourcei(srcL, AL10.AL_SOURCE_STATE);
            if (st != AL10.AL_PLAYING || queue.isEmpty()) {
                // Puffer leergelaufen: neu synchronisieren
                reset();
            } else if (++driftCounter % 5 == 0) {
                correctDrift(now);
            }
        }
        fill(now);
        bufferedMicros = buffer.bufferedAheadMicros(now);
    }

    /** Serverzeit des gerade hoerbaren Samples. */
    private double heardPlayout() {
        Chunk head = queue.peekFirst();
        if (head == null) return Double.NaN;
        double offsetSamples;
        double latencySamples = 0;
        if (hasLatencyExt) {
            double[] v = new double[2];
            SOFTSourceLatency.alGetSourcedvSOFT(srcL, SOFTSourceLatency.AL_SEC_OFFSET_LATENCY_SOFT, v);
            offsetSamples = v[0] * AudioConstants.SAMPLE_RATE;
            latencySamples = v[1] * AudioConstants.SAMPLE_RATE;
        } else {
            offsetSamples = AL10.alGetSourcei(srcL, org.lwjgl.openal.AL11.AL_SAMPLE_OFFSET);
        }
        return head.startPlayout + (offsetSamples - latencySamples) * head.step;
    }

    private void correctDrift(long now) {
        double heard = heardPlayout();
        if (Double.isNaN(heard)) return;
        lastErrorMicros = (long) (heard - now);
        DriftController.Decision d = drift.update(lastErrorMicros);
        switch (d.action()) {
            case HARD -> reset();
            case SOFT -> rate = d.playbackRate();
            case NONE -> rate = 1.0;
        }
        resampler.setRatio(rate, 1.0);
        lastRate = rate;
    }

    private void fill(long now) {
        double queuedEnd = queue.isEmpty() ? Double.NaN : queue.peekLast().startPlayout + queue.peekLast().samples * queue.peekLast().step;
        while (playing ? queuedEnd - now < TARGET_QUEUE_MICROS : true) {
            if (!playing) {
                if (!tryStart(now)) return;
                queuedEnd = queue.peekLast().startPlayout + queue.peekLast().samples * queue.peekLast().step;
                continue;
            }
            if (resampler.available() < AudioConstants.FRAME_SAMPLES / 2) {
                if (!feedInput(now)) break;
                continue;
            }
            int n = resampler.pull(out, 0, AudioConstants.FRAME_SAMPLES);
            if (n <= 0) break;
            double step = MICROS_PER_SAMPLE * rate;
            queueChunk(out, n, outPlayout, step);
            outPlayout += n * step;
            queuedEnd = outPlayout;
        }
    }

    /** Naechsten Frame in den Resampler geben (mit Luecken-Verschleierung). */
    private boolean feedInput(long now) {
        TimedFrame f = buffer.peek();
        if (f != null && f.playoutMicros() < nextInputPlayout - FRAME / 2) {
            buffer.pollUntil(Long.MAX_VALUE); // ueberholt/doppelt
            return true;
        }
        if (f == null || f.playoutMicros() > nextInputPlayout + FRAME + FRAME / 2) {
            // Luecke: kurz mit Verlustverschleierung, sonst Stille, solange noch Daten folgen koennen
            if (nextInputPlayout - now > TARGET_QUEUE_MICROS + 60_000) return false;
            if (f == null && nextInputPlayout - now > 0) {
                // nichts mehr da: Warteschlange einfach auslaufen lassen
                return false;
            }
            long gap = f == null ? Long.MAX_VALUE : f.playoutMicros() - nextInputPlayout;
            int n;
            if (gap <= 3 * FRAME) {
                n = decoder.decode(null, pcm); // kurze Luecke: Opus-Verlustverschleierung
            } else {
                java.util.Arrays.fill(pcm, 0f); // laengere Luecke (z.B. Pause -> Fortsetzen): Stille
                n = AudioConstants.FRAME_SAMPLES;
            }
            resampler.push(pcm, 0, n);
            nextInputPlayout += FRAME;
            return true;
        }
        buffer.pollUntil(Long.MAX_VALUE);
        if (f.streamId() != currentStream) {
            decoder.reset();
            currentStream = f.streamId();
        }
        int n = decoder.decode(f.opus(), pcm);
        resampler.push(pcm, 0, n);
        nextInputPlayout = f.playoutMicros() + FRAME;
        return true;
    }

    private boolean tryStart(long now) {
        buffer.dropBefore(now + 30_000);
        TimedFrame f = buffer.peek();
        if (f == null || f.playoutMicros() > now + TARGET_QUEUE_MICROS + 40_000) return false;
        if (appliedGain <= 0f && targetGain <= 0f) {
            // ausser Hoerweite: nichts abspielen, aber Puffer aktuell halten
            buffer.dropBefore(now + FRAME);
            return false;
        }
        resampler.reset();
        rate = 1.0;
        resampler.setRatio(1.0, 1.0);
        drift.reset();
        decoder.reset();
        currentStream = f.streamId();
        // Stille bis zum Abspielzeitpunkt des ersten Frames
        long lead = Math.max(0, f.playoutMicros() - now - 20_000);
        int silence = (int) Math.min(AudioConstants.FRAME_SAMPLES * 8L, lead / (long) MICROS_PER_SAMPLE);
        double start = f.playoutMicros() - silence * MICROS_PER_SAMPLE;
        if (silence > 0) {
            java.util.Arrays.fill(out, 0, silence * 2, 0f);
            queueChunk(out, silence, start, MICROS_PER_SAMPLE);
        }
        outPlayout = f.playoutMicros();
        nextInputPlayout = f.playoutMicros();
        feedInput(now);
        int n = resampler.pull(out, 0, AudioConstants.FRAME_SAMPLES);
        if (n > 0) {
            queueChunk(out, n, outPlayout, MICROS_PER_SAMPLE);
            outPlayout += n * MICROS_PER_SAMPLE;
        }
        if (queue.isEmpty()) return false;
        AL10.alSourcePlayv(new int[]{srcL, srcR});
        playing = true;
        error = "";
        return true;
    }

    private void queueChunk(float[] data, int samples, double startPlayout, double step) {
        int[] bufs = freeBuffers.isEmpty() ? new int[]{AL10.alGenBuffers(), AL10.alGenBuffers()} : freeBuffers.pollFirst();
        left.clear();
        right.clear();
        for (int i = 0; i < samples; i++) {
            left.put(toShort(data[i * 2]));
            right.put(toShort(data[i * 2 + 1]));
        }
        left.flip();
        right.flip();
        AL10.alBufferData(bufs[0], AL10.AL_FORMAT_MONO16, left, AudioConstants.SAMPLE_RATE);
        AL10.alBufferData(bufs[1], AL10.AL_FORMAT_MONO16, right, AudioConstants.SAMPLE_RATE);
        AL10.alSourceQueueBuffers(srcL, bufs[0]);
        AL10.alSourceQueueBuffers(srcR, bufs[1]);
        queue.addLast(new Chunk(bufs[0], bufs[1], samples, startPlayout, step));
    }

    private static short toShort(float v) {
        int s = Math.round(v * 32767f);
        return (short) Math.max(-32768, Math.min(32767, s));
    }

    private void reclaim() {
        if (srcL == 0) return;
        int done = Math.min(AL10.alGetSourcei(srcL, AL10.AL_BUFFERS_PROCESSED), AL10.alGetSourcei(srcR, AL10.AL_BUFFERS_PROCESSED));
        for (int i = 0; i < done && !queue.isEmpty(); i++) {
            Chunk c = queue.pollFirst();
            AL10.alSourceUnqueueBuffers(srcL);
            AL10.alSourceUnqueueBuffers(srcR);
            if (freeBuffers.size() < 64) freeBuffers.addLast(new int[]{c.bufL, c.bufR});
            else {
                AL10.alDeleteBuffers(c.bufL);
                AL10.alDeleteBuffers(c.bufR);
            }
        }
    }

    private void reset() {
        if (srcL == 0) return;
        AL10.alSourceStopv(new int[]{srcL, srcR});
        reclaim();
        // nach dem Stoppen gelten alle Puffer als verarbeitet
        while (!queue.isEmpty()) {
            Chunk c = queue.pollFirst();
            AL10.alSourceUnqueueBuffers(srcL);
            AL10.alSourceUnqueueBuffers(srcR);
            freeBuffers.addLast(new int[]{c.bufL, c.bufR});
        }
        playing = false;
        currentStream = -1;
    }

    private void updateSpatial() {
        float[] l = listener;
        AL10.alListener3f(AL10.AL_POSITION, l[0], l[1], l[2]);
        AL10.alListenerfv(AL10.AL_ORIENTATION, new float[]{l[3], l[4], l[5], l[6], l[7], l[8]});
        float[] s = speakers;
        AL10.alSource3f(srcL, AL10.AL_POSITION, s[0], s[1], s[2]);
        AL10.alSource3f(srcR, AL10.AL_POSITION, s[3], s[4], s[5]);
        // sanfte Lautstaerkeaenderung ohne Knacken
        float g = targetGain;
        appliedGain += (g - appliedGain) * 0.15f;
        if (Math.abs(g - appliedGain) < 0.001f) appliedGain = g;
        AL10.alSourcef(srcL, AL10.AL_GAIN, appliedGain);
        AL10.alSourcef(srcR, AL10.AL_GAIN, appliedGain);
    }

    private void cleanup() {
        try {
            if (srcL != 0) {
                reset();
                for (int[] b : freeBuffers) AL10.alDeleteBuffers(b);
                freeBuffers.clear();
                AL10.alDeleteSources(new int[]{srcL, srcR});
            }
            if (context != 0) {
                EXTThreadLocalContext.alcSetThreadContext(0L);
                ALC10.alcDestroyContext(context);
            }
            if (device != 0) ALC10.alcCloseDevice(device);
        } catch (Throwable t) {
            LOG.warn("BlockRave: Fehler beim Freigeben von OpenAL", t);
        }
        srcL = srcR = 0;
        context = device = 0;
    }

    public void clearBuffer() {
        buffer.clear();
    }

    public void shutdown() {
        running = false;
        try {
            thread.join(2000);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    /**
     * Lautstaerkekurve: bis 16 Bloecke voll, dann sanft (Kosinus) bis zur Reichweite auf 0.
     */
    public static float distanceGain(double distance, double range) {
        double full = Math.min(16, range * 0.25);
        if (distance <= full) return 1f;
        if (distance >= range) return 0f;
        double t = (distance - full) / (range - full);
        return (float) (0.5 * (1 + Math.cos(Math.PI * t)));
    }
}
