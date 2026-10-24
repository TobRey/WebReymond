package de.blockrave.core.analysis;

import java.nio.ByteBuffer;

/**
 * Ergebnis der Audioanalyse fuer genau einen 20-ms-Audioframe. Wird zusammen mit dem Audio
 * uebertragen; alle Clients berechnen die Lichtshow aus denselben Werten.
 *
 * @param flags      Bitmaske, siehe Konstanten
 * @param beatIndex  fortlaufender Beatzaehler des aktuellen Streams
 * @param beatPhase  Position innerhalb des aktuellen Beats am Frameende (0..255)
 * @param bpmTenths  geschaetztes Tempo in 1/10 BPM (0 = unbekannt)
 * @param energy     Lautheit relativ zum Track (0..255), traege
 * @param intensity  musikalische Intensitaet (Lautheit + Onset-Dichte), sehr traege (0..255)
 * @param loudness   kurzzeitige Lautheit (0..255)
 * @param bands      Bass, untere Mitten, obere Mitten, Hoehen (je 0..255)
 * @param scene      von der Analyse gewaehlte Show-Szene: Bits 0-3 Muster, Bits 4-5 Energieklasse
 *                   (0 ruhig, 1 mittel, 2 energiegeladen), Bit 6 Hoehepunkt-Phase aktiv
 */
public record AnalysisFrame(int flags, int beatIndex, int beatPhase, int bpmTenths, int energy, int intensity,
                            int loudness, byte[] bands, int scene) {
    public static final int BEAT = 1;
    public static final int BASS_HIT = 1 << 1;
    public static final int ONSET = 1 << 2;
    public static final int PEAK = 1 << 3;
    public static final int SILENT = 1 << 4;
    public static final int TEMPO_LOCKED = 1 << 5;

    public static final int ENCODED_SIZE = 16;
    public static final AnalysisFrame SILENCE = new AnalysisFrame(SILENT, 0, 0, 0, 0, 0, 0, new byte[4], 0);

    public boolean has(int flag) {
        return (flags & flag) != 0;
    }

    public float energyF() {
        return energy / 255f;
    }

    public float intensityF() {
        return intensity / 255f;
    }

    public float loudnessF() {
        return loudness / 255f;
    }

    public float band(int i) {
        return (bands[i] & 0xFF) / 255f;
    }

    public int pattern() {
        return scene & 0x0F;
    }

    public int energyClass() {
        return (scene >> 4) & 0x03;
    }

    public boolean dropActive() {
        return (scene & 0x40) != 0;
    }

    public float beatPhaseF() {
        return beatPhase / 256f;
    }

    public void write(ByteBuffer b) {
        b.put((byte) flags);
        b.putInt(beatIndex);
        b.put((byte) beatPhase);
        b.putShort((short) bpmTenths);
        b.put((byte) energy);
        b.put((byte) intensity);
        b.put((byte) loudness);
        b.put(bands, 0, 4);
        b.put((byte) scene);
    }

    public static AnalysisFrame read(ByteBuffer b) {
        int flags = b.get() & 0xFF;
        int beatIndex = b.getInt();
        int phase = b.get() & 0xFF;
        int bpm = b.getShort() & 0xFFFF;
        int energy = b.get() & 0xFF;
        int intensity = b.get() & 0xFF;
        int loud = b.get() & 0xFF;
        byte[] bands = new byte[4];
        b.get(bands);
        int scene = b.get() & 0xFF;
        return new AnalysisFrame(flags, beatIndex, phase, bpm, energy, intensity, loud, bands, scene);
    }

    public byte[] encode() {
        ByteBuffer b = ByteBuffer.allocate(ENCODED_SIZE);
        write(b);
        return b.array();
    }

    public static AnalysisFrame decode(byte[] data) {
        return read(ByteBuffer.wrap(data));
    }
}
