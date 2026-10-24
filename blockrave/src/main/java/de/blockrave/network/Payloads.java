package de.blockrave.network;

import de.blockrave.BlockRave;
import de.blockrave.core.analysis.AnalysisFrame;
import de.blockrave.core.audio.AudioConstants;
import net.minecraft.core.BlockPos;
import net.minecraft.network.FriendlyByteBuf;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.network.chat.Component;
import net.minecraft.network.chat.ComponentSerialization;
import net.minecraft.network.codec.StreamCodec;
import net.minecraft.network.protocol.common.custom.CustomPacketPayload;

import java.util.ArrayList;
import java.util.List;

/**
 * Alle Netzwerkpakete von BlockRave. Groessen werden beim Lesen begrenzt, damit fehlerhafte
 * oder boeswillige Pakete keinen unbegrenzten Speicher belegen.
 */
public final class Payloads {
    private Payloads() {}

    public static final int MAX_CHUNK = 16 * 1024;
    public static final int MAX_FRAMES_PER_PACKET = 8;
    public static final int MAX_AFFECTED = 20_000;

    private static <T extends CustomPacketPayload> CustomPacketPayload.Type<T> payloadType(String name) {
        return new CustomPacketPayload.Type<>(BlockRave.id(name));
    }

    static byte[][] readFrames(FriendlyByteBuf buf, int maxLen) {
        int n = buf.readVarInt();
        if (n < 0 || n > MAX_FRAMES_PER_PACKET) throw new IllegalArgumentException("zu viele Frames: " + n);
        byte[][] r = new byte[n][];
        for (int i = 0; i < n; i++) r[i] = buf.readByteArray(maxLen);
        return r;
    }

    static void writeFrames(FriendlyByteBuf buf, byte[][] frames) {
        buf.writeVarInt(frames.length);
        for (byte[] f : frames) buf.writeByteArray(f);
    }

    // =====================================================================  Client -> Server

    /** Bitte um serverseitige Pruefung eines Bauplatzes. */
    public record PlacementCheck(int requestId, BlockPos anchor, int rotation) implements CustomPacketPayload {
        public static final Type<PlacementCheck> TYPE = payloadType("placement_check");
        public static final StreamCodec<FriendlyByteBuf, PlacementCheck> CODEC = StreamCodec.of(
                (b, p) -> { b.writeVarInt(p.requestId); b.writeBlockPos(p.anchor); b.writeByte(p.rotation); },
                b -> new PlacementCheck(b.readVarInt(), b.readBlockPos(), b.readByte()));

        @Override
        public Type<PlacementCheck> type() { return TYPE; }
    }

    /** Ausdrueckliche Bestaetigung; {@code hash} beschreibt den bestaetigten Weltzustand. */
    public record PlacementConfirm(int requestId, BlockPos anchor, int rotation, long hash) implements CustomPacketPayload {
        public static final Type<PlacementConfirm> TYPE = payloadType("placement_confirm");
        public static final StreamCodec<FriendlyByteBuf, PlacementConfirm> CODEC = StreamCodec.of(
                (b, p) -> { b.writeVarInt(p.requestId); b.writeBlockPos(p.anchor); b.writeByte(p.rotation); b.writeLong(p.hash); },
                b -> new PlacementConfirm(b.readVarInt(), b.readBlockPos(), b.readByte(), b.readLong()));

        @Override
        public Type<PlacementConfirm> type() { return TYPE; }
    }

    /** Bedienbefehl am DJ-Pult, siehe {@link DjAction}. */
    public record DjCommand(int action, String trackId, int arg) implements CustomPacketPayload {
        public static final Type<DjCommand> TYPE = payloadType("dj_command");
        public static final StreamCodec<FriendlyByteBuf, DjCommand> CODEC = StreamCodec.of(
                (b, p) -> { b.writeVarInt(p.action); b.writeUtf(p.trackId, 64); b.writeVarInt(p.arg); },
                b -> new DjCommand(b.readVarInt(), b.readUtf(64), b.readVarInt()));

        @Override
        public Type<DjCommand> type() { return TYPE; }
    }

