package de.blockrave.core.audio;

import io.github.jaredmdobson.concentus.OpusDecoder;
import io.github.jaredmdobson.concentus.OpusException;

/** Decodiert Opus-Frames zu interleavtem Stereo-Float (48 kHz). */
public final class OpusFrameDecoder {
    private final OpusDecoder decoder;
    private final short[] pcm = new short[AudioConstants.FRAME_SAMPLES * AudioConstants.CHANNELS * 6];

    public OpusFrameDecoder() {
        try {
            decoder = new OpusDecoder(AudioConstants.SAMPLE_RATE, AudioConstants.CHANNELS);
        } catch (OpusException e) {
            throw new IllegalStateException("Opus-Decoder konnte nicht erstellt werden", e);
        }
    }

    /**
     * @param data Opus-Paket oder {@code null} fuer Verlustverschleierung (PLC)
     * @param out  Ziel, mindestens FRAME_SAMPLES*2
     * @return decodierte Frames (normalerweise 960)
     */
    public int decode(byte[] data, float[] out) {
        try {
            int n;
            if (data == null) {
                n = decoder.decode(null, 0, 0, pcm, 0, AudioConstants.FRAME_SAMPLES, false);
            } else {
                n = decoder.decode(data, 0, data.length, pcm, 0, AudioConstants.FRAME_SAMPLES, false);
            }
            int samples = Math.min(n * 2, out.length);
            for (int i = 0; i < samples; i++) out[i] = pcm[i] / 32768f;
            return n;
        } catch (OpusException | RuntimeException e) {
            // Defektes Paket: Stille statt Absturz.
            java.util.Arrays.fill(out, 0, AudioConstants.FRAME_SAMPLES * 2, 0f);
            return AudioConstants.FRAME_SAMPLES;
        }
    }

    public void reset() {
        decoder.resetState();
    }
}
