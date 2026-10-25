package de.custompaintings.core;

import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

import javax.imageio.ImageIO;
import java.awt.Color;
import java.awt.Graphics2D;
import java.awt.image.BufferedImage;
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Arrays;

import static org.junit.jupiter.api.Assertions.*;

class CoreTest {
    @TempDir
    Path dir;

    /** Breites Testbild: links rot, Mitte gruen, rechts blau (je ein Drittel). */
    private static BufferedImage stripes(int w, int h) {
        BufferedImage img = new BufferedImage(w, h, BufferedImage.TYPE_INT_RGB);
        Graphics2D g = img.createGraphics();
        g.setColor(Color.RED);
        g.fillRect(0, 0, w / 3, h);
        g.setColor(Color.GREEN);
        g.fillRect(w / 3, 0, w / 3, h);
        g.setColor(Color.BLUE);
        g.fillRect(2 * w / 3, 0, w - 2 * w / 3, h);
        g.dispose();
        return img;
    }

    private static int[] rgb(BufferedImage img, int x, int y) {
        int c = img.getRGB(x, y);
        return new int[]{(c >> 16) & 0xFF, (c >> 8) & 0xFF, c & 0xFF};
    }

    @Test
    void zielgroesseJeBlock() {
        assertArrayEquals(new int[]{64, 64}, ImageFit.targetSize(1, 1, 64, 1024));
        assertArrayEquals(new int[]{256, 128}, ImageFit.targetSize(4, 2, 64, 1024));
        assertArrayEquals(new int[]{1024, 512}, ImageFit.targetSize(32, 16, 64, 1024)); // begrenzt
        assertThrows(IllegalArgumentException.class, () -> ImageFit.targetSize(0, 1, 64, 1024));
    }

    @Test
    void fuellenSchneidetMittigZu() {
        // 300x100 auf quadratisch: nur das mittlere (gruene) Drittel bleibt uebrig
        BufferedImage out = ImageFit.fit(stripes(300, 100), 64, 64, ImageFit.Mode.FILL, 0xFFFFFF);
        assertEquals(64, out.getWidth());
        assertEquals(64, out.getHeight());
        for (int x : new int[]{4, 32, 59}) {
            int[] c = rgb(out, x, 32);
            assertTrue(c[1] > 200 && c[0] < 60 && c[2] < 60, "ueberall gruen erwartet bei x=" + x + ": " + Arrays.toString(c));
        }
    }

    @Test
    void fuellenOhneRaender() {
        // Hochformat auf Querformat: muss ebenfalls vollflaechig sein (keine Hintergrundfarbe)
        BufferedImage tall = new BufferedImage(50, 200, BufferedImage.TYPE_INT_RGB);
        Graphics2D g = tall.createGraphics();
        g.setColor(Color.MAGENTA);
        g.fillRect(0, 0, 50, 200);
        g.dispose();
        BufferedImage out = ImageFit.fit(tall, 256, 128, ImageFit.Mode.FILL, 0x000000);
        for (int[] p : new int[][]{{0, 0}, {255, 0}, {0, 127}, {255, 127}, {128, 64}}) {
            int[] c = rgb(out, p[0], p[1]);
            assertTrue(c[0] > 200 && c[2] > 200 && c[1] < 60, "Ecke/Mitte nicht gefuellt: " + Arrays.toString(c));
        }
    }

    @Test
    void einpassenZeigtAllesMitRand() {
        BufferedImage out = ImageFit.fit(stripes(300, 100), 120, 120, ImageFit.Mode.FIT, 0x000000);
        assertArrayEquals(new int[]{0, 0, 0}, rgb(out, 60, 5), "oben Rand");
        assertTrue(rgb(out, 5, 60)[0] > 200, "links rot sichtbar");
        assertTrue(rgb(out, 114, 60)[2] > 200, "rechts blau sichtbar");
    }

    @Test
    void streckenBehaeltAlleTeile() {
        BufferedImage out = ImageFit.fit(stripes(300, 100), 60, 60, ImageFit.Mode.STRETCH, 0);
        assertTrue(rgb(out, 3, 30)[0] > 200);
        assertTrue(rgb(out, 30, 30)[1] > 200);
        assertTrue(rgb(out, 57, 30)[2] > 200);
    }

    @Test
    void transparenzWirdHintergrund() {
        BufferedImage alpha = new BufferedImage(10, 10, BufferedImage.TYPE_INT_ARGB);
        BufferedImage out = ImageFit.fit(alpha, 16, 16, ImageFit.Mode.FILL, 0xFFFFFF);
        assertArrayEquals(new int[]{255, 255, 255}, rgb(out, 8, 8));
    }