    public record UploadBegin(int clientUploadId, String fileName, long size) implements CustomPacketPayload {
        public static final Type<UploadBegin> TYPE = payloadType("upload_begin");
        public static final StreamCodec<FriendlyByteBuf, UploadBegin> CODEC = StreamCodec.of(
                (b, p) -> { b.writeVarInt(p.clientUploadId); b.writeUtf(p.fileName, 256); b.writeVarLong(p.size); },
                b -> new UploadBegin(b.readVarInt(), b.readUtf(256), b.readVarLong()));

        @Override
        public Type<UploadBegin> type() { return TYPE; }
    }

    public record UploadChunk(String uploadId, long offset, byte[] data) implements CustomPacketPayload {
        public static final Type<UploadChunk> TYPE = payloadType("upload_chunk");
        public static final StreamCodec<FriendlyByteBuf, UploadChunk> CODEC = StreamCodec.of(
                (b, p) -> { b.writeUtf(p.uploadId, 40); b.writeVarLong(p.offset); b.writeByteArray(p.data); },
                b -> new UploadChunk(b.readUtf(40), b.readVarLong(), b.readByteArray(MAX_CHUNK)));

        @Override
        public Type<UploadChunk> type() { return TYPE; }
    }

    public record UploadCancel(String uploadId) implements CustomPacketPayload {
        public static final Type<UploadCancel> TYPE = payloadType("upload_cancel");
        public static final StreamCodec<FriendlyByteBuf, UploadCancel> CODEC = StreamCodec.of(
                (b, p) -> b.writeUtf(p.uploadId, 40), b -> new UploadCancel(b.readUtf(40)));

        @Override
        public Type<UploadCancel> type() { return TYPE; }
    }

    /** Der sendende Spieler moechte live senden (nur er selbst kann dies ausloesen). */
    public record LiveStart(String sourceName) implements CustomPacketPayload {
        public static final Type<LiveStart> TYPE = payloadType("live_start");
        public static final StreamCodec<FriendlyByteBuf, LiveStart> CODEC = StreamCodec.of(
                (b, p) -> b.writeUtf(p.sourceName, 128), b -> new LiveStart(b.readUtf(128)));

        @Override
        public Type<LiveStart> type() { return TYPE; }
    }

    /**
     * Live-Audio vom Sender: aufeinanderfolgende 20-ms-Frames ab Sample-Position {@code firstPts}
     * (48 kHz, fortlaufend seit Sendebeginn) mit Opus-Daten und Analyse.
     */
    public record LiveAudio(int sessionId, long firstPts, byte[][] opus, byte[][] analysis) implements CustomPacketPayload {
        public static final Type<LiveAudio> TYPE = payloadType("live_audio");
        public static final StreamCodec<FriendlyByteBuf, LiveAudio> CODEC = StreamCodec.of(
                (b, p) -> { b.writeVarInt(p.sessionId); b.writeVarLong(p.firstPts); writeFrames(b, p.opus); writeFrames(b, p.analysis); },
                b -> new LiveAudio(b.readVarInt(), b.readVarLong(), readFrames(b, AudioConstants.MAX_OPUS_PACKET),
                        readFrames(b, AnalysisFrame.ENCODED_SIZE)));

        @Override
        public Type<LiveAudio> type() { return TYPE; }
    }

    public record LiveStop(int sessionId) implements CustomPacketPayload {
        public static final Type<LiveStop> TYPE = payloadType("live_stop");
        public static final StreamCodec<FriendlyByteBuf, LiveStop> CODEC = StreamCodec.of(
                (b, p) -> b.writeVarInt(p.sessionId), b -> new LiveStop(b.readVarInt()));

        @Override
        public Type<LiveStop> type() { return TYPE; }
    }

    public record TimeSyncRequest(long clientMicros) implements CustomPacketPayload {
        public static final Type<TimeSyncRequest> TYPE = payloadType("time_sync_request");
        public static final StreamCodec<FriendlyByteBuf, TimeSyncRequest> CODEC = StreamCodec.of(
                (b, p) -> b.writeLong(p.clientMicros), b -> new TimeSyncRequest(b.readLong()));

