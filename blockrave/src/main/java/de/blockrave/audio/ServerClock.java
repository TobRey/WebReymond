package de.blockrave.audio;

/** Monotone Serveruhr in Mikrosekunden; Bezug fuer alle Abspielzeitpunkte. */
public final class ServerClock {
    private ServerClock() {}

    public static long nowMicros() {
        return System.nanoTime() / 1000L;
    }
}
