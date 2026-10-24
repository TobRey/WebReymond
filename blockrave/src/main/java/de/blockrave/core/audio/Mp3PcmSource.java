package de.blockrave.core.audio;

import javazoom.jl.decoder.Bitstream;
import javazoom.jl.decoder.BitstreamException;
import javazoom.jl.decoder.Decoder;
import javazoom.jl.decoder.DecoderException;
import javazoom.jl.decoder.Header;
import javazoom.jl.decoder.SampleBuffer;

import java.io.BufferedInputStream;
import java.io.Closeable;
import java.io.IOException;
import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;

/**
 * Decodiert eine MP3-Datei schrittweise zu 48-kHz-Stereo-Float-Frames.
 * Wird ausschliesslich auf Hintergrund-Threads benutzt.
 */
public final class Mp3PcmSource implements Closeable {
    /** Nach so vielen aufeinanderfolgenden defekten Frames wird abgebrochen. */
    private static final int MAX_CONSECUTIVE_ERRORS = 64;

    private final InputStream raw;
    private final Bitstream bitstream;
    private final Decoder decoder = new Decoder();
    private final int sampleRate;
    private SincResampler resampler; // null wenn bereits 48 kHz
    private final float[] tmp = new float[4608];
    private Header pendingHeader;
    private boolean eof;

    public Mp3PcmSource(Path file) throws IOException {
        this.raw = new BufferedInputStream(Files.newInputStream(file), 64 * 1024);
        // JLayer ueberspringt ID3v2-Tags selbststaendig.
        this.bitstream = new Bitstream(raw);
        try {
            pendingHeader = bitstream.readFrame();
        } catch (BitstreamException e) {
            raw.close();
            throw new IOException("MP3 konnte nicht gelesen werden: " + e.getMessage(), e);
        }
        if (pendingHeader == null) {
            raw.close();
            throw new IOException("MP3 enthaelt keine Audioframes");
        }
        this.sampleRate = pendingHeader.frequency();
        this.resampler = sampleRate == AudioConstants.SAMPLE_RATE ? null
                : new SincResampler(2, sampleRate, AudioConstants.SAMPLE_RATE, 16);
    }

    public int sourceSampleRate() {
        return sampleRate;
    }

    /**
     * Fuellt {@code out} (interleavt Stereo, Laenge >= frames*2) mit bis zu {@code frames} Frames.
     *
     * @return Anzahl Frames; 0 bedeutet Dateiende
     */
    public int read(float[] out, int frames) throws IOException {
        int done = 0;
        while (done < frames) {
            if (resampler != null) {
                done += resampler.pull(out, done, frames - done);
                if (done >= frames) break;
                if (eof) {
                    if (!flushed) {
                        // Filter-Nachlauf mit Stille ausspuelen, damit das Dateiende hoerbar bleibt.
                        resampler.push(new float[64 * 2], 0, 64);
                        flushed = true;
                        continue;
                    }
                    break;
                }
                int got = decodeChunk();
                if (got <= 0) {
                    eof = true;
                    continue;
                }
                resampler.push(tmp, 0, got);
            } else {
                if (pendingCount == 0) {
                    if (eof) break;
                    int got = decodeChunk();
                    if (got <= 0) {
                        eof = true;
                        break;
                    }
                    pendingOffset = 0;
                    pendingCount = got;
                }
                int take = Math.min(pendingCount, frames - done);
                System.arraycopy(tmp, pendingOffset * 2, out, done * 2, take * 2);
                pendingOffset += take;
                pendingCount -= take;
                done += take;
            }
        }
        return done;
    }

    private boolean flushed;
    private int pendingOffset;
    private int pendingCount;

    /** Decodiert den naechsten MP3-Frame nach {@link #tmp} (Stereo-Float). Liefert Frames, 0 = Ende. */
    private int decodeChunk() throws IOException {
        int errors = 0;
        while (true) {
            Header h;
            try {
                h = pendingHeader != null ? pendingHeader : bitstream.readFrame();
            } catch (BitstreamException e) {
                if (++errors > MAX_CONSECUTIVE_ERRORS) throw new IOException("MP3-Datenstrom defekt: " + e.getMessage(), e);
                continue;
            }
            pendingHeader = null;
            if (h == null) return 0;
            try {
                if (h.frequency() != sampleRate) {
                    // Wechsel der Abtastrate mitten in der Datei: Resampler neu einstellen
                    throw new DecoderException("Abtastrate wechselt innerhalb der Datei", null);
                }
                SampleBuffer out = (SampleBuffer) decoder.decodeFrame(h, bitstream);
                short[] pcm = out.getBuffer();
                int len = out.getBufferLength();
                int ch = out.getChannelCount();
                int frames = len / ch;
                for (int i = 0; i < frames; i++) {
                    if (ch == 2) {
                        tmp[i * 2] = pcm[i * 2] / 32768f;
                        tmp[i * 2 + 1] = pcm[i * 2 + 1] / 32768f;
                    } else {
                        float v = pcm[i] / 32768f;
                        tmp[i * 2] = v;
                        tmp[i * 2 + 1] = v;
                    }
                }
                return frames;
            } catch (DecoderException | RuntimeException e) {
                if (++errors > MAX_CONSECUTIVE_ERRORS) throw new IOException("MP3-Decodierfehler: " + e.getMessage(), e);
            } finally {
                bitstream.closeFrame();
            }
        }
    }

    /**
     * Decodiert die komplette Datei und liefert die Dauer in 48-kHz-Samples.
     * Dient beim Upload als vollstaendige Formatpruefung.
     */
    public static long measureSamples(Path file, java.util.function.BooleanSupplier cancelled) throws IOException {
        try (Mp3PcmSource src = new Mp3PcmSource(file)) {
            float[] buf = new float[AudioConstants.FRAME_SAMPLES * 2 * 8];
            long total = 0;
            while (true) {
                if (cancelled.getAsBoolean()) throw new IOException("abgebrochen");
                int n = src.read(buf, AudioConstants.FRAME_SAMPLES * 8);
                if (n <= 0) break;
                total += n;
            }
            return total;
        }
    }

    @Override
    public void close() throws IOException {
        try {
            bitstream.close();
        } catch (BitstreamException e) {
            raw.close();
        }
    }
}
