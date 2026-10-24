package de.blockrave.core;

import de.blockrave.core.analysis.AnalysisFrame;
import de.blockrave.core.show.ShowInput;
import de.blockrave.core.show.ShowProgram;
import de.blockrave.core.stage.StageLayout;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.*;

class ShowTest {
    private static ShowInput input(int energyClass, int intensity, double beatPos) {
        ShowInput in = new ShowInput();
        in.active = true;
        in.timeMicros = 5_000_000;
        in.beatPos = beatPos;
        in.frame = new AnalysisFrame(AnalysisFrame.TEMPO_LOCKED, (int) beatPos, 0, 1280, 200, intensity, 180,
                new byte[]{(byte) 200, 100, 80, 60}, 1 | (energyClass << 4));
        in.sinceBassMicros = 50_000;
        return in;
    }

    @Test
    void gleicheEingabeErgibtGleicheShow() {
        var fx = StageLayout.get().fixtures();
        ShowProgram a = new ShowProgram(fx), b = new ShowProgram(fx);
        var oa = new ShowProgram.Output(fx);
        var ob = new ShowProgram.Output(fx);
        a.evaluate(input(2, 230, 37.25), oa);
        b.evaluate(input(2, 230, 37.25), ob);
        for (int i = 0; i < oa.heads.length; i++) {
            assertEquals(oa.heads[i].yaw, ob.heads[i].yaw);
            assertEquals(oa.heads[i].color, ob.heads[i].color);
        }
        assertEquals(a.ledColor(0, 0.3, 0.6), b.ledColor(0, 0.3, 0.6));
    }

    @Test
    void ruhigBewegtSichLangsamerAlsEnergiegeladen() {
        var fx = StageLayout.get().fixtures();
        ShowProgram p = new ShowProgram(fx);
        var o1 = new ShowProgram.Output(fx);
        var o2 = new ShowProgram.Output(fx);
        double calm = 0, wild = 0;
        for (double beat = 0; beat < 16; beat += 0.25) {
            p.evaluate(input(0, 40, beat), o1);
            p.evaluate(input(0, 40, beat + 0.25), o2);
            calm += Math.abs(o2.heads[0].yaw - o1.heads[0].yaw) + Math.abs(o2.heads[0].pitch - o1.heads[0].pitch);
            p.evaluate(input(2, 240, beat), o1);
            p.evaluate(input(2, 240, beat + 0.25), o2);
            wild += Math.abs(o2.heads[0].yaw - o1.heads[0].yaw) + Math.abs(o2.heads[0].pitch - o1.heads[0].pitch);
        }
        assertTrue(wild > calm * 2, "energiegeladen sollte deutlich mehr Bewegung haben: " + wild + " vs " + calm);
        p.evaluate(input(0, 40, 3), o1);
        for (var l : o1.lasers) assertFalse(l.on, "Laser in ruhigen Passagen aus");
        p.evaluate(input(2, 240, 3), o1);
        for (var l : o1.lasers) assertTrue(l.on);
    }

    @Test
    void ohneMusikIstAllesAus() {
        var fx = StageLayout.get().fixtures();
        ShowProgram p = new ShowProgram(fx);
        var o = new ShowProgram.Output(fx);
        ShowInput in = new ShowInput();
        in.active = false;
        p.evaluate(in, o);
        for (var h : o.heads) assertEquals(0f, h.intensity);
        for (float s : o.strobes) assertEquals(0f, s);
        for (float f : o.fogRates) assertEquals(0f, f);
    }

    private static ShowInput dropInput(double beatPos, long sinceBeat, long sinceDropStart) {
        ShowInput in = new ShowInput();
        in.active = true;
        in.timeMicros = 9_000_000;
        in.beatPos = beatPos;
        in.frame = new AnalysisFrame(AnalysisFrame.TEMPO_LOCKED, (int) beatPos, 0, 1280, 240, 240, 200,
                new byte[]{(byte) 230, (byte) 150, 100, 80}, 1 | (2 << 4) | 0x40);
        in.sinceBassMicros = sinceBeat;
        in.sinceBeatMicros = sinceBeat;
        in.sinceDropStartMicros = sinceDropStart;
        return in;
    }

    @Test
    void dropsBlitzenAufDemBeatUndBeimEinschlag() {
        var fx = StageLayout.get().fixtures();
        ShowProgram p = new ShowProgram(fx);
        var o = new ShowProgram.Output(fx);
        // ohne Drop: kein Blitz
        p.evaluate(input(2, 240, 8.02), o);
        assertEquals(0f, o.flash);
        // Drop laeuft schon laenger: direkt auf dem Downbeat voller Blitz, kurz danach abgeklungen
        p.evaluate(dropInput(8.02, 5_000, ShowInput.NEVER), o);
        assertTrue(o.flash > 0.9f, "Blitz auf dem Downbeat: " + o.flash);
        assertFalse(o.dropHit);
        p.evaluate(dropInput(8.45, 220_000, ShowInput.NEVER), o);
        assertTrue(o.flash < 0.35f, "Blitz klingt ab: " + o.flash);
        // Drop-Einschlag: auch auf der Achtel dazwischen voller Blitz und alle Stroboskope an
        p.evaluate(dropInput(9.52, 240_000, 400_000), o);
        assertTrue(o.dropHit);
        assertEquals(1f, o.flash);
        for (float s : o.strobes) assertEquals(1f, s);
        // nach dem Einschlag wieder nur auf dem Beat
        p.evaluate(dropInput(9.52, 240_000, 2_000_000), o);
        assertFalse(o.dropHit);
        assertTrue(o.flash < 0.35f);
    }

    @Test
    void pixelschriftDjTobi() {
        String t = de.blockrave.core.show.PixelFont.sanitize("dj tobi");
        assertEquals("DJ TOBI", t);
        boolean[][] m = de.blockrave.core.show.PixelFont.render(t);
        assertEquals(7, m.length);
        assertEquals(41, m[0].length);
        int on = 0;
        for (boolean[] row : m) for (boolean b : row) if (b) on++;
        assertTrue(on > 60 && on < 200, "Pixel gesetzt: " + on);
        // Leerzeichen-Spalte zwischen "DJ" und "TOBI" ist leer
        for (boolean[] row : m) for (int x = 12; x < 17; x++) assertFalse(row[x]);
        assertEquals("ABC 1", de.blockrave.core.show.PixelFont.sanitize("  abc#1 "));
        assertEquals(16, de.blockrave.core.show.PixelFont.sanitize("x".repeat(40)).length());
    }
}
