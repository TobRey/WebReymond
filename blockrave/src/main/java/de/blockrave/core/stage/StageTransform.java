package de.blockrave.core.stage;

/**
 * Abbildung lokaler Buehnenkoordinaten auf Weltkoordinaten.
 *
 * <p>{@code rotation} entspricht Minecrafts 2D-Richtungsindex der Blickrichtung des Publikums
 * (Tiefenachse der Buehne): 0 = Sueden (+z), 1 = Westen (-x), 2 = Norden (-z), 3 = Osten (+x).
 * Der Anker ist die vordere Mitte der Buehne auf Bodenhoehe (lokal x=17, y=0, z=0).
 */
public record StageTransform(int anchorX, int anchorY, int anchorZ, int rotation) {
    private static final int[][] DIRS = {{0, 1}, {-1, 0}, {0, -1}, {1, 0}}; // S, W, N, E (dx, dz)

    public StageTransform {
        rotation = Math.floorMod(rotation, 4);
    }

    /** Tiefenrichtung (lokal +z) als {dx, dz}. */
    public int[] forward() {
        return DIRS[rotation];
    }

    /** Rechtsrichtung (lokal +x) aus Sicht des Publikums als {dx, dz}. */
    public int[] right() {
        return DIRS[(rotation + 1) % 4];
    }

    public int worldX(int lx, int lz) {
        return anchorX + (lx - StageLayout.ANCHOR_X) * right()[0] + lz * forward()[0];
    }

    public int worldY(int ly) {
        return anchorY + ly;
    }

    public int worldZ(int lx, int lz) {
        return anchorZ + (lx - StageLayout.ANCHOR_X) * right()[1] + lz * forward()[1];
    }

    /** Lokale Fliesskomma-Position in Weltkoordinaten (Blockmitte = +0.5). */
    public double[] toWorld(double lx, double ly, double lz) {
        int[] f = forward(), r = right();
        double ox = lx - StageLayout.ANCHOR_X;
        // Blockmittelpunkte: lokale Blockecke liegt je nach Drehung auf anderer Weltecke
        double wx = anchorX + 0.5 + (ox - 0.5) * r[0] + (lz - 0.5) * f[0];
        double wz = anchorZ + 0.5 + (ox - 0.5) * r[1] + (lz - 0.5) * f[1];
        return new double[]{wx, anchorY + ly, wz};
    }

    /** Dreht einen lokalen Richtungsvektor in Weltrichtung. */
    public double[] dirToWorld(double dx, double dy, double dz) {
        int[] f = forward(), r = right();
        return new double[]{dx * r[0] + dz * f[0], dy, dx * r[1] + dz * f[1]};
    }

    /** Weltrichtung einer lokalen Richtung als 2D-Index (0=S,1=W,2=N,3=E) oder -1 fuer UP, -2 fuer DOWN. */
    public int worldHorizontalIndex(LocalDir d) {
        return switch (d) {
            case BACK -> rotation;
            case FRONT -> (rotation + 2) % 4;
            case RIGHT -> (rotation + 1) % 4;
            case LEFT -> (rotation + 3) % 4;
            case UP -> -1;
            case DOWN -> -2;
        };
    }

    /** Minimale/Maximale Weltkoordinaten des Buehnenquaders {minX,minY,minZ,maxX,maxY,maxZ}. */
    public int[] bounds() {
        int x0 = worldX(0, 0), z0 = worldZ(0, 0);
        int x1 = worldX(StageLayout.WIDTH - 1, StageLayout.DEPTH - 1), z1 = worldZ(StageLayout.WIDTH - 1, StageLayout.DEPTH - 1);
        return new int[]{Math.min(x0, x1), anchorY, Math.min(z0, z1), Math.max(x0, x1), anchorY + StageLayout.HEIGHT - 1,
                Math.max(z0, z1)};
    }
}
