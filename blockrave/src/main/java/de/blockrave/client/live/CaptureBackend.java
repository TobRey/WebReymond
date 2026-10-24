package de.blockrave.client.live;

import java.util.List;

/** Plattformabhaengige Audioaufnahme. */
public interface CaptureBackend {
    /** Empfaenger der aufgenommenen Daten (Aufnahme-Thread). */
    interface Sink {
        /** Interleavte Float-Samples (-1..1). */
        void onAudio(float[] data, int frames, int channels, int sampleRate);

        void onError(String message);
    }

    interface Stream extends AutoCloseable {
        /** Gibt alle Aufnahme-Ressourcen frei (idempotent). */
        @Override
        void close();

        /** Ungefaehre Aufnahmelatenz in Millisekunden. */
        int latencyMillis();
    }

    boolean isSupported();

    String unsupportedReason();

    List<CaptureSource> listSources() throws Exception;

    Stream open(CaptureSource source, Sink sink) throws Exception;

    static CaptureBackend create() {
        String os = System.getProperty("os.name", "").toLowerCase(java.util.Locale.ROOT);
        if (os.contains("win")) {
            try {
                return new de.blockrave.client.live.windows.WasapiBackend();
            } catch (Throwable t) {
                return new Unsupported("Windows-Audioschnittstelle nicht verfuegbar: " + t.getMessage());
            }
        }
        return new Unsupported("Live-Aufnahme ist nur unter Windows verfuegbar (WASAPI). MP3-Wiedergabe funktioniert ueberall.");
    }

    record Unsupported(String reason) implements CaptureBackend {
        @Override
        public boolean isSupported() { return false; }

        @Override
        public String unsupportedReason() { return reason; }

        @Override
        public List<CaptureSource> listSources() { return List.of(); }

        @Override
        public Stream open(CaptureSource source, Sink sink) {
            throw new UnsupportedOperationException(reason);
        }
    }
}
