package de.blockrave.core.sync;

import de.blockrave.core.audio.AudioConstants;

import java.util.ArrayList;
import java.util.List;
import java.util.TreeMap;

/**
 * Begrenzter, nach Abspielzeit sortierter Empfangspuffer. Behandelt Segmentwechsel und
 * Schnitte (Pause/Stopp/Weiter) und liefert Frames in Abspielreihenfolge.
 *
 * <p>Threadsicher (Netzwerk-Thread schreibt, Audio-Thread liest).
 */
public final class JitterBuffer {
    private final TreeMap<Long, TimedFrame> frames = new TreeMap<>();
    private final int maxFrames;
    /** Frames ab dieser Serverzeit werden verworfen (Schnitt), Long.MAX_VALUE = kein Schnitt. */
    private long cutFromMicros = Long.MAX_VALUE;
    private int cutStreamId = -1;
    private int droppedLate;
    private int droppedOverflow;

    public JitterBuffer(int maxFrames) {
        this.maxFrames = maxFrames;
    }

    /** Fuegt einen Frame ein. Zu spaete oder abgeschnittene Frames werden verworfen. */
    public synchronized boolean offer(TimedFrame f, long nowServerMicros) {
        if (f.playoutMicros() + AudioConstants.FRAME_MILLIS * 1000L < nowServerMicros) {
            droppedLate++;
            return false;
        }
        if (f.streamId() == cutStreamId && f.playoutMicros() >= cutFromMicros) return false;
        frames.put(f.playoutMicros(), f);
        while (frames.size() > maxFrames) {
            frames.pollLastEntry(); // neueste verwerfen: vorhandene Wiedergabe bleibt stabil
            droppedOverflow++;
        }
        return true;
    }

    /** Verwirft alle Frames eines Segments ab einem Zeitpunkt (Pause/Stopp/naechster Track). */
    public synchronized void cut(int streamId, long fromMicros) {
        cutStreamId = streamId;
        cutFromMicros = fromMicros;
        frames.values().removeIf(f -> f.streamId() == streamId && f.playoutMicros() >= fromMicros);
    }

    /** Entnimmt den naechsten Frame, sofern er spaetestens bis {@code horizonMicros} abgespielt werden soll. */
    public synchronized TimedFrame pollUntil(long horizonMicros) {
        var e = frames.firstEntry();
        if (e == null || e.getKey() > horizonMicros) return null;
        frames.pollFirstEntry();
        return e.getValue();
    }

    public synchronized TimedFrame peek() {
        var e = frames.firstEntry();
        return e == null ? null : e.getValue();
    }

    /** Entfernt alle Frames, die vor {@code beforeMicros} haetten beginnen sollen. */
    public synchronized int dropBefore(long beforeMicros) {
        int n = 0;
        while (!frames.isEmpty() && frames.firstKey() < beforeMicros) {
            frames.pollFirstEntry();
            n++;
        }
        return n;
    }

    public synchronized int size() {
        return frames.size();
    }

    /** Gepufferte Dauer ab der aktuellen Serverzeit in Mikrosekunden. */
    public synchronized long bufferedAheadMicros(long nowServerMicros) {
        if (frames.isEmpty()) return 0;
        return Math.max(0, frames.lastKey() + AudioConstants.FRAME_MILLIS * 1000L - nowServerMicros);
    }

    public synchronized void clear() {
        frames.clear();
    }

    public synchronized List<TimedFrame> snapshot() {
        return new ArrayList<>(frames.values());
    }

    public synchronized int droppedLate() {
        return droppedLate;
    }

    public synchronized int droppedOverflow() {
        return droppedOverflow;
    }
}
