package de.blockrave.network;

import de.blockrave.server.ServerPayloadHandler;
import net.neoforged.neoforge.network.event.RegisterPayloadHandlersEvent;
import net.neoforged.neoforge.network.registration.HandlerThread;
import net.neoforged.neoforge.network.registration.PayloadRegistrar;

/**
 * Registriert alle Pakete. Audio-, Zeit- und Upload-Pakete werden direkt auf dem Netzwerk-Thread
 * verarbeitet, damit weder der Server-Tick noch der Render-Thread blockiert oder sie verzoegert.
 */
public final class BlockRaveNetwork {
    public static final String PROTOCOL = "1";

    private BlockRaveNetwork() {}

    private static ClientBridge client() {
        return ClientBridge.Holder.get();
    }

    public static void register(RegisterPayloadHandlersEvent event) {
        PayloadRegistrar main = event.registrar(PROTOCOL);
        // Server-Hauptthread (Weltzugriffe, Zustandsaenderungen)
        main.playToServer(Payloads.PlacementCheck.TYPE, Payloads.PlacementCheck.CODEC, ServerPayloadHandler::placementCheck);
        main.playToServer(Payloads.PlacementConfirm.TYPE, Payloads.PlacementConfirm.CODEC, ServerPayloadHandler::placementConfirm);
        main.playToServer(Payloads.DjCommand.TYPE, Payloads.DjCommand.CODEC, ServerPayloadHandler::djCommand);
        main.playToServer(Payloads.UploadBegin.TYPE, Payloads.UploadBegin.CODEC, ServerPayloadHandler::uploadBegin);
        main.playToServer(Payloads.UploadCancel.TYPE, Payloads.UploadCancel.CODEC, ServerPayloadHandler::uploadCancel);
        main.playToServer(Payloads.LiveStart.TYPE, Payloads.LiveStart.CODEC, ServerPayloadHandler::liveStart);
        main.playToServer(Payloads.LiveStop.TYPE, Payloads.LiveStop.CODEC, ServerPayloadHandler::liveStop);

        main.playToClient(Payloads.StageInfo.TYPE, Payloads.StageInfo.CODEC, (p, c) -> client().handleStageInfo(p, c));
        main.playToClient(Payloads.PlacementResult.TYPE, Payloads.PlacementResult.CODEC, (p, c) -> client().handlePlacementResult(p, c));
        main.playToClient(Payloads.RaveStatus.TYPE, Payloads.RaveStatus.CODEC, (p, c) -> client().handleStatus(p, c));
        main.playToClient(Payloads.Library.TYPE, Payloads.Library.CODEC, (p, c) -> client().handleLibrary(p, c));
        main.playToClient(Payloads.UploadStatus.TYPE, Payloads.UploadStatus.CODEC, (p, c) -> client().handleUploadStatus(p, c));
        main.playToClient(Payloads.LiveControl.TYPE, Payloads.LiveControl.CODEC, (p, c) -> client().handleLiveControl(p, c));

        // Netzwerk-Thread: zeitkritisch bzw. grosse Datenmengen
        PayloadRegistrar fast = event.registrar(PROTOCOL).executesOn(HandlerThread.NETWORK);
        fast.playToServer(Payloads.UploadChunk.TYPE, Payloads.UploadChunk.CODEC, ServerPayloadHandler::uploadChunk);
        fast.playToServer(Payloads.LiveAudio.TYPE, Payloads.LiveAudio.CODEC, ServerPayloadHandler::liveAudio);
        fast.playToServer(Payloads.TimeSyncRequest.TYPE, Payloads.TimeSyncRequest.CODEC, ServerPayloadHandler::timeSync);
        fast.playToClient(Payloads.AudioFrames.TYPE, Payloads.AudioFrames.CODEC, (p, c) -> client().handleAudioFrames(p, c));
        fast.playToClient(Payloads.StreamCut.TYPE, Payloads.StreamCut.CODEC, (p, c) -> client().handleStreamCut(p, c));
        fast.playToClient(Payloads.TimeSyncResponse.TYPE, Payloads.TimeSyncResponse.CODEC, (p, c) -> client().handleTimeSync(p, c));
    }
}
