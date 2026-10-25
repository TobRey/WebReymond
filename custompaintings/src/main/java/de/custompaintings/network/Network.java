package de.custompaintings.network;

import de.custompaintings.server.PaintingImages;
import net.minecraft.server.level.ServerPlayer;
import net.minecraft.world.entity.Entity;
import net.minecraft.network.protocol.common.custom.CustomPacketPayload;
import net.neoforged.neoforge.network.PacketDistributor;
import net.neoforged.neoforge.network.event.RegisterPayloadHandlersEvent;
import net.neoforged.neoforge.network.registration.HandlerThread;
import net.neoforged.neoforge.network.registration.PayloadRegistrar;

/** Registriert die Pakete und versendet sie (nur an Spieler, die die Mod haben). */
public final class Network {
    public static final String PROTOCOL = "1";

    private Network() {}

    private static ClientBridge client() {
        return ClientBridge.Holder.get();
    }

    public static void register(RegisterPayloadHandlersEvent event) {
        PayloadRegistrar main = event.registrar(PROTOCOL);
        main.playToServer(Payloads.SetImage.TYPE, Payloads.SetImage.CODEC,
                (p, c) -> PaintingImages.get().handleSetImage((ServerPlayer) c.player(), p));
        main.playToServer(Payloads.RequestImage.TYPE, Payloads.RequestImage.CODEC,
                (p, c) -> PaintingImages.get().handleRequest((ServerPlayer) c.player(), p));
        main.playToClient(Payloads.UploadReply.TYPE, Payloads.UploadReply.CODEC, (p, c) -> client().handleUploadReply(p, c));
        main.playToClient(Payloads.PaintingImage.TYPE, Payloads.PaintingImage.CODEC, (p, c) -> client().handlePaintingImage(p, c));

        // Datenbloecke direkt auf dem Netzwerk-Thread (blockieren weder Server-Tick noch Render-Thread)
        PayloadRegistrar fast = event.registrar(PROTOCOL).executesOn(HandlerThread.NETWORK);
        fast.playToServer(Payloads.UploadChunk.TYPE, Payloads.UploadChunk.CODEC,
                (p, c) -> PaintingImages.get().handleChunk((ServerPlayer) c.player(), p));
        fast.playToClient(Payloads.ImageData.TYPE, Payloads.ImageData.CODEC, (p, c) -> client().handleImageData(p, c));
    }

    public static void send(ServerPlayer player, CustomPacketPayload payload) {
        if (player.connection != null && player.connection.hasChannel(payload.type())) {
            PacketDistributor.sendToPlayer(player, payload);
        }
    }

    /** An alle Spieler, die das Gemaelde gerade sehen (in Sichtweite geladen haben). */
    public static void sendToTracking(Entity entity, CustomPacketPayload payload) {
        PacketDistributor.sendToPlayersTrackingEntity(entity, payload);
    }
}
