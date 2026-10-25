package de.custompaintings.network;

import net.neoforged.neoforge.network.handling.IPayloadContext;

/**
 * Bruecke vom gemeinsamen Code zum Client. Auf einem dedizierten Server bleibt die leere Standardimplementierung
 * aktiv, der Client setzt beim Start seine eigene. So laedt der Server nie Client-Klassen.
 */
public interface ClientBridge {
    ClientBridge NOOP = new ClientBridge() {};

    default void handleUploadReply(Payloads.UploadReply p, IPayloadContext ctx) {}

    default void handlePaintingImage(Payloads.PaintingImage p, IPayloadContext ctx) {}

    default void handleImageData(Payloads.ImageData p, IPayloadContext ctx) {}

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
