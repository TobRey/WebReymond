package de.blockrave.network;

import net.minecraft.world.entity.player.Player;
import net.minecraft.world.InteractionHand;
import net.neoforged.neoforge.network.handling.IPayloadContext;

/**
 * Bruecke vom gemeinsamen Code zum Client. Auf einem dedizierten Server bleibt die leere
 * Standardimplementierung aktiv; der Client setzt beim Start seine Implementierung.
 * So laedt der Server niemals Client-Klassen.
 */
public interface ClientBridge {
    ClientBridge NOOP = new ClientBridge() {};

    default void onStageKitUse(Player player, InteractionHand hand) {}

    default void handleStageInfo(Payloads.StageInfo p, IPayloadContext ctx) {}

    default void handlePlacementResult(Payloads.PlacementResult p, IPayloadContext ctx) {}

    default void handleStatus(Payloads.RaveStatus p, IPayloadContext ctx) {}

    default void handleLibrary(Payloads.Library p, IPayloadContext ctx) {}

    /** Wird auf dem Netzwerk-Thread aufgerufen. */
    default void handleAudioFrames(Payloads.AudioFrames p, IPayloadContext ctx) {}

    /** Wird auf dem Netzwerk-Thread aufgerufen. */
    default void handleStreamCut(Payloads.StreamCut p, IPayloadContext ctx) {}

    /** Wird auf dem Netzwerk-Thread aufgerufen. */
    default void handleTimeSync(Payloads.TimeSyncResponse p, IPayloadContext ctx) {}

    default void handleUploadStatus(Payloads.UploadStatus p, IPayloadContext ctx) {}

    default void handleLiveControl(Payloads.LiveControl p, IPayloadContext ctx) {}

    final class Holder {
        private static volatile ClientBridge instance = NOOP;

        private Holder() {}

        public static ClientBridge get() {
            return instance;
        }

        public static void set(ClientBridge bridge) {
            instance = bridge;
        }
    }
}
