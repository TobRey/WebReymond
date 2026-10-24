package de.blockrave.audio;

import de.blockrave.BlockRave;
import de.blockrave.BlockRaveConfig;
import de.blockrave.core.audio.AudioConstants;
import de.blockrave.core.storage.TrackLibrary;
import de.blockrave.menu.DjBoothMenu;
import de.blockrave.network.DjAction;
import de.blockrave.network.Payloads;
import de.blockrave.stage.StageManager;
import net.minecraft.network.chat.Component;
import net.minecraft.server.MinecraftServer;
import net.minecraft.server.level.ServerLevel;
import net.minecraft.server.level.ServerPlayer;
import net.minecraft.world.phys.Vec3;
import de.blockrave.network.Net;

import java.io.IOException;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.ExecutorService;

/**
 * Gemeinsamer Wiedergabezustand (Server-Hauptthread). Alle Bedienbefehle laufen nacheinander hier
 * durch, dadurch gibt es keine widerspruechlichen Zustaende und immer hoechstens eine aktive Quelle.
 * Nach einem Neustart beginnt die Wiedergabe gestoppt; Live-Streams werden nie automatisch fortgesetzt.
 */
public final class PlaybackController implements AudioEngine.Callbacks {
    private final MinecraftServer server;
    private final StageManager stage;
    private final ExecutorService io;
    private LibraryService library;
    private UploadManager uploads;
    private final AudioEngine engine;

    private int mode = Payloads.RaveStatus.MODE_MP3;
    private int state = Payloads.RaveStatus.STOPPED;
    private String trackId = "";
    private String title = "";
    private long durationMicros;
    private long trackStartMicros;
    private long positionMicros;
    private volatile int currentStreamToken; // Schutz gegen veraltete "Titel zu Ende"-Meldungen
    private int revision;
    private Component lastMessage = Component.empty();

    // Live
    private int liveSessionCounter = 1;
    private int liveSession;
    private UUID liveSender;
    private String liveSenderName = "";
    private String liveSource = "";

    // Zuhoerer
    private final Set<UUID> audioListeners = new HashSet<>();
    private final Set<UUID> showListeners = new HashSet<>();
    private int tickCounter;

    public PlaybackController(MinecraftServer server, StageManager stage, Path dataDir) {
        this.server = server;
        this.stage = stage;
        this.io = LibraryService.newIoExecutor();
        try {
            this.library = new LibraryService(dataDir, io);
        } catch (IOException e) {
            throw new IllegalStateException("BlockRave: Datenverzeichnis nicht nutzbar: " + dataDir, e);
        }
        this.mode = "LIVE".equals(library.lib().mode()) ? Payloads.RaveStatus.MODE_LIVE : Payloads.RaveStatus.MODE_MP3;
        this.uploads = new UploadManager(server, library, io, this::broadcastLibrary);
        this.engine = new AudioEngine(this);
        applyConfig();
        selfCheck();
    }

    /** Prueft beim Start, dass die eingebetteten Audio-Bibliotheken nutzbar sind (verstaendliche Logs). */
    private static void selfCheck() {
        try {
            byte[] pkt = new de.blockrave.core.audio.OpusFrameEncoder(64_000, 1).encode(new float[AudioConstants.FRAME_SAMPLES * 2]);
            new de.blockrave.core.audio.OpusFrameDecoder().decode(pkt, new float[AudioConstants.FRAME_SAMPLES * 2]);
            Class.forName("javazoom.jl.decoder.Decoder");
            BlockRave.LOG.info("BlockRave: Audio-Bibliotheken geladen (Opus/Concentus, MP3/JLayer)");
        } catch (Throwable t) {
            BlockRave.LOG.error("BlockRave: Audio-Bibliotheken nicht nutzbar – MP3/Live-Wiedergabe funktioniert nicht", t);
        }
    }

    public void applyConfig() {
        engine.configure(BlockRaveConfig.OPUS_BITRATE.get(), BlockRaveConfig.MP3_BUFFER_MS.get() * 1000L,
                BlockRaveConfig.LIVE_DELAY_MS.get() * 1000L);
    }

    public UploadManager uploads() {
        return uploads;
    }

