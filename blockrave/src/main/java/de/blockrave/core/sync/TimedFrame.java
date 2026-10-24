package de.blockrave.core.sync;

import de.blockrave.core.analysis.AnalysisFrame;

/**
 * Ein codierter 20-ms-Audioframe mit seinem Abspielzeitpunkt auf der Serveruhr.
 *
 * @param streamId     Segment-ID; ein neues Segment setzt Decoder zurueck
 * @param sequence     fortlaufend innerhalb des Segments
 * @param playoutMicros Serverzeit, zu der der Frame hoerbar sein soll
 * @param opus         Opus-Daten (leer = nur Analysedaten, kein Audio)
 * @param analysis     Analyse fuer die Lichtshow
 */
public record TimedFrame(int streamId, int sequence, long playoutMicros, byte[] opus, AnalysisFrame analysis) {}
