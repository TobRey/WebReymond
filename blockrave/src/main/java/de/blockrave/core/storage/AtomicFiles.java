package de.blockrave.core.storage;

import java.io.IOException;
import java.nio.ByteBuffer;
import java.nio.channels.FileChannel;
import java.nio.file.AtomicMoveNotSupportedException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.nio.file.StandardOpenOption;

/**
 * Absturzsicheres Schreiben: erst in eine Temporaerdatei, auf die Platte zwingen, dann atomar
 * umbenennen. Die vorherige Version bleibt als {@code .bak} erhalten.
 */
public final class AtomicFiles {
    private AtomicFiles() {}

    public static void write(Path target, byte[] data) throws IOException {
        Path dir = target.toAbsolutePath().getParent();
        Files.createDirectories(dir);
        Path tmp = dir.resolve(target.getFileName() + ".tmp");
        try (FileChannel ch = FileChannel.open(tmp, StandardOpenOption.CREATE, StandardOpenOption.WRITE,
                StandardOpenOption.TRUNCATE_EXISTING)) {
            ByteBuffer buf = ByteBuffer.wrap(data);
            while (buf.hasRemaining()) ch.write(buf);
            ch.force(true);
        }
        if (Files.exists(target)) {
            Path bak = dir.resolve(target.getFileName() + ".bak");
            Files.copy(target, bak, StandardCopyOption.REPLACE_EXISTING);
        }
        move(tmp, target);
    }

    public static void move(Path from, Path to) throws IOException {
        try {
            Files.move(from, to, StandardCopyOption.ATOMIC_MOVE, StandardCopyOption.REPLACE_EXISTING);
        } catch (AtomicMoveNotSupportedException e) {
            Files.move(from, to, StandardCopyOption.REPLACE_EXISTING);
        }
    }

    /** Liest die Datei; ist sie unlesbar oder fehlt sie, wird die Sicherung versucht. */
    public static <T> T readWithBackup(Path target, IoFunction<byte[], T> parser) throws IOException {
        IOException first = null;
        for (Path p : new Path[]{target, target.resolveSibling(target.getFileName() + ".bak")}) {
            if (!Files.isRegularFile(p)) continue;
            try {
                return parser.apply(Files.readAllBytes(p));
            } catch (IOException | RuntimeException e) {
                if (first == null) first = new IOException("Datei beschaedigt: " + p.getFileName() + " (" + e.getMessage() + ")", e);
            }
        }
        if (first != null) throw first;
        return null;
    }

    @FunctionalInterface
    public interface IoFunction<A, B> {
        B apply(A a) throws IOException;
    }
}
