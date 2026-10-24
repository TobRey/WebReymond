package de.blockrave.audio;

import de.blockrave.BlockRave;
import de.blockrave.BlockRaveConfig;
import de.blockrave.core.audio.AudioConstants;
import de.blockrave.core.audio.Mp3Inspector;
import de.blockrave.core.audio.Mp3PcmSource;
import de.blockrave.core.storage.TrackLibrary;
import de.blockrave.core.storage.TrackStore;
import de.blockrave.core.upload.UploadAssembler;
import de.blockrave.network.Payloads;
import net.minecraft.network.chat.Component;
import net.minecraft.server.MinecraftServer;
import net.minecraft.server.level.ServerPlayer;
import de.blockrave.network.Net;

import java.io.IOException;
import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.ScheduledExecutorService;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Nimmt MP3-Uploads in begrenzten Bloecken entgegen (Fenster-Flusskontrolle), schreibt sie auf
 * einem I/O-Thread in eine Temporaerdatei, prueft Format und Dauer im Hintergrund und uebernimmt
 * sie dann mit serverseitiger ID in die Bibliothek.
 */
public final class UploadManager {
    /** Anzahl unbestaetigter Bloecke, die ein Client gleichzeitig senden darf. */
    public static final int WINDOW = 8;
    private static final long TIMEOUT_MS = 60_000;

    private final MinecraftServer server;
    private final LibraryService library;
    private final Runnable onLibraryChanged;
    private final ExecutorService io;
    private final ExecutorService decode;
    private final ScheduledExecutorService timer;
    private final Map<String, Session> sessions = new ConcurrentHashMap<>();

    private final class Session {
        final String uploadId;
        final UUID owner;
        final int clientId;
        final String fileName;
        final long size;
        final UploadAssembler assembler;
        final AtomicInteger inFlight = new AtomicInteger();
        volatile long lastActivity = System.currentTimeMillis();
        volatile boolean closed;
        long rateWindowStart = System.nanoTime();
        long rateBytes;

        Session(String uploadId, UUID owner, int clientId, String fileName, long size, UploadAssembler assembler) {
            this.uploadId = uploadId;
            this.owner = owner;
            this.clientId = clientId;
            this.fileName = fileName;
            this.size = size;
            this.assembler = assembler;
        }
    }

    public UploadManager(MinecraftServer server, LibraryService library, ExecutorService io, Runnable onLibraryChanged) {
        this.server = server;
        this.library = library;
        this.io = io;
        this.onLibraryChanged = onLibraryChanged;
        this.decode = Executors.newSingleThreadExecutor(r -> {
            Thread t = new Thread(r, "BlockRave-Decode");
            t.setDaemon(true);
            t.setPriority(Thread.MIN_PRIORITY);
            return t;
        });
        this.timer = Executors.newSingleThreadScheduledExecutor(r -> {
            Thread t = new Thread(r, "BlockRave-UploadTimer");
            t.setDaemon(true);
            return t;
        });
    }

    private static void status(ServerPlayer p, int clientId, String uploadId, int state, long received, long total, Component msg) {
        Net.send(p, new Payloads.UploadStatus(clientId, uploadId, state, received, total, WINDOW, msg));
    }

    private long pendingBytes() {
        long s = 0;
        for (Session x : sessions.values()) s += x.size;
        return s;
    }

    /** Hauptthread. */
    public void begin(ServerPlayer player, Payloads.UploadBegin req) {
        String name = UploadAssembler.sanitizeFileName(req.fileName());
        long maxBytes = BlockRaveConfig.MAX_UPLOAD_MB.get() * 1024L * 1024L;
        long maxStorage = BlockRaveConfig.MAX_STORAGE_MB.get() * 1024L * 1024L;
        Component err = null;
        if (!BlockRaveConfig.ALLOW_UPLOADS.get()) err = Component.translatable("blockrave.upload.disabled");
        else if (req.size() <= 0) err = Component.translatable("blockrave.upload.empty");
        else if (req.size() > maxBytes) err = Component.translatable("blockrave.upload.too_big", BlockRaveConfig.MAX_UPLOAD_MB.get());
        else if (library.lib().totalBytes() + pendingBytes() + req.size() > maxStorage)
            err = Component.translatable("blockrave.upload.storage_full", BlockRaveConfig.MAX_STORAGE_MB.get());
        else if (library.lib().tracks().size() >= BlockRaveConfig.MAX_TRACKS.get())
            err = Component.translatable("blockrave.upload.too_many_tracks", BlockRaveConfig.MAX_TRACKS.get());
        else if (!name.toLowerCase(java.util.Locale.ROOT).endsWith(".mp3"))
            err = Component.translatable("blockrave.upload.not_mp3_name");
        else {
            long mine = sessions.values().stream().filter(s -> s.owner.equals(player.getUUID())).count();
            if (mine >= BlockRaveConfig.MAX_PARALLEL_UPLOADS.get())
                err = Component.translatable("blockrave.upload.too_many_parallel", BlockRaveConfig.MAX_PARALLEL_UPLOADS.get());
        }
        if (err != null) {
            status(player, req.clientUploadId(), "", Payloads.UploadStatus.FAILED, 0, req.size(), err);
            return;
        }
        String uploadId = TrackStore.newId();
        try {
            UploadAssembler asm = new UploadAssembler(library.store().incomingFile(uploadId), req.size());
            sessions.put(uploadId, new Session(uploadId, player.getUUID(), req.clientUploadId(), name, req.size(), asm));
        } catch (IOException e) {
            BlockRave.LOG.error("BlockRave: Upload-Datei konnte nicht angelegt werden", e);
            status(player, req.clientUploadId(), "", Payloads.UploadStatus.FAILED, 0, req.size(),
                    Component.translatable("blockrave.upload.io_error"));
            return;
        }
        BlockRave.LOG.info("BlockRave: Upload '{}' ({} Bytes) von {} gestartet", name, req.size(), player.getGameProfile().getName());
        status(player, req.clientUploadId(), uploadId, Payloads.UploadStatus.ACCEPTED, 0, req.size(), Component.empty());
    }

