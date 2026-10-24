package de.blockrave.core.stage;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Bauplan der Festivalbuehne (35 breit, 20 tief, 19 hoch).
 *
 * <pre>
 *  z=0..7   Tanzflaeche (leuchtend) vor der Buehne
 *  z=8..19  erhoehte Buehne x=4..30, Lautsprechertuerme links/rechts, Traversen, LED-Wand
 * </pre>
 * Jede Position im Quader ist entweder ein Bauteil oder wird beim Aufbau geleert (Innenraum).
 */
public final class StageLayout {
    public static final int WIDTH = 35;
    public static final int DEPTH = 20;
    public static final int HEIGHT = 19;
    public static final int ANCHOR_X = 17;

    public static final int DECK_X0 = 4, DECK_X1 = 30, DECK_Z0 = 8, DECK_Z1 = 19;
    public static final int FLOOR_Z1 = 7;
    public static final int TRUSS_Y = 17;

    private static final StageLayout INSTANCE = new StageLayout();

    private final List<StagePart> parts;
    private final Map<Long, StagePart> byPos;
    private final Fixtures fixtures;

    public static StageLayout get() {
        return INSTANCE;
    }

    /** Fester Bezugspunkt der Show-Elemente (Mitte zwischen den Lautsprechern, lokal). */
    public record Fixtures(List<StagePart> movingHeads, List<StagePart> lasers, List<StagePart> strobes,
                           List<StagePart> fogMachines, List<StagePart> ledPanels, List<StagePart> floorTiles,
                           List<StagePart> frontPanels, double[][] fireworkPoints, double[] speakerLeft,
                           double[] speakerRight, double[] center, int[] booth) {}

