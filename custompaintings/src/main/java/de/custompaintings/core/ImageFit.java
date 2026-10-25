package de.custompaintings.core;

import java.awt.AlphaComposite;
import java.awt.Color;
import java.awt.Graphics2D;
import java.awt.RenderingHints;
import java.awt.image.BufferedImage;

/**
 * Bringt ein beliebiges Bild auf das Seitenverhaeltnis eines Gemaeldes.
 *
 * <ul>
 *   <li>{@link Mode#FILL}: fuellt das Gemaelde vollstaendig, ueberstehende Raender werden mittig abgeschnitten
 *   (Standard).</li>
 *   <li>{@link Mode#FIT}: ganzes Bild sichtbar, freie Flaechen werden mit der Randfarbe gefuellt.</li>
 *   <li>{@link Mode#STRETCH}: auf das Format gezogen (verzerrt).</li>
 * </ul>
 * Transparente Bereiche werden auf die Hintergrundfarbe gelegt, weil Gemaelde undurchsichtig sind.
 */
public final class ImageFit {
    public enum Mode { FILL, FIT, STRETCH }

    /** Pixel pro Block, mit denen eigene Bilder gespeichert werden (Vanilla-Gemaelde: 16). */
    public static final int DEFAULT_PIXELS_PER_BLOCK = 64;
    /** Groesste erlaubte Kantenlaenge eines gespeicherten Bildes. */
    public static final int MAX_SIDE = 1024;

    private ImageFit() {}

    /** Zielgroesse in Pixeln fuer ein Gemaelde von {@code wBlocks} x {@code hBlocks}. */
    public static int[] targetSize(int wBlocks, int hBlocks, int pixelsPerBlock, int maxSide) {
        if (wBlocks <= 0 || hBlocks <= 0) throw new IllegalArgumentException("Gemaeldegroesse ungueltig");
        int w = wBlocks * pixelsPerBlock, h = hBlocks * pixelsPerBlock;
        int longest = Math.max(w, h);
        if (longest > maxSide) {
            double s = (double) maxSide / longest;
            w = Math.max(1, (int) Math.round(w * s));
            h = Math.max(1, (int) Math.round(h * s));
        }
        return new int[]{w, h};
    }

    public static BufferedImage fit(BufferedImage src, int tw, int th, Mode mode, int backgroundRgb) {
        if (tw <= 0 || th <= 0) throw new IllegalArgumentException("Zielgroesse ungueltig");
        BufferedImage flat = flatten(src, backgroundRgb);
        int sw = flat.getWidth(), sh = flat.getHeight();
        // Quellausschnitt (sx, sy, cw, ch) und Zielrechteck (dx, dy, dw, dh)
        int sx = 0, sy = 0, cw = sw, ch = sh, dx = 0, dy = 0, dw = tw, dh = th;
        switch (mode) {
            case FILL -> {
                double scale = Math.max((double) tw / sw, (double) th / sh);
                cw = Math.max(1, Math.min(sw, (int) Math.round(tw / scale)));
                ch = Math.max(1, Math.min(sh, (int) Math.round(th / scale)));
                sx = (sw - cw) / 2;
                sy = (sh - ch) / 2;
            }
            case FIT -> {
                double scale = Math.min((double) tw / sw, (double) th / sh);
                dw = Math.max(1, (int) Math.round(sw * scale));
                dh = Math.max(1, (int) Math.round(sh * scale));
                dx = (tw - dw) / 2;
                dy = (th - dh) / 2;
            }
            case STRETCH -> { }
        }
        BufferedImage part = flat.getSubimage(sx, sy, cw, ch);
        BufferedImage scaled = scale(part, dw, dh);
        BufferedImage out = new BufferedImage(tw, th, BufferedImage.TYPE_INT_RGB);
        Graphics2D g = out.createGraphics();
        try {
            g.setColor(new Color(backgroundRgb));
            g.fillRect(0, 0, tw, th);
            g.drawImage(scaled, dx, dy, null);
        } finally {
            g.dispose();
        }
        return out;
    }

    /** Legt das Bild auf eine undurchsichtige Flaeche (Transparenz -> Hintergrundfarbe). */
    private static BufferedImage flatten(BufferedImage src, int backgroundRgb) {
        BufferedImage out = new BufferedImage(src.getWidth(), src.getHeight(), BufferedImage.TYPE_INT_RGB);
        Graphics2D g = out.createGraphics();
        try {
            g.setColor(new Color(backgroundRgb));
            g.fillRect(0, 0, out.getWidth(), out.getHeight());
            g.setComposite(AlphaComposite.SrcOver);
            g.drawImage(src, 0, 0, null);
        } finally {
            g.dispose();
        }
        return out;
    }

    /** Hochwertiges Skalieren; starkes Verkleinern schrittweise (halbieren), damit nichts flimmert. */
    static BufferedImage scale(BufferedImage src, int w, int h) {
        BufferedImage cur = src;
        int cw = src.getWidth(), ch = src.getHeight();
        while (cw / 2 >= w && ch / 2 >= h) {
            cw /= 2;
            ch /= 2;
            cur = draw(cur, cw, ch);
        }
        return cw == w && ch == h && cur != src ? cur : draw(cur, w, h);
    }

    private static BufferedImage draw(BufferedImage src, int w, int h) {
        BufferedImage out = new BufferedImage(w, h, BufferedImage.TYPE_INT_RGB);
        Graphics2D g = out.createGraphics();
        try {
            g.setRenderingHint(RenderingHints.KEY_INTERPOLATION, RenderingHints.VALUE_INTERPOLATION_BICUBIC);
            g.setRenderingHint(RenderingHints.KEY_RENDERING, RenderingHints.VALUE_RENDER_QUALITY);
            g.setRenderingHint(RenderingHints.KEY_ANTIALIASING, RenderingHints.VALUE_ANTIALIAS_ON);
            g.drawImage(src, 0, 0, w, h, null);
        } finally {
            g.dispose();
        }
        return out;
    }
}
