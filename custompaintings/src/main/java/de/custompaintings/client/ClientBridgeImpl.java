package de.custompaintings.client;

import de.custompaintings.network.ClientBridge;
import de.custompaintings.network.Payloads;
import net.neoforged.neoforge.network.handling.IPayloadContext;

/** Client-Implementierung der Paketbehandlung. */
public final class ClientBridgeImpl implements ClientBridge {
    @Override
    public void handleUploadReply(Payloads.UploadReply p, IPayloadContext ctx) {
        ClientUploader.INSTANCE.onReply(p);
    }

    @Override
    public void handlePaintingImage(Payloads.PaintingImage p, IPayloadContext ctx) {
        ClientImages.INSTANCE.setPaintingImage(p.entityId(), p.hash());
    }

    @Override
    public void handleImageData(Payloads.ImageData p, IPayloadContext ctx) {
        ClientImages.INSTANCE.onImageData(p);
    }
}