        @Override
        public Type<TimeSyncRequest> type() { return TYPE; }
    }

    // =====================================================================  Server -> Client

    public record StageInfo(boolean present, String dimension, BlockPos anchor, int rotation, int hearingRange, String stageText)
            implements CustomPacketPayload {
        public static final Type<StageInfo> TYPE = payloadType("stage_info");
        public static final StreamCodec<FriendlyByteBuf, StageInfo> CODEC = StreamCodec.of(
                (b, p) -> { b.writeBoolean(p.present); b.writeUtf(p.dimension, 256); b.writeBlockPos(p.anchor); b.writeByte(p.rotation);
                    b.writeVarInt(p.hearingRange); b.writeUtf(p.stageText, 64); },
                b -> new StageInfo(b.readBoolean(), b.readUtf(256), b.readBlockPos(), b.readByte(), b.readVarInt(), b.readUtf(64)));

        @Override
        public Type<StageInfo> type() { return TYPE; }
    }

    /** Ergebnis von Pruefung oder Aufbau. */
    public record PlacementResult(int requestId, int status, Component message, BlockPos anchor, int rotation,
                                  int affectedCount, int containerCount, long hash, List<Component> summary,
                                  long[] affected) implements CustomPacketPayload {
        public static final int CHECK_OK = 0, CHECK_FAILED = 1, CHANGED = 2, PLACED = 3, PLACE_FAILED = 4;
        public static final Type<PlacementResult> TYPE = payloadType("placement_result");
        public static final StreamCodec<RegistryFriendlyByteBuf, PlacementResult> CODEC = StreamCodec.of(
                (b, p) -> {
                    b.writeVarInt(p.requestId);
                    b.writeVarInt(p.status);
                    ComponentSerialization.TRUSTED_STREAM_CODEC.encode(b, p.message);
                    b.writeBlockPos(p.anchor);
                    b.writeByte(p.rotation);
                    b.writeVarInt(p.affectedCount);
                    b.writeVarInt(p.containerCount);
                    b.writeLong(p.hash);
                    b.writeVarInt(p.summary.size());
                    for (Component c : p.summary) ComponentSerialization.TRUSTED_STREAM_CODEC.encode(b, c);
                    b.writeLongArray(p.affected);
                },
                b -> {
                    int req = b.readVarInt();
                    int status = b.readVarInt();
                    Component msg = ComponentSerialization.TRUSTED_STREAM_CODEC.decode(b);
                    BlockPos anchor = b.readBlockPos();
                    int rot = b.readByte();
                    int count = b.readVarInt();
                    int containers = b.readVarInt();
                    long hash = b.readLong();
                    int n = Math.min(b.readVarInt(), 32);
                    List<Component> summary = new ArrayList<>();
                    for (int i = 0; i < n; i++) summary.add(ComponentSerialization.TRUSTED_STREAM_CODEC.decode(b));
                    long[] affected = b.readLongArray(null, MAX_AFFECTED);
                    return new PlacementResult(req, status, msg, anchor, rot, count, containers, hash, summary, affected);
                });

        @Override
        public Type<PlacementResult> type() { return TYPE; }
    }

