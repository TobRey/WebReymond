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
}