    public AudioEngine engine() {
        return engine;
    }

    public LibraryService library() {
        return library;
    }

    // ================================================================== Status

    private Payloads.RaveStatus statusPayload() {
        return new Payloads.RaveStatus(mode, state, trackId, title, durationMicros, trackStartMicros, positionMicros,
                library.lib().masterVolume(), liveSenderName, (int) (engine.liveDelayMicros() / 1000), liveSource, revision,
                lastMessage);
    }

    private void changed() {
        revision++;
        Net.sendToAll(server, statusPayload());
    }

    private void message(Component c) {
        lastMessage = c;
    }

    public void sendStatus(ServerPlayer p) {
        Net.send(p, statusPayload());
    }

    private Payloads.Library libraryPayload() {
        List<Payloads.TrackInfo> tracks = new ArrayList<>();
        for (TrackLibrary.Track t : library.lib().tracks()) {
            tracks.add(new Payloads.TrackInfo(t.id, t.displayName(), AudioConstants.samplesToMicros(t.durationSamples),
                    t.uploaderName == null ? "" : t.uploaderName, t.sizeBytes));
        }
        return new Payloads.Library(revision, tracks, new ArrayList<>(library.lib().playlist()),
                new ArrayList<>(library.lib().queue()), library.lib().totalBytes(), BlockRaveConfig.MAX_STORAGE_MB.get() * 1024L * 1024L);
    }

    public void broadcastLibrary() {
        Payloads.Library lib = libraryPayload();
        for (ServerPlayer p : server.getPlayerList().getPlayers()) {
            if (p.containerMenu instanceof DjBoothMenu) Net.send(p, lib);
        }
    }

    public void onMenuOpened(ServerPlayer p) {
        sendStatus(p);
        Net.send(p, libraryPayload());
    }

    // ================================================================== Befehle

    private static boolean usesBooth(ServerPlayer p) {
        return p.containerMenu instanceof DjBoothMenu m && m.stillValid(p);
    }

    public void handleCommand(ServerPlayer player, Payloads.DjCommand cmd) {
        if (!stage.hasStage() || !usesBooth(player)) {
            player.displayClientMessage(Component.translatable("blockrave.msg.use_booth"), true);
            return;
        }
        TrackLibrary lib = library.lib();
        boolean libChanged = false;
        switch (cmd.action()) {
            case DjAction.PLAY -> play(player);
            case DjAction.PAUSE -> pause();
            case DjAction.STOP -> stop(Component.translatable("blockrave.status.stopped_by", player.getGameProfile().getName()));
            case DjAction.NEXT -> next(player);
            case DjAction.PLAY_TRACK -> {
                if (liveSender != null) {
                    message(Component.translatable("blockrave.status.live_running", liveSenderName));
                } else if (lib.find(cmd.trackId()).isPresent()) {
                    lib.setCursorTo(cmd.trackId());
                    lib.takeNext(); // Cursor hinter den gewaehlten Titel setzen
                    startTrack(cmd.trackId(), ServerClock.nowMicros() + 300_000);
                    libChanged = true;
                }
            }
            case DjAction.SET_MODE -> setMode(cmd.arg() == 1 ? Payloads.RaveStatus.MODE_LIVE : Payloads.RaveStatus.MODE_MP3);
            case DjAction.SET_VOLUME -> {
                lib.setMasterVolume(cmd.arg());
                libChanged = true;
            }
            case DjAction.PLAYLIST_ADD -> libChanged = lib.addToPlaylist(cmd.trackId());
            case DjAction.PLAYLIST_REMOVE -> libChanged = lib.removeFromPlaylist(cmd.trackId());
            case DjAction.PLAYLIST_MOVE -> libChanged = lib.movePlaylist(cmd.trackId(), cmd.arg() < 0 ? -1 : 1);
            case DjAction.QUEUE_ADD -> libChanged = lib.enqueue(cmd.trackId());
            case DjAction.QUEUE_REMOVE -> libChanged = lib.removeFromQueue(cmd.arg());
            case DjAction.QUEUE_MOVE -> {
                try {
                    libChanged = lib.moveQueue(Integer.parseInt(cmd.trackId()), cmd.arg() < 0 ? -1 : 1);
                } catch (NumberFormatException ignored) {
                }
            }
            case DjAction.DELETE_TRACK -> libChanged = deleteTrack(cmd.trackId(), player);
            case DjAction.LIVE_STOP -> {
                if (liveSender != null) endLive(Component.translatable("blockrave.live.stopped_by", player.getGameProfile().getName()));
            }
            case DjAction.REQUEST_LIBRARY -> Net.send(player, libraryPayload());
            default -> {
                return;
            }
        }
        if (libChanged) {
            library.markDirty();
            broadcastLibrary();
        }
        changed();
    }

