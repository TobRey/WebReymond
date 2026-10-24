package de.blockrave.client;

import de.blockrave.client.placement.PlacementController;
import de.blockrave.network.ClientBridge;
import de.blockrave.network.Payloads;
import net.minecraft.world.InteractionHand;
import net.minecraft.world.entity.player.Player;
import net.neoforged.neoforge.network.handling.IPayloadContext;

/** Client-Implementierung der Paketbehandlung. */
public final class ClientBridgeImpl implements ClientBridge {
    @Override
    public void onStageKitUse(Player player, InteractionHand hand) {
        PlacementController.INSTANCE.onUse();
    }

    @Override
    public void handleStageInfo(Payloads.StageInfo p, IPayloadContext ctx) {
        ClientRaveState s = ClientRaveState.get();
        if (s != null) s.setStage(p);
    }

    @Override
    public void handlePlacementResult(Payloads.PlacementResult p, IPayloadContext ctx) {
        PlacementController.INSTANCE.onResult(p);
    }

    @Override
    public void handleStatus(Payloads.RaveStatus p, IPayloadContext ctx) {
        ClientRaveState s = ClientRaveState.get();
        if (s != null) s.setStatus(p);
    }

    @Override
    public void handleLibrary(Payloads.Library p, IPayloadContext ctx) {
        ClientRaveState s = ClientRaveState.get();
        if (s != null) s.setLibrary(p);
    }

    @Override
    public void handleAudioFrames(Payloads.AudioFrames p, IPayloadContext ctx) {
        ClientRaveState s = ClientRaveState.get();
        if (s != null) s.onFrames(p);
    }

    @Override
    public void handleStreamCut(Payloads.StreamCut p, IPayloadContext ctx) {
        ClientRaveState s = ClientRaveState.get();
        if (s != null) s.onCut(p);
    }

    @Override
    public void handleTimeSync(Payloads.TimeSyncResponse p, IPayloadContext ctx) {
        ClientRaveState s = ClientRaveState.get();
        if (s != null) s.onTimeSync(p);
    }

    @Override
    public void handleUploadStatus(Payloads.UploadStatus p, IPayloadContext ctx) {
        ClientRaveState s = ClientRaveState.get();
        if (s != null) s.uploads.onStatus(p);
    }

    @Override
    public void handleLiveControl(Payloads.LiveControl p, IPayloadContext ctx) {
        ClientRaveState s = ClientRaveState.get();
        if (s == null) return;
        switch (p.action()) {
            case Payloads.LiveControl.GRANTED -> s.live.onGranted(p.sessionId(), p.delayMs());
            case Payloads.LiveControl.REJECTED -> s.live.onRejected(p.message());
            case Payloads.LiveControl.STOP -> s.live.onServerStop(p.message());
            default -> {}
        }
    }
}
