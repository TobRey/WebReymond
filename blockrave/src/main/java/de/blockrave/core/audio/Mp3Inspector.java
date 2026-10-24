package de.blockrave.core.audio;

import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;

/**
 * Prueft, ob Daten tatsaechlich ein MPEG-Audio-Layer-III-Stream sind (unabhaengig von
 * Dateiendung oder Dateiname) und liest optionale ID3v2-Titelinformationen.
 */
public final class Mp3Inspector {
    private static final int[][] BITRATES_KBPS = {
            // MPEG-1 Layer III
            {0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, -1},
            // MPEG-2/2.5 Layer III
            {0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, -1},
    };
    private static final int[][] SAMPLE_RATES = {
            {44100, 48000, 32000}, // MPEG-1
            {22050, 24000, 16000}, // MPEG-2
            {11025, 12000, 8000},  // MPEG-2.5
    };

    /** Anzahl aufeinanderfolgender gueltiger Frames, die fuer eine positive Erkennung noetig sind. */
    private static final int REQUIRED_CONSECUTIVE_FRAMES = 4;

    private Mp3Inspector() {}

    public record FrameHeader(int version /* 1, 2, 25 */, int bitrateKbps, int sampleRate, boolean stereo,
                              int frameLength) {}

    public record Result(boolean valid, String error, int id3Size, FrameHeader firstFrame, String title,
                         String artist) {
        static Result invalid(String error) {
            return new Result(false, error, 0, null, null, null);
        }
    }

    /** Groesse des ID3v2-Tags am Dateianfang (inkl. Header/Footer) oder 0. */
    public static int id3v2Size(byte[] head, int len) {
        if (len < 10 || head[0] != 'I' || head[1] != 'D' || head[2] != '3') return 0;
        int flags = head[5] & 0xFF;
        int size = ((head[6] & 0x7F) << 21) | ((head[7] & 0x7F) << 14) | ((head[8] & 0x7F) << 7) | (head[9] & 0x7F);
        int total = 10 + size;
        if ((flags & 0x10) != 0) total += 10; // Footer
        return total;
    }

    /** Liest einen Frame-Header an Position {@code off}; null wenn ungueltig. */
    public static FrameHeader parseHeader(byte[] b, int off, int len) {
        if (off + 4 > len) return null;
        int h = ((b[off] & 0xFF) << 24) | ((b[off + 1] & 0xFF) << 16) | ((b[off + 2] & 0xFF) << 8) | (b[off + 3] & 0xFF);
        if ((h & 0xFFE00000) != 0xFFE00000) return null;
        int versionBits = (h >>> 19) & 3;
        int layerBits = (h >>> 17) & 3;
        int bitrateIdx = (h >>> 12) & 0xF;
        int srIdx = (h >>> 10) & 3;
        int padding = (h >>> 9) & 1;
        int channelMode = (h >>> 6) & 3;
        if (versionBits == 1) return null; // reserviert
        if (layerBits != 1) return null;   // nur Layer III ("01")
        if (bitrateIdx == 0 || bitrateIdx == 15) return null; // free format / ungueltig
        if (srIdx == 3) return null;
        int version = versionBits == 3 ? 1 : versionBits == 2 ? 2 : 25;
        int kbps = BITRATES_KBPS[version == 1 ? 0 : 1][bitrateIdx];
        int sr = SAMPLE_RATES[version == 1 ? 0 : version == 2 ? 1 : 2][srIdx];
        int frameLen = version == 1 ? 144_000 * kbps / sr + padding : 72_000 * kbps / sr + padding;
        if (frameLen < 24) return null;
        return new FrameHeader(version, kbps, sr, channelMode != 3, frameLen);
    }

    /**
     * Untersucht den Anfang einer Datei. {@code head} sollte mindestens die ersten 256 KiB
     * enthalten (oder die ganze Datei, falls kleiner).
     */
    public static Result inspect(byte[] head, int len) {
        int id3 = id3v2Size(head, len);
        String title = null;
        String artist = null;
        if (id3 > 0) {
            String[] ta = readId3Text(head, Math.min(len, id3));
            title = ta[0];
            artist = ta[1];
        }
        int searchStart = Math.min(id3, len);
        int searchEnd = Math.min(len, searchStart + 64 * 1024);
        for (int i = searchStart; i < searchEnd - 4; i++) {
            if ((head[i] & 0xFF) != 0xFF) continue;
            FrameHeader first = parseHeader(head, i, len);
            if (first == null) continue;
            if (chainValid(head, len, i, first)) {
                return new Result(true, null, id3, first, title, artist);
            }
        }
        if (len < 4096) return Result.invalid("Datei ist zu kurz fuer eine MP3-Datei.");
        return Result.invalid("Keine gueltigen MP3-Audioframes (MPEG Layer III) gefunden.");
    }