    private void setMode(int newMode) {
        if (newMode == mode) return;
        if (newMode == Payloads.RaveStatus.MODE_MP3 && liveSender != null) {
            message(Component.translatable("blockrave.status.live_running", liveSenderName));
            return;
        }
        if (newMode == Payloads.RaveStatus.MODE_LIVE && state == Payloads.RaveStatus.PLAYING) pause();
        mode = newMode;
        library.lib().setMode(mode == Payloads.RaveStatus.MODE_LIVE ? "LIVE" : "MP3");
        library.markDirty();
    }

    private void play(ServerPlayer player) {
        if (liveSender != null) {
            message(Component.translatable("blockrave.status.live_running", liveSenderName));
            return;
        }
        mode = Payloads.RaveStatus.MODE_MP3;
        if (state == Payloads.RaveStatus.PAUSED && engine.isMp3Active()) {
            trackStartMicros = engine.resume(ServerClock.nowMicros());
            state = Payloads.RaveStatus.PLAYING;
            message(Component.translatable("blockrave.status.resumed_by", player.getGameProfile().getName()));
            return;
        }
        if (state == Payloads.RaveStatus.PLAYING) return;
        var next = library.lib().takeNext();
        if (next.isEmpty()) {
            message(Component.translatable("blockrave.status.no_tracks"));
            return;
        }
        library.markDirty();
        startTrack(next.get(), ServerClock.nowMicros() + 300_000);
    }

    private void startTrack(String id, long startMicros) {
        var t = library.lib().find(id);
        if (t.isEmpty()) return;
        engine.startTrack(id, library.trackFile(id), startMicros);
        currentStreamToken++;
        trackId = id;
        title = t.get().displayName();
        durationMicros = AudioConstants.samplesToMicros(t.get().durationSamples);
        trackStartMicros = startMicros;
        positionMicros = 0;
        state = Payloads.RaveStatus.PLAYING;
        mode = Payloads.RaveStatus.MODE_MP3;
        message(Component.translatable("blockrave.status.now_playing", title));
        BlockRave.LOG.info("BlockRave: Spiele '{}'", title);
    }

    private void pause() {
        if (state != Payloads.RaveStatus.PLAYING) return;
        positionMicros = engine.pause(ServerClock.nowMicros());
        state = Payloads.RaveStatus.PAUSED;
        message(Component.translatable("blockrave.status.paused"));
    }

    private void stop(Component why) {
        if (state == Payloads.RaveStatus.PLAYING || state == Payloads.RaveStatus.PAUSED) {
            engine.stopMp3(ServerClock.nowMicros() + 100_000);
        }
        state = liveSender != null ? Payloads.RaveStatus.LIVE : Payloads.RaveStatus.STOPPED;
        trackId = "";
        title = "";
        durationMicros = 0;
        positionMicros = 0;
        currentStreamToken++;
        message(why);
    }

    private void next(ServerPlayer player) {
        if (liveSender != null) {
            message(Component.translatable("blockrave.status.live_running", liveSenderName));
            return;
        }
        var next = library.lib().takeNext();
        library.markDirty();
        if (next.isEmpty()) {
            stop(Component.translatable("blockrave.status.no_tracks"));
            return;
        }
        startTrack(next.get(), ServerClock.nowMicros() + 200_000);
    }

    private boolean deleteTrack(String id, ServerPlayer player) {
        var t = library.lib().find(id);
        if (t.isEmpty()) return false;
        if (id.equals(trackId)) stop(Component.translatable("blockrave.status.track_deleted"));
        library.lib().deleteTrack(id);
        io.execute(() -> {
            try {
                library.store().delete(id);
            } catch (IOException e) {
                BlockRave.LOG.warn("BlockRave: Datei {} konnte nicht geloescht werden", id, e);
            }
        });
        BlockRave.LOG.info("BlockRave: Titel '{}' von {} geloescht", t.get().displayName(), player.getGameProfile().getName());
        return true;
    }

