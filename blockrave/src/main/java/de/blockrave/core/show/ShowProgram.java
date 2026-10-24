package de.blockrave.core.show;

import de.blockrave.core.analysis.AnalysisFrame;
import de.blockrave.core.stage.PartType;
import de.blockrave.core.stage.StageLayout;
import de.blockrave.core.stage.StagePart;

import java.util.List;

/**
 * Deterministische, automatische Lichtshow. Berechnet aus der gehoerten Musik (Analysewerte +
 * gemeinsame Abspielzeit) den Zustand aller Scheinwerfer, Laser, Stroboskope, LED-Flaechen,
 * Tanzflaeche und Nebel. Gleiche Eingaben ergeben auf allen Clients die gleiche Show.
 *
 * <p>Winkel: yaw = Drehung um die Senkrechte (0 = Richtung Publikum, positiv = nach rechts),
 * pitch = Neigung ueber der Waagerechten (positiv = nach oben). Alles im lokalen Buehnensystem.
 */
public final class ShowProgram {
    private static final double TAU = Math.PI * 2;

    public static final class Head {
        public double yaw, pitch;
        public int color;
        public float intensity;
        public float width;
    }

    public static final class Laser {
        public boolean on;
        public int beams;
        public double yaw, pitch, spread;
        public int color;
        public float intensity;
    }

    public static final class Output {
        public final Head[] heads;
        public final Laser[] lasers;
        public final float[] strobes;
        public final float[] fogRates;
        public float frontBrightness;
        public int frontColor;
        public float masterLevel;

        public Output(StageLayout.Fixtures fx) {
            heads = new Head[fx.movingHeads().size()];
            for (int i = 0; i < heads.length; i++) heads[i] = new Head();
            lasers = new Laser[fx.lasers().size()];
            for (int i = 0; i < lasers.length; i++) lasers[i] = new Laser();
            strobes = new float[fx.strobes().size()];
            fogRates = new float[fx.fogMachines().size()];
        }
    }

    private final StageLayout.Fixtures fx;
    private final int[] headGroup;
    private final int[] headIndexInGroup;
    private final int[] groupSize = new int[3];
    private ShowInput in;
    private int paletteShift;

    public ShowProgram(StageLayout.Fixtures fx) {
        this.fx = fx;
        List<StagePart> heads = fx.movingHeads();
        headGroup = new int[heads.size()];
        headIndexInGroup = new int[heads.size()];
        for (int i = 0; i < heads.size(); i++) {
            StagePart p = heads.get(i);
            int g = p.type() == PartType.MOVING_HEAD_HANGING ? 1 : (p.y() <= 1 ? 2 : 0);
            headGroup[i] = g;
            headIndexInGroup[i] = groupSize[g]++;
        }
    }

    /** Pulsform nach einem Ereignis (1 direkt danach, exponentiell abklingend). */
    public static float pulse(long sinceMicros, double decayMillis) {
        if (sinceMicros < 0 || sinceMicros >= ShowInput.NEVER) return 0f;
        return (float) Math.exp(-(sinceMicros / 1000.0) / decayMillis);
    }

