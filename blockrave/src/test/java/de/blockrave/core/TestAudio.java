package de.blockrave.core;

import java.io.IOException;
import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;

final class TestAudio {
    private TestAudio() {}

    static Path resource(Path dir, String name) throws IOException {
        Path p = dir.resolve(name);
        try (InputStream in = TestAudio.class.getResourceAsStream("/audio/" + name)) {
            if (in == null) throw new IOException("Testdatei fehlt: " + name);
            Files.copy(in, p, StandardCopyOption.REPLACE_EXISTING);
        }
        return p;
    }

    static float[] sine(double freq, double amp, int frames, int rate, int channels) {
        float[] out = new float[frames * channels];
        for (int i = 0; i < frames; i++) {
            float v = (float) (amp * Math.sin(2 * Math.PI * freq * i / rate));
            for (int c = 0; c < channels; c++) out[i * channels + c] = v;
        }
        return out;
    }
}
