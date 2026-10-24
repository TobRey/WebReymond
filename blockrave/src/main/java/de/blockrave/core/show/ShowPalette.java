package de.blockrave.core.show;

/** Feste Farbpalette der Show (wie im Referenzbild): Pink, Cyan, Gelb, Gruen und Weiss. */
public final class ShowPalette {
    public static final int PINK = 0xFF2EA6;
    public static final int CYAN = 0x00E5FF;
    public static final int YELLOW = 0xFFE01A;
    public static final int GREEN = 0x39FF14;
    public static final int WHITE = 0xFFFFFF;
    public static final int[] COLORS = {PINK, CYAN, YELLOW, GREEN};

    private ShowPalette() {}

    public static int color(int index) {
        return COLORS[Math.floorMod(index, COLORS.length)];
    }

    public static int scale(int rgb, float f) {
        f = Math.max(0f, Math.min(1f, f));
        int r = Math.round(((rgb >> 16) & 0xFF) * f);
        int g = Math.round(((rgb >> 8) & 0xFF) * f);
        int b = Math.round((rgb & 0xFF) * f);
        return (r << 16) | (g << 8) | b;
    }

    public static int mix(int a, int b, float t) {
        t = Math.max(0f, Math.min(1f, t));
        int r = Math.round(((a >> 16) & 0xFF) * (1 - t) + ((b >> 16) & 0xFF) * t);
        int g = Math.round(((a >> 8) & 0xFF) * (1 - t) + ((b >> 8) & 0xFF) * t);
        int bl = Math.round((a & 0xFF) * (1 - t) + (b & 0xFF) * t);
        return (r << 16) | (g << 8) | bl;
    }
}
