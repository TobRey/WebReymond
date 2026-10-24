package de.blockrave.audio;

import de.blockrave.BlockRave;
import de.blockrave.core.analysis.AnalysisFrame;
import de.blockrave.core.analysis.AnalysisPipeline;
import de.blockrave.core.audio.AudioConstants;
import de.blockrave.core.audio.Mp3PcmSource;
import de.blockrave.core.audio.OpusFrameEncoder;
import de.blockrave.network.Payloads;
import net.minecraft.network.protocol.common.custom.CustomPacketPayload;
import net.minecraft.server.level.ServerPlayer;
import de.blockrave.network.Net;

import java.io.IOException;
import java.nio.file.Path;
import java.util.ArrayDeque;
import java.util.ArrayList;
import java.util.Deque;
import java.util.List;
import java.util.UUID;
import java.util.concurrent.locks.LockSupport;

/**
 * Serverseitige Audio-Engine auf einem eigenen Thread (nie auf dem Server-Tick).
 *
 * <p>MP3: Datei wird schrittweise decodiert, auf 48 kHz gebracht, analysiert, mit Opus codiert und
 * mit absoluten Abspielzeitpunkten (Serveruhr) an alle Zuhoerer in Reichweite verteilt &ndash; etwa
 * eine Sekunde vor der Abspielzeit. Live: Frames des Senders werden mit Abspielzeitpunkten versehen
 * und weitergeleitet.
 *
 * <p>Es gibt immer hoechstens eine aktive Quelle.
 */
public final class AudioEngine {
    private static final long FRAME_MICROS = AudioConstants.FRAME_MILLIS * 1000L;
    private static final long CUT_MARGIN_MICROS = 150_000;
    private static final long HISTORY_MICROS = 2_500_000;

    public interface Callbacks {
        /** MP3-Titel ist vollstaendig ausgeliefert; {@code endMicros} = Abspielende auf der Serveruhr. */
        void onTrackEnded(int streamId, String trackId, long endMicros);

        void onTrackFailed(String trackId, String message);
    }

    private final Object lock = new Object();
    private final Callbacks callbacks;
    private final Thread thread;
    private volatile boolean running = true;

    private volatile List<ServerPlayer> audioListeners = List.of();
    private volatile List<ServerPlayer> showListeners = List.of();
    private volatile int opusBitrate = 160_000;
    private volatile long mp3LeadMicros = 1_000_000;
    private volatile long liveDelayMicros = 1_500_000;

    private int nextStreamId = 1;
    private Mp3Job mp3;
    private LiveJob live;
    private final Deque<Payloads.AudioFrames> history = new ArrayDeque<>();
    private final java.util.concurrent.atomic.AtomicLong framesProduced = new java.util.concurrent.atomic.AtomicLong();

    public AudioEngine(Callbacks callbacks) {
        this.callbacks = callbacks;
        this.thread = new Thread(this::run, "BlockRave-Audio");
        this.thread.setDaemon(true);
        this.thread.setPriority(Thread.NORM_PRIORITY + 1);
        this.thread.start();
    }

    public void configure(int bitrate, long mp3LeadMicros, long liveDelayMicros) {
        this.opusBitrate = bitrate;
        this.mp3LeadMicros = mp3LeadMicros;
        this.liveDelayMicros = liveDelayMicros;
    }

    /** Anzahl seit dem Start erzeugter MP3-Frames (Diagnose/Tests). */
    public long framesProduced() {
        return framesProduced.get();
    }

    public long liveDelayMicros() {
        return liveDelayMicros;
    }

    public void setListeners(List<ServerPlayer> audio, List<ServerPlayer> show) {
        this.audioListeners = List.copyOf(audio);
        this.showListeners = List.copyOf(show);
    }

    // ================================================================== MP3

    private final class Mp3Job {
        final String trackId;
        final Path file;
        Mp3PcmSource source;
        final AnalysisPipeline pipeline = new AnalysisPipeline();
        OpusFrameEncoder encoder;
        int streamId;
        int seq;
        /** Abspielzeit des naechsten zu erzeugenden Frames. */
        long nextPlayout;
        /** Titelposition (Samples) des naechsten zu erzeugenden Frames. */
        long position;
        boolean sourceEof;
        int flushFrames = AnalysisPipeline.DELAY_FRAMES;
        boolean paused;
        boolean finished;
        /** Bereits erzeugte, ggf. noch nicht gehoerte Frames (fuer Pause/Fortsetzen). */
        final Deque<Produced> recent = new ArrayDeque<>();
        /** Nach Pause erneut auszuliefernde Frames. */
        final Deque<Produced> replay = new ArrayDeque<>();
        final List<Produced> packet = new ArrayList<>(AudioConstants.FRAMES_PER_PACKET);