    private static boolean chainValid(byte[] b, int len, int start, FrameHeader first) {
        int off = start;
        FrameHeader h = first;
        for (int n = 0; n < REQUIRED_CONSECUTIVE_FRAMES; n++) {
            if (h == null || h.version() != first.version() || h.sampleRate() != first.sampleRate()) return false;
            off += h.frameLength();
            if (off + 4 > len) {
                // Datei endet - mindestens zwei Frames muessen gepasst haben.
                return n >= 1 && off <= len;
            }
            h = parseHeader(b, off, len);
        }
        return true;
    }

    /** Liest TIT2/TPE1 (v2.3/2.4) bzw. TT2/TP1 (v2.2). Rueckgabe {title, artist}, Eintraege evtl. null. */
    static String[] readId3Text(byte[] b, int tagEnd) {
        String[] out = new String[2];
        int major = b[3] & 0xFF;
        int flags = b[5] & 0xFF;
        if ((flags & 0x80) != 0) return out; // Unsynchronisation - selten, ignorieren
        int pos = 10;
        if ((flags & 0x40) != 0 && major >= 3 && pos + 4 <= tagEnd) { // erweiterter Header
            int ext = major == 4 ? syncsafe(b, pos) : be32(b, pos) + 4;
            pos += ext;
        }
        boolean v22 = major == 2;
        int idLen = v22 ? 3 : 4;
        int hdrLen = v22 ? 6 : 10;
        while (pos + hdrLen <= tagEnd) {
            if (b[pos] == 0) break; // Padding
            String id = new String(b, pos, idLen, StandardCharsets.ISO_8859_1);
            int size;
            if (v22) size = ((b[pos + 3] & 0xFF) << 16) | ((b[pos + 4] & 0xFF) << 8) | (b[pos + 5] & 0xFF);
            else if (major == 4) size = syncsafe(b, pos + 4);
            else size = be32(b, pos + 4);
            int dataStart = pos + hdrLen;
            if (size <= 0 || dataStart + size > tagEnd) break;
            if (id.equals("TIT2") || id.equals("TT2")) out[0] = decodeText(b, dataStart, size);
            else if (id.equals("TPE1") || id.equals("TP1")) out[1] = decodeText(b, dataStart, size);
            pos = dataStart + size;
        }
        return out;
    }

    private static int syncsafe(byte[] b, int p) {
        return ((b[p] & 0x7F) << 21) | ((b[p + 1] & 0x7F) << 14) | ((b[p + 2] & 0x7F) << 7) | (b[p + 3] & 0x7F);
    }

    private static int be32(byte[] b, int p) {
        return ((b[p] & 0xFF) << 24) | ((b[p + 1] & 0xFF) << 16) | ((b[p + 2] & 0xFF) << 8) | (b[p + 3] & 0xFF);
    }

    private static String decodeText(byte[] b, int start, int size) {
        if (size < 2) return null;
        int enc = b[start] & 0xFF;
        int s = start + 1;
        int n = size - 1;
        String text = switch (enc) {
            case 0 -> new String(b, s, n, StandardCharsets.ISO_8859_1);
            case 1 -> new String(b, s, n, StandardCharsets.UTF_16);
            case 2 -> new String(b, s, n, StandardCharsets.UTF_16BE);
            case 3 -> new String(b, s, n, StandardCharsets.UTF_8);
            default -> null;
        };
        if (text == null) return null;
        int nul = text.indexOf('\0');
        if (nul >= 0) text = text.substring(0, nul);
        text = sanitizeDisplay(text);
        return text.isEmpty() ? null : text;
    }

    /** Entfernt Steuerzeichen und begrenzt die Laenge fuer die Anzeige. */
    public static String sanitizeDisplay(String s) {
        StringBuilder sb = new StringBuilder();
        s.codePoints().forEach(cp -> {
            if (!Character.isISOControl(cp) && cp != 0xA7 /* Minecraft-Formatcode § */) sb.appendCodePoint(cp);
        });
        String r = sb.toString().strip().replaceAll("\\s+", " ");
        return r.length() > 80 ? r.substring(0, 80) : r;
    }

    /** Liest bis zu {@code max} Bytes vom Stream. */
    public static int readUpTo(InputStream in, byte[] buf, int max) throws IOException {
        int total = 0;
        while (total < max) {
            int r = in.read(buf, total, max - total);
            if (r < 0) break;
            total += r;
        }
        return total;
    }
}