    private StageLayout() {
        Map<Long, StagePart> map = new LinkedHashMap<>();
        // --- Boden: Tanzflaeche vorne, Bodenplatten hinten (keine Loecher im Gelaende) ---
        for (int x = 0; x < WIDTH; x++) {
            for (int z = 0; z < DEPTH; z++) {
                if (z <= FLOOR_Z1) put(map, x, 0, z, PartType.DANCE_FLOOR, LocalDir.UP);
                else if (z == DECK_Z0 && x >= DECK_X0 && x <= DECK_X1) put(map, x, 0, z, PartType.STAGE_FRONT, LocalDir.FRONT);
                else put(map, x, 0, z, PartType.STAGE_DECK, LocalDir.UP);
            }
        }
        // --- Buehnenplattform (eine Ebene hoeher), Frontkante leuchtend ---
        for (int x = DECK_X0; x <= DECK_X1; x++) {
            for (int z = DECK_Z0; z <= DECK_Z1; z++) {
                put(map, x, 1, z, z == DECK_Z0 ? PartType.STAGE_FRONT : PartType.STAGE_DECK, z == DECK_Z0 ? LocalDir.FRONT : LocalDir.UP);
            }
        }
        // --- Treppen seitlich hinten ---
        for (int z = 15; z <= 17; z++) {
            put(map, DECK_X0 - 1, 1, z, PartType.STAGE_STEPS, LocalDir.RIGHT);
            put(map, DECK_X1 + 1, 1, z, PartType.STAGE_STEPS, LocalDir.LEFT);
        }
        // --- Lautsprechertuerme (4 breit, 3 tief, 12 hoch) ---
        for (int y = 1; y <= 12; y++) {
            for (int dz = 0; dz < 3; dz++) {
                for (int dx = 0; dx < 4; dx++) {
                    put(map, dx, y, DECK_Z0 + dz, PartType.SPEAKER, LocalDir.FRONT);
                    put(map, WIDTH - 1 - dx, y, DECK_Z0 + dz, PartType.SPEAKER, LocalDir.FRONT);
                }
            }
        }
        // --- LED-Panels auf den Lautsprechertuermen (4x4 je Seite) ---
        for (int y = 13; y <= 16; y++) {
            for (int dx = 0; dx < 4; dx++) {
                put(map, dx, y, DECK_Z0, PartType.LED_PANEL, LocalDir.FRONT);
                put(map, WIDTH - 1 - dx, y, DECK_Z0, PartType.LED_PANEL, LocalDir.FRONT);
            }
        }
        // --- Traversen: vier Stuetzen, umlaufender Rahmen oben, Mitteltraverse ---
        int[][] pillars = {{DECK_X0, DECK_Z0}, {DECK_X1, DECK_Z0}, {DECK_X0, DECK_Z1}, {DECK_X1, DECK_Z1}};
        for (int[] p : pillars) {
            for (int y = 2; y < TRUSS_Y; y++) put(map, p[0], y, p[1], PartType.TRUSS_VERTICAL, LocalDir.UP);
        }
        for (int x = DECK_X0; x <= DECK_X1; x++) {
            put(map, x, TRUSS_Y, DECK_Z0, PartType.TRUSS_ALONG_X, LocalDir.FRONT);
            put(map, x, TRUSS_Y, DECK_Z1, PartType.TRUSS_ALONG_X, LocalDir.FRONT);
            put(map, x, TRUSS_Y, 13, PartType.TRUSS_ALONG_X, LocalDir.FRONT);
        }
        for (int z = DECK_Z0 + 1; z < DECK_Z1; z++) {
            if (z == 13) continue;
            put(map, DECK_X0, TRUSS_Y, z, PartType.TRUSS_ALONG_Z, LocalDir.FRONT);
            put(map, DECK_X1, TRUSS_Y, z, PartType.TRUSS_ALONG_Z, LocalDir.FRONT);
        }
        // --- LED-Banner oben auf der Fronttraverse ---
        for (int x = DECK_X0 + 2; x <= DECK_X1 - 2; x++) put(map, x, TRUSS_Y + 1, DECK_Z0, PartType.LED_PANEL, LocalDir.FRONT);
        // --- Grosse LED-Wand hinter dem DJ ---
        for (int x = 8; x <= 26; x++) {
            for (int y = 2; y <= 13; y++) put(map, x, y, DECK_Z1 - 1, PartType.LED_PANEL, LocalDir.FRONT);
        }
        // --- DJ-Pult in der Mitte ---
        put(map, ANCHOR_X, 2, 12, PartType.DJ_BOOTH, LocalDir.FRONT);
        put(map, ANCHOR_X - 1, 2, 12, PartType.DJ_BOOTH_SIDE, LocalDir.FRONT);
        put(map, ANCHOR_X + 1, 2, 12, PartType.DJ_BOOTH_SIDE, LocalDir.FRONT);
        // --- Moving Heads: Buehnenkante (stehend), Traversen (haengend), Tanzflaechen-Ecken ---
        for (int x : new int[]{6, 10, 14, 20, 24, 28}) put(map, x, 2, DECK_Z0 + 1, PartType.MOVING_HEAD, LocalDir.FRONT);
        for (int x : new int[]{7, 11, 15, 19, 23, 27}) put(map, x, TRUSS_Y - 1, 13, PartType.MOVING_HEAD_HANGING, LocalDir.FRONT);
        for (int x : new int[]{9, 13, 21, 25}) put(map, x, TRUSS_Y - 1, DECK_Z0, PartType.MOVING_HEAD_HANGING, LocalDir.FRONT);
        put(map, 0, 1, 0, PartType.MOVING_HEAD, LocalDir.FRONT);
        put(map, WIDTH - 1, 1, 0, PartType.MOVING_HEAD, LocalDir.FRONT);
        // --- Laser ---
        put(map, ANCHOR_X, TRUSS_Y - 1, DECK_Z0, PartType.LASER, LocalDir.FRONT);
        put(map, 8, 14, DECK_Z1 - 1, PartType.LASER, LocalDir.FRONT);
        put(map, 26, 14, DECK_Z1 - 1, PartType.LASER, LocalDir.FRONT);
        // --- Stroboskope ---
        put(map, 6, TRUSS_Y - 1, DECK_Z0, PartType.STROBE, LocalDir.FRONT);
        put(map, 28, TRUSS_Y - 1, DECK_Z0, PartType.STROBE, LocalDir.FRONT);
        put(map, 11, TRUSS_Y - 1, DECK_Z1, PartType.STROBE, LocalDir.FRONT);
        put(map, 23, TRUSS_Y - 1, DECK_Z1, PartType.STROBE, LocalDir.FRONT);
        // --- Nebelmaschinen ---
        put(map, 8, 2, DECK_Z0 + 1, PartType.FOG_MACHINE, LocalDir.FRONT);
        put(map, 26, 2, DECK_Z0 + 1, PartType.FOG_MACHINE, LocalDir.FRONT);
        put(map, 6, 2, DECK_Z1 - 1, PartType.FOG_MACHINE, LocalDir.FRONT);
        put(map, 28, 2, DECK_Z1 - 1, PartType.FOG_MACHINE, LocalDir.FRONT);

        this.byPos = Collections.unmodifiableMap(map);
        this.parts = List.copyOf(map.values());
        this.fixtures = buildFixtures();
    }