        Mp3Job(String trackId, Path file) {
            this.trackId = trackId;
            this.file = file;
        }
    }

    private record Produced(float[] pcm, AnalysisFrame analysis, long position, long playout, byte[] opus) {}

    /**
     * Startet einen Titel. {@code startMicros} ist der Abspielzeitpunkt des Titelanfangs.
     * Ein laufender MP3-Titel wird vorher ab {@code startMicros} abgeschnitten.
     */
    public void startTrack(String trackId, Path file, long startMicros) throws IOException {
        Mp3PcmSource src = new Mp3PcmSource(file); // Fehler hier -> Aufrufer meldet
        synchronized (lock) {
            stopMp3Locked(startMicros);
            Mp3Job job = new Mp3Job(trackId, file);
            job.source = src;
            job.streamId = nextStreamId++;
            job.encoder = new OpusFrameEncoder(opusBitrate, 5);
            job.nextPlayout = startMicros;
            mp3 = job;
        }
    }

    /** Pausiert; liefert die Titelposition (Mikrosekunden), an der fortgesetzt wird. */
    public long pause(long nowMicros) {
        synchronized (lock) {
            Mp3Job job = mp3;
            if (job == null || job.paused) return job == null ? 0 : AudioConstants.samplesToMicros(firstReplayPosition(job));
            long cut = nowMicros + CUT_MARGIN_MICROS;
            flushPacket(job);
            // Alle noch nicht gehoerten Frames fuer die Fortsetzung zurueckhalten
            List<Produced> keep = new ArrayList<>();
            for (Produced p : job.recent) if (p.playout >= cut) keep.add(p);
            for (int i = keep.size() - 1; i >= 0; i--) job.replay.addFirst(keep.get(i));
            job.recent.clear();
            job.paused = true;
            broadcastCut(job.streamId, cut);
            return AudioConstants.samplesToMicros(firstReplayPosition(job));
        }
    }

    private static long firstReplayPosition(Mp3Job job) {
        return job.replay.isEmpty() ? job.position : job.replay.peekFirst().position;
    }

    /** Setzt fort; liefert die Serverzeit, zu der Titelposition 0 zu hoeren waere. */
    public long resume(long nowMicros) {
        synchronized (lock) {
            Mp3Job job = mp3;
            if (job == null || !job.paused) return 0;
            long start = nowMicros + 300_000;
            job.streamId = nextStreamId++;
            job.seq = 0;
            job.encoder = new OpusFrameEncoder(opusBitrate, 5);
            job.nextPlayout = start;
            job.paused = false;
            long pos = firstReplayPosition(job);
            return start - AudioConstants.samplesToMicros(pos);
        }
    }

    /** Beendet die MP3-Wiedergabe ab {@code cutMicros}. */
    public void stopMp3(long cutMicros) {
        synchronized (lock) {
            stopMp3Locked(cutMicros);
        }
    }

    private void stopMp3Locked(long cutMicros) {
        Mp3Job job = mp3;
        if (job == null) return;
        flushPacket(job);
        if (!job.paused) broadcastCut(job.streamId, cutMicros);
        closeQuietly(job);
        mp3 = null;
    }

    public boolean isMp3Active() {
        synchronized (lock) {
            return mp3 != null;
        }
    }

    private void closeQuietly(Mp3Job job) {
        try {
            if (job.source != null) job.source.close();
        } catch (IOException ignored) {
        }
    }

    private void produceMp3(long now) {
        Mp3Job job = mp3;
        if (job == null || job.paused || job.finished) return;
        float[] buf = new float[AudioConstants.FRAME_SAMPLES * 2];
        int budget = 6; // max. Frames pro Durchlauf, Sperre kurz halten
        while (budget-- > 0 && job.nextPlayout < now + mp3LeadMicros) {
            Produced p;
            if (!job.replay.isEmpty()) {
                Produced old = job.replay.pollFirst();
                p = new Produced(old.pcm, old.analysis, old.position, job.nextPlayout, job.encoder.encode(old.pcm));
            } else {
                if (!job.sourceEof) {
                    int n;
                    try {
                        n = job.source.read(buf, AudioConstants.FRAME_SAMPLES);
                    } catch (IOException e) {
                        BlockRave.LOG.warn("BlockRave: Decodierfehler in Titel {}: {}", job.trackId, e.getMessage());
                        n = 0;
                    }
                    if (n < AudioConstants.FRAME_SAMPLES) {
                        java.util.Arrays.fill(buf, n * 2, buf.length, 0f);
                        job.sourceEof = true;
                        if (n == 0) {
                            if (job.flushFrames-- <= 0) {
                                finishTrack(job);
                                return;
                            }
                        }
                    }
                } else {
                    java.util.Arrays.fill(buf, 0f);
                    if (job.flushFrames-- <= 0) {
                        finishTrack(job);
                        return;
                    }
                }
                AnalysisPipeline.Output out = job.pipeline.process(buf.clone());
                p = new Produced(out.audio(), out.analysis(), job.position, job.nextPlayout, job.encoder.encode(out.audio()));
                job.position += AudioConstants.FRAME_SAMPLES;
            }
            job.recent.addLast(new Produced(p.pcm, p.analysis, p.position, p.playout, null));
            while (!job.recent.isEmpty() && job.recent.peekFirst().playout < now - FRAME_MICROS) job.recent.pollFirst();
            job.nextPlayout += FRAME_MICROS;
            framesProduced.incrementAndGet();
            job.packet.add(p);
            if (job.packet.size() >= AudioConstants.FRAMES_PER_PACKET) flushPacket(job);
        }
    }

