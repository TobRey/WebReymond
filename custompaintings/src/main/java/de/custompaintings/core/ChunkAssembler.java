package de.custompaintings.core;

/**
 * Setzt eine in Bloecken uebertragene Datei wieder zusammen. Die Bloecke kommen ueber eine geordnete
 * Verbindung, daher muss jeder Block genau dort anschliessen, wo der vorige aufgehoert hat.
 */
public final class ChunkAssembler {
    private final byte[] data;
    private int received;

    public ChunkAssembler(int size) {
        if (size <= 0) throw new IllegalArgumentException("Groesse ungueltig");
        this.data = new byte[size];
    }

    /** @return false bei Luecke, Ueberlappung oder Ueberlauf */
    public boolean append(int offset, byte[] chunk) {
        if (offset != received || chunk.length == 0 || offset + chunk.length > data.length) return false;
        System.arraycopy(chunk, 0, data, offset, chunk.length);
        received += chunk.length;
        return true;
    }

    public boolean complete() {
        return received == data.length;
    }

    public int received() {
        return received;
    }

    public int size() {
        return data.length;
    }

    public byte[] data() {
        if (!complete()) throw new IllegalStateException("unvollstaendig");
        return data;
    }
}
