package de.blockrave.core;

import de.blockrave.core.stage.LocalDir;
import de.blockrave.core.stage.PartType;
import de.blockrave.core.stage.StageLayout;
import de.blockrave.core.stage.StagePart;
import de.blockrave.core.stage.StageTransform;
import org.junit.jupiter.api.Test;

import java.util.HashSet;
import java.util.Set;

import static org.junit.jupiter.api.Assertions.*;

class StageTest {
    @Test
    void bauplanIstKonsistent() {
        StageLayout l = StageLayout.get();
        Set<Long> keys = new HashSet<>();
        int booth = 0, speakers = 0, leds = 0;
        for (StagePart p : l.parts()) {
            assertTrue(keys.add(StageLayout.key(p.x(), p.y(), p.z())), "doppelte Position");
            if (p.type() == PartType.DJ_BOOTH) booth++;
            if (p.type() == PartType.SPEAKER) speakers++;
            if (p.type() == PartType.LED_PANEL) leds++;
        }
        assertEquals(1, booth);
        assertEquals(288, speakers);
        assertTrue(leds > 200);
        assertNotNull(l.boothLocal());
        // Boden ist vollstaendig belegt (keine Loecher ins Gelaende)
        for (int x = 0; x < StageLayout.WIDTH; x++)
            for (int z = 0; z < StageLayout.DEPTH; z++) assertNotNull(l.partAt(x, 0, z));
        System.out.println("Bauteile: " + l.parts().size() + " von " + StageLayout.volume() + " Positionen");
        assertEquals(18, l.fixtures().movingHeads().size());
    }

    @Test
    void drehungenSindBijektivUndPassenZurGroesse() {
        for (int rot = 0; rot < 4; rot++) {
            StageTransform t = new StageTransform(100, 64, -50, rot);
            assertEquals(100, t.worldX(StageLayout.ANCHOR_X, 0));
            assertEquals(-50, t.worldZ(StageLayout.ANCHOR_X, 0));
            Set<Long> seen = new HashSet<>();
            for (int x = 0; x < StageLayout.WIDTH; x++)
                for (int z = 0; z < StageLayout.DEPTH; z++)
                    assertTrue(seen.add(((long) t.worldX(x, z) << 32) ^ (t.worldZ(x, z) & 0xFFFFFFFFL)));
            int[] b = t.bounds();
            int sx = b[3] - b[0] + 1, sz = b[5] - b[2] + 1;
            if (rot % 2 == 0) {
                assertEquals(35, sx);
                assertEquals(20, sz);
            } else {
                assertEquals(20, sx);
                assertEquals(35, sz);
            }
            assertEquals(19, b[4] - b[1] + 1);
            // toWorld der Blockmitte stimmt mit worldX/Z ueberein
            double[] w = t.toWorld(3.5, 0, 9.5);
            assertEquals(t.worldX(3, 9) + 0.5, w[0], 1e-9);
            assertEquals(t.worldZ(3, 9) + 0.5, w[2], 1e-9);
        }
    }

    @Test
    void buehneLiegtVorDemSpieler() {
        // Spieler blickt nach Sueden (+z): Buehne erstreckt sich nach +z, rechts aus Spielersicht ist -x
        StageTransform t = new StageTransform(0, 0, 0, 0);
        assertEquals(19, t.worldZ(StageLayout.ANCHOR_X, 19));
        assertTrue(t.worldX(34, 0) < 0);
        assertEquals(2, t.worldHorizontalIndex(LocalDir.FRONT)); // Lautsprecher zeigen nach Norden zum Publikum
    }
}
