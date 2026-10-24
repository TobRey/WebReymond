package de.blockrave.client;

import de.blockrave.BlockRave;
import de.blockrave.audio.PlaybackController;
import de.blockrave.client.placement.PlacementController;
import de.blockrave.client.screen.DjBoothScreen;
import de.blockrave.core.show.ShowInput;
import de.blockrave.core.storage.TrackLibrary;
import de.blockrave.core.storage.TrackStore;
import de.blockrave.menu.DjBoothMenu;
import de.blockrave.network.DjAction;
import de.blockrave.network.Payloads;
import de.blockrave.registry.ModItems;
import de.blockrave.stage.StageFootprint;
import de.blockrave.stage.StageManager;
import net.minecraft.client.Minecraft;
import net.minecraft.client.Screenshot;
import net.minecraft.client.gui.screens.TitleScreen;
import net.minecraft.core.BlockPos;
import net.minecraft.core.registries.Registries;
import net.minecraft.network.chat.Component;
import net.minecraft.server.level.ServerPlayer;
import net.minecraft.world.Difficulty;
import net.minecraft.world.InteractionHand;
import net.minecraft.world.item.ItemStack;
import net.minecraft.world.level.GameRules;
import net.minecraft.world.level.GameType;
import net.minecraft.world.level.LevelSettings;
import net.minecraft.world.level.WorldDataConfiguration;
import net.minecraft.world.level.levelgen.WorldOptions;
import net.minecraft.world.level.levelgen.presets.WorldPresets;
import net.neoforged.bus.api.SubscribeEvent;
import net.neoforged.neoforge.client.event.ClientTickEvent;
import net.neoforged.neoforge.client.event.ScreenEvent;

import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.StandardCopyOption;

/**
 * Automatischer Client-Rauchtest fuer CI (nur aktiv mit -Dblockrave.clientSmokeTest=true, Run
 * "clientSmokeTest"). Legt eine flache Welt an, baut die Buehne, spielt einen Testtitel, prueft
 * Wiedergabe/Lichtshow-Zustand, oeffnet DJ-Pult und Bauvorschau und speichert Screenshots.
 * Ergebnisse werden als "BlockRave-Smoke:"-Zeilen protokolliert; danach beendet sich der Client.
 */
public final class ClientSmokeTest {
    private static boolean worldRequested;
    private static int ticksInWorld;
    private static int failures;
    private static long watchdogStart = System.currentTimeMillis();

    private ClientSmokeTest() {}

    static {
        // Unabhaengig von Ticks: nach 5 Minuten hart beenden (CI darf nie haengen bleiben)
        Thread t = new Thread(() -> {
            try {
                Thread.sleep(300_000);
                BlockRave.LOG.error("BlockRave-Smoke: FAIL globale Zeitueberschreitung (Bildschirm: {})",
                        Minecraft.getInstance().screen == null ? "-" : Minecraft.getInstance().screen.getClass().getName());
                Runtime.getRuntime().halt(4);
            } catch (InterruptedException ignored) {
            }
        }, "BlockRave-Smoke-Watchdog");
        t.setDaemon(true);
        t.start();
    }

    private static void log(String s) {
        BlockRave.LOG.info("BlockRave-Smoke: {}", s);
    }

    private static void check(boolean ok, String what) {
        log((ok ? "OK   " : "FAIL ") + what);
        if (!ok) failures++;
    }

