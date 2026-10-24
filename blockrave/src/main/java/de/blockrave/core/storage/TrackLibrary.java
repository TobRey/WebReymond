package de.blockrave.core.storage;

import com.google.gson.Gson;
import com.google.gson.GsonBuilder;
import com.google.gson.JsonParseException;

import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Optional;

/**
 * Musikbibliothek, Playlist und Warteschlange (serverseitig, wird als JSON gespeichert).
 *
 * <ul>
 *   <li>Bibliothek: alle hochgeladenen Titel</li>
 *   <li>Playlist: geordnete Titelliste, die nacheinander (und wiederholt) laeuft</li>
 *   <li>Warteschlange: einmalig als Naechstes zu spielende Titel, Vorrang vor der Playlist</li>
 * </ul>
 * Nicht threadsicher; wird nur auf dem Server-Hauptthread veraendert.
 */
public final class TrackLibrary {
    public static final int FORMAT_VERSION = 1;
    private static final Gson GSON = new GsonBuilder().setPrettyPrinting().disableHtmlEscaping().create();

    public static final class Track {
        public String id;
        public String title;
        public String artist;
        public String originalName;
        public long sizeBytes;
        public long durationSamples;
        public String uploaderName;
        public String uploaderUuid;
        public long uploadedAtEpochMs;

        public String displayName() {
            if (artist != null && !artist.isBlank()) return artist + " - " + title;
            return title;
        }
    }

    private int formatVersion = FORMAT_VERSION;
    private List<Track> tracks = new ArrayList<>();
    private List<String> playlist = new ArrayList<>();
    private List<String> queue = new ArrayList<>();
    private int masterVolume = 80;
    /** Zuletzt gewaehlter Modus ("MP3" oder "LIVE"). */
    private String mode = "MP3";
    /** Index in der Playlist, der als Naechstes gespielt wird (Hinweis, wird validiert). */
    private int playlistCursor;

    public static TrackLibrary fromJson(byte[] json) {
        TrackLibrary lib;
        try {
            lib = GSON.fromJson(new String(json, StandardCharsets.UTF_8), TrackLibrary.class);
        } catch (JsonParseException e) {
            throw new IllegalArgumentException("Ungueltiges Bibliotheksformat: " + e.getMessage(), e);
        }
        if (lib == null) throw new IllegalArgumentException("Leere Bibliotheksdatei");
        lib.normalize();
        return lib;
    }

    public byte[] toJson() {
        return GSON.toJson(this).getBytes(StandardCharsets.UTF_8);
    }

    /** Entfernt doppelte/unbekannte IDs nach dem Laden. */
    public void normalize() {
        if (tracks == null) tracks = new ArrayList<>();
        if (playlist == null) playlist = new ArrayList<>();
        if (queue == null) queue = new ArrayList<>();
        tracks.removeIf(t -> t == null || t.id == null || !isValidId(t.id));
        var ids = new java.util.HashSet<String>();
        tracks.removeIf(t -> !ids.add(t.id));
        playlist.removeIf(id -> !ids.contains(id));
        var seen = new java.util.HashSet<String>();
        playlist.removeIf(id -> !seen.add(id));
        queue.removeIf(id -> !ids.contains(id));
        masterVolume = Math.max(0, Math.min(100, masterVolume));
        if (playlistCursor < 0 || playlistCursor >= Math.max(1, playlist.size())) playlistCursor = 0;
        formatVersion = FORMAT_VERSION;
    }

    /** Server-IDs bestehen nur aus Kleinbuchstaben/Ziffern (keine Pfade). */
    public static boolean isValidId(String id) {
        return id != null && id.matches("[a-z0-9]{8,40}");
    }

    public List<Track> tracks() {
        return Collections.unmodifiableList(tracks);
    }

    public List<String> playlist() {
        return Collections.unmodifiableList(playlist);
    }

    public List<String> queue() {
        return Collections.unmodifiableList(queue);
    }

    public Optional<Track> find(String id) {
        for (Track t : tracks) if (t.id.equals(id)) return Optional.of(t);
        return Optional.empty();
    }

    public long totalBytes() {
        long s = 0;
        for (Track t : tracks) s += t.sizeBytes;
        return s;
    }

    public String mode() {
        return mode == null ? "MP3" : mode;
    }

    public void setMode(String m) {
        this.mode = "LIVE".equals(m) ? "LIVE" : "MP3";
    }

    public int masterVolume() {
        return masterVolume;
    }

    public void setMasterVolume(int v) {
        masterVolume = Math.max(0, Math.min(100, v));
    }

    /** Neuer Upload: in die Bibliothek und ans Ende der Playlist. */
    public void addTrack(Track t) {
        if (!isValidId(t.id)) throw new IllegalArgumentException("ungueltige ID");
        if (find(t.id).isPresent()) return;
        tracks.add(t);
        playlist.add(t.id);
    }

    /** Entfernt einen Titel komplett (Bibliothek, Playlist, Warteschlange). */
    public boolean deleteTrack(String id) {
        boolean removed = tracks.removeIf(t -> t.id.equals(id));
        if (removed) {
            int idx = playlist.indexOf(id);
            playlist.remove(id);
            if (idx >= 0 && idx < playlistCursor) playlistCursor--;
            queue.removeIf(q -> q.equals(id));
            if (playlistCursor >= playlist.size()) playlistCursor = 0;
        }
        return removed;
    }

    public boolean addToPlaylist(String id) {
        if (find(id).isEmpty() || playlist.contains(id)) return false;
        playlist.add(id);
        return true;
    }

    public boolean removeFromPlaylist(String id) {
        int idx = playlist.indexOf(id);
        if (idx < 0) return false;
        playlist.remove(idx);
        if (idx < playlistCursor) playlistCursor--;
        if (playlistCursor >= playlist.size()) playlistCursor = 0;
        return true;
    }

    public boolean enqueue(String id) {
        if (find(id).isEmpty() || queue.size() >= 200) return false;
        queue.add(id);
        return true;
    }

    public boolean removeFromQueue(int index) {
        if (index < 0 || index >= queue.size()) return false;
        queue.remove(index);
        return true;
    }

    /** Verschiebt einen Eintrag um {@code delta} Positionen (Playlist). */
    public boolean movePlaylist(String id, int delta) {
        int idx = playlist.indexOf(id);
        return idx >= 0 && move(playlist, idx, delta);
    }

    public boolean moveQueue(int index, int delta) {
        return index >= 0 && index < queue.size() && move(queue, index, delta);
    }

    private static boolean move(List<String> list, int idx, int delta) {
        int to = idx + delta;
        if (to < 0 || to >= list.size() || delta == 0) return false;
        String v = list.remove(idx);
        list.add(to, v);
        return true;
    }

    /**
     * Bestimmt den naechsten Titel: zuerst die Warteschlange, dann die Playlist (endlos).
     * Veraendert Warteschlange/Cursor entsprechend.
     */
    public Optional<String> takeNext() {
        if (!queue.isEmpty()) return Optional.of(queue.remove(0));
        if (playlist.isEmpty()) return Optional.empty();
        if (playlistCursor >= playlist.size()) playlistCursor = 0;
        String id = playlist.get(playlistCursor);
        playlistCursor = (playlistCursor + 1) % playlist.size();
        return Optional.of(id);
    }

    /** Setzt die Playlist so, dass als Naechstes der Titel {@code id} kommt. */
    public void setCursorTo(String id) {
        int idx = playlist.indexOf(id);
        if (idx >= 0) playlistCursor = idx;
    }

    public int playlistCursor() {
        return playlistCursor;
    }
}
