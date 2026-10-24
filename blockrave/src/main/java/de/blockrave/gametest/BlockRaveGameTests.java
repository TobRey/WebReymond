package de.blockrave.gametest;

import de.blockrave.BlockRave;
import de.blockrave.audio.AudioEngine;
import de.blockrave.audio.ServerClock;
import de.blockrave.core.stage.StageLayout;
import de.blockrave.network.Payloads;
import de.blockrave.registry.ModBlocks;
import de.blockrave.registry.ModItems;
import de.blockrave.stage.StageFootprint;
import de.blockrave.stage.StageManager;
import io.netty.buffer.Unpooled;
import net.minecraft.core.BlockPos;
import net.minecraft.gametest.framework.GameTest;
import net.minecraft.gametest.framework.GameTestHelper;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.network.chat.Component;
import net.minecraft.server.level.ServerLevel;
import net.minecraft.server.level.ServerPlayer;
import net.minecraft.world.InteractionHand;
import net.minecraft.world.item.ItemStack;
import net.minecraft.world.item.Items;
import net.minecraft.world.level.GameType;
import net.minecraft.world.level.block.Blocks;
import net.neoforged.neoforge.gametest.GameTestHolder;
import net.neoforged.neoforge.gametest.PrefixGameTestTemplate;

import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.util.List;
import java.util.concurrent.atomic.AtomicLong;
import java.util.concurrent.atomic.AtomicReference;

/**
 * GameTests, die auf einem echten dedizierten GameTest-Server (ohne Grafik/Audio) laufen:
 * {@code ./gradlew runGameTestServer}.
 */
@GameTestHolder(BlockRave.MOD_ID)
@PrefixGameTestTemplate(false)
public final class BlockRaveGameTests {
    private BlockRaveGameTests() {}

    private static ServerPlayer playerWithKit(GameTestHelper h, BlockPos at) {
        ServerPlayer p = h.makeMockServerPlayerInLevel();
        p.setGameMode(GameType.SURVIVAL);
        p.teleportTo(at.getX() + 0.5, at.getY() + 1, at.getZ() - 3.5);
        p.setItemInHand(InteractionHand.MAIN_HAND, new ItemStack(ModItems.STAGE_KIT.get()));
        return p;
    }

    private static int countKits(ServerPlayer p) {
        int n = 0;
        for (int i = 0; i < p.getInventory().getContainerSize(); i++) {
            ItemStack s = p.getInventory().getItem(i);
            if (s.is(ModItems.STAGE_KIT.get())) n += s.getCount();
        }
        return n;
    }

