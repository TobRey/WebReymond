package de.blockrave.server;

import de.blockrave.audio.ServerClock;
import de.blockrave.network.Payloads;
import de.blockrave.stage.StageManager;
import net.minecraft.server.level.ServerPlayer;
import net.neoforged.neoforge.network.handling.IPayloadContext;

/** Serverseitige Paketannahme. Jede Anfrage wird gegen den sendenden Spieler geprueft. */
public final class ServerPayloadHandler {
    private ServerPayloadHandler() {}

    private static StageManager manager(ServerPlayer p) {
        return StageManager.get(p.server);
    }

    public static void placementCheck(Payloads.PlacementCheck p, IPayloadContext ctx) {
        if (ctx.player() instanceof ServerPlayer sp) manager(sp).handleCheck(sp, p);
    }

    public static void placementConfirm(Payloads.PlacementConfirm p, IPayloadContext ctx) {
        if (ctx.player() instanceof ServerPlayer sp) manager(sp).handleConfirm(sp, p);
    }

    public static void djCommand(Payloads.DjCommand p, IPayloadContext ctx) {
        if (ctx.player() instanceof ServerPlayer sp) manager(sp).playback().handleCommand(sp, p);
    }

    public static void uploadBegin(Payloads.UploadBegin p, IPayloadContext ctx) {
        if (ctx.player() instanceof ServerPlayer sp) manager(sp).playback().uploads().begin(sp, p);
    }

    public static void uploadCancel(Payloads.UploadCancel p, IPayloadContext ctx) {
        if (ctx.player() instanceof ServerPlayer sp) manager(sp).playback().uploads().cancel(sp, p.uploadId());
    }

    public static void liveStart(Payloads.LiveStart p, IPayloadContext ctx) {
        if (ctx.player() instanceof ServerPlayer sp) manager(sp).playback().liveStart(sp, p);
    }

    public static void liveStop(Payloads.LiveStop p, IPayloadContext ctx) {
        if (ctx.player() instanceof ServerPlayer sp) manager(sp).playback().liveStop(sp, p);
    }

    /** Netzwerk-Thread. */
    public static void uploadChunk(Payloads.UploadChunk p, IPayloadContext ctx) {
        StageManager m = StageManager.getOrNull();
        if (m != null && ctx.player() instanceof ServerPlayer sp) m.playback().uploads().chunk(sp, p);
    }

    /** Netzwerk-Thread: nur der freigegebene Sender wird angenommen (Pruefung in der Engine). */
    public static void liveAudio(Payloads.LiveAudio p, IPayloadContext ctx) {
        StageManager m = StageManager.getOrNull();
        if (m != null && ctx.player() instanceof ServerPlayer sp) m.playback().engine().onLiveAudio(sp.getUUID(), p);
    }

    /** Netzwerk-Thread: sofort antworten, damit die Laufzeitmessung genau bleibt. */
    public static void timeSync(Payloads.TimeSyncRequest p, IPayloadContext ctx) {
        ctx.reply(new Payloads.TimeSyncResponse(p.clientMicros(), ServerClock.nowMicros()));
    }
}