    /** Netzwerk-Thread. */
    public void chunk(ServerPlayer player, Payloads.UploadChunk c) {
        Session s = sessions.get(c.uploadId());
        if (s == null || !s.owner.equals(player.getUUID()) || s.closed) return;
        if (s.inFlight.incrementAndGet() > WINDOW + 2) {
            fail(s, player, Component.translatable("blockrave.upload.protocol"));
            return;
        }
        s.lastActivity = System.currentTimeMillis();
        io.execute(() -> {
            if (s.closed) return;
            try {
                s.assembler.append(c.offset(), c.data());
            } catch (IOException e) {
                fail(s, player, Component.translatable("blockrave.upload.transfer_error", e.getMessage()));
                return;
            }
            long received = s.assembler.received();
            // Ratenbegrenzung: Bestaetigung ggf. verzoegern
            long delayMs;
            synchronized (s) {
                long now = System.nanoTime();
                if (now - s.rateWindowStart > 1_000_000_000L) {
                    s.rateWindowStart = now;
                    s.rateBytes = 0;
                }
                s.rateBytes += c.data().length;
                long allowed = BlockRaveConfig.UPLOAD_RATE_KBPS.get() * 1024L;
                delayMs = s.rateBytes > allowed ? Math.min(1000, (s.rateBytes - allowed) * 1000 / allowed) : 0;
            }
            Runnable ack = () -> {
                s.inFlight.decrementAndGet();
                if (s.closed) return;
                if (s.assembler.isComplete()) {
                    finish(s, player);
                } else {
                    status(player, s.clientId, s.uploadId, Payloads.UploadStatus.PROGRESS, received, s.size, Component.empty());
                }
            };
            if (delayMs > 0) timer.schedule(() -> io.execute(ack), delayMs, TimeUnit.MILLISECONDS);
            else ack.run();
        });
    }

    private void fail(Session s, ServerPlayer player, Component msg) {
        if (s.closed) return;
        s.closed = true;
        sessions.remove(s.uploadId);
        io.execute(s.assembler::abort);
        BlockRave.LOG.info("BlockRave: Upload '{}' abgebrochen: {}", s.fileName, msg.getString());
        status(player, s.clientId, s.uploadId, Payloads.UploadStatus.FAILED, s.assembler.received(), s.size, msg);
    }

    /** I/O-Thread: Datei vollstaendig, jetzt pruefen (im Hintergrund). */
    private void finish(Session s, ServerPlayer player) {
        s.closed = true;
        Path file;
        try {
            file = s.assembler.finish();
        } catch (IOException e) {
            sessions.remove(s.uploadId);
            s.assembler.abort();
            status(player, s.clientId, s.uploadId, Payloads.UploadStatus.FAILED, s.size, s.size,
                    Component.translatable("blockrave.upload.io_error"));
            return;
        }
        status(player, s.clientId, s.uploadId, Payloads.UploadStatus.PROCESSING, s.size, s.size, Component.empty());
        decode.execute(() -> validate(s, player, file));
    }

