package de.blockrave.core.storage;

import java.io.IOException;
import java.nio.file.DirectoryStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.SecureRandom;
import java.util.HexFormat;
import java.util.Set;

/**
 * Ablage der MP3-Dateien. Dateinamen werden ausschliesslich vom Server vergeben
 * ({@code tracks/<id>.mp3}); Clients koennen keine Pfade bestimmen.
 */
public final class TrackStore {
    private static final SecureRandom RANDOM = new SecureRandom();
    private final Path root;
    private final Path tracksDir;
    private final Path incomingDir;

    public TrackStore(Path root) throws IOException {
        this.root = root;
        this.tracksDir = root.resolve("tracks");
        this.incomingDir = root.resolve("incoming");
        Files.createDirectories(tracksDir);
        Files.createDirectories(incomingDir);
    }

    public Path root() {
        return root;
    }

    public static String newId() {
        byte[] b = new byte[12];
        RANDOM.nextBytes(b);
        return HexFormat.of().formatHex(b);
    }

    public Path trackFile(String id) {
        if (!TrackLibrary.isValidId(id)) throw new IllegalArgumentException("ungueltige Track-ID");
        return tracksDir.resolve(id + ".mp3");
    }

    public Path incomingFile(String uploadId) {
        if (!TrackLibrary.isValidId(uploadId)) throw new IllegalArgumentException("ungueltige Upload-ID");
        return incomingDir.resolve(uploadId + ".part");
    }

    /** Uebernimmt eine fertig gepruefte Upload-Datei atomar in die Bibliothek. */
    public void commit(String uploadId, String trackId) throws IOException {
        AtomicFiles.move(incomingFile(uploadId), trackFile(trackId));
    }

    public void delete(String trackId) throws IOException {
        Files.deleteIfExists(trackFile(trackId));
    }

    /** Raeumt nach einem Absturz unvollstaendige Uploads und verwaiste Dateien auf. */
    public int cleanup(Set<String> knownTrackIds) throws IOException {
        int removed = 0;
        try (DirectoryStream<Path> ds = Files.newDirectoryStream(incomingDir)) {
            for (Path p : ds) {
                Files.deleteIfExists(p);
                removed++;
            }
        }
        try (DirectoryStream<Path> ds = Files.newDirectoryStream(tracksDir, "*.mp3")) {
            for (Path p : ds) {
                String name = p.getFileName().toString();
                String id = name.substring(0, name.length() - 4);
                if (!knownTrackIds.contains(id)) {
                    Files.deleteIfExists(p);
                    removed++;
                }
            }
        }
        return removed;
    }

    public boolean exists(String trackId) {
        return Files.isRegularFile(trackFile(trackId));
    }
}
