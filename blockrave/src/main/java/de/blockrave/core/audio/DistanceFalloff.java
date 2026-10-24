package de.blockrave.core.audio;

/**
 * Lautstaerkeverlauf ueber die Entfernung zur Buehne: bis 16 Bloecke (bzw. einem Viertel der
 * Reichweite) volle Lautstaerke, danach sanfter Kosinus-Abfall bis 0 genau an der Reichweite,
 * dahinter stumm.
 */
public final class DistanceFalloff {
    private DistanceFalloff() {}

    public static float gain(double distance, double range) {
        double full = Math.min(16, range * 0.25);
        if (distance <= full) return 1f;
        if (distance >= range) return 0f;
        double t = (distance - full) / (range - full);
        return (float) (0.5 * (1 + Math.cos(Math.PI * t)));
    }
}