    /**
     * Pruefen, Aufbauen (nur nach passender Bestaetigung), zweite Buehne ablehnen, Einpacken ohne
     * fremde Bloecke zu loeschen, Bauset genau einmal zurueck, erneut aufbauen.
     */
    @GameTest(template = "stage_area", timeoutTicks = 200, batch = "buehne")
    public static void stageLifecycle(GameTestHelper h) {
        ServerLevel level = h.getLevel();
        StageManager m = StageManager.get(level.getServer());
        if (m.hasStage()) m.packUp(m.level());

        BlockPos anchor = h.absolutePos(new BlockPos(20, 1, 1));
        StageFootprint fp = new StageFootprint(anchor, 0);
        // Gelaende im Bereich und ein Block direkt ausserhalb
        BlockPos inside = fp.worldPos(10, 5, 4);
        level.setBlockAndUpdate(inside, Blocks.STONE.defaultBlockState());
        BlockPos outside = fp.worldPos(StageLayout.ANCHOR_X, 0, -1);
        level.setBlockAndUpdate(outside, Blocks.GOLD_BLOCK.defaultBlockState());

        ServerPlayer player = playerWithKit(h, anchor);
        StageManager.Check check = m.check(player, fp);
        h.assertTrue(check.ok(), "Pruefung sollte erfolgreich sein: " + check.error().getString());
        h.assertTrue(check.affected().contains(inside), "Stein im Innenraum muss als betroffen markiert sein");
        h.assertFalse(check.affected().contains(outside), "Block ausserhalb darf nicht markiert sein");

        // Veraenderung nach der Bestaetigung -> neue Bestaetigung verlangt, nichts gebaut
        long confirmedHash = check.hash();
        level.setBlockAndUpdate(fp.worldPos(12, 3, 5), Blocks.DIRT.defaultBlockState());
        StageManager.ConfirmResult changed = m.confirm(player, fp, confirmedHash);
        h.assertTrue(changed.status() == Payloads.PlacementResult.CHANGED, "Geaenderter Bereich muss erkannt werden");
        h.assertFalse(m.hasStage(), "Ohne aktuelle Bestaetigung darf nicht gebaut werden");
        h.assertTrue(countKits(player) == 1, "Bauset darf nicht verbraucht sein");

        // Aufbau mit aktueller Bestaetigung
        StageManager.ConfirmResult placed = m.confirm(player, fp, changed.check().hash());
        h.assertTrue(placed.status() == Payloads.PlacementResult.PLACED, "Aufbau fehlgeschlagen: " + placed.message().getString());
        h.assertTrue(m.hasStage(), "Buehne muss registriert sein");
        h.assertTrue(countKits(player) == 0, "Bauset muss genau einmal verbraucht sein");
        h.assertTrue(level.getBlockState(fp.boothPos()).is(ModBlocks.DJ_BOOTH.get()), "DJ-Pult fehlt");
        h.assertTrue(level.getBlockState(inside).isAir() || !level.getBlockState(inside).is(Blocks.STONE), "Innenraum nicht geleert");
        h.assertTrue(level.getBlockState(outside).is(Blocks.GOLD_BLOCK), "Block ausserhalb wurde veraendert");
        h.assertTrue(m.state().positions.length == StageLayout.get().parts().size(), "Bauteile nicht vollstaendig erfasst");

        // Zweite Buehne wird abgelehnt
        player.setItemInHand(InteractionHand.MAIN_HAND, new ItemStack(ModItems.STAGE_KIT.get()));
        StageFootprint other = new StageFootprint(anchor.offset(0, 0, 30), 0);
        StageManager.Check second = m.check(player, other);
        h.assertFalse(second.ok(), "Zweite Buehne muss abgelehnt werden");
        player.setItemInHand(InteractionHand.MAIN_HAND, ItemStack.EMPTY);

        // Spieler bauen spaeter fremde Bloecke: in den Innenraum und anstelle eines Bauteils
        BlockPos foreignInterior = fp.worldPos(12, 4, 11);
        level.setBlockAndUpdate(foreignInterior, Blocks.OAK_PLANKS.defaultBlockState());
        BlockPos replacedPart = fp.worldPos(0, 1, StageLayout.DECK_Z0); // Lautsprecher
        level.setBlockAndUpdate(replacedPart, Blocks.GLASS.defaultBlockState());

        Component err = m.packUp(level);
        h.assertTrue(err == null, "Einpacken fehlgeschlagen: " + (err == null ? "" : err.getString()));
        h.assertFalse(m.hasStage(), "Buehne muss entfernt sein");
        h.assertTrue(level.getBlockState(fp.boothPos()).isAir(), "DJ-Pult muss entfernt sein");
        h.assertTrue(level.getBlockState(foreignInterior).is(Blocks.OAK_PLANKS), "Fremder Block im Innenraum wurde geloescht");
        h.assertTrue(level.getBlockState(replacedPart).is(Blocks.GLASS), "Fremder Block an Bauteilposition wurde geloescht");
        h.assertTrue(level.getBlockState(outside).is(Blocks.GOLD_BLOCK), "Block ausserhalb wurde veraendert");
        for (StageFootprint.PlacedPart part : fp.parts()) {
            if (part.pos().equals(replacedPart)) continue;
            h.assertTrue(level.getBlockState(part.pos()).isAir(), "Bauteil nicht entfernt bei " + part.pos().toShortString());
        }

        // Erneut aufbauen (gedreht) funktioniert
        player.setItemInHand(InteractionHand.MAIN_HAND, new ItemStack(ModItems.STAGE_KIT.get()));
        level.setBlockAndUpdate(foreignInterior, Blocks.AIR.defaultBlockState());
        level.setBlockAndUpdate(replacedPart, Blocks.AIR.defaultBlockState());
        StageFootprint fp2 = new StageFootprint(anchor.offset(0, 0, 20), 2);
        StageManager.Check c2 = m.check(player, fp2);
        h.assertTrue(c2.ok(), "Erneute Pruefung fehlgeschlagen: " + c2.error().getString());
        StageManager.ConfirmResult again = m.confirm(player, fp2, c2.hash());
        h.assertTrue(again.status() == Payloads.PlacementResult.PLACED, "Erneuter Aufbau fehlgeschlagen");
        h.assertTrue(m.packUp(level) == null, "Abschliessendes Einpacken fehlgeschlagen");
        h.succeed();
    }

