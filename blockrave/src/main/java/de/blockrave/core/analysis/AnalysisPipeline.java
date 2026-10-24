package de.blockrave.core.analysis;

import de.blockrave.core.audio.AudioConstants;

/**
 * Verbindet Audio und Analyse zeitrichtig: Die Analyse erkennt Ereignisse mit etwa 40&ndash;50 ms
 * Verzoegerung (FFT-Fenster, Spitzenwertsuche). Deshalb wird das Audio um {@link #DELAY_FRAMES}
 * Frames verzoegert, bevor es zusammen mit den Analysewerten verschickt wird. So leuchten Akzente
 * genau dann, wenn der zugehoerige Ton zu hoeren ist.
 */
public final class AnalysisPipeline {
    public static final int DELAY_FRAMES = 2;

    public record Output(float[] audio, AnalysisFrame analysis) {}

    private final AudioAnalyzer analyzer = new AudioAnalyzer();
    private final float[][] delay = new float[DELAY_FRAMES][];
    private int pos;

    public AnalysisPipeline() {
        for (int i = 0; i < DELAY_FRAMES; i++) delay[i] = new float[AudioConstants.FRAME_SAMPLES * 2];
    }

    /** Nimmt einen Frame entgegen und liefert den um DELAY_FRAMES verzoegerten Frame mit passender Analyse. */
    public Output process(float[] frame) {
        AnalysisFrame a = analyzer.analyze(frame);
        float[] out = delay[pos];
        delay[pos] = frame.clone();
        pos = (pos + 1) % DELAY_FRAMES;
        return new Output(out, a);
    }

    public AudioAnalyzer analyzer() {
        return analyzer;
    }
}
