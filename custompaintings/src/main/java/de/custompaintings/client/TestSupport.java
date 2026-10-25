package de.custompaintings.client;

import com.mojang.blaze3d.platform.NativeImage;
import de.custompaintings.CustomPaintings;
import net.minecraft.client.Minecraft;
import net.minecraft.client.Screenshot;
import net.minecraft.world.entity.decoration.Painting;
import net.minecraft.world.entity.decoration.PaintingVariant;
import net.minecraft.world.phys.AABB;

import java.awt.Color;
import java.awt.Graphics2D;
import java.awt.image.BufferedImage;
import java.util.ArrayList;
import java.util.List;
import java.util.function.BooleanSupplier;

/** Gemeinsame Hilfen fuer den Client-Rauchtest und den Mehrspieler-E2E-Test (nur in automatischen Tests aktiv). */
final class TestSupport {
    private TestSupport() {}

    interface Step {
        /** @return true, wenn erledigt */
        boolean run();
    }

    record Phase(String name, long timeoutMs, Step step) {}

    /** Arbeitet Schritte nacheinander ab, protokolliert OK/FAIL und beendet danach den Client. */
    static final class Runner {
        private final String tag;
        private final List<Phase> phases = new ArrayList<>();
        private int index;
        private long phaseStart = System.currentTimeMillis();
        private long waitUntil;
        int failures;

        Runner(String tag) {
            this.tag = tag;
        }

        void add(String name, long timeoutMs, Step step) {
            phases.add(new Phase(name, timeoutMs, step));
        }

        void log(String s) {
            CustomPaintings.LOG.info("{}: {}", tag, s);
        }

        void ok(boolean ok, String what) {
            log((ok ? "OK   " : "FAIL ") + what);
            if (!ok) failures++;
        }

        /** Wartet nicht-blockierend; true nach Ablauf. */
        boolean sleep(long ms) {
            long now = System.currentTimeMillis();
            if (waitUntil == 0) waitUntil = now + ms;
            if (now < waitUntil) return false;
            waitUntil = 0;
            return true;
        }

        /** Pro Client-Tick aufrufen. */
        void tick(BooleanSupplier ready) {
            if (index >= phases.size()) return;
            Phase p = phases.get(index);
            boolean done;
            try {
                done = ready.getAsBoolean() && p.step().run();
            } catch (Exception e) {
                CustomPaintings.LOG.error("{}: Ausnahme in Schritt '{}'", tag, p.name(), e);
                ok(false, "Schritt '" + p.name() + "' Ausnahme: " + e);
                done = true;
            }
            long now = System.currentTimeMillis();
            if (!done && now - phaseStart > p.timeoutMs()) {
                ok(false, "Zeitueberschreitung in Schritt '" + p.name() + "'");
                done = true;
            }
            if (done) {
                index++;
                phaseStart = now;
                waitUntil = 0;
                if (index >= phases.size()) {
                    log(failures == 0 ? "ERGEBNIS: alle Pruefungen bestanden" : "ERGEBNIS: " + failures + " Pruefung(en) fehlgeschlagen");
                    Runtime.getRuntime().halt(failures == 0 ? 0 : 1);
                }
            }
        }

        String currentPhase() {
            return index < phases.size() ? phases.get(index).name() : "-";
        }
    }

    /** Testbild: linke Haelfte rot, rechte Haelfte blau (prueft Sichtbarkeit und Ausrichtung). */
    static BufferedImage leftRedRightBlue(int w, int h) {
        BufferedImage img = new BufferedImage(w, h, BufferedImage.TYPE_INT_RGB);
        Graphics2D g = img.createGraphics();
        g.setColor(new Color(230, 20, 20));
        g.fillRect(0, 0, w / 2, h);
        g.setColor(new Color(20, 40, 230));
        g.fillRect(w / 2, 0, w - w / 2, h);
        g.dispose();
        return img;
    }

    static Painting nearestPainting(double radius) {
        Minecraft mc = Minecraft.getInstance();
        if (mc.player == null || mc.level == null) return null;
        AABB box = mc.player.getBoundingBox().inflate(radius);
        Painting best = null;
        double bestD = Double.MAX_VALUE;
        for (Painting p : mc.level.getEntitiesOfClass(Painting.class, box)) {
            double d = p.distanceToSqr(mc.player);
            if (d < bestD) {
                bestD = d;
                best = p;
            }
        }
        return best;
    }

    /**
     * Bildschirmspalten, an denen die Mitte der linken bzw. rechten Gemaeldehaelfte zu sehen ist, wenn die Kamera
     * genau auf die Gemaeldemitte blickt (Perspektive mit eingestelltem Sichtfeld).
     */
    static int[] sampleColumns(Painting p, int screenW, int screenH) {
        Minecraft mc = Minecraft.getInstance();
        PaintingVariant v = p.getVariant().value();
        double d = mc.gameRenderer.getMainCamera().getPosition().distanceTo(p.position());
        double tanHalfV = Math.tan(Math.toRadians(mc.options.fov().get() / 2.0));
        double aspect = screenW / (double) screenH;
        double offset = (v.width() / 4.0) / d / (tanHalfV * aspect) * (screenW / 2.0);
        return new int[]{(int) (screenW / 2.0 - offset), (int) (screenW / 2.0 + offset)};
    }

    /** Liest die aktuelle Bildschirmfarbe {r, g, b} an zwei Stellen (Mitte links/rechts) aus. */
    static int[][] samplePaintingColors(Painting p) {
        Minecraft mc = Minecraft.getInstance();
        try (NativeImage img = Screenshot.takeScreenshot(mc.getMainRenderTarget())) {
            int w = img.getWidth(), h = img.getHeight();
            int[] cols = sampleColumns(p, w, h);
            int[][] out = new int[2][];
            for (int i = 0; i < 2; i++) {
                long r = 0, g = 0, b = 0;
                int n = 0;
                for (int dx = -2; dx <= 2; dx++) {
                    for (int dy = -2; dy <= 2; dy++) {
                        int c = img.getPixelRGBA(Math.max(0, Math.min(w - 1, cols[i] + dx)), Math.max(0, Math.min(h - 1, h / 2 + dy)));
                        // NativeImage speichert ABGR
                        r += c & 0xFF;
                        g += (c >> 8) & 0xFF;
                        b += (c >> 16) & 0xFF;
                        n++;
                    }
                }
                out[i] = new int[]{(int) (r / n), (int) (g / n), (int) (b / n)};
            }
            return out;
        }
    }

    static boolean reddish(int[] c) {
        return c[0] > 60 && c[0] > c[2] * 2 && c[0] > c[1] * 2;
    }

    static boolean bluish(int[] c) {
        return c[2] > 60 && c[2] > c[0] * 2 && c[2] > c[1] * 1.5;
    }

    static String fmt(int[] c) {
        return "(" + c[0] + "," + c[1] + "," + c[2] + ")";
    }

    static void screenshot(String name, Runner r) {
        Minecraft mc = Minecraft.getInstance();
        Screenshot.grab(mc.gameDirectory, "custompaintings_" + name + ".png", mc.getMainRenderTarget(),
                c -> r.log("Screenshot " + name + ": " + c.getString()));
    }
}
