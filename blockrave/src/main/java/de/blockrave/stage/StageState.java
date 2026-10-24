package de.blockrave.stage;

import com.google.gson.Gson;
import com.google.gson.GsonBuilder;

import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;

/**
 * Gespeicherter Zustand der (einzigen) Buehne: Position, Ausrichtung und exakt welche Positionen
 * welche Bauteile enthalten. Nur diese Positionen werden beim Einpacken entfernt &ndash; und auch nur,
 * wenn dort noch das erwartete Bauteil steht.
 */
public final class StageState {
    private static final Gson GSON = new GsonBuilder().disableHtmlEscaping().create();

    public int formatVersion = 1;
    public String dimension;
    public int anchorX, anchorY, anchorZ, rotation;
    public long boothPos;
    /** Registry-Namen der verwendeten Bloecke. */
    public List<String> palette = new ArrayList<>();
    public long[] positions = new long[0];
    public int[] blocks = new int[0];
    public String placedBy;
    public long placedAtEpochMs;

    public byte[] toJson() {
        return GSON.toJson(this).getBytes(StandardCharsets.UTF_8);
    }

    public static StageState fromJson(byte[] data) {
        StageState s = GSON.fromJson(new String(data, StandardCharsets.UTF_8), StageState.class);
        if (s == null || s.dimension == null || s.positions == null || s.blocks == null
                || s.positions.length != s.blocks.length) {
            throw new IllegalArgumentException("Unvollstaendiger Buehnenzustand");
        }
        return s;
    }
}
