package de.blockrave.client;

import de.blockrave.BlockRaveConfig;
import de.blockrave.client.audio.RavePlayer;
import de.blockrave.client.audio.ShowTimeline;
import de.blockrave.client.live.LiveSender;
import de.blockrave.client.upload.ClientUploader;
import de.blockrave.core.analysis.AnalysisFrame;
import de.blockrave.core.stage.StageLayout;
import de.blockrave.core.stage.StageTransform;
import de.blockrave.core.sync.ClockSync;
import de.blockrave.core.sync.TimedFrame;
import de.blockrave.network.Payloads;
import net.minecraft.client.Camera;
import net.minecraft.client.Minecraft;
import net.minecraft.network.chat.Component;
import net.minecraft.sounds.SoundSource;
import net.minecraft.world.phys.Vec3;
import org.joml.Vector3f;

/**
 * Clientzustand waehrend einer Verbindung: Uhrenabgleich, Zeitleiste der Show, Audio-Wiedergabe,
 * Uploads, Live-Senden und die vom Server synchronisierten Zustaende.
 */
public final class ClientRaveState {
    private static volatile ClientRaveState instance;

    public final ClockSync clock = new ClockSync();
    public final ShowTimeline timeline = new ShowTimeline();
    public final RavePlayer player = new RavePlayer(clock);
    public final ClientUploader uploads = new ClientUploader();
    public final LiveSender live = new LiveSender();

    private volatile Payloads.StageInfo stage = new Payloads.StageInfo(false, "", net.minecraft.core.BlockPos.ZERO, 0, 128, "");
    private volatile StageTransform transform;
    private volatile Payloads.RaveStatus status;
    private volatile Payloads.Library library;
    private int ticks;
    private int syncSent;

    private ClientRaveState() {}

    public static ClientRaveState get() {
        return instance;
    }

    public static void connect() {
        disconnect();
        ClientRaveState s = new ClientRaveState();
        s.player.start(Minecraft.getInstance().options.soundDevice().get());
        instance = s;
    }

    /** Disconnect: Aufnahme, Uploads, Audio und Puffer freigeben. */
    public static void disconnect() {
        ClientRaveState s = instance;
        instance = null;
        if (s == null) return;
        s.live.release();
        s.uploads.shutdown();
        s.player.shutdown();
        s.timeline.clear();
    }

    // ------------------------------------------------------------------ Zustand vom Server

    public Payloads.StageInfo stage() {
        return stage;
    }

    public StageTransform transform() {
        return transform;
    }

    public void setStage(Payloads.StageInfo info) {
        stage = info;
        transform = info.present() ? new StageTransform(info.anchor().getX(), info.anchor().getY(), info.anchor().getZ(), info.rotation()) : null;
        if (!info.present()) {
            timeline.clear();
            player.clearBuffer();
        }
    }

    /** Liegt die Buehne in der Dimension, in der sich der Spieler gerade befindet? */
    public boolean stageInCurrentDimension() {
        var level = Minecraft.getInstance().level;
        return stage.present() && level != null && level.dimension().location().toString().equals(stage.dimension());
    }

    public Payloads.RaveStatus status() {
        return status;
    }

    public void setStatus(Payloads.RaveStatus s) {
        status = s;
    }

    public Payloads.Library library() {
        return library;
    }

    public void setLibrary(Payloads.Library l) {
        library = l;
    }

    public long serverNow() {
        return clock.toServer(ClockSync.nowMicros());
    }

    /**
     * Zeitpunkt, fuer den die Lichtshow gezeichnet wird. Normalerweise die gehoerte Abspielzeit (= Serverzeit).
     * Der Live-Sender hoert seinen Mix aber direkt (rekordbox), nicht ueber Minecraft: Bei ihm laeuft die Show
     * deshalb um den Live-Puffer vorgezogen, abzueglich Uebertragungszeit, damit die Lichter zu dem passen,
     * was er gerade hoert.
     */
    public long showTimeMicros() {
        long now = serverNow();
        Payloads.RaveStatus s = status;
        if (s == null || !suppressOwnAudio()) return now;
        long lead = s.liveDelayMs() * 1000L - SENDER_SHOW_LAG_MICROS - clock.lastRttMicros();
        return now + Math.max(0, lead);
    }

    /** Aufnahme, Paketbildung (2 Frames), Analyse (2 Frames) und Reserve, bis Showdaten beim Sender zurueck sind. */
    private static final long SENDER_SHOW_LAG_MICROS = 150_000;

