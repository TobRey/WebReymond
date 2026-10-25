package de.custompaintings.core;

import java.io.IOException;
import java.nio.file.AtomicMoveNotSupportedException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.util.stream.Stream;

/**
 * Inhaltsadressierter Bildspeicher: jede Datei heisst nach dem SHA-256 ihres Inhalts. Gleiche Bilder auf
 * mehreren Gemaelden liegen nur einmal vor; ein Dateiname kann nie auf fremde Pfade zeigen.
 */
public final class ImageStore {
    private final Path dir;

    public ImageStore(Path dir) {
        this.dir = dir;
    }

    public Path dir() {
        return dir;
    }

    public Path path(String hash) {
        if (!ImageCodec.isHash(hash)) throw new IllegalArgumentException("ungueltige Bild-ID");
        return dir.resolve(hash + ".png");
    }

    public boolean has(String hash) {
        return ImageCodec.isHash(hash) && Files.isRegularFile(path(hash));
    }

    public byte[] read(String hash) throws IOException {
        return Files.readAllBytes(path(hash));
    }

    /** Atomar schreiben (temporaere Datei + Umbenennen), damit nie halbe Bilder gespeichert sind. */
    public void write(String hash, byte[] data) throws IOException {
        if (!hash.equals(ImageCodec.sha256(data))) throw new IOException("Pruefsumme passt nicht");
        Files.createDirectories(dir);
        Path target = path(hash);
        if (Files.isRegularFile(target)) return;
        Path tmp = dir.resolve(hash + ".tmp");
        Files.write(tmp, data);
        try {
            Files.move(tmp, target, StandardCopyOption.ATOMIC_MOVE, StandardCopyOption.REPLACE_EXISTING);
        } catch (AtomicMoveNotSupportedException e) {
            Files.move(tmp, target, StandardCopyOption.REPLACE_EXISTING);
        }
    }

    /** Belegter Speicher aller Bilder in Bytes. */
    public long totalBytes() {
        if (!Files.isDirectory(dir)) return 0;
        try (Stream<Path> s = Files.list(dir)) {
            return s.filter(p -> p.getFileName().toString().endsWith(".png")).mapToLong(p -> {
                try {
                    return Files.size(p);
                } catch (IOException e) {
                    return 0;
                }
            }).sum();
        } catch (IOException e) {
            return 0;
        }
    }
}