    private void validate(Session s, ServerPlayer player, Path file) {
        Component error = null;
        Mp3Inspector.Result info = null;
        long samples = 0;
        try {
            byte[] head = new byte[256 * 1024];
            int len;
            try (InputStream in = Files.newInputStream(file)) {
                len = Mp3Inspector.readUpTo(in, head, head.length);
            }
            info = Mp3Inspector.inspect(head, len);
            if (!info.valid()) {
                error = Component.translatable("blockrave.upload.not_mp3", info.error());
            } else {
                long maxSamples = BlockRaveConfig.MAX_TRACK_MINUTES.get() * 60L * AudioConstants.SAMPLE_RATE;
                samples = Mp3PcmSource.measureSamples(file, () -> !sessionsAlive());
                if (samples < AudioConstants.SAMPLE_RATE) error = Component.translatable("blockrave.upload.too_short");
                else if (samples > maxSamples) error = Component.translatable("blockrave.upload.too_long", BlockRaveConfig.MAX_TRACK_MINUTES.get());
            }
        } catch (IOException | RuntimeException e) {
            error = Component.translatable("blockrave.upload.decode_error", String.valueOf(e.getMessage()));
        }
        final Component err = error;
        final Mp3Inspector.Result meta = info;
        final long dur = samples;
        server.execute(() -> commit(s, player, file, err, meta, dur));
    }

    private boolean sessionsAlive() {
        return !decode.isShutdown();
    }

    /** Hauptthread: in die Bibliothek uebernehmen. */
    private void commit(Session s, ServerPlayer player, Path file, Component error, Mp3Inspector.Result meta, long samples) {
        sessions.remove(s.uploadId);
        if (error == null && library.lib().tracks().size() >= BlockRaveConfig.MAX_TRACKS.get()) {
            error = Component.translatable("blockrave.upload.too_many_tracks", BlockRaveConfig.MAX_TRACKS.get());
        }
        if (error != null) {
            try {
                Files.deleteIfExists(file);
            } catch (IOException ignored) {
            }
            BlockRave.LOG.info("BlockRave: Upload '{}' abgelehnt: {}", s.fileName, error.getString());
            status(player, s.clientId, s.uploadId, Payloads.UploadStatus.FAILED, s.size, s.size, error);
            return;
        }
        String trackId = TrackStore.newId();
        try {
            library.store().commit(s.uploadId, trackId);
        } catch (IOException e) {
            status(player, s.clientId, s.uploadId, Payloads.UploadStatus.FAILED, s.size, s.size,
                    Component.translatable("blockrave.upload.io_error"));
            return;
        }
        TrackLibrary.Track t = new TrackLibrary.Track();
        t.id = trackId;
        String base = s.fileName.replaceFirst("(?i)\\.mp3$", "");
        t.title = meta.title() != null ? meta.title() : Mp3Inspector.sanitizeDisplay(base);
        t.artist = meta.artist();
        t.originalName = s.fileName;
        t.sizeBytes = s.size;
        t.durationSamples = samples;
        t.uploaderName = player.getGameProfile().getName();
        t.uploaderUuid = player.getUUID().toString();
        t.uploadedAtEpochMs = System.currentTimeMillis();
        library.lib().addTrack(t);
        library.markDirty();
        BlockRave.LOG.info("BlockRave: Titel '{}' ({} s) von {} hinzugefuegt (ID {})", t.displayName(),
                samples / AudioConstants.SAMPLE_RATE, t.uploaderName, trackId);
        status(player, s.clientId, s.uploadId, Payloads.UploadStatus.DONE, s.size, s.size,
                Component.translatable("blockrave.upload.done", t.displayName()));
        onLibraryChanged.run();
    }

    /** Laufende Upload-IDs eines Spielers (Diagnose/Tests). */
    public java.util.List<String> activeUploadIds(UUID owner) {
        return sessions.values().stream().filter(x -> x.owner.equals(owner) && !x.closed).map(x -> x.uploadId).toList();
    }

    /** Hauptthread. */
    public void cancel(ServerPlayer player, String uploadId) {
        Session s = sessions.get(uploadId);
        if (s != null && s.owner.equals(player.getUUID())) fail(s, player, Component.translatable("blockrave.upload.cancelled"));
    }

    /** Hauptthread: Disconnect bricht laufende Uploads ab. */
    public void onLogout(ServerPlayer player) {
        for (Session s : sessions.values()) {
            if (s.owner.equals(player.getUUID()) && !s.closed) {
                s.closed = true;
                sessions.remove(s.uploadId);
                io.execute(s.assembler::abort);
            }
        }
    }

    /** Hauptthread, regelmaessig: haengende Uploads abbrechen. */
    public void tick() {
        long now = System.currentTimeMillis();
        for (Session s : sessions.values()) {
            if (!s.closed && now - s.lastActivity > TIMEOUT_MS) {
                ServerPlayer p = server.getPlayerList().getPlayer(s.owner);
                if (p != null) fail(s, p, Component.translatable("blockrave.upload.timeout"));
                else {
                    s.closed = true;
                    sessions.remove(s.uploadId);
                    io.execute(s.assembler::abort);
                }
            }
        }
    }

    public void shutdown() {
        for (Session s : sessions.values()) {
            s.closed = true;
            s.assembler.abort();
        }
        sessions.clear();
        timer.shutdownNow();
        decode.shutdownNow();
    }
}