    /** Sende ich gerade selbst live (und will mich nicht selbst hoeren)? */
    private boolean suppressOwnAudio() {
        Payloads.RaveStatus s = status;
        var p = Minecraft.getInstance().player;
        return s != null && p != null && s.state() == Payloads.RaveStatus.LIVE
                && s.liveSender().equals(p.getGameProfile().getName()) && !BlockRaveConfig.MONITOR_OWN_LIVE.get();
    }

    // ------------------------------------------------------------------ Netzwerk-Thread

    /** Anzahl empfangener Audio-/Lichtpakete (Diagnose). */
    public final java.util.concurrent.atomic.AtomicLong packetsReceived = new java.util.concurrent.atomic.AtomicLong();

    public void onFrames(Payloads.AudioFrames f) {
        packetsReceived.incrementAndGet();
        boolean playAudio = f.audio() && !suppressOwnAudio();
        long nowServer = serverNow();
        for (int i = 0; i < f.analysis().length; i++) {
            long playout = f.firstPlayoutMicros() + (long) i * f.frameMicros();
            AnalysisFrame a = AnalysisFrame.decode(f.analysis()[i]);
            timeline.add(f.streamId(), playout, a);
            if (playAudio && f.opus()[i].length > 0 && playout > nowServer - 40_000) {
                player.offer(new TimedFrame(f.streamId(), f.firstSeq() + i, playout, f.opus()[i], a));
            }
        }
    }

    public void onCut(Payloads.StreamCut c) {
        timeline.cut(c.streamId(), c.cutMicros());
        player.cut(c.streamId(), c.cutMicros());
    }

    public void onTimeSync(Payloads.TimeSyncResponse r) {
        clock.addSample(r.clientMicros(), r.serverMicros(), ClockSync.nowMicros());
    }

    // ------------------------------------------------------------------ Client-Tick

    public void tick() {
        ticks++;
        // Uhrenabgleich: anfangs schnell, danach alle 2 Sekunden
        int interval = syncSent < 10 ? 5 : 40;
        if (ticks % interval == 0) {
            ClientNet.send(new Payloads.TimeSyncRequest(ClockSync.nowMicros()));
            syncSent++;
        }
        if (ticks % 20 == 0) uploads.pump();
    }

    /** Pro Bild: Hoererposition und Lautstaerke an die Wiedergabe uebergeben. */
    public void updateAudio(Camera camera) {
        Minecraft mc = Minecraft.getInstance();
        StageTransform t = transform;
        if (t == null || !stageInCurrentDimension() || status == null) {
            player.setGain(0f);
            return;
        }
        Vec3 pos = camera.getPosition();
        Vector3f look = camera.getLookVector();
        Vector3f up = camera.getUpVector();
        player.setListener(new float[]{(float) pos.x, (float) pos.y, (float) pos.z, look.x(), look.y(), look.z(), up.x(), up.y(), up.z()});
        var fx = StageLayout.get().fixtures();
        double[] l = t.toWorld(fx.speakerLeft()[0], fx.speakerLeft()[1], fx.speakerLeft()[2]);
        double[] r = t.toWorld(fx.speakerRight()[0], fx.speakerRight()[1], fx.speakerRight()[2]);
        player.setSpeakers(new float[]{(float) l[0], (float) l[1], (float) l[2], (float) r[0], (float) r[1], (float) r[2]});
        double[] c = t.toWorld(fx.center()[0], fx.center()[1], fx.center()[2]);
        double dist = pos.distanceTo(new Vec3(c[0], c[1], c[2]));
        float gain = RavePlayer.distanceGain(dist, stage.hearingRange());
        gain *= status.masterVolume() / 100f;
        gain *= BlockRaveConfig.LOCAL_VOLUME.get() / 100f;
        gain *= mc.options.getSoundSourceVolume(SoundSource.MASTER) * mc.options.getSoundSourceVolume(SoundSource.RECORDS);
        if (BlockRaveConfig.MUTED.get()) gain = 0f;
        player.setGain(gain);
    }

    public Component audioStatus() {
        String err = player.error();
        if (!err.isEmpty()) return Component.literal(err);
        if (!clock.isValid()) return Component.translatable("blockrave.gui.sync_pending");
        return Component.translatable("blockrave.gui.audio_ok", clock.lastRttMicros() / 1000, player.bufferedMicros() / 1000);
    }
}
