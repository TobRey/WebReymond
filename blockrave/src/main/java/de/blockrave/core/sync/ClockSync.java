package de.blockrave.core.sync;

/**
 * Schaetzt den Versatz zwischen lokaler Uhr und Serveruhr (NTP-artig).
 * Aus den letzten Messungen wird die mit der kuerzesten Laufzeit verwendet, weil sie den
 * geringsten Asymmetriefehler hat. Kleine Aenderungen werden sanft nachgefuehrt, damit die
 * Wiedergabe nicht springt.
 *
 * <p>Threadsicher.
 */
public final class ClockSync {
    private static final int WINDOW = 16;
    private final long[] rtt = new long[WINDOW];
    private final long[] offsets = new long[WINDOW];
    private int count;
    private int next;
    private double offsetMicros;
    private boolean valid;
    private long lastRtt;

    /**
     * @param clientSendMicros  lokale Zeit beim Senden der Anfrage
     * @param serverMicros      Serverzeit beim Beantworten
     * @param clientRecvMicros  lokale Zeit beim Empfang der Antwort
     */
    public synchronized void addSample(long clientSendMicros, long serverMicros, long clientRecvMicros) {
        long r = clientRecvMicros - clientSendMicros;
        if (r < 0 || r > 5_000_000) return; // unplausibel
        long off = serverMicros - (clientSendMicros + r / 2);
        rtt[next] = r;
        offsets[next] = off;
        next = (next + 1) % WINDOW;
        if (count < WINDOW) count++;
        lastRtt = r;
        int best = 0;
        for (int i = 1; i < count; i++) if (rtt[i] < rtt[best]) best = i;
        long target = offsets[best];
        if (!valid || Math.abs(target - offsetMicros) > 50_000) {
            offsetMicros = target; // erster Wert oder grosser Sprung: direkt uebernehmen
            valid = true;
        } else {
            double diff = target - offsetMicros;
            double maxStep = 2_000; // max. 2 ms pro Messung
            offsetMicros += Math.max(-maxStep, Math.min(maxStep, diff * 0.5));
        }
    }

    public synchronized boolean isValid() {
        return valid;
    }

    public synchronized int sampleCount() {
        return count;
    }

    /** Serverzeit (Mikrosekunden) zu einem lokalen Zeitpunkt. */
    public synchronized long toServer(long localMicros) {
        return localMicros + (long) offsetMicros;
    }

    public synchronized long toLocal(long serverMicros) {
        return serverMicros - (long) offsetMicros;
    }

    public synchronized long lastRttMicros() {
        return lastRtt;
    }

    public synchronized void reset() {
        count = 0;
        next = 0;
        valid = false;
        offsetMicros = 0;
    }

    /** Monotone lokale Uhr in Mikrosekunden. */
    public static long nowMicros() {
        return System.nanoTime() / 1000L;
    }
}