    public void evaluate(ShowInput input, Output out) {
        this.in = input;
        AnalysisFrame f = input.frame;
        boolean active = input.active && !f.has(AnalysisFrame.SILENT);
        int ec = f.energyClass();
        float intensity = f.intensityF();
        float energy = f.energyF();
        int pattern = f.pattern();
        boolean drop = f.dropActive();
        int beat = f.beatIndex();
        float bass = pulse(input.sinceBassMicros, 160);
        // Farbwechsel: ruhig alle 4 Takte, mittel jeden Takt, energiegeladen jeden Beat
        int colorStep = ec == 0 ? beat / 16 : ec == 1 ? beat / 4 : beat;
        paletteShift = colorStep;
        out.masterLevel = active ? 0.35f + 0.65f * energy : 0f;

        // --- Moving Heads ---
        double cycle = ec == 0 ? 16 : ec == 1 ? 8 : 4;
        double phi = f.has(AnalysisFrame.TEMPO_LOCKED) ? input.beatPos / cycle : input.timeMicros / 1e6 / (cycle * 0.5);
        double amp = 0.35 + 0.65 * intensity;
        for (int i = 0; i < out.heads.length; i++) {
            Head h = out.heads[i];
            int g = headGroup[i];
            int n = groupSize[g];
            int k = headIndexInGroup[i];
            double spread = n > 1 ? (k - (n - 1) / 2.0) / ((n - 1) / 2.0) : 0;
            double basePitch = g == 0 ? 0.75 : g == 1 ? -0.95 : 1.2;
            double yawScale = g == 2 ? 0.35 : 1.0;
            double yaw, pitch;
            double p = phi * TAU;
            switch (pattern) {
                case 0 -> { // Faecher
                    yaw = spread * amp * 0.9 + 0.25 * amp * Math.sin(p);
                    pitch = basePitch + 0.2 * amp * Math.sin(p + k * 0.5);
                }
                case 1 -> { // Kreis
                    double q = p + TAU * k / Math.max(1, n);
                    yaw = amp * 0.6 * Math.cos(q);
                    pitch = basePitch + amp * 0.35 * Math.sin(q);
                }
                case 2 -> { // Kreuz
                    yaw = (k % 2 == 0 ? 1 : -1) * amp * 0.8 * Math.sin(p) + spread * 0.2;
                    pitch = basePitch + 0.15 * amp * Math.cos(2 * p);
                }
                case 3 -> { // Welle
                    double q = p - k * 0.45;
                    yaw = amp * 0.7 * Math.sin(q);
                    pitch = basePitch + 0.25 * amp * Math.cos(q);
                }
                default -> { // Ballyhoo
                    yaw = amp * 0.9 * Math.sin(p + k);
                    pitch = basePitch + amp * 0.4 * Math.sin(2 * p + k * 0.7);
                }
            }
            h.yaw = yaw * yawScale;
            h.pitch = pitch;
            int colorIdx = paletteShift + g + (pattern == 2 ? k % 2 : 0);
            h.color = drop && (beat % 2 == 0) && g == 0 ? ShowPalette.WHITE : ShowPalette.color(colorIdx);
            float dim = 0.3f + 0.6f * intensity + 0.35f * bass;
            if (ec == 2 && pattern == 4) dim *= 0.7f + 0.3f * (float) Math.abs(Math.sin(input.beatPos * Math.PI));
            h.intensity = active ? Math.min(1f, dim) : 0f;
            h.width = ec == 0 ? 0.13f : ec == 1 ? 0.10f : 0.075f;
        }

        // --- Laser ---
        boolean lasersOn = active && (ec >= 1 || drop);
        for (int i = 0; i < out.lasers.length; i++) {
            Laser l = out.lasers[i];
            l.on = lasersOn;
            l.beams = ec == 2 || drop ? 7 : 4;
            double lp = input.beatPos / 16.0 * TAU;
            l.yaw = 0.45 * Math.sin(lp + i * 2.1) * (i == 0 ? 1 : 0.6);
            l.spread = 0.5 + 0.35 * Math.sin(input.beatPos / 8.0 * TAU + i);
            l.pitch = 0.08 + 0.12 * Math.sin(input.beatPos / 4.0 * TAU + i * 1.3);
            int[] laserColors = {ShowPalette.GREEN, ShowPalette.CYAN, ShowPalette.PINK};
            l.color = laserColors[Math.floorMod(beat / 8 + i, 3)];
            // im Energieteil im Beat "gechoppt"
            double frac = input.beatPos - Math.floor(input.beatPos);
            l.intensity = !lasersOn ? 0f : (ec == 2 && frac > 0.75) ? 0.25f : 0.8f + 0.2f * bass;
        }

        // --- Stroboskope ---
        double frac = input.beatPos - Math.floor(input.beatPos);
        for (int i = 0; i < out.strobes.length; i++) {
            float s = 0f;
            if (active && drop && input.sinceBeatMicros < 45_000) s = 1f;
            if (active && ec == 2 && drop && Math.abs(frac - 0.5) < 0.04) s = 1f;
            if (active && ec == 2 && f.band(3) > 0.75f && input.sinceOnsetMicros < 35_000 && (i % 2) == (beat % 2)) s = Math.max(s, 0.8f);
            out.strobes[i] = s;
        }

        // --- Nebel: Stoesse am Hoehepunkt und an Phrasengrenzen im Energieteil ---
        float fog = 0f;
        if (active) {
            fog = pulse(input.sincePeakMicros, 1500) ;
            if (ec == 2 && Math.floorMod(beat, 32) == 0) fog = Math.max(fog, 0.6f * pulse(input.sinceBeatMicros, 900));
        }
        for (int i = 0; i < out.fogRates.length; i++) out.fogRates[i] = fog;

        // --- Frontpaneele ---
        out.frontBrightness = active ? Math.min(1f, 0.35f + 0.45f * f.loudnessF() + 0.4f * bass) : 0.12f;
        out.frontColor = drop ? ShowPalette.color(beat) : ShowPalette.WHITE;
    }