    /** Ohne Bauset in der Hand wird nichts geprueft/gebaut. */
    @GameTest(template = "stage_area", batch = "buehne")
    public static void noKitNoStage(GameTestHelper h) {
        StageManager m = StageManager.get(h.getLevel().getServer());
        BlockPos anchor = h.absolutePos(new BlockPos(20, 1, 1));
        ServerPlayer p = h.makeMockServerPlayerInLevel();
        p.setItemInHand(InteractionHand.MAIN_HAND, new ItemStack(Items.STICK));
        h.assertFalse(m.check(p, new StageFootprint(anchor, 1)).ok(), "Ohne Bauset darf nichts gebaut werden");
        h.succeed();
    }

    /**
     * Audio-Engine auf dem dedizierten Server: MP3 decodieren, analysieren, Opus-codieren, in Echtzeit
     * takten und das Titelende melden.
     */
    // Der GameTest-Server tickt ungebremst: Echtzeit-Tests brauchen viele Ticks als Zeitlimit.
    @GameTest(template = "stage_area", timeoutTicks = 20_000)
    public static void audioEngineDecodesAndPaces(GameTestHelper h) {
        Path file;
        try (InputStream in = BlockRave.class.getResourceAsStream("/assets/blockrave/gametest/beat.mp3")) {
            if (in == null) throw new IllegalStateException("Testdatei fehlt");
            file = Files.createTempFile("blockrave-test", ".mp3");
            Files.copy(in, file, StandardCopyOption.REPLACE_EXISTING);
        } catch (Exception e) {
            h.fail("Testdatei: " + e);
            return;
        }
        AtomicLong endMicros = new AtomicLong(-1);
        AtomicReference<String> failure = new AtomicReference<>();
        AudioEngine engine = new AudioEngine(new AudioEngine.Callbacks() {
            @Override
            public void onTrackEnded(int streamId, String trackId, long end) {
                endMicros.set(end);
            }

            @Override
            public void onTrackFailed(String trackId, String message) {
                failure.set(message);
            }
        });
        long start = ServerClock.nowMicros() + 200_000;
        try {
            engine.startTrack("gametest01", file, start);
        } catch (Exception e) {
            engine.shutdown();
            h.fail("Start fehlgeschlagen: " + e);
            return;
        }
        h.succeedWhen(() -> {
            if (failure.get() != null) {
                engine.shutdown();
                throw new IllegalStateException("Engine-Fehler: " + failure.get());
            }
            long end = endMicros.get();
            h.assertTrue(end > 0, "Titelende noch nicht erreicht");
            long frames = engine.framesProduced();
            double seconds = (end - start) / 1e6;
            engine.shutdown();
            // 4 s Datei (+40 ms Analyseverzoegerung, +Decoder-Vorlauf)
            h.assertTrue(Math.abs(seconds - 4.0) < 0.3, "Unerwartete Titeldauer " + seconds + " s");
            h.assertTrue(Math.abs(frames - seconds * 50) <= 2, "Frameanzahl passt nicht: " + frames);
            // Taktung: Ende wird hoechstens ~1 s (Vorlauf) vor Ablauf gemeldet, nicht sofort
            h.assertTrue(ServerClock.nowMicros() > start + 2_000_000, "Engine hat nicht in Echtzeit getaktet");
        });
    }

