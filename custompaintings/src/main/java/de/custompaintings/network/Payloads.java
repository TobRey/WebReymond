package de.custompaintings.network;

import de.custompaintings.CustomPaintings;
import net.minecraft.network.FriendlyByteBuf;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.network.chat.Component;
import net.minecraft.network.chat.ComponentSerialization;
import net.minecraft.network.codec.StreamCodec;
import net.minecraft.network.protocol.common.custom.CustomPacketPayload;

/** Alle Netzwerkpakete von Custom Paintings. */
public final class Payloads {
    /** Groesse eines Upload-Blocks (Client -> Server; Grenze fuer Server-Pakete liegt bei 32 KiB). */
    public static final int UPLOAD_CHUNK = 16 * 1024;
    /** Groesse eines Download-Blocks (Server -> Client). */
    public static final int DOWNLOAD_CHUNK = 32 * 1024;
    public static final int MAX_FILE = 16 * 1024 * 1024;

    private Payloads() {}

    private static <T extends CustomPacketPayload> CustomPacketPayload.Type<T> payloadType(String name) {
        return new CustomPacketPayload.Type<>(CustomPaintings.id(name));
    }

    /** Client -> Server: Bild fuer ein Gemaelde setzen ({@code hash} leer = Originalmotiv wiederherstellen). */
    public record SetImage(int entityId, String hash, int size) implements CustomPacketPayload {
        public static final Type<SetImage> TYPE = payloadType("set_image");
        public static final StreamCodec<FriendlyByteBuf, SetImage> CODEC = StreamCodec.of(
                (b, p) -> { b.writeVarInt(p.entityId); b.writeUtf(p.hash, 64); b.writeVarInt(p.size); },
                b -> new SetImage(b.readVarInt(), b.readUtf(64), b.readVarInt()));

        @Override
        public Type<SetImage> type() { return TYPE; }
    }

    /** Client -> Server: ein Block der Bilddatei. */
    public record UploadChunk(String hash, int offset, byte[] data) implements CustomPacketPayload {
        public static final Type<UploadChunk> TYPE = payloadType("upload_chunk");
        public static final StreamCodec<FriendlyByteBuf, UploadChunk> CODEC = StreamCodec.of(
                (b, p) -> { b.writeUtf(p.hash, 64); b.writeVarInt(p.offset); b.writeByteArray(p.data); },
                b -> new UploadChunk(b.readUtf(64), b.readVarInt(), b.readByteArray(UPLOAD_CHUNK)));

        @Override
        public Type<UploadChunk> type() { return TYPE; }
    }

    /** Server -> Client: Antwort auf {@link SetImage}. */
    public record UploadReply(int entityId, String hash, int status, Component message) implements CustomPacketPayload {
        public static final int SEND_DATA = 0, DONE = 1, FAILED = 2;
        public static final Type<UploadReply> TYPE = payloadType("upload_reply");
        public static final StreamCodec<RegistryFriendlyByteBuf, UploadReply> CODEC = StreamCodec.of(
                (b, p) -> { b.writeVarInt(p.entityId); b.writeUtf(p.hash, 64); b.writeVarInt(p.status);
                    ComponentSerialization.TRUSTED_STREAM_CODEC.encode(b, p.message); },
                b -> new UploadReply(b.readVarInt(), b.readUtf(64), b.readVarInt(), ComponentSerialization.TRUSTED_STREAM_CODEC.decode(b)));

        @Override
        public Type<UploadReply> type() { return TYPE; }
    }

    /** Server -> Client: welches Bild ein Gemaelde zeigt ({@code hash} leer = Originalmotiv). */
    public record PaintingImage(int entityId, String hash) implements CustomPacketPayload {
        public static final Type<PaintingImage> TYPE = payloadType("painting_image");
        public static final StreamCodec<FriendlyByteBuf, PaintingImage> CODEC = StreamCodec.of(
                (b, p) -> { b.writeVarInt(p.entityId); b.writeUtf(p.hash, 64); },
                b -> new PaintingImage(b.readVarInt(), b.readUtf(64)));

        @Override
        public Type<PaintingImage> type() { return TYPE; }
    }

    /** Client -> Server: Bilddaten anfordern. */
    public record RequestImage(String hash) implements CustomPacketPayload {
        public static final Type<RequestImage> TYPE = payloadType("request_image");
        public static final StreamCodec<FriendlyByteBuf, RequestImage> CODEC = StreamCodec.of(
                (b, p) -> b.writeUtf(p.hash, 64), b -> new RequestImage(b.readUtf(64)));

        @Override
        public Type<RequestImage> type() { return TYPE; }
    }

    /** Server -> Client: ein Block der Bilddaten. */
    public record ImageData(String hash, int total, int offset, byte[] data) implements CustomPacketPayload {
        public static final Type<ImageData> TYPE = payloadType("image_data");
        public static final StreamCodec<FriendlyByteBuf, ImageData> CODEC = StreamCodec.of(
                (b, p) -> { b.writeUtf(p.hash, 64); b.writeVarInt(p.total); b.writeVarInt(p.offset); b.writeByteArray(p.data); },
                b -> new ImageData(b.readUtf(64), b.readVarInt(), b.readVarInt(), b.readByteArray(DOWNLOAD_CHUNK)));

        @Override
        public Type<ImageData> type() { return TYPE; }
    }
}
