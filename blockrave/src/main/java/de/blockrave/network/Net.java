package de.blockrave.network;

import net.minecraft.network.protocol.common.custom.CustomPacketPayload;
import net.minecraft.server.MinecraftServer;
import net.minecraft.server.level.ServerPlayer;
import net.neoforged.neoforge.network.PacketDistributor;

/**
 * Sendet Pakete nur an Verbindungen, die den Kanal ausgehandelt haben (z.B. keine
 * Test-Spieler ohne Netzwerk). Threadsicher.
 */
public final class Net {
    private Net() {}

    public static void send(ServerPlayer player, CustomPacketPayload payload) {
        if (player.connection != null && player.connection.hasChannel(payload.type())) {
            PacketDistributor.sendToPlayer(player, payload);
        }
    }

    public static void sendToAll(MinecraftServer server, CustomPacketPayload payload) {
        for (ServerPlayer p : server.getPlayerList().getPlayers()) send(p, payload);
    }
}