    // ================================================================== Engine-Rueckmeldungen (beliebiger Thread)

    @Override
    public void onTrackEnded(int streamId, String endedTrack, long endMicros) {
        int token = currentStreamToken;
        server.execute(() -> {
            if (token != currentStreamToken || !endedTrack.equals(trackId) || state != Payloads.RaveStatus.PLAYING) return;
            var next = library.lib().takeNext();
            library.markDirty();
            if (next.isEmpty()) {
                stop(Component.translatable("blockrave.status.playlist_end"));
            } else {
                // Nahtlos: naechster Titel beginnt genau am Ende des vorherigen
                startTrack(next.get(), Math.max(endMicros, ServerClock.nowMicros() + 100_000));
            }
            changed();
        });
    }

    @Override
    public void onTrackFailed(String failedTrack, String msg) {
        server.execute(() -> {
            if (!failedTrack.equals(trackId)) return;
            String failedTitle = title;
            // Defekten Titel ueberspringen, naechsten versuchen (hoechstens einmal pro Befehl)
            var next = library.lib().takeNext();
            if (next.isPresent() && !next.get().equals(failedTrack)) {
                startTrack(next.get(), ServerClock.nowMicros() + 300_000);
            } else {
                stop(Component.translatable("blockrave.status.track_error", failedTitle));
            }
            message(Component.translatable("blockrave.status.track_error", failedTitle));
            changed();
        });
    }

    // ================================================================== Live

    public void liveStart(ServerPlayer player, Payloads.LiveStart req) {
        Component err = null;
        if (!BlockRaveConfig.ALLOW_LIVE.get()) err = Component.translatable("blockrave.live.disabled");
        else if (!stage.hasStage() || !usesBooth(player)) err = Component.translatable("blockrave.msg.use_booth");
        else if (liveSender != null && !liveSender.equals(player.getUUID()))
            err = Component.translatable("blockrave.live.busy", liveSenderName);
        if (err != null) {
            Net.send(player, new Payloads.LiveControl(Payloads.LiveControl.REJECTED, 0, 0, err));
            return;
        }
        if (state == Payloads.RaveStatus.PLAYING) pause();
        liveSession = liveSessionCounter++;
        liveSender = player.getUUID();
        liveSenderName = player.getGameProfile().getName();
        liveSource = req.sourceName().length() > 100 ? req.sourceName().substring(0, 100) : req.sourceName();
        mode = Payloads.RaveStatus.MODE_LIVE;
        state = Payloads.RaveStatus.LIVE;
        engine.startLive(liveSession, liveSender);
        message(Component.translatable("blockrave.live.started", liveSenderName));
        BlockRave.LOG.info("BlockRave: Live-Uebertragung von {} gestartet (Quelle: {})", liveSenderName, liveSource);
        Net.send(player, new Payloads.LiveControl(Payloads.LiveControl.GRANTED, liveSession,
                (int) (engine.liveDelayMicros() / 1000), Component.empty()));
        changed();
    }

    public void liveStop(ServerPlayer player, Payloads.LiveStop req) {
        if (liveSender != null && liveSender.equals(player.getUUID()) && req.sessionId() == liveSession) {
            endLive(Component.translatable("blockrave.live.ended", liveSenderName));
        }
    }

    private void endLive(Component why) {
        if (liveSender == null) return;
        engine.stopLive(ServerClock.nowMicros() + 100_000);
        ServerPlayer sender = server.getPlayerList().getPlayer(liveSender);
        if (sender != null) {
            Net.send(sender, new Payloads.LiveControl(Payloads.LiveControl.STOP, liveSession, 0, why));
        }
        BlockRave.LOG.info("BlockRave: Live-Uebertragung beendet: {}", why.getString());
        liveSender = null;
        liveSenderName = "";
        liveSource = "";
        state = engine.isMp3Active() ? Payloads.RaveStatus.PAUSED : Payloads.RaveStatus.STOPPED;
        message(why);
        changed();
    }

