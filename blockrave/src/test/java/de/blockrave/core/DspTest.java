package de.blockrave.core;

import de.blockrave.core.audio.AudioConstants;
import de.blockrave.core.audio.OpusFrameDecoder;
import de.blockrave.core.audio.OpusFrameEncoder;
import de.blockrave.core.audio.SincResampler;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.*;

class DspTest {
    /** Leistung (Amplitude^2) einer Frequenz per direkter Projektion. */
    static double power(float[] x, int ch, int channels, int from, int to, double f, double rate) {
        double re = 0, im = 0;
        for (int i = from; i < to; i++) {
            double w = 2 * Math.PI * f * i / rate;
            re += x[i * channels + ch] * Math.cos(w);
            im += x[i * channels + ch] * Math.sin(w);
        }
        double n = to - from;
        return (re * re + im * im) * 4 / (n * n);
    }

    @Test
    void resamplerErhaeltFrequenzUndPegel() {
        int inRate = 44100;
        float[] in = TestAudio.sine(1000, 0.5, inRate, inRate, 2);
        SincResampler r = new SincResampler(2, inRate, 48000, 16);
        float[] out = new float[50000 * 2];
        int total = 0;
        for (int off = 0; off < inRate; off += 1000) {
            r.push(in, off, Math.min(1000, inRate - off));
            total += r.pull(out, total, out.length / 2 - total);
        }
        assertEquals(48000, total, 100);
        double p1k = power(out, 0, 2, 2000, 46000, 1000, 48000);
        double p900 = power(out, 0, 2, 2000, 46000, 900, 48000);
        assertEquals(0.25, p1k, 0.02, "Amplitude 0.5 -> Leistung 0.25");
        assertTrue(p900 < 0.001, "Nebenfrequenz zu stark");
    }

    @Test
    void resamplerVerhaeltnisLaesstSichFeinVerstellen() {
        SincResampler r = new SincResampler(2, 48000, 48000, 8);
        float[] in = TestAudio.sine(440, 0.3, 48000, 48000, 2);
        r.setRatio(1.005, 1.0); // 0,5 % schneller verbrauchen
        r.push(in, 0, 48000);
        float[] out = new float[48000 * 2];
        int n = r.pull(out, 0, 48000);
        assertEquals(48000 / 1.005, n, 30);
    }

    @Test
    void opusRundreise() {
        OpusFrameEncoder enc = new OpusFrameEncoder(160_000, 8);
        OpusFrameDecoder dec = new OpusFrameDecoder();
        int frames = 100;
        float[] sig = TestAudio.sine(523.25, 0.4, AudioConstants.FRAME_SAMPLES * frames, 48000, 2);
        float[] out = new float[sig.length];
        float[] frame = new float[AudioConstants.FRAME_SAMPLES * 2];
        float[] dframe = new float[AudioConstants.FRAME_SAMPLES * 2];
        long bytes = 0;
        long t0 = System.nanoTime();
        for (int i = 0; i < frames; i++) {
            System.arraycopy(sig, i * frame.length, frame, 0, frame.length);
            byte[] pkt = enc.encode(frame);
            assertTrue(pkt.length > 0 && pkt.length <= AudioConstants.MAX_OPUS_PACKET);
            bytes += pkt.length;
            int n = dec.decode(pkt, dframe);
            assertEquals(AudioConstants.FRAME_SAMPLES, n);
            System.arraycopy(dframe, 0, out, i * frame.length, frame.length);
        }
        double millis = (System.nanoTime() - t0) / 1e6;
        System.out.printf("Opus: %d Frames (2 s Audio) in %.1f ms codiert+decodiert, %.1f kbit/s%n", frames, millis,
                bytes * 8 / 2.0 / 1000);
        double ms = 0;
        for (int i = 9600; i < out.length - 9600; i++) ms += out[i] * out[i];
        double rms = Math.sqrt(ms / (out.length - 19200));
        assertEquals(0.4 / Math.sqrt(2), rms, 0.03, "Pegel nach Opus");
        double p523 = power(out, 0, 2, 4800, 52800, 523.25, 48000);
        double p600 = power(out, 0, 2, 4800, 52800, 600, 48000);
        assertTrue(p523 > 100 * p600, "Grundton muss dominieren");
        assertTrue(millis < 2000, "Opus zu langsam fuer Echtzeit");
        // Verlustverschleierung liefert einen Frame
        assertEquals(AudioConstants.FRAME_SAMPLES, dec.decode(null, dframe));
    }
}
