package de.blockrave.core.sync;

/**
 * Entscheidet anhand der gemessenen Abweichung zwischen tatsaechlicher und geplanter
 * Wiedergabeposition, wie stark die Wiedergabe beschleunigt/verlangsamt werden soll.
 *
 * <p>Fehler = (Serverzeit des gerade hoerbaren Samples) - (aktuelle Serverzeit).
 * Positiv: wir spielen zu frueh (zu weit vorne) &rarr; langsamer abspielen.
 */
public final class DriftController {
    /** Unterhalb dieser Abweichung wird nichts korrigiert (Mikrosekunden). */
    public static final long DEADBAND = 4_000;
    /** Ab dieser Abweichung wird hart neu synchronisiert (springen/auffuellen). */
    public static final long HARD_LIMIT = 120_000;
    /** Maximale Tempoabweichung der weichen Korrektur (0,6 %, praktisch unhoerbar). */
    public static final double MAX_RATE_DEVIATION = 0.006;

    private double filteredError;
    private boolean initialized;

    public enum Action { NONE, SOFT, HARD }

    public record Decision(Action action, double playbackRate, long hardCorrectionMicros) {}

    public Decision update(long errorMicros) {
        if (!initialized) {
            filteredError = errorMicros;
            initialized = true;
        } else {
            filteredError += (errorMicros - filteredError) * 0.2;
        }
        if (Math.abs(errorMicros) > HARD_LIMIT) {
            filteredError = 0;
            initialized = false;
            return new Decision(Action.HARD, 1.0, errorMicros);
        }
        if (Math.abs(filteredError) < DEADBAND) {
            return new Decision(Action.NONE, 1.0, 0);
        }
        // Proportionale Korrektur: 40 ms Fehler -> volle Abweichung, ueber ca. 7 s ausgeglichen.
        double rateDev = Math.max(-MAX_RATE_DEVIATION, Math.min(MAX_RATE_DEVIATION, filteredError / 40_000.0 * MAX_RATE_DEVIATION));
        // Fehler positiv (zu frueh) -> langsamer (Rate < 1)
        return new Decision(Action.SOFT, 1.0 - rateDev, 0);
    }

    public void reset() {
        initialized = false;
        filteredError = 0;
    }
}
