package de.blockrave.client;

import de.blockrave.BlockRave;
import net.minecraft.client.Minecraft;
import net.minecraft.network.protocol.common.custom.CustomPacketPayload;
import net.neoforged.neoforge.network.PacketDistributor;

/** Sendet an den Server, sofern verbunden (auch von Hintergrund-Threads aus nutzbar). */
public final class ClientNet {
    private ClientNet() {}

    public static boolean send(CustomPacketPayload payload) {
        if (Minecraft.getInstance().getConnection() == null) return false;
        try {
            PacketDistributor.sendToServer(payload);
            return true;
        } catch (RuntimeException e) {
            BlockRave.LOG.debug("BlockRave: Senden fehlgeschlagen: {}", e.toString());
            return false;
        }
    }
}
