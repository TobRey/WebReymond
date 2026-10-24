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
}
