package de.blockrave.core.upload;

import java.io.IOException;
import java.nio.ByteBuffer;
import java.nio.channels.FileChannel;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardOpenOption;

/**
 * Setzt einen Upload aus geordneten Bloecken zusammen und erzwingt die angekuendigte Groesse.
 * Wird nur vom Upload-Schreibthread benutzt.
 */
public final class UploadAssembler implements AutoCloseable {
    private final Path file;
    private final long expectedSize;
    private final FileChannel channel;
    private long received;
    private boolean closed;

    public UploadAssembler(Path file, long expectedSize) throws IOException {
        this.file = file;
        this.expectedSize = expectedSize;
        Files.createDirectories(file.toAbsolutePath().getParent());
        this.channel = FileChannel.open(file, StandardOpenOption.CREATE, StandardOpenOption.WRITE,
                StandardOpenOption.TRUNCATE_EXISTING);
    }

    /** @throws IOException bei Luecken, Ueberlaenge oder Schreibfehlern */
    public void append(long offset, byte[] data) throws IOException {
        if (closed) throw new IOException("Upload bereits geschlossen");
        if (offset != received) throw new IOException("Unerwarteter Block-Offset " + offset + " (erwartet " + received + ")");
        if (received + data.length > expectedSize) throw new IOException("Mehr Daten als angekuendigt");
        ByteBuffer b = ByteBuffer.wrap(data);
        while (b.hasRemaining()) channel.write(b);
        received += data.length;
    }

    public long received() {
        return received;
    }

    public long expectedSize() {
        return expectedSize;
    }

    public boolean isComplete() {
        return received == expectedSize;
    }

    /** Schliesst die Datei und erzwingt das Schreiben auf die Platte. */
    public Path finish() throws IOException {
        if (!isComplete()) throw new IOException("Upload unvollstaendig (" + received + "/" + expectedSize + " Bytes)");
        channel.force(true);
        close();
        return file;
    }

    public void abort() {
        close();
        try {
            Files.deleteIfExists(file);
        } catch (IOException ignored) {
            // wird beim naechsten Start aufgeraeumt
        }
    }

    @Override
    public void close() {
        if (closed) return;
        closed = true;
        try {
            channel.close();
        } catch (IOException ignored) {
        }
    }

    /** Zulaessiger, angezeigter Dateiname (nur Anzeige, nie als Pfad verwendet). */
    public static String sanitizeFileName(String name) {
        if (name == null) return "unbenannt.mp3";
        String base = name.replace('\\', '/');
        base = base.substring(base.lastIndexOf('/') + 1);
        StringBuilder sb = new StringBuilder();
        base.codePoints().forEach(cp -> {
            if (Character.isLetterOrDigit(cp) || " ._-()[]&'!,+".indexOf(cp) >= 0) sb.appendCodePoint(cp);
            else sb.append('_');
        });
        String r = sb.toString().strip();
        if (r.isEmpty() || r.chars().allMatch(c -> c == '.')) r = "unbenannt.mp3";
        if (r.length() > 100) r = r.substring(0, 100);
        return r;
    }
}
