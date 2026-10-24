package de.blockrave.core;

import de.blockrave.core.audio.AudioConstants;
import de.blockrave.core.audio.Mp3Inspector;
import de.blockrave.core.audio.Mp3PcmSource;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

import java.nio.file.Files;
import java.nio.file.Path;

import static org.junit.jupiter.api.Assertions.*;

class Mp3Test {
    @TempDir
    Path dir;

    private Mp3Inspector.Result inspect(Path p) throws Exception {
        byte[] all = Files.readAllBytes(p);
        int len = Math.min(all.length, 256 * 1024);
        return Mp3Inspector.inspect(all, len);
    }

    @Test
    void erkenntEchteMp3UndLiestId3() throws Exception {
        var r = inspect(TestAudio.resource(dir, "techno128_44k.mp3"));
        assertTrue(r.valid(), r.error());
        assertEquals(44100, r.firstFrame().sampleRate());
        assertEquals(1, r.firstFrame().version());
        assertTrue(r.id3Size() > 0);
        assertEquals("Testtrack Techno", r.title());
        assertEquals("BlockRave", r.artist());
    }

    @Test
    void erkenntMpeg2() throws Exception {
        var r = inspect(TestAudio.resource(dir, "sine_mono_22k_mpeg2.mp3"));
        assertTrue(r.valid(), r.error());
        assertEquals(2, r.firstFrame().version());
        assertEquals(22050, r.firstFrame().sampleRate());
    }

    @Test
    void lehntFremdeDatenAb() throws Exception {
        var r = inspect(TestAudio.resource(dir, "not_audio.mp3"));
        assertFalse(r.valid());
        assertNotNull(r.error());
    }

    @Test
    void decodiertUndRechnetAuf48kHzUm() throws Exception {
        Path p = TestAudio.resource(dir, "techno128_44k.mp3");
        long samples = Mp3PcmSource.measureSamples(p, () -> false);
        double seconds = (double) samples / AudioConstants.SAMPLE_RATE;
        assertEquals(20.0, seconds, 0.15, "Dauer nach Decodierung");
    }

    @Test
    void decodiert48kHzDirekt() throws Exception {
        Path p = TestAudio.resource(dir, "drop124_48k.mp3");
        long samples = Mp3PcmSource.measureSamples(p, () -> false);
        assertEquals(30.0, (double) samples / AudioConstants.SAMPLE_RATE, 0.15);
    }

    @Test
    void decodiertMpeg2Mono() throws Exception {
        Path p = TestAudio.resource(dir, "sine_mono_22k_mpeg2.mp3");
        try (Mp3PcmSource src = new Mp3PcmSource(p)) {
            float[] buf = new float[960 * 2 * 50];
            int n = src.read(buf, 960 * 50);
            assertTrue(n > 0, "keine Samples");
            double ms = 0;
            for (int i = 960 * 10 * 2; i < n * 2; i++) ms += buf[i] * buf[i];
            ms /= (n * 2 - 960 * 20);
            assertTrue(ms > 0.05, "Signal zu leise: " + ms);
        }
    }

    @Test
    void dateinameWirdBereinigt() {
        assertEquals("evil.mp3", de.blockrave.core.upload.UploadAssembler.sanitizeFileName("../../etc/evil.mp3"));
        assertEquals("a_b.mp3", de.blockrave.core.upload.UploadAssembler.sanitizeFileName("C:\\x\\a:b.mp3"));
        assertEquals("unbenannt.mp3", de.blockrave.core.upload.UploadAssembler.sanitizeFileName(".."));
    }

    /** Haeng ein grosses Cover-Bild (APIC-Frame) in den ID3v2-Tag, wie bei gekauften Tracks ueblich. */
    private static byte[] withBigCover(byte[] mp3, int coverBytes) {
        int id3 = Mp3Inspector.id3v2Size(mp3, mp3.length);
        int major = mp3[3];
        byte[] body = java.util.Arrays.copyOfRange(mp3, 10, id3);
        byte[] cover = new byte[coverBytes];
        new java.util.Random(7).nextBytes(cover);
        var out = new java.io.ByteArrayOutputStream();
        int frameLen = cover.length;
        int newBody = body.length + 10 + frameLen;
        out.write('I'); out.write('D'); out.write('3'); out.write(major); out.write(0); out.write(0);
        out.write((newBody >> 21) & 0x7F); out.write((newBody >> 14) & 0x7F); out.write((newBody >> 7) & 0x7F); out.write(newBody & 0x7F);
        out.writeBytes(body);
        out.writeBytes("APIC".getBytes());
        if (major == 4) {
            out.write((frameLen >> 21) & 0x7F); out.write((frameLen >> 14) & 0x7F); out.write((frameLen >> 7) & 0x7F); out.write(frameLen & 0x7F);
        } else {
            out.write(frameLen >>> 24); out.write((frameLen >> 16) & 0xFF); out.write((frameLen >> 8) & 0xFF); out.write(frameLen & 0xFF);
        }
        out.write(0); out.write(0);
        out.writeBytes(cover);
        out.writeBytes(java.util.Arrays.copyOfRange(mp3, id3, mp3.length));
        return out.toByteArray();
    }

    @Test
    void erkenntMp3MitGrossemCoverBild() throws Exception {
        byte[] orig = Files.readAllBytes(TestAudio.resource(dir, "techno128_44k.mp3"));
        Path big = dir.resolve("mit_cover.mp3");
        Files.write(big, withBigCover(orig, 3 * 1024 * 1024));
        // Frueheres Verhalten (nur die ersten 256 KiB): wird abgelehnt
        assertFalse(inspect(big).valid());
        Mp3Inspector.Result r;
        try (var in = new java.io.BufferedInputStream(Files.newInputStream(big))) {
            r = Mp3Inspector.inspect(in);
        }
        assertTrue(r.valid(), r.error());
        assertEquals("Testtrack Techno", r.title());
        assertEquals(44100, r.firstFrame().sampleRate());
        long withCover = Mp3PcmSource.measureSamples(big, () -> false);
        long plain = Mp3PcmSource.measureSamples(TestAudio.resource(dir, "techno128_44k.mp3"), () -> false);
        assertEquals(plain, withCover, "Decoder muss das Cover ueberspringen");
    }

    @Test
    void streamPruefungLehntNichtMp3Ab() throws Exception {
        byte[] junk = new byte[300_000];
        new java.util.Random(3).nextBytes(junk);
        assertFalse(Mp3Inspector.inspect(new java.io.ByteArrayInputStream(junk)).valid());
        byte[] fakeTag = withBigCover(Files.readAllBytes(TestAudio.resource(dir, "techno128_44k.mp3")), 10);
        // nur der Tag, keine Audiodaten
        int id3 = Mp3Inspector.id3v2Size(fakeTag, fakeTag.length);
        assertFalse(Mp3Inspector.inspect(new java.io.ByteArrayInputStream(java.util.Arrays.copyOf(fakeTag, id3))).valid());
    }
}