    /** Aktuelle Live-Sitzung (0 = keine). */
    public int liveSessionId() {
        return liveSender == null ? 0 : liveSession;
    }

    public int state() {
        return state;
    }

    public String currentTrackId() {
        return trackId;
    }

    /** UUIDs der Spieler, die gerade Audio bzw. nur Lichtshow-Daten erhalten (Diagnose/Tests). */
    public java.util.Set<UUID> audioListenerIds() {
        return java.util.Set.copyOf(audioListeners);
    }

    public java.util.Set<UUID> showListenerIds() {
        return java.util.Set.copyOf(showListeners);
    }

    public boolean isLiveSender(UUID id) {
        return liveSender != null && liveSender.equals(id);
    }

    // ================================================================== Lebenszyklus

    public void tick() {
        tickCounter++;
        if (tickCounter % 10 == 0) updateListeners();
        if (tickCounter % 20 == 0) {
            uploads.tick();
            if (liveSender != null) {
                long last = engine.liveLastArrival();
                if (last > 0 && ServerClock.nowMicros() - last > 5_000_000) {
                    endLive(Component.translatable("blockrave.live.timeout", liveSenderName));
                }
            }
        }
        if (tickCounter % 200 == 0) Net.sendToAll(server, statusPayload());
    }

    /** Bestimmt, wer Audio (Hoerreichweite) bzw. nur Lichtshow-Daten (Sichtweite) bekommt. */
    private void updateListeners() {
        List<ServerPlayer> audio = new ArrayList<>();
        List<ServerPlayer> show = new ArrayList<>();
        ServerLevel level = stage.level();
        Vec3 center = stage.center();
        if (level != null && center != null) {
            double audioRange = BlockRaveConfig.HEARING_RANGE.get() + 16; // Rand fuer sanftes Einblenden
            double showRange = Math.max(audioRange, BlockRaveConfig.SHOW_RANGE.get());
            for (ServerPlayer p : level.players()) {
                double d = p.position().distanceTo(center);
                if (d <= audioRange) audio.add(p);
                else if (d <= showRange) show.add(p);
            }
        }
        Set<UUID> newAudio = new HashSet<>();
        Set<UUID> newShow = new HashSet<>();
        audio.forEach(p -> newAudio.add(p.getUUID()));
        show.forEach(p -> newShow.add(p.getUUID()));
        engine.setListeners(audio, show);
        if (!newAudio.equals(audioListeners) || !newShow.equals(showListeners)) {
            BlockRave.LOG.info("BlockRave: Empfaenger Audio [{}], nur Licht [{}]",
                    String.join(", ", audio.stream().map(p -> p.getGameProfile().getName()).toList()),
                    String.join(", ", show.stream().map(p -> p.getGameProfile().getName()).toList()));
        }
        for (ServerPlayer p : audio) if (!audioListeners.contains(p.getUUID())) engine.catchUp(p, true);
        for (ServerPlayer p : show) if (!showListeners.contains(p.getUUID()) && !audioListeners.contains(p.getUUID())) engine.catchUp(p, false);
        audioListeners.clear();
        audioListeners.addAll(newAudio);
        showListeners.clear();
        showListeners.addAll(newShow);
    }

    public void onLogin(ServerPlayer p) {
        sendStatus(p);
    }

    public void onLogout(ServerPlayer p) {
        uploads.onLogout(p);
        if (isLiveSender(p.getUUID())) endLive(Component.translatable("blockrave.live.disconnected", liveSenderName));
        audioListeners.remove(p.getUUID());
        showListeners.remove(p.getUUID());
    }

    public void onStagePacked() {
        if (liveSender != null) endLive(Component.translatable("blockrave.live.stage_packed"));
        stop(Component.translatable("blockrave.status.stage_packed"));
        engine.setListeners(List.of(), List.of());
        audioListeners.clear();
        showListeners.clear();
        changed();
    }

    public void onStageChanged() {
        changed();
    }

    public void shutdown() {
        engine.shutdown();
        uploads.shutdown();
        library.saveNow();
        LibraryService.shutdownExecutor(io);
    }
}