    /**
     * Gemeinsamer Wiedergabezustand fuer alle Spieler.
     *
     * @param trackStartMicros Serverzeit, zu der Position 0 des Titels zu hoeren war bzw. waere
     * @param positionMicros   Position beim Pausieren/Stoppen
     */
    public record RaveStatus(int mode, int state, String trackId, String title, long durationMicros,
                             long trackStartMicros, long positionMicros, int masterVolume, String liveSender,
                             int liveDelayMs, String liveSource, int revision, Component message)
            implements CustomPacketPayload {
        public static final int MODE_MP3 = 0, MODE_LIVE = 1;
        public static final int STOPPED = 0, PLAYING = 1, PAUSED = 2, LIVE = 3;
        public static final Type<RaveStatus> TYPE = payloadType("rave_status");
        public static final StreamCodec<RegistryFriendlyByteBuf, RaveStatus> CODEC = StreamCodec.of(
                (b, p) -> {
                    b.writeByte(p.mode);
                    b.writeByte(p.state);
                    b.writeUtf(p.trackId, 64);
                    b.writeUtf(p.title, 256);
                    b.writeVarLong(p.durationMicros);
                    b.writeLong(p.trackStartMicros);
                    b.writeVarLong(p.positionMicros);
                    b.writeByte(p.masterVolume);
                    b.writeUtf(p.liveSender, 64);
                    b.writeVarInt(p.liveDelayMs);
                    b.writeUtf(p.liveSource, 128);
                    b.writeVarInt(p.revision);
                    ComponentSerialization.TRUSTED_STREAM_CODEC.encode(b, p.message);
                },
                b -> new RaveStatus(b.readByte(), b.readByte(), b.readUtf(64), b.readUtf(256), b.readVarLong(), b.readLong(),
                        b.readVarLong(), b.readByte(), b.readUtf(64), b.readVarInt(), b.readUtf(128), b.readVarInt(),
                        ComponentSerialization.TRUSTED_STREAM_CODEC.decode(b)));

        @Override
        public Type<RaveStatus> type() { return TYPE; }
    }

    public record TrackInfo(String id, String title, long durationMicros, String uploader, long sizeBytes) {
        static void write(FriendlyByteBuf b, TrackInfo t) {
            b.writeUtf(t.id, 64);
            b.writeUtf(t.title, 256);
            b.writeVarLong(t.durationMicros);
            b.writeUtf(t.uploader, 64);
            b.writeVarLong(t.sizeBytes);
        }

        static TrackInfo read(FriendlyByteBuf b) {
            return new TrackInfo(b.readUtf(64), b.readUtf(256), b.readVarLong(), b.readUtf(64), b.readVarLong());
        }
    }

    /** Bibliothek, Playlist und Warteschlange (nur an Spieler mit geoeffnetem DJ-Pult). */
    public record Library(int revision, List<TrackInfo> tracks, List<String> playlist, List<String> queue,
                          long usedBytes, long limitBytes) implements CustomPacketPayload {
        public static final Type<Library> TYPE = payloadType("library");
        public static final StreamCodec<FriendlyByteBuf, Library> CODEC = StreamCodec.of(
                (b, p) -> {
                    b.writeVarInt(p.revision);
                    b.writeVarInt(p.tracks.size());
                    for (TrackInfo t : p.tracks) TrackInfo.write(b, t);
                    b.writeVarInt(p.playlist.size());
                    for (String s : p.playlist) b.writeUtf(s, 64);
                    b.writeVarInt(p.queue.size());
                    for (String s : p.queue) b.writeUtf(s, 64);
                    b.writeVarLong(p.usedBytes);
                    b.writeVarLong(p.limitBytes);
                },
                b -> {
                    int rev = b.readVarInt();
                    int n = Math.min(b.readVarInt(), 20_000);
                    List<TrackInfo> tracks = new ArrayList<>(n);
                    for (int i = 0; i < n; i++) tracks.add(TrackInfo.read(b));
                    int pn = Math.min(b.readVarInt(), 20_000);
                    List<String> pl = new ArrayList<>(pn);
                    for (int i = 0; i < pn; i++) pl.add(b.readUtf(64));
                    int qn = Math.min(b.readVarInt(), 20_000);
                    List<String> q = new ArrayList<>(qn);
                    for (int i = 0; i < qn; i++) q.add(b.readUtf(64));
                    return new Library(rev, tracks, pl, q, b.readVarLong(), b.readVarLong());
                });

        @Override
        public Type<Library> type() { return TYPE; }
    }