    @SubscribeEvent
    public static void onScreen(ScreenEvent.Init.Post e) {
        if (worldRequested || !(e.getScreen() instanceof TitleScreen
                || e.getScreen() instanceof net.minecraft.client.gui.screens.AccessibilityOnboardingScreen)) return;
        worldRequested = true;
        Minecraft mc = Minecraft.getInstance();
        log("Titelbildschirm erreicht, lege Testwelt an");
        mc.options.pauseOnLostFocus = false;
        GameRules rules = new GameRules();
        rules.getRule(GameRules.RULE_DAYLIGHT).set(false, null);
        rules.getRule(GameRules.RULE_DOMOBSPAWNING).set(false, null);
        LevelSettings settings = new LevelSettings("blockrave-smoke-" + System.currentTimeMillis(), GameType.CREATIVE, false,
                Difficulty.PEACEFUL, true, rules, WorldDataConfiguration.DEFAULT);
        mc.createWorldOpenFlows().createFreshLevel(settings.levelName(), settings, new WorldOptions(1234L, false, false),
                access -> access.registryOrThrow(Registries.WORLD_PRESET).getHolderOrThrow(WorldPresets.FLAT).value().createWorldDimensions(),
                e.getScreen());
    }

    @SubscribeEvent
    public static void onTick(ClientTickEvent.Post e) {
        Minecraft mc = Minecraft.getInstance();
        if (System.currentTimeMillis() - watchdogStart > 240_000) {
            log("FAIL Zeitueberschreitung – beende");
            Runtime.getRuntime().halt(3);
        }
        if (mc.player == null || mc.level == null || mc.getSingleplayerServer() == null) return;
        ticksInWorld++;
        var server = mc.getSingleplayerServer();
        switch (ticksInWorld) {
            case 60 -> server.execute(() -> {
                try {
                    ServerPlayer sp = server.getPlayerList().getPlayer(mc.player.getUUID());
                    server.overworld().setDayTime(18000);
                    BlockPos anchor = new BlockPos(sp.getBlockX(), sp.getBlockY() - 1, sp.getBlockZ() + 12);
                    sp.teleportTo(anchor.getX() + 0.5, anchor.getY() + 1, anchor.getZ() - 14.5);
                    sp.setYRot(0);
                    sp.setXRot(-12);
                    sp.setGameMode(GameType.SURVIVAL);
                    sp.setItemInHand(InteractionHand.MAIN_HAND, new ItemStack(ModItems.STAGE_KIT.get()));
                    StageManager m = StageManager.get(server);
                    StageFootprint fp = new StageFootprint(anchor, 0);
                    StageManager.Check c = m.check(sp, fp);
                    check(c.ok(), "Serverpruefung des Bauplatzes: " + c.error().getString());
                    var r = m.confirm(sp, fp, c.hash());
                    check(r.status() == Payloads.PlacementResult.PLACED, "Buehne aufgebaut");
                    sp.setGameMode(GameType.CREATIVE);
                    // Testtitel in die Bibliothek legen und abspielen
                    PlaybackController pb = m.playback();
                    String id = TrackStore.newId();
                    try (InputStream in = BlockRave.class.getResourceAsStream("/assets/blockrave/gametest/beat.mp3")) {
                        Files.copy(in, pb.library().trackFile(id), StandardCopyOption.REPLACE_EXISTING);
                    }
                    TrackLibrary.Track t = new TrackLibrary.Track();
                    t.id = id;
                    t.title = "Smoke-Test";
                    t.sizeBytes = Files.size(pb.library().trackFile(id));
                    t.durationSamples = 4 * 48_000;
                    pb.library().lib().addTrack(t);
                    for (int i = 0; i < 6; i++) pb.library().lib().enqueue(id);
                    // Bedienung nur direkt am Pult: kurz hinstellen, Start druecken, zurueck zum Zuschauerplatz
                    BlockPos booth = fp.boothPos();
                    sp.teleportTo(booth.getX() + 0.5, booth.getY(), booth.getZ() - 2.5);
                    sp.containerMenu = new DjBoothMenu(99, sp.getInventory(), booth);
                    pb.handleCommand(sp, new Payloads.DjCommand(DjAction.PLAY, "", 0));
                    check(pb.state() == Payloads.RaveStatus.PLAYING, "Wiedergabe gestartet");
                    sp.containerMenu = sp.inventoryMenu;
                    sp.teleportTo(anchor.getX() + 0.5, anchor.getY() + 1, anchor.getZ() - 14.5);
                } catch (Exception ex) {
                    check(false, "Aufbau/Start: " + ex);
                }
            });
            case 240 -> {
                ClientRaveState s = ClientRaveState.get();
                check(s != null && s.stage().present(), "Client kennt die Buehne");
                check(s != null && s.clock.isValid(), "Uhrenabgleich gueltig");
                check(s != null && s.status() != null && s.status().state() == Payloads.RaveStatus.PLAYING, "Client sieht Status SPIELT");
                if (s != null) {
                    ShowInput in = new ShowInput();
                    s.timeline.fill(s.serverNow(), in);
                    check(in.active, "Lichtshow-Zeitleiste hat Daten zur aktuellen Abspielzeit");
                    log("Audio: " + s.audioStatus().getString() + " | Fehler='" + s.player.error() + "' spielt=" + s.player.isPlaying()
                            + " Sync-Fehler " + s.player.syncErrorMicros() / 1000 + " ms");
                }
                shot("buehne_show");
            }
            case 300 -> {
                ClientRaveState s = ClientRaveState.get();
                if (s != null) {
                    log("Nach 3 s: Beat " + snapshotBeat(s) + ", Audio spielt=" + s.player.isPlaying());
                }
                shot("buehne_show2");
            }
            case 340 -> {
                // DJ-Pult-Oberflaeche (clientseitig mit Menue des Servers)
                mc.player.containerMenu = new DjBoothMenu(98, mc.player.getInventory(), StageManager.get(server).footprint().boothPos());
                mc.setScreen(new DjBoothScreen((DjBoothMenu) mc.player.containerMenu, mc.player.getInventory(), Component.translatable("container.blockrave.dj_booth")));
                check(mc.screen instanceof DjBoothScreen, "DJ-Pult-Oberflaeche geoeffnet");
            }
            case 380 -> {
                shot("dj_pult");
                mc.setScreen(null);
            }
            case 400 -> server.execute(() -> {
                StageManager m = StageManager.get(server);
                check(m.packUp(m.level()) == null, "Buehne eingepackt");
            });
            case 440 -> {
                // Bauvorschau: Bauset in die Hand, Vorschau starten
                mc.player.getInventory().setItem(mc.player.getInventory().selected, new ItemStack(ModItems.STAGE_KIT.get()));
                server.execute(() -> server.getPlayerList().getPlayer(mc.player.getUUID())
                        .setItemInHand(InteractionHand.MAIN_HAND, new ItemStack(ModItems.STAGE_KIT.get())));
            }
            case 460 -> {
                mc.player.setXRot(40); // auf den Boden vor der Spielfigur blicken
                PlacementController.INSTANCE.onUse();
                check(PlacementController.INSTANCE.isActive(), "Bauvorschau aktiv");
            }
            case 500 -> {
                check(PlacementController.INSTANCE.footprint() != null, "Vorschau hat Position");
                shot("vorschau");
                PlacementController.INSTANCE.cancel();
            }
            case 540 -> {
                log(failures == 0 ? "ERGEBNIS: alle Pruefungen bestanden" : "ERGEBNIS: " + failures + " Pruefung(en) fehlgeschlagen");
                Runtime.getRuntime().halt(failures == 0 ? 0 : 1);
            }
            default -> {}
        }
    }

    private static String snapshotBeat(ClientRaveState s) {
        ShowInput in = new ShowInput();
        s.timeline.fill(s.serverNow(), in);
        return String.format("%.2f (aktiv=%s, BPM=%.1f)", in.beatPos, in.active, in.frame.bpmTenths() / 10.0);
    }

    private static void shot(String name) {
        Minecraft mc = Minecraft.getInstance();
        Screenshot.grab(mc.gameDirectory, "blockrave_" + name + ".png", mc.getMainRenderTarget(), c -> log("Screenshot " + name + ": " + c.getString()));
    }
}
