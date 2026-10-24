package de.blockrave.client.screen;

import net.minecraft.client.gui.GuiGraphics;

/** Zeichnet Flaechen im klassischen Minecraft-Inventarstil (hellgrau, abgesetzte Kanten). */
public final class GuiStyle {
    public static final int TEXT = 0x404040;
    public static final int TEXT_LIGHT = 0xFFFFFF;
    public static final int RED = 0xC02020;
    public static final int GREEN = 0x207020;

    private GuiStyle() {}

    /** Erhabenes Fenster wie das Spielerinventar. */
    public static void panel(GuiGraphics g, int x, int y, int w, int h) {
        g.fill(x + 1, y, x + w - 1, y + h, 0xFF000000);
        g.fill(x, y + 1, x + w, y + h - 1, 0xFF000000);
        g.fill(x + 1, y + 1, x + w - 1, y + h - 1, 0xFFC6C6C6);
        g.fill(x + 1, y + 1, x + w - 2, y + 3, 0xFFFFFFFF);
        g.fill(x + 1, y + 1, x + 3, y + h - 2, 0xFFFFFFFF);
        g.fill(x + 3, y + h - 3, x + w - 1, y + h - 1, 0xFF555555);
        g.fill(x + w - 3, y + 3, x + w - 1, y + h - 1, 0xFF555555);
    }

    /** Vertiefte Flaeche wie ein Inventarplatz. */
    public static void inset(GuiGraphics g, int x, int y, int w, int h) {
        g.fill(x, y, x + w, y + h, 0xFF8B8B8B);
        g.fill(x, y, x + w - 1, y + 1, 0xFF373737);
        g.fill(x, y, x + 1, y + h - 1, 0xFF373737);
        g.fill(x + 1, y + h - 1, x + w, y + h, 0xFFFFFFFF);
        g.fill(x + w - 1, y + 1, x + w, y + h, 0xFFFFFFFF);
    }

    /** Fortschrittsbalken in einer vertieften Flaeche. */
    public static void bar(GuiGraphics g, int x, int y, int w, int h, float progress, int color) {
        inset(g, x, y, w, h);
        int fill = (int) ((w - 2) * Math.max(0f, Math.min(1f, progress)));
        if (fill > 0) g.fill(x + 1, y + 1, x + 1 + fill, y + h - 1, 0xFF000000 | color);
    }

    public static String time(long micros) {
        long s = Math.max(0, micros / 1_000_000);
        return String.format("%d:%02d", s / 60, s % 60);
    }
}