    /**
     * Aufeinanderfolgende 20-ms-Frames eines Segments. Ohne Audio ({@code audio=false}) enthalten
     * die Opus-Eintraege leere Arrays (nur Lichtshow fuer weiter entfernte Spieler).
     */
    public record AudioFrames(int streamId, int firstSeq, long firstPlayoutMicros, int frameMicros, boolean audio,
                              byte[][] opus, byte[][] analysis) implements CustomPacketPayload {
        public static final Type<AudioFrames> TYPE = payloadType("audio_frames");
        public static final StreamCodec<FriendlyByteBuf, AudioFrames> CODEC = StreamCodec.of(
                (b, p) -> {
                    b.writeVarInt(p.streamId);
                    b.writeVarInt(p.firstSeq);
                    b.writeLong(p.firstPlayoutMicros);
                    b.writeVarInt(p.frameMicros);
                    b.writeBoolean(p.audio);
                    writeFrames(b, p.opus);
                    writeFrames(b, p.analysis);
                },
                b -> new AudioFrames(b.readVarInt(), b.readVarInt(), b.readLong(), b.readVarInt(), b.readBoolean(),
                        readFrames(b, AudioConstants.MAX_OPUS_PACKET), readFrames(b, AnalysisFrame.ENCODED_SIZE)));

        @Override
        public Type<AudioFrames> type() { return TYPE; }
    }

    /** Alle Frames des Segments ab {@code cutMicros} verwerfen (Pause, Stopp, naechster Titel). */
    public record StreamCut(int streamId, long cutMicros) implements CustomPacketPayload {
        public static final Type<StreamCut> TYPE = payloadType("stream_cut");
        public static final StreamCodec<FriendlyByteBuf, StreamCut> CODEC = StreamCodec.of(
                (b, p) -> { b.writeVarInt(p.streamId); b.writeLong(p.cutMicros); },
                b -> new StreamCut(b.readVarInt(), b.readLong()));

        @Override
        public Type<StreamCut> type() { return TYPE; }
    }

    public record TimeSyncResponse(long clientMicros, long serverMicros) implements CustomPacketPayload {
        public static final Type<TimeSyncResponse> TYPE = payloadType("time_sync_response");
        public static final StreamCodec<FriendlyByteBuf, TimeSyncResponse> CODEC = StreamCodec.of(
                (b, p) -> { b.writeLong(p.clientMicros); b.writeLong(p.serverMicros); },
                b -> new TimeSyncResponse(b.readLong(), b.readLong()));

        @Override
        public Type<TimeSyncResponse> type() { return TYPE; }
    }

    public record UploadStatus(int clientUploadId, String uploadId, int state, long received, long total,
                               int windowChunks, Component message) implements CustomPacketPayload {
        public static final int ACCEPTED = 0, PROGRESS = 1, PROCESSING = 2, DONE = 3, FAILED = 4;
        public static final Type<UploadStatus> TYPE = payloadType("upload_status");
        public static final StreamCodec<RegistryFriendlyByteBuf, UploadStatus> CODEC = StreamCodec.of(
                (b, p) -> {
                    b.writeVarInt(p.clientUploadId);
                    b.writeUtf(p.uploadId, 40);
                    b.writeByte(p.state);
                    b.writeVarLong(p.received);
                    b.writeVarLong(p.total);
                    b.writeVarInt(p.windowChunks);
                    ComponentSerialization.TRUSTED_STREAM_CODEC.encode(b, p.message);
                },
                b -> new UploadStatus(b.readVarInt(), b.readUtf(40), b.readByte(), b.readVarLong(), b.readVarLong(),
                        b.readVarInt(), ComponentSerialization.TRUSTED_STREAM_CODEC.decode(b)));

        @Override
        public Type<UploadStatus> type() { return TYPE; }
    }

    /** Steuerung der Live-Aufnahme beim Sender: Freigabe, Ablehnung oder Stopp. */
    public record LiveControl(int action, int sessionId, int delayMs, Component message) implements CustomPacketPayload {
        public static final int GRANTED = 0, REJECTED = 1, STOP = 2;
        public static final Type<LiveControl> TYPE = payloadType("live_control");
        public static final StreamCodec<RegistryFriendlyByteBuf, LiveControl> CODEC = StreamCodec.of(
                (b, p) -> { b.writeByte(p.action); b.writeVarInt(p.sessionId); b.writeVarInt(p.delayMs);
                    ComponentSerialization.TRUSTED_STREAM_CODEC.encode(b, p.message); },
                b -> new LiveControl(b.readByte(), b.readVarInt(), b.readVarInt(), ComponentSerialization.TRUSTED_STREAM_CODEC.decode(b)));

        @Override
        public Type<LiveControl> type() { return TYPE; }
    }
}
