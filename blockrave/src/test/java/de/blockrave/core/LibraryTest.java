package de.blockrave.core;

import de.blockrave.core.storage.AtomicFiles;
import de.blockrave.core.storage.TrackLibrary;
import de.blockrave.core.storage.TrackStore;
import de.blockrave.core.upload.UploadAssembler;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Set;

import static org.junit.jupiter.api.Assertions.*;

class LibraryTest {
    @TempDir
    Path dir;

    private static TrackLibrary.Track track(String id, String title) {
        TrackLibrary.Track t = new TrackLibrary.Track();
        t.id = id;
        t.title = title;
        t.sizeBytes = 1000;
        return t;
    }

    @Test
    void playlistWarteschlangeUndReihenfolge() {
        TrackLibrary lib = new TrackLibrary();
        lib.addTrack(track("aaaaaaaa", "A"));
        lib.addTrack(track("bbbbbbbb", "B"));
        lib.addTrack(track("cccccccc", "C"));
        assertEquals(3, lib.playlist().size());
        assertTrue(lib.movePlaylist("cccccccc", -2));
        assertEquals("cccccccc", lib.playlist().get(0));
        lib.enqueue("bbbbbbbb");
        assertEquals("bbbbbbbb", lib.takeNext().orElseThrow()); // Warteschlange zuerst
        assertEquals("cccccccc", lib.takeNext().orElseThrow());
        assertEquals("aaaaaaaa", lib.takeNext().orElseThrow());
        assertEquals("bbbbbbbb", lib.takeNext().orElseThrow());
        assertEquals("cccccccc", lib.takeNext().orElseThrow()); // Playlist wiederholt sich
        lib.deleteTrack("aaaaaaaa");
        assertEquals(2, lib.tracks().size());
        assertFalse(lib.playlist().contains("aaaaaaaa"));
        assertFalse(TrackLibrary.isValidId("../x"));
    }

    @Test
    void jsonRundreiseUndBeschaedigteDatei() throws IOException {
        TrackLibrary lib = new TrackLibrary();
        lib.addTrack(track("aaaaaaaa", "Ä Titel"));
        lib.setMasterVolume(55);
        Path f = dir.resolve("library.json");
        AtomicFiles.write(f, lib.toJson());
        lib.addTrack(track("bbbbbbbb", "B"));
        AtomicFiles.write(f, lib.toJson()); // .bak enthaelt Version 1
        // Hauptdatei durch abgebrochenen Schreibvorgang beschaedigen
        Files.writeString(f, "{\"tracks\": [ {\"id\": \"aaa");
        TrackLibrary loaded = AtomicFiles.readWithBackup(f, TrackLibrary::fromJson);
        assertNotNull(loaded);
        assertEquals(1, loaded.tracks().size());
        assertEquals("Ä Titel", loaded.tracks().get(0).title);
        assertEquals(55, loaded.masterVolume());
    }

    @Test
    void uploadZusammensetzenUndAufraeumen() throws IOException {
        TrackStore store = new TrackStore(dir);
        String up = TrackStore.newId();
        UploadAssembler a = new UploadAssembler(store.incomingFile(up), 10);
        a.append(0, new byte[]{1, 2, 3, 4});
        assertThrows(IOException.class, () -> a.append(2, new byte[]{1}));
        assertThrows(IOException.class, () -> a.append(4, new byte[7]));
        a.append(4, new byte[6]);
        a.finish();
        String id = TrackStore.newId();
        store.commit(up, id);
        assertTrue(store.exists(id));
        // Verwaiste Upload-Reste werden geloescht, bekannte Titel bleiben
        Files.write(store.incomingFile(TrackStore.newId()), new byte[3]);
        assertEquals(1, store.cleanup(Set.of(id)));
        assertTrue(store.exists(id));
        assertEquals(1, store.cleanup(Set.of()));
        assertFalse(store.exists(id));
    }
}