    /** Netzwerkpakete: Kodierung und Groessenbegrenzung. */
    @GameTest(template = "stage_area")
    public static void payloadCodecs(GameTestHelper h) {
        RegistryFriendlyByteBuf buf = new RegistryFriendlyByteBuf(Unpooled.buffer(), h.getLevel().registryAccess());
        var frames = new Payloads.AudioFrames(7, 3, 123456789L, 20_000, true, new byte[][]{{1, 2, 3}, {4}},
                new byte[][]{new byte[16], new byte[16]});
        Payloads.AudioFrames.CODEC.encode(buf, frames);
        var back = Payloads.AudioFrames.CODEC.decode(buf);
        h.assertTrue(back.streamId() == 7 && back.firstSeq() == 3 && back.firstPlayoutMicros() == 123456789L
                && back.opus().length == 2 && back.opus()[0][2] == 3, "AudioFrames-Rundreise");
        RegistryFriendlyByteBuf big = new RegistryFriendlyByteBuf(Unpooled.buffer(), h.getLevel().registryAccess());
        big.writeUtf("abc", 40);
        big.writeVarLong(0);
        big.writeByteArray(new byte[Payloads.MAX_CHUNK + 1]);
        boolean rejected = false;
        try {
            Payloads.UploadChunk.CODEC.decode(big);
        } catch (RuntimeException e) {
            rejected = true;
        }
        h.assertTrue(rejected, "Zu grosser Upload-Block muss abgelehnt werden");
        RegistryFriendlyByteBuf st = new RegistryFriendlyByteBuf(Unpooled.buffer(), h.getLevel().registryAccess());
        var status = new Payloads.RaveStatus(0, 1, "abc", "Titel", 5_000_000, 42, 0, 80, "", 1500, "", 3,
                Component.translatable("blockrave.status.paused"));
        Payloads.RaveStatus.CODEC.encode(st, status);
        var s2 = Payloads.RaveStatus.CODEC.decode(st);
        h.assertTrue(s2.title().equals("Titel") && s2.masterVolume() == 80 && s2.revision() == 3, "RaveStatus-Rundreise");
        var lib = new Payloads.Library(1, List.of(new Payloads.TrackInfo("aaaaaaaa", "A", 1, "x", 2)), List.of("aaaaaaaa"),
                List.of(), 2, 3);
        RegistryFriendlyByteBuf lb = new RegistryFriendlyByteBuf(Unpooled.buffer(), h.getLevel().registryAccess());
        Payloads.Library.CODEC.encode(lb, lib);
        h.assertTrue(Payloads.Library.CODEC.decode(lb).tracks().get(0).title().equals("A"), "Library-Rundreise");
        h.succeed();
    }

    // ------------------------------------------------------------------ Mehrspieler-Ablauf

    private static ServerPlayer playerAt(GameTestHelper h, BlockPos booth, double dx, double dz) {
        ServerPlayer p = h.makeMockServerPlayerInLevel();
        p.setGameMode(GameType.SURVIVAL);
        p.teleportTo(booth.getX() + 0.5 + dx, booth.getY() + 1, booth.getZ() + 0.5 + dz);
        p.containerMenu = new de.blockrave.menu.DjBoothMenu(1, p.getInventory(), booth);
        return p;
    }

