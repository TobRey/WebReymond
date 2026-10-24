package de.blockrave.core.audio;

import io.github.jaredmdobson.concentus.OpusApplication;
import io.github.jaredmdobson.concentus.OpusEncoder;
import io.github.jaredmdobson.concentus.OpusException;
import io.github.jaredmdobson.concentus.OpusSignal;

/** Codiert 20-ms-Stereo-Frames (48 kHz) mit Opus (Concentus, reines Java). */
public final class OpusFrameEncoder {
    private final OpusEncoder encoder;
    private final short[] pcm = new short[AudioConstants.FRAME_SAMPLES * AudioConstants.CHANNELS];
    private final byte[] out = new byte[AudioConstants.MAX_OPUS_PACKET];

    public OpusFrameEncoder(int bitrate, int complexity) {
        try {
            encoder = new OpusEncoder(AudioConstants.SAMPLE_RATE, AudioConstants.CHANNELS, OpusApplication.OPUS_APPLICATION_AUDIO);
        } catch (OpusException e) {
            throw new IllegalStateException("Opus-Encoder konnte nicht erstellt werden", e);
        }
        encoder.setBitrate(bitrate);
        encoder.setSignalType(OpusSignal.OPUS_SIGNAL_MUSIC);
        encoder.setComplexity(Math.max(0, Math.min(10, complexity)));
        encoder.setUseVBR(true);
        encoder.setUseConstrainedVBR(true);
    }

    /** @param frame interleavtes Stereo-Float, genau {@link AudioConstants#FRAME_SAMPLES} Frames */
    public byte[] encode(float[] frame) {
        for (int i = 0; i < pcm.length; i++) {
            float v = frame[i];
            if (v > 1f) v = 1f;
            else if (v < -1f) v = -1f;
            pcm[i] = (short) Math.round(v * 32767f);
        }
        try {
            int len = encoder.encode(pcm, 0, AudioConstants.FRAME_SAMPLES, out, 0, out.length);
            byte[] r = new byte[len];
            System.arraycopy(out, 0, r, 0, len);
            return r;
        } catch (OpusException e) {
            throw new IllegalStateException("Opus-Codierung fehlgeschlagen", e);
        }
    }

    public void reset() {
        encoder.resetState();
    }
}
