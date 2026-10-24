package de.blockrave.client.audio;

import de.blockrave.core.analysis.AnalysisFrame;
import de.blockrave.core.show.ShowInput;

import java.util.Map;
import java.util.concurrent.ConcurrentSkipListMap;
import java.util.function.BiConsumer;

/**
 * Zeitleiste der Analysedaten nach Abspielzeit (Serveruhr). Die Lichtshow liest hier den Frame,
 * der gerade zu hoeren ist &ndash; nicht den zuletzt empfangenen. Dadurch bleiben Licht und
 * gepuffertes Audio (auch Live) synchron. Threadsicher (Netzwerk schreibt, Render liest).
 */
public final class ShowTimeline {
    private static final long FRAME = 20_000;
    private static final long KEEP_PAST = 10_000_000;

    public record Entry(int streamId, AnalysisFrame frame) {}

    private final ConcurrentSkipListMap<Long, Entry> frames = new ConcurrentSkipListMap<>();

    public void add(int streamId, long playoutMicros, AnalysisFrame f) {
        frames.put(playoutMicros, new Entry(streamId, f));
        if (frames.size() > 4000) frames.pollFirstEntry();
    }

    public void cut(int streamId, long fromMicros) {
        frames.tailMap(fromMicros, true).entrySet().removeIf(e -> e.getValue().streamId() == streamId);
    }

    public void clear() {
        frames.clear();
    }

    /** Fuellt die Show-Eingabe fuer den Zeitpunkt {@code now} (Serveruhr). */
    public void fill(long now, ShowInput in) {
        frames.headMap(now - KEEP_PAST).clear();
        in.timeMicros = now;
        Map.Entry<Long, Entry> cur = frames.floorEntry(now);
        if (cur == null || now - cur.getKey() > FRAME * 3) {
            in.active = false;
            in.frame = AnalysisFrame.SILENCE;
            in.sinceBeatMicros = in.sinceBassMicros = in.sinceOnsetMicros = in.sincePeakMicros = ShowInput.NEVER;
            return;
        }
        in.active = true;
        AnalysisFrame f = cur.getValue().frame();
        in.frame = f;
        // Kontinuierliche Beatposition: zwischen Ende des vorherigen und Ende dieses Frames interpolieren
        double end = f.beatIndex() + f.beatPhaseF();
        Map.Entry<Long, Entry> prev = frames.lowerEntry(cur.getKey());
        double start = end;
        if (prev != null && cur.getKey() - prev.getKey() <= FRAME * 2) {
            AnalysisFrame pf = prev.getValue().frame();
            start = pf.beatIndex() + pf.beatPhaseF();
            if (end < start || end - start > 1.0) start = end; // Sprung (neues Tempo/Segment)
        }
        double frac = Math.min(1.0, Math.max(0.0, (now - cur.getKey()) / (double) FRAME));
        in.beatPos = start + (end - start) * frac;
        in.sinceBeatMicros = in.sinceBassMicros = in.sinceOnsetMicros = in.sincePeakMicros = ShowInput.NEVER;
        int found = 0;
        for (Map.Entry<Long, Entry> e : frames.headMap(now, true).descendingMap().entrySet()) {
            long since = now - e.getKey();
            if (since > 2_500_000 || found == 0b1111) break;
            AnalysisFrame a = e.getValue().frame();
            if ((found & 1) == 0 && a.has(AnalysisFrame.BEAT)) { in.sinceBeatMicros = since; found |= 1; }
            if ((found & 2) == 0 && a.has(AnalysisFrame.BASS_HIT)) { in.sinceBassMicros = since; found |= 2; }
            if ((found & 4) == 0 && a.has(AnalysisFrame.ONSET)) { in.sinceOnsetMicros = since; found |= 4; }
            if ((found & 8) == 0 && a.has(AnalysisFrame.PEAK)) { in.sincePeakMicros = since; found |= 8; }
        }
    }

    /** Ruft fuer alle Frames mit Abspielzeit in (from, to] den Verbraucher auf (fuer einmalige Ereignisse). */
    public void forEachBetween(long from, long to, BiConsumer<Long, AnalysisFrame> c) {
        if (to <= from) return;
        for (Map.Entry<Long, Entry> e : frames.subMap(from, false, to, true).entrySet()) c.accept(e.getKey(), e.getValue().frame());
    }
}