    @Test
    void starkesVerkleinern() {
        BufferedImage out = ImageFit.fit(stripes(6000, 2000), 192, 64, ImageFit.Mode.FILL, 0);
        assertEquals(192, out.getWidth());
        assertTrue(rgb(out, 10, 32)[0] > 200);
        assertTrue(rgb(out, 180, 32)[2] > 200);
    }

    private static byte[] encode(BufferedImage img, String format) throws IOException {
        ByteArrayOutputStream o = new ByteArrayOutputStream();
        assertTrue(ImageIO.write(img, format, o));
        return o.toByteArray();
    }

    @Test
    void liestPngJpegBmpGif() throws IOException {
        BufferedImage src = stripes(90, 30);
        for (String f : new String[]{"png", "jpg", "bmp", "gif"}) {
            BufferedImage r = ImageCodec.readSource(encode(src, f), "bild." + f);
            assertEquals(90, r.getWidth(), f);
            assertEquals(30, r.getHeight(), f);
        }
    }

    @Test
    void lehntUnbekanntesFormatVerstaendlichAb() {
        IOException e = assertThrows(IOException.class, () -> ImageCodec.readSource("RIFF....WEBPVP8 ".getBytes(), "foto.webp"));
        assertTrue(e.getMessage().contains("WebP"), e.getMessage());
        assertThrows(IOException.class, () -> ImageCodec.readSource(new byte[]{1, 2, 3}, "x.png"));
    }

    @Test
    void serverpruefungPng() throws IOException {
        byte[] png = ImageCodec.encodePng(ImageFit.fit(stripes(90, 30), 128, 64, ImageFit.Mode.FILL, 0));
        assertArrayEquals(new int[]{128, 64}, ImageCodec.pngSize(png));
        assertTrue(ImageCodec.validateUpload(png, 4 << 20, 1024).ok());
        assertFalse(ImageCodec.validateUpload(png, 100, 1024).ok(), "zu viele Bytes");
        assertFalse(ImageCodec.validateUpload(png, 4 << 20, 100).ok(), "zu grosse Abmessungen");
        assertFalse(ImageCodec.validateUpload(encode(stripes(30, 30), "jpg"), 4 << 20, 1024).ok(), "kein PNG");
        byte[] broken = Arrays.copyOf(png, png.length / 2);
        assertFalse(ImageCodec.validateUpload(broken, 4 << 20, 1024).ok(), "abgeschnitten");
        // Kopf behauptet riesige Abmessungen: wird vor dem Dekodieren abgelehnt
        byte[] bomb = png.clone();
        bomb[16] = 0x7F;
        assertFalse(ImageCodec.validateUpload(bomb, 4 << 20, 1024).ok());
    }

    @Test
    void speicherIstInhaltsadressiert() throws IOException {
        ImageStore store = new ImageStore(dir.resolve("bilder"));
        byte[] png = ImageCodec.encodePng(stripes(30, 30));
        String hash = ImageCodec.sha256(png);
        assertTrue(ImageCodec.isHash(hash));
        assertFalse(store.has(hash));
        store.write(hash, png);
        assertTrue(store.has(hash));
        assertArrayEquals(png, store.read(hash));
        assertEquals(png.length, store.totalBytes());
        assertThrows(IOException.class, () -> store.write("0".repeat(64), png), "falsche Pruefsumme");
        assertThrows(IllegalArgumentException.class, () -> store.path("../../etc/passwd"));
        assertFalse(store.has("../x"));
        try (var s = Files.list(store.dir())) {
            assertEquals(1, s.count(), "keine temporaeren Reste");
        }
    }

    @Test
    void bloeckeZusammensetzen() {
        ChunkAssembler a = new ChunkAssembler(10);
        assertFalse(a.append(2, new byte[]{1}), "Luecke");
        assertTrue(a.append(0, new byte[]{1, 2, 3, 4}));
        assertFalse(a.append(0, new byte[]{9}), "Ueberlappung");
        assertFalse(a.append(4, new byte[7]), "Ueberlauf");
        assertFalse(a.complete());
        assertThrows(IllegalStateException.class, a::data);
        assertTrue(a.append(4, new byte[]{5, 6, 7, 8, 9, 10}));
        assertTrue(a.complete());
        assertArrayEquals(new byte[]{1, 2, 3, 4, 5, 6, 7, 8, 9, 10}, a.data());
    }
}
