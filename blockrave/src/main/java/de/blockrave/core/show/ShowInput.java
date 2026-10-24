package de.blockrave.core.show;

import de.blockrave.core.analysis.AnalysisFrame;

/**
 * Momentaufnahme der gehoerten Musik fuer die Lichtshow. Wird pro Bild aus der
 * synchronisierten Zeitleiste gebildet; alle Werte beziehen sich auf die Abspielzeit.
 */
public final class ShowInput {
    public static final long NEVER = Long.MAX_VALUE / 4;

    /** Musik laeuft (MP3 oder Live). */
    public boolean active;
    /** Abspielzeit auf der Serveruhr. */
    public long timeMicros;
    /** Analyseframe, der gerade zu hoeren ist. */
    public AnalysisFrame frame = AnalysisFrame.SILENCE;
    /** Kontinuierliche Beatposition (Beatindex + Phase). */
    public double beatPos;
    public long sinceBeatMicros = NEVER;
    public long sinceBassMicros = NEVER;
    public long sinceOnsetMicros = NEVER;
    public long sincePeakMicros = NEVER;
}
