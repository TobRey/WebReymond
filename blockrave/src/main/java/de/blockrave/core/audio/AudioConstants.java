package de.blockrave.core.audio;

/**
 * Gemeinsames Audioformat aller BlockRave-Streams (MP3 und Live).
 * Alles wird intern auf 48 kHz Stereo gebracht und in 20-ms-Frames mit Opus codiert.
 */
public final class AudioConstants {
    public static final int SAMPLE_RATE = 48_000;
    public static final int CHANNELS = 2;
    /** Samples pro Kanal in einem 20-ms-Frame. */
    public static final int FRAME_SAMPLES = 960;
    public static final int FRAME_MILLIS = 20;
    /** Maximale Groesse eines Opus-Pakets laut RFC 6716. */
    public static final int MAX_OPUS_PACKET = 1275;
    /** Anzahl Frames, die zusammen in einem Netzwerkpaket reisen (40 ms). */
    public static final int FRAMES_PER_PACKET = 2;

    private AudioConstants() {}

    public static long samplesToMicros(long samples) {
        return samples * 1_000_000L / SAMPLE_RATE;
    }

    public static long microsToSamples(long micros) {
        return micros * SAMPLE_RATE / 1_000_000L;
    }
}