    private void finishTrack(Mp3Job job) {
        flushPacket(job);
        job.finished = true;
        long end = job.nextPlayout;
        int sid = job.streamId;
        String id = job.trackId;
        callbacks.onTrackEnded(sid, id, end);
    }

    private void flushPacket(Mp3Job job) {
        if (job.packet.isEmpty()) return;
        int n = job.packet.size();
        byte[][] opus = new byte[n][];
        byte[][] an = new byte[n][];
        for (int i = 0; i < n; i++) {
            opus[i] = job.packet.get(i).opus;
            an[i] = job.packet.get(i).analysis.encode();
        }
        Payloads.AudioFrames frames = new Payloads.AudioFrames(job.streamId, job.seq, job.packet.get(0).playout,
                (int) FRAME_MICROS, true, opus, an);
        job.seq += n;
        job.packet.clear();
        distribute(frames, null);
    }

    // ================================================================== Live

    private static final class LiveJob {
        final int sessionId;
        final UUID sender;
        int streamId;
        int seq;
        boolean anchored;
        long anchor; // Abspielzeit von Sample-Position 0
        double margin;
        long lastArrival;
        long lastEndPlayout;
        final long startedAt;

        LiveJob(int sessionId, UUID sender, long now) {
            this.sessionId = sessionId;
            this.sender = sender;
            this.startedAt = now;
            this.lastArrival = now;
        }
    }

    public void startLive(int sessionId, UUID sender) {
        synchronized (lock) {
            stopLiveLocked(ServerClock.nowMicros() + 50_000);
            LiveJob job = new LiveJob(sessionId, sender, ServerClock.nowMicros());
            job.streamId = nextStreamId++;
            live = job;
        }
    }

    public void stopLive(long cutMicros) {
        synchronized (lock) {
            stopLiveLocked(cutMicros);
        }
    }

    private void stopLiveLocked(long cutMicros) {
        if (live == null) return;
        broadcastCut(live.streamId, cutMicros);
        live = null;
    }

    /** Zeitpunkt des letzten Live-Pakets (fuer Zeitueberschreitung), oder -1. */
    public long liveLastArrival() {
        synchronized (lock) {
            return live == null ? -1 : live.lastArrival;
        }
    }

    /**
     * Live-Frames vom Sender (Netzwerk-Thread). Nur der freigegebene Spieler mit passender
     * Sitzungs-ID wird akzeptiert.
     *
     * @return false, wenn das Paket abgelehnt wurde
     */
    public boolean onLiveAudio(UUID player, Payloads.LiveAudio p) {
        long now = ServerClock.nowMicros();
        int n = p.opus().length;
        if (n == 0 || n != p.analysis().length) return false;
        for (int i = 0; i < n; i++) {
            if (p.opus()[i].length == 0 || p.analysis()[i].length != AnalysisFrame.ENCODED_SIZE) return false;
        }
        synchronized (lock) {
            LiveJob job = live;
            if (job == null || job.sessionId != p.sessionId() || !job.sender.equals(player)) return false;
            long ptsMicros = AudioConstants.samplesToMicros(p.firstPts());
            // Schutz: nicht schneller als Echtzeit (+3 s Toleranz) annehmen
            if (ptsMicros > (now - job.startedAt) + 3_000_000) return false;
            if (!job.anchored) {
                job.anchor = now + liveDelayMicros - ptsMicros;
                job.anchored = true;
                job.margin = liveDelayMicros;
            }
            long playout = job.anchor + ptsMicros;
            long margin = playout - now;
            job.margin += (margin - job.margin) * 0.05;
            if (margin < 100_000 || margin > liveDelayMicros * 3) {
                // Sender stockte oder Uhren laufen stark auseinander: neues Segment, neu verankern
                broadcastCut(job.streamId, Math.max(job.lastEndPlayout, now));
                job.streamId = nextStreamId++;
                job.seq = 0;
                job.anchor = now + liveDelayMicros - ptsMicros;
                job.margin = liveDelayMicros;
                playout = job.anchor + ptsMicros;
            } else if (job.margin < liveDelayMicros * 0.6) {
                job.anchor += 100; // sanft mehr Puffer (0,25 %), Clients gleichen per Resampling aus
            } else if (job.margin > liveDelayMicros * 1.6) {
                job.anchor -= 100;
            }
            job.lastArrival = now;
            job.lastEndPlayout = playout + n * FRAME_MICROS;
            Payloads.AudioFrames frames = new Payloads.AudioFrames(job.streamId, job.seq, playout, (int) FRAME_MICROS, true,
                    p.opus(), p.analysis());
            job.seq += n;
            distribute(frames, null);
            return true;
        }
    }

