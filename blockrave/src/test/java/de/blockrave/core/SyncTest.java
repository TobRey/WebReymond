package de.blockrave.core;

import de.blockrave.core.analysis.AnalysisFrame;
import de.blockrave.core.sync.ClockSync;
import de.blockrave.core.sync.DriftController;
import de.blockrave.core.sync.JitterBuffer;
import de.blockrave.core.sync.TimedFrame;
import org.junit.jupiter.api.Test;

import java.util.Random;

import static org.junit.jupiter.api.Assertions.*;

class SyncTest {
    @Test
    void uhrenabgleichFindetVersatzTrotzJitter() {
        ClockSync cs = new ClockSync();
        long trueOffset = 7_345_000; // Server 7,345 s voraus
        Random rnd = new Random(1);
        long local = 1_000_000;
        for (int i = 0; i < 16; i++) {
            long up = 20_000 + rnd.nextInt(60_000);   // asymmetrische Laufzeiten
            long down = 20_000 + rnd.nextInt(60_000);
            if (i == 5) { up = 20_000; down = 20_500; } // eine gute Messung
            long server = local + up + trueOffset;
            cs.addSample(local, server, local + up + down);
            local += 2_000_000;
        }
        long est = cs.toServer(0);
        assertEquals(trueOffset, est, 3_000, "Versatz auf 3 ms genau");
    }

    @Test
    void jitterBufferSortiertUndSchneidet() {
        JitterBuffer jb = new JitterBuffer(100);
        long now = 1_000_000;
        for (int i = 9; i >= 0; i--) {
            jb.offer(new TimedFrame(1, i, now + i * 20_000L, new byte[1], AnalysisFrame.SILENCE), now);
        }
        assertEquals(10, jb.size());
        assertEquals(0, jb.pollUntil(now).sequence());
        jb.cut(1, now + 100_000);
        assertEquals(4, jb.size());
        // Nach dem Schnitt eintreffende Frames desselben Segments werden verworfen
        assertFalse(jb.offer(new TimedFrame(1, 20, now + 200_000, new byte[1], AnalysisFrame.SILENCE), now));
        // Neues Segment wird angenommen
        assertTrue(jb.offer(new TimedFrame(2, 0, now + 200_000, new byte[1], AnalysisFrame.SILENCE), now));
        // Verspaeteter Frame wird verworfen
        assertFalse(jb.offer(new TimedFrame(2, 1, now - 100_000, new byte[1], AnalysisFrame.SILENCE), now));
    }

    @Test
    void jitterBufferIstBegrenzt() {
        JitterBuffer jb = new JitterBuffer(10);
        for (int i = 0; i < 50; i++) jb.offer(new TimedFrame(1, i, 1_000_000 + i * 20_000L, new byte[0], AnalysisFrame.SILENCE), 0);
        assertEquals(10, jb.size());
        assertEquals(40, jb.droppedOverflow());
    }

    @Test
    void driftKorrekturWeichUndHart() {
        DriftController dc = new DriftController();
        assertEquals(DriftController.Action.NONE, dc.update(1_000).action());
        dc.reset();
        var d = dc.update(30_000); // 30 ms zu frueh
        assertEquals(DriftController.Action.SOFT, d.action());
        assertTrue(d.playbackRate() < 1.0 && d.playbackRate() >= 1.0 - DriftController.MAX_RATE_DEVIATION);
        dc.reset();
        d = dc.update(-30_000);
        assertTrue(d.playbackRate() > 1.0);
        assertEquals(DriftController.Action.HARD, dc.update(500_000).action());
    }
}