    /**
     * Farbe eines LED-Pixels. {@code u} 0..1 von links nach rechts, {@code v} 0..1 von unten nach oben,
     * {@code wall}: 0 Hauptwand, 1 seitlich, 2 Banner.
     */
    public int ledColor(int wall, double u, double v) {
        ShowInput input = this.in;
        AnalysisFrame f = input.frame;
        if (!input.active || f.has(AnalysisFrame.SILENT)) {
            // Ruhebild: dunkles Lila-Raster
            return ((int) (u * 19) + (int) (v * 12)) % 2 == 0 ? 0x140A1E : 0x0A0612;
        }
        float bass = pulse(input.sinceBassMicros, 200);
        float level = 0.3f + 0.7f * f.energyF();
        int pattern = wall == 0 ? f.pattern() : (f.pattern() + wall) % AudioPatterns.COUNT;
        int beat = f.beatIndex();
        int c;
        switch (pattern) {
            case 0 -> { // Spektrum: Bass in der Mitte, Hoehen aussen
                double d = Math.abs(u - 0.5) * 2; // 0 Mitte .. 1 Rand
                double bandPos = d * 3;
                int b0 = (int) Math.min(2, Math.floor(bandPos));
                double t = bandPos - b0;
                double h = f.band(b0) * (1 - t) + f.band(b0 + 1) * t;
                if (v < h) {
                    c = v < 0.45 ? ShowPalette.GREEN : v < 0.75 ? ShowPalette.YELLOW : ShowPalette.PINK;
                    return ShowPalette.scale(c, 0.6f + 0.4f * level);
                }
                return ShowPalette.scale(ShowPalette.CYAN, 0.08f);
            }
            case 1 -> { // Puls mit Rahmen
                boolean border = u < 0.08 || u > 0.92 || v < 0.1 || v > 0.9;
                c = ShowPalette.color(paletteShift + (border ? 1 : 0));
                return ShowPalette.scale(c, border ? level : 0.2f + 0.8f * bass * level);
            }
            case 2 -> { // Schachbrett im Beat
                int cell = ((int) (u * 6) + (int) (v * 4)) & 1;
                c = ShowPalette.color(paletteShift + ((cell ^ (beat & 1)) == 0 ? 0 : 2));
                return ShowPalette.scale(c, (0.45f + 0.55f * bass) * level);
            }
            case 3 -> { // Diagonale Streifen, laufen im Takt
                double s = u * 2 + v + input.beatPos * 0.5;
                s -= Math.floor(s);
                c = ShowPalette.color((int) (s * 4));
                return ShowPalette.scale(c, (0.5f + 0.5f * bass) * level);
            }
            default -> { // Ringe aus der Mitte
                double du = (u - 0.5) * 1.6, dv = v - 0.5;
                double r = Math.sqrt(du * du + dv * dv);
                double ring = r * 3 - input.beatPos * 0.5;
                ring -= Math.floor(ring);
                c = ShowPalette.color(paletteShift + (ring < 0.5 ? 0 : 1));
                return ShowPalette.scale(c, (ring < 0.3 ? 1f : 0.25f) * level);
            }
        }
    }

    /** Farbe einer Tanzflaechen-Kachel; {@code x} 0..34, {@code z} 0..7 (0 = vorne). */
    public int floorColor(int x, int z) {
        ShowInput input = this.in;
        AnalysisFrame f = input.frame;
        if (!input.active || f.has(AnalysisFrame.SILENT)) {
            return ((x + z) & 1) == 0 ? 0x12081C : 0x08040E;
        }
        int ec = f.energyClass();
        float level = 0.3f + 0.7f * f.energyF();
        // Bassimpuls als Ring, der sich von der Buehne aus ausbreitet
        double dx = x - StageLayout.ANCHOR_X, dz = (StageLayout.FLOOR_Z1 + 1) - z;
        double dist = Math.sqrt(dx * dx + dz * dz);
        if (input.sinceBassMicros < 1_200_000) {
            double radius = input.sinceBassMicros / 1e6 * 28.0;
            if (Math.abs(dist - radius) < 1.6) {
                return ShowPalette.scale(ShowPalette.WHITE, (1f - (float) (input.sinceBassMicros / 1.2e6)) * level);
            }
        }
        int beat = f.beatIndex();
        int c;
        if (ec == 0) {
            double s = x / 34.0 + input.beatPos / 16.0;
            s -= Math.floor(s);
            c = ShowPalette.color((int) (s * 4));
            return ShowPalette.scale(c, 0.35f * level + 0.1f);
        }
        if (f.pattern() % 2 == 0) {
            int cell = ((x / 3) + (z / 2) + beat) & 1;
            c = ShowPalette.color(paletteShift + cell * 2);
        } else {
            double s = dist / 10.0 - input.beatPos * 0.25;
            s -= Math.floor(s);
            c = ShowPalette.color((int) (s * 4));
        }
        return ShowPalette.scale(c, level * (ec == 2 ? 1f : 0.7f));
    }

    /** Soll bei diesem Frame ein Feuerwerk gezuendet werden? */
    public static boolean fireworkTrigger(AnalysisFrame f) {
        if (f.has(AnalysisFrame.SILENT)) return false;
        return f.has(AnalysisFrame.PEAK) || (f.dropActive() && f.has(AnalysisFrame.BEAT) && Math.floorMod(f.beatIndex(), 8) == 0);
    }

    /** Anzahl der Muster (fuer Wand-Varianten). */
    private static final class AudioPatterns {
        static final int COUNT = 5;
    }
}