    private static void put(Map<Long, StagePart> map, int x, int y, int z, PartType t, LocalDir f) {
        if (x < 0 || x >= WIDTH || y < 0 || y >= HEIGHT || z < 0 || z >= DEPTH) {
            throw new IllegalStateException("Bauteil ausserhalb des Buehnenquaders: " + x + "," + y + "," + z);
        }
        map.put(key(x, y, z), new StagePart(x, y, z, t, f)); // spaetere Eintraege ueberschreiben fruehere
    }

    public static long key(int x, int y, int z) {
        return ((long) x << 16) | ((long) y << 8) | z;
    }

    private Fixtures buildFixtures() {
        List<StagePart> heads = new ArrayList<>(), lasers = new ArrayList<>(), strobes = new ArrayList<>(),
                fog = new ArrayList<>(), leds = new ArrayList<>(), floor = new ArrayList<>(), front = new ArrayList<>();
        int[] booth = null;
        for (StagePart p : parts) {
            switch (p.type()) {
                case MOVING_HEAD, MOVING_HEAD_HANGING -> heads.add(p);
                case LASER -> lasers.add(p);
                case STROBE -> strobes.add(p);
                case FOG_MACHINE -> fog.add(p);
                case LED_PANEL -> leds.add(p);
                case DANCE_FLOOR -> floor.add(p);
                case STAGE_FRONT -> { if (p.y() == 1) front.add(p); }
                case DJ_BOOTH -> booth = new int[]{p.x(), p.y(), p.z()};
                default -> {}
            }
        }
        double[][] fw = {{2, 14.5, 9.5}, {WIDTH - 2, 14.5, 9.5}, {10.5, 19, 19.5}, {24.5, 19, 19.5}};
        return new Fixtures(List.copyOf(heads), List.copyOf(lasers), List.copyOf(strobes), List.copyOf(fog),
                List.copyOf(leds), List.copyOf(floor), List.copyOf(front), fw,
                new double[]{2.0, 6.5, DECK_Z0 + 0.5}, new double[]{WIDTH - 2.0, 6.5, DECK_Z0 + 0.5},
                new double[]{ANCHOR_X + 0.5, 6.0, DECK_Z0 + 0.5}, booth);
    }

    public List<StagePart> parts() {
        return parts;
    }

    public StagePart partAt(int x, int y, int z) {
        return byPos.get(key(x, y, z));
    }

    public Fixtures fixtures() {
        return fixtures;
    }

    /** Lokale Position des zentralen DJ-Pults. */
    public int[] boothLocal() {
        return fixtures.booth();
    }

    public static int volume() {
        return WIDTH * DEPTH * HEIGHT;
    }
}
