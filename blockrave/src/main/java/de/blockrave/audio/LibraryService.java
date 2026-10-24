package de.blockrave.audio;

import de.blockrave.BlockRave;
import de.blockrave.core.storage.AtomicFiles;
import de.blockrave.core.storage.TrackLibrary;
import de.blockrave.core.storage.TrackStore;

import java.io.IOException;
import java.nio.file.Path;
import java.util.HashSet;
import java.util.Set;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;

/**
 * Musikbibliothek mit persistenter Speicherung. Aenderungen erfolgen auf dem Server-Hauptthread,
 * Schreibvorgaenge laufen gebuendelt auf einem eigenen I/O-Thread (atomar mit Sicherungskopie).
 */
public final class LibraryService {
    private final Path file;
    private final TrackStore store;
    private TrackLibrary library;
    private final ExecutorService io;
    private final AtomicBoolean savePending = new AtomicBoolean();
    private volatile byte[] pendingJson;

    public LibraryService(Path dataDir, ExecutorService io) throws IOException {
        this.file = dataDir.resolve("library.json");
        this.store = new TrackStore(dataDir);
        this.io = io;
        TrackLibrary loaded;
        try {
            loaded = AtomicFiles.readWithBackup(file, TrackLibrary::fromJson);
        } catch (IOException e) {
            BlockRave.LOG.error("BlockRave: Bibliothek beschaedigt, beginne mit leerer Bibliothek (Datei bleibt erhalten)", e);
            loaded = null;
        }
        library = loaded != null ? loaded : new TrackLibrary();
        // Eintraege ohne Datei entfernen, verwaiste Dateien und Upload-Reste aufraeumen
        for (TrackLibrary.Track t : library.tracks().toArray(new TrackLibrary.Track[0])) {
            if (!store.exists(t.id)) {
                BlockRave.LOG.warn("BlockRave: Datei fuer Titel '{}' fehlt, Eintrag wird entfernt", t.title);
                library.deleteTrack(t.id);
            }
        }
        Set<String> ids = new HashSet<>();
        library.tracks().forEach(t -> ids.add(t.id));
        int cleaned = store.cleanup(ids);
        if (cleaned > 0) BlockRave.LOG.info("BlockRave: {} unvollstaendige/verwaiste Dateien entfernt", cleaned);
        BlockRave.LOG.info("BlockRave: Bibliothek geladen: {} Titel, Playlist {}, Warteschlange {}",
                library.tracks().size(), library.playlist().size(), library.queue().size());
    }

    public TrackLibrary lib() {
        return library;
    }

    public TrackStore store() {
        return store;
    }

    public Path trackFile(String id) {
        return store.trackFile(id);
    }

    /** Plant eine Speicherung (mehrere Aenderungen kurz hintereinander werden zusammengefasst). */
    public void markDirty() {
        pendingJson = library.toJson();
        if (savePending.compareAndSet(false, true)) {
            io.execute(() -> {
                savePending.set(false);
                byte[] json = pendingJson;
                try {
                    AtomicFiles.write(file, json);
                } catch (IOException e) {
                    BlockRave.LOG.error("BlockRave: Bibliothek konnte nicht gespeichert werden", e);
                }
            });
        }
    }

    /** Synchrones Speichern beim Herunterfahren. */
    public void saveNow() {
        try {
            AtomicFiles.write(file, library.toJson());
        } catch (IOException e) {
            BlockRave.LOG.error("BlockRave: Bibliothek konnte beim Beenden nicht gespeichert werden", e);
        }
    }

    public static ExecutorService newIoExecutor() {
        return Executors.newSingleThreadExecutor(r -> {
            Thread t = new Thread(r, "BlockRave-IO");
            t.setDaemon(true);
            return t;
        });
    }

    public static void shutdownExecutor(ExecutorService ex) {
        ex.shutdown();
        try {
            if (!ex.awaitTermination(5, TimeUnit.SECONDS)) ex.shutdownNow();
        } catch (InterruptedException e) {
            ex.shutdownNow();
            Thread.currentThread().interrupt();
        }
    }
}
