package de.custompaintings.core;

import javax.imageio.ImageIO;
import java.awt.image.BufferedImage;
import java.io.ByteArrayInputStream;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.HexFormat;
import java.util.Locale;

/**
 * Lesen, Pruefen und Kodieren der Bilddaten. Gespeichert und uebertragen wird immer PNG; eingelesen werden
 * alle Formate, die Java von Haus aus kann (PNG, JPEG, BMP, GIF &ndash; bei GIF das erste Bild).
 */
public final class ImageCodec {
    private static final byte[] PNG_SIGNATURE = {(byte) 0x89, 'P', 'N', 'G', 0x0D, 0x0A, 0x1A, 0x0A};
    /** Groesste Datei, die ueberhaupt eingelesen wird (Schutz vor riesigen Dateien). */
    public static final long MAX_SOURCE_BYTES = 64L * 1024 * 1024;
    /** Groesste Kantenlaenge eines Quellbildes (Schutz vor Speicherueberlauf beim Dekodieren). */
    public static final int MAX_SOURCE_SIDE = 12_000;

    public record Validation(boolean ok, String error, int width, int height) {
        static Validation fail(String error) {
            return new Validation(false, error, 0, 0);
        }
    }

    private ImageCodec() {}

    public static String sha256(byte[] data) {
        try {
            return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(data));
        } catch (NoSuchAlgorithmException e) {
            throw new IllegalStateException(e);
        }
    }

    public static boolean isHash(String s) {
        return s != null && s.length() == 64 && s.chars().allMatch(c -> (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f'));
    }

    /** Liest eine Bilddatei des Spielers ein. */
    public static BufferedImage readSource(Path file) throws IOException {
        long size = Files.size(file);
        if (size <= 0) throw new IOException("Die Datei ist leer.");
        if (size > MAX_SOURCE_BYTES) throw new IOException("Die Datei ist zu gross (max. 64 MB).");
        return readSource(Files.readAllBytes(file), file.getFileName().toString());
    }

    public static BufferedImage readSource(byte[] data, String name) throws IOException {
        int[] dims = pngSize(data);
        if (dims != null && (dims[0] > MAX_SOURCE_SIDE || dims[1] > MAX_SOURCE_SIDE)) {
            throw new IOException("Das Bild ist zu gross (max. " + MAX_SOURCE_SIDE + " Pixel Kantenlaenge).");
        }
        BufferedImage img;
        try {
            img = ImageIO.read(new ByteArrayInputStream(data));
        } catch (IOException | RuntimeException e) {
            throw new IOException("Bild konnte nicht gelesen werden: " + e.getMessage(), e);
        }
        if (img == null) {
            String lower = name == null ? "" : name.toLowerCase(Locale.ROOT);
            String hint = lower.endsWith(".webp") || lower.endsWith(".heic") || lower.endsWith(".avif")
                    ? " WebP/HEIC/AVIF werden nicht unterstuetzt – bitte als PNG oder JPEG speichern."
                    : " Unterstuetzt: PNG, JPEG, BMP, GIF.";
            throw new IOException("Unbekanntes Bildformat." + hint);
        }
        if (img.getWidth() > MAX_SOURCE_SIDE || img.getHeight() > MAX_SOURCE_SIDE) {
            throw new IOException("Das Bild ist zu gross (max. " + MAX_SOURCE_SIDE + " Pixel Kantenlaenge).");
        }
        return img;
    }

    public static byte[] encodePng(BufferedImage img) {
        try {
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            if (!ImageIO.write(img, "png", out)) throw new IOException("kein PNG-Schreiber");
            return out.toByteArray();
        } catch (IOException e) {
            throw new IllegalStateException("PNG konnte nicht erzeugt werden", e);
        }
    }

    /** Breite/Hoehe aus dem PNG-Kopf (IHDR) oder null, wenn kein PNG. Liest keine Bilddaten. */
    public static int[] pngSize(byte[] data) {
        if (data.length < 24) return null;
        for (int i = 0; i < 8; i++) if (data[i] != PNG_SIGNATURE[i]) return null;
        if (data[12] != 'I' || data[13] != 'H' || data[14] != 'D' || data[15] != 'R') return null;
        return new int[]{be32(data, 16), be32(data, 20)};
    }

    private static int be32(byte[] b, int o) {
        return ((b[o] & 0xFF) << 24) | ((b[o + 1] & 0xFF) << 16) | ((b[o + 2] & 0xFF) << 8) | (b[o + 3] & 0xFF);
    }

    /**
     * Prueft ein hochgeladenes Gemaeldebild auf dem Server: echtes PNG, Groesse und Abmessungen in den Grenzen,
     * vollstaendig dekodierbar. Die Abmessungen werden vor dem Dekodieren aus dem Kopf gelesen.
     */
    public static Validation validateUpload(byte[] data, int maxBytes, int maxSide) {
        if (data.length == 0) return Validation.fail("leere Datei");
        if (data.length > maxBytes) return Validation.fail("Bild zu gross (" + data.length / 1024 + " KB, erlaubt " + maxBytes / 1024 + " KB)");
        int[] dims = pngSize(data);
        if (dims == null) return Validation.fail("kein PNG");
        if (dims[0] <= 0 || dims[1] <= 0 || dims[0] > maxSide || dims[1] > maxSide)
            return Validation.fail("Abmessungen " + dims[0] + "x" + dims[1] + " nicht erlaubt (max. " + maxSide + ")");
        BufferedImage img;
        try {
            img = ImageIO.read(new ByteArrayInputStream(data));
        } catch (IOException | RuntimeException e) {
            return Validation.fail("PNG fehlerhaft: " + e.getMessage());
        }
        if (img == null || img.getWidth() != dims[0] || img.getHeight() != dims[1]) return Validation.fail("PNG fehlerhaft");
        return new Validation(true, null, dims[0], dims[1]);
    }
}