    /**
     * Serverseitiger Mehrspieler-Ablauf mit simulierten Spielern: MP3-Upload in Bloecken (inkl.
     * Pruefung im Hintergrund), Wiedergabestart durch einen anderen Spieler, Zuhoerer nach Reichweite,
     * Live-Rechte (nur der freigegebene Sender), Live-Disconnect und "Neustart" (Zustand neu laden).
     */
    @GameTest(template = "stage_area", timeoutTicks = 20_000, batch = "mehrspieler")
    public static void multiplayerFlow(GameTestHelper h) {
        ServerLevel level = h.getLevel();
        StageManager m = StageManager.get(level.getServer());
        if (m.hasStage()) m.packUp(m.level());
        BlockPos anchor = h.absolutePos(new BlockPos(20, 1, 1));
        StageFootprint fp = new StageFootprint(anchor, 0);
        ServerPlayer builder = playerWithKit(h, anchor);
        StageManager.Check c = m.check(builder, fp);
        h.assertTrue(c.ok(), "Pruefung: " + c.error().getString());
        h.assertTrue(m.confirm(builder, fp, c.hash()).status() == Payloads.PlacementResult.PLACED, "Aufbau");
        BlockPos booth = fp.boothPos();

        ServerPlayer a = playerAt(h, booth, 0, -3);     // DJ / Uploader
        ServerPlayer b = playerAt(h, booth, 5, -20);    // Zuhoerer
        ServerPlayer far = playerAt(h, booth, 0, -200); // nur Lichtshow
        ServerPlayer gone = playerAt(h, booth, 0, -400); // gar nichts
        var pb = m.playback();
        int tracksBefore = pb.library().lib().tracks().size();

        byte[] mp3;
        try (InputStream in = BlockRave.class.getResourceAsStream("/assets/blockrave/gametest/beat.mp3")) {
            mp3 = in.readAllBytes();
        } catch (Exception e) {
            h.fail("Testdatei: " + e);
            return;
        }
        pb.uploads().begin(a, new Payloads.UploadBegin(1, "Mein Track.mp3", mp3.length));
        // Upload-ID aus der Upload-Verwaltung holen (Client wuerde sie per Paket bekommen)
        String uploadId = pb.uploads().activeUploadIds(a.getUUID()).stream().findFirst().orElse(null);
        h.assertTrue(uploadId != null, "Upload wurde nicht angenommen");
        for (int off = 0; off < mp3.length; off += Payloads.MAX_CHUNK) {
            int n = Math.min(Payloads.MAX_CHUNK, mp3.length - off);
            byte[] chunk = java.util.Arrays.copyOfRange(mp3, off, off + n);
            pb.uploads().chunk(a, new Payloads.UploadChunk(uploadId, off, chunk));
        }
        AtomicReference<String> phase = new AtomicReference<>("upload");
        AtomicLong t = new AtomicLong();
        h.succeedWhen(() -> {
            switch (phase.get()) {
                case "upload" -> {
                    h.assertTrue(pb.library().lib().tracks().size() == tracksBefore + 1, "Upload noch nicht in der Bibliothek");
                    var track = pb.library().lib().tracks().get(pb.library().lib().tracks().size() - 1);
                    h.assertTrue(track.title.equals("Mein Track"), "Titel aus Dateiname erwartet, war: " + track.title);
                    h.assertTrue(pb.library().store().exists(track.id), "Datei fehlt im Speicher");
                    // B startet die Wiedergabe (keine OP-Rechte noetig)
                    pb.handleCommand(b, new Payloads.DjCommand(de.blockrave.network.DjAction.PLAY_TRACK, track.id, 0));
                    h.assertTrue(pb.state() == Payloads.RaveStatus.PLAYING, "Wiedergabe startet nicht");
                    t.set(level.getGameTime());
                    phase.set("listeners");
                    throw new net.minecraft.gametest.framework.GameTestAssertException("naechste Phase");
                }
                case "listeners" -> {
                    h.assertTrue(level.getGameTime() - t.get() > 25, "warte auf Zuhoerer-Aktualisierung");
                    h.assertTrue(pb.audioListenerIds().contains(a.getUUID()) && pb.audioListenerIds().contains(b.getUUID()),
                            "Spieler in Reichweite muessen Audio erhalten");
                    h.assertTrue(pb.showListenerIds().contains(far.getUUID()) && !pb.audioListenerIds().contains(far.getUUID()),
                            "Spieler in 200 Bloecken: nur Lichtshow");
                    h.assertFalse(pb.audioListenerIds().contains(gone.getUUID()) || pb.showListenerIds().contains(gone.getUUID()),
                            "Spieler in 400 Bloecken: gar keine Daten");
                    h.assertTrue(pb.engine().framesProduced() > 20, "Audio-Engine erzeugt keine Frames");
                    // Live: A sendet, B darf nicht zusaetzlich senden
                    pb.liveStart(a, new Payloads.LiveStart("Testquelle"));
                    h.assertTrue(pb.state() == Payloads.RaveStatus.LIVE, "Live-Start fehlgeschlagen");
                    int session = pb.liveSessionId();
                    pb.liveStart(b, new Payloads.LiveStart("Fremd"));
                    h.assertTrue(pb.isLiveSender(a.getUUID()) && !pb.isLiveSender(b.getUUID()), "Nur eine Live-Quelle erlaubt");
                    var frame = new byte[][]{new byte[]{1, 2, 3}, new byte[]{4, 5}};
                    var an = new byte[][]{AnalysisFrameBytes.SILENT, AnalysisFrameBytes.SILENT};
                    h.assertFalse(pb.engine().onLiveAudio(b.getUUID(), new Payloads.LiveAudio(session, 0, frame, an)),
                            "Live-Audio eines anderen Spielers muss abgelehnt werden");
                    h.assertTrue(pb.engine().onLiveAudio(a.getUUID(), new Payloads.LiveAudio(session, 0, frame, an)),
                            "Live-Audio des Senders muss angenommen werden");
                    // Disconnect des Senders beendet die Uebertragung
                    pb.onLogout(a);
                    h.assertTrue(pb.liveSessionId() == 0, "Live-Sitzung nach Disconnect nicht beendet");
                    h.assertTrue(pb.state() == Payloads.RaveStatus.PAUSED || pb.state() == Payloads.RaveStatus.STOPPED,
                            "Zustand nach Live-Ende");
                    // "Neustart": Verwaltung beenden und aus den gespeicherten Dateien neu laden
                    StageManager.stop();
                    StageManager.start(level.getServer());
                    StageManager m2 = StageManager.get(level.getServer());
                    h.assertTrue(m2.hasStage() && m2.isStageBooth(level, booth), "Buehne nach Neustart nicht geladen");
                    h.assertTrue(m2.playback().library().lib().tracks().size() == tracksBefore + 1, "Bibliothek nach Neustart");
                    h.assertTrue(m2.playback().state() == Payloads.RaveStatus.STOPPED, "Nach Neustart muss die Wiedergabe gestoppt sein");
                    h.assertTrue(m2.playback().liveSessionId() == 0, "Live darf nach Neustart nicht laufen");
                    // Aufraeumen: Titel loeschen, Buehne einpacken
                    String id = m2.playback().library().lib().tracks().get(m2.playback().library().lib().tracks().size() - 1).id;
                    b.containerMenu = new de.blockrave.menu.DjBoothMenu(2, b.getInventory(), booth);
                    m2.playback().handleCommand(b, new Payloads.DjCommand(de.blockrave.network.DjAction.DELETE_TRACK, id, 0));
                    h.assertTrue(m2.packUp(level) == null, "Einpacken");
                    phase.set("done");
                }
                default -> {}
            }
        });
    }

    /** Ein leerer, als "still" markierter Analyseframe. */
    private static final class AnalysisFrameBytes {
        static final byte[] SILENT = de.blockrave.core.analysis.AnalysisFrame.SILENCE.encode();
    }
}