    // ================================================================== Verteilung

    private void distribute(Payloads.AudioFrames frames, ServerPlayer only) {
        history.addLast(frames);
        long cutoff = ServerClock.nowMicros() - HISTORY_MICROS;
        while (!history.isEmpty() && history.peekFirst().firstPlayoutMicros() < cutoff) history.pollFirst();
        while (history.size() > 400) history.pollFirst();
        for (ServerPlayer sp : audioListeners) send(sp, frames);
        List<ServerPlayer> show = showListeners;
        if (!show.isEmpty()) {
            byte[][] empty = new byte[frames.opus().length][];
            for (int i = 0; i < empty.length; i++) empty[i] = new byte[0];
            Payloads.AudioFrames light = new Payloads.AudioFrames(frames.streamId(), frames.firstSeq(),
                    frames.firstPlayoutMicros(), frames.frameMicros(), false, empty, frames.analysis());
            for (ServerPlayer sp : show) send(sp, light);
        }
    }

    private static void send(ServerPlayer sp, CustomPacketPayload payload) {
        try {
            if (!sp.hasDisconnected()) Net.send(sp, payload);
        } catch (RuntimeException e) {
            BlockRave.LOG.debug("BlockRave: Senden an {} fehlgeschlagen: {}", sp.getGameProfile().getName(), e.toString());
        }
    }

    private void broadcastCut(int streamId, long cutMicros) {
        history.removeIf(f -> f.streamId() == streamId && f.firstPlayoutMicros() >= cutMicros);
        Payloads.StreamCut cut = new Payloads.StreamCut(streamId, cutMicros);
        for (ServerPlayer sp : audioListeners) send(sp, cut);
        for (ServerPlayer sp : showListeners) send(sp, cut);
    }

    /** Neu in Reichweite: noch nicht gehoerte Frames sofort nachliefern (Einstieg an aktueller Position). */
    public void catchUp(ServerPlayer player, boolean withAudio) {
        long now = ServerClock.nowMicros();
        List<Payloads.AudioFrames> toSend = new ArrayList<>();
        synchronized (lock) {
            for (Payloads.AudioFrames f : history) {
                long end = f.firstPlayoutMicros() + (long) f.frameMicros() * f.opus().length;
                if (end > now + 20_000) toSend.add(f);
            }
        }
        for (Payloads.AudioFrames f : toSend) {
            if (withAudio) {
                send(player, f);
            } else {
                byte[][] empty = new byte[f.opus().length][];
                for (int i = 0; i < empty.length; i++) empty[i] = new byte[0];
                send(player, new Payloads.AudioFrames(f.streamId(), f.firstSeq(), f.firstPlayoutMicros(), f.frameMicros(),
                        false, empty, f.analysis()));
            }
        }
    }

    // ================================================================== Thread

    private void run() {
        while (running) {
            try {
                long now = ServerClock.nowMicros();
                synchronized (lock) {
                    produceMp3(now);
                }
            } catch (RuntimeException e) {
                BlockRave.LOG.error("BlockRave: Fehler in der Audio-Engine", e);
                String failed;
                synchronized (lock) {
                    failed = mp3 != null ? mp3.trackId : null;
                    if (mp3 != null) {
                        closeQuietly(mp3);
                        mp3 = null;
                    }
                }
                if (failed != null) callbacks.onTrackFailed(failed, e.toString());
            }
            LockSupport.parkNanos(5_000_000L);
        }
    }

    public void shutdown() {
        running = false;
        synchronized (lock) {
            if (mp3 != null) closeQuietly(mp3);
            mp3 = null;
            live = null;
            history.clear();
        }
        try {
            thread.join(2000);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }
}
