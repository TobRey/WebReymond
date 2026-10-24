package de.blockrave.client;

import de.blockrave.BlockRave;
import de.blockrave.client.placement.PlacementController;
import de.blockrave.client.screen.DjBoothScreen;
import de.blockrave.core.show.ShowInput;
import de.blockrave.network.DjAction;
import de.blockrave.network.Payloads;
import de.blockrave.registry.ModItems;
import de.blockrave.stage.StageFootprint;
import net.minecraft.client.Minecraft;
import net.minecraft.core.BlockPos;
import net.minecraft.core.Direction;
import net.minecraft.world.InteractionHand;
import net.minecraft.world.item.Items;
import net.minecraft.world.phys.BlockHitResult;
import net.minecraft.world.phys.Vec3;
import net.neoforged.bus.api.SubscribeEvent;
import net.neoforged.neoforge.client.event.ClientTickEvent;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.List;
import java.util.function.BooleanSupplier;

/**
 * Mehrspieler-Ende-zu-Ende-Test mit echten Clients gegen einen echten dedizierten Server (CI).
 * Aktiv nur mit {@code -Dblockrave.e2e=A|B|A2}. Rollen:
 * <ul>
 *   <li>A: Buehne per Vorschau/Bestaetigung bauen, DJ-Pult oeffnen, MP3 hochladen, abspielen, Sync messen</li>
 *   <li>B: tritt spaeter bei (Einstieg an laufender Position), Sync messen, Reichweite testen</li>
 *   <li>A2: nach Server-Neustart: Persistenz pruefen, mit Spitzhacke einpacken, erneut aufbauen</li>
 * </ul>
 * Ergebnisse als "BlockRave-E2E[...]"-Zeilen; der Client beendet sich danach selbst.
 */
public final class MultiplayerE2E {
    private static final String ROLE = System.getProperty("blockrave.e2e", "");
    private static final Path DIR = Path.of(System.getProperty("blockrave.e2e.dir", "."));

    private interface Step {
        /** @return true, wenn erledigt */
        boolean run();
    }

    private record Phase(String name, long timeoutMs, Step step) {}

    private static final List<Phase> PHASES = new ArrayList<>();
    private static int index;
    private static long phaseStart;
    private static long started = System.currentTimeMillis();
    private static int failures;
    private static long waitUntil;
    private static final List<Long> syncErrors = new ArrayList<>();

    private MultiplayerE2E() {}

    public static boolean enabled() {
        return !ROLE.isEmpty();
    }

    private static void log(String s) {
        BlockRave.LOG.info("BlockRave-E2E[{}]: {}", ROLE, s);
    }

    private static void ok(boolean cond, String what) {
        log((cond ? "OK   " : "FAIL ") + what);
        if (!cond) failures++;
    }

    private static Minecraft mc() {
        return Minecraft.getInstance();
    }

    private static ClientRaveState st() {
        return ClientRaveState.get();
    }

    private static void cmd(String c) {
        mc().player.connection.sendCommand(c);
    }

    private static boolean sleep(long ms) {
        long now = System.currentTimeMillis();
        if (waitUntil == 0) waitUntil = now + ms;
        if (now < waitUntil) return false;
        waitUntil = 0;
        return true;
    }

    private static boolean waitFor(BooleanSupplier c) {
        return c.getAsBoolean();
    }

    /** DJ-Pult der tatsaechlich gebauten Buehne (aus den Serverdaten). */
    private static BlockPos booth() {
        Payloads.StageInfo info = st().stage();
        return new StageFootprint(info.anchor(), info.rotation()).boothPos();
    }

    private static int findSlot(net.minecraft.world.item.Item item) {
        var inv = mc().player.getInventory();
        for (int i = 0; i < 9; i++) if (inv.getItem(i).is(item)) return i;
        return -1;
    }

    private static boolean playing() {
        Payloads.RaveStatus s = st() == null ? null : st().status();
        return s != null && s.state() == Payloads.RaveStatus.PLAYING;
    }

    private static double positionSec() {
        Payloads.RaveStatus s = st().status();
        return (st().serverNow() - s.trackStartMicros()) / 1e6;
    }

    private static void touch(String name) {
        try {
            Files.createDirectories(DIR);
            Files.writeString(DIR.resolve(name), String.valueOf(System.currentTimeMillis()));
        } catch (Exception e) {
            log("Markerdatei " + name + " nicht schreibbar: " + e);
        }
    }

    /** Sync-Messung: Abweichung gehoerte Position - Serverzeit (aus dem OpenAL-Abspielzaehler). */
    private static Step measureSync(int seconds) {
        return () -> {
            if (!sleep(1000)) return false;
            ClientRaveState s = st();
            long err = s.player.syncErrorMicros();
            if (s.player.isPlaying()) syncErrors.add(err);
            log(String.format("SYNC t=%d pos=%.2fs fehler=%+.1fms rate=%.4f spielt=%s puffer=%dms ping=%dms", s.serverNow() / 1000,
                    positionSec(), err / 1000.0, s.player.playbackRate(), s.player.isPlaying(), s.player.bufferedMicros() / 1000,
                    s.clock.lastRttMicros() / 1000));
            return syncErrors.size() >= seconds;
        };
    }

    private static void evaluateSync() {
        if (syncErrors.isEmpty()) {
            ok(false, "keine Sync-Messwerte (Audio spielte nicht)");
            return;
        }
        int within = 0;
        long max = 0;
        for (long e : syncErrors) {
            if (Math.abs(e) <= 40_000) within++;
            max = Math.max(max, Math.abs(e));
        }
        ok(within >= syncErrors.size() * 0.8, String.format("Synchron zur Serveruhr: %d/%d Messungen innerhalb +-40 ms (max %.1f ms)",
                within, syncErrors.size(), max / 1000.0));
        syncErrors.clear();
    }

    private static List<Phase> buildPlacement() {
        List<Phase> p = new ArrayList<>();
        p.add(new Phase("Bauset in die Hand", 15_000, () -> {
            int slot = findSlot(ModItems.STAGE_KIT.get());
            if (slot < 0) return false;
            mc().player.getInventory().selected = slot;
            return sleep(1000);
        }));
        p.add(new Phase("Vorschau starten", 10_000, () -> {
            if (!PlacementController.INSTANCE.isActive()) PlacementController.INSTANCE.onUse();
            return PlacementController.INSTANCE.isActive() && PlacementController.INSTANCE.footprint() != null && sleep(1500);
        }));
        p.add(new Phase("Bestaetigen + Serverpruefung", 15_000, () -> {
            if (!PlacementController.INSTANCE.isLocked()) PlacementController.INSTANCE.onUse();
            Payloads.PlacementResult r = PlacementController.INSTANCE.lastResult();
            if (r == null) return false;
            ok(r.status() == Payloads.PlacementResult.CHECK_OK, "Serverpruefung: Status " + r.status() + ", betroffene Bloecke "
                    + r.affectedCount() + ", Anker " + PlacementController.INSTANCE.anchor().toShortString());
            PlacementController.INSTANCE.confirm();
            return true;
        }));
        p.add(new Phase("Buehne steht", 15_000, () -> waitFor(() -> st().stage().present())));
        p.add(new Phase("Buehne pruefen", 10_000, () -> {
            // Die Buehnen-Info kann vor den Blockaenderungen ankommen: bis zu 4 s auf das Pult warten
            boolean booth = mc().level.getBlockState(booth()).is(de.blockrave.registry.ModBlocks.DJ_BOOTH.get());
            if (!booth && !sleep(4000)) return false;
            if (booth) waitUntil = 0;
            ok(booth, "DJ-Pult in der Welt sichtbar bei " + booth().toShortString());
            return true;
        }));
        return p;
    }

    private static Phase openBooth() {
        return new Phase("DJ-Pult oeffnen", 20_000, () -> {
            if (mc().screen instanceof DjBoothScreen) return true;
            if (sleep(1500)) {
                BlockPos b = booth();
                mc().gameMode.useItemOn(mc().player, InteractionHand.MAIN_HAND,
                        new BlockHitResult(Vec3.atCenterOf(b), Direction.NORTH, b, false));
            }
            return false;
        });
    }

    private static void setup() {
        PHASES.clear();
        Phase join = new Phase("Verbinden + Uhrenabgleich", 120_000,
                () -> mc().player != null && st() != null && st().clock.isValid() && st().clock.sampleCount() >= 5 && sleep(2000));
        PHASES.add(join);
        switch (ROLE) {
            case "A" -> {
                PHASES.add(new Phase("Vorbereitung per Befehl", 10_000, () -> {
                    cmd("gamemode creative");
                    cmd("time set 18000");
                    cmd("gamerule doDaylightCycle false");
                    cmd("tp @s 0.5 -60 0.5 0 12");
                    cmd("give @s blockrave:stage_kit");
                    return true;
                }));
                PHASES.add(new Phase("warten", 10_000, () -> sleep(3000)));
                PHASES.addAll(buildPlacement());
                PHASES.add(new Phase("zum DJ-Pult", 10_000, () -> {
                    BlockPos b = booth();
                    cmd(String.format("tp @s %.1f %d %.1f 0 30", b.getX() + 0.5, b.getY(), b.getZ() - 1.5));
                    return sleep(2500);
                }));
                PHASES.add(openBooth());
                PHASES.add(new Phase("MP3 hochladen", 120_000, new Step() {
                    long t0;

                    @Override
                    public boolean run() {
                        if (t0 == 0) {
                            t0 = System.currentTimeMillis();
                            st().uploads.upload(Path.of(System.getProperty("blockrave.e2e.mp3")));
                        }
                        Payloads.Library lib = st().library();
                        if (lib != null && !lib.tracks().isEmpty()) {
                            ok(true, "Upload ueber das Netzwerk fertig in " + (System.currentTimeMillis() - t0) + " ms: "
                                    + lib.tracks().get(0).title() + " (" + lib.tracks().get(0).sizeBytes() + " Bytes, "
                                    + lib.tracks().get(0).durationMicros() / 1_000_000 + " s)");
                            return true;
                        }
                        var tasks = st().uploads.tasks();
                        if (!tasks.isEmpty() && tasks.get(0).state == de.blockrave.client.upload.ClientUploader.State.FAILED) {
                            ok(false, "Upload fehlgeschlagen: " + tasks.get(0).message.getString());
                            return true;
                        }
                        return false;
                    }
                }));
                PHASES.add(new Phase("Abspielen", 15_000, new Step() {
                    boolean sent;

                    @Override
                    public boolean run() {
                        if (!sent) {
                            ClientNet.send(new Payloads.DjCommand(DjAction.PLAY, "", 0));
                            sent = true;
                        }
                        if (!playing()) return false;
                        ok(true, "Status SPIELT: " + st().status().title());
                        mc().player.closeContainer();
                        touch("playing");
                        return true;
                    }
                }));
                PHASES.add(new Phase("Audio laeuft lokal", 15_000, () -> st().player.isPlaying()));
                PHASES.add(new Phase("Sync messen", 60_000, measureSync(25)));
                PHASES.add(new Phase("Sync auswerten", 1_000, () -> {
                    evaluateSync();
                    ShowInput in = new ShowInput();
                    st().timeline.fill(st().serverNow(), in);
                    ok(in.active, String.format("Lichtshow aktiv (Beat %.2f, BPM %.1f, Energieklasse %d)", in.beatPos,
                            in.frame.bpmTenths() / 10.0, in.frame.energyClass()));
                    return true;
                }));
                PHASES.add(new Phase("auf B warten", 400_000, () -> Files.exists(DIR.resolve("b_done")) && sleep(1000)));
            }
            case "B" -> {
                PHASES.add(new Phase("Spaeteinstieg", 20_000, () -> {
                    if (!playing() || !st().player.isPlaying()) return false;
                    ok(true, String.format("Spaeteinstieg: hoert laufenden Titel '%s' an Position %.2f s", st().status().title(), positionSec()));
                    return true;
                }));
                PHASES.add(new Phase("Sync messen", 40_000, measureSync(15)));
                PHASES.add(new Phase("Sync auswerten", 1_000, () -> {
                    evaluateSync();
                    return true;
                }));
                PHASES.add(new Phase("Weit weg (nur Lichtshow)", 10_000, () -> {
                    cmd("tp @s 0.5 -60 -180");
                    return true;
                }));
                PHASES.add(new Phase("Ausserhalb Hoerweite", 20_000, () -> {
                    if (!sleep(6000)) return false;
                    ShowInput in = new ShowInput();
                    st().timeline.fill(st().serverNow(), in);
                    ok(!st().player.isPlaying(), "ca. 200 Bloecke entfernt: kein Audio mehr");
                    ok(in.active, "ca. 200 Bloecke entfernt: Lichtshow-Daten kommen weiter an");
                    return true;
                }));
                PHASES.add(new Phase("Zurueck in Hoerweite", 10_000, () -> {
                    cmd("tp @s 0.5 -60 0.5");
                    return true;
                }));
                PHASES.add(new Phase("Audio wieder da", 15_000, () -> {
                    if (!st().player.isPlaying()) return false;
                    ok(true, String.format("Zurueck in Reichweite: Audio laeuft wieder (Position %.2f s)", positionSec()));
                    return true;
                }));
                PHASES.add(new Phase("Ende B", 2_000, () -> {
                    touch("b_done");
                    return true;
                }));
            }
            case "A2" -> {
                PHASES.add(new Phase("Zustand nach Neustart", 15_000, () -> {
                    if (st().status() == null || !sleep(2000)) return false;
                    ok(st().stage().present(), "Buehne nach Server-Neustart vorhanden");
                    ok(st().status().state() == Payloads.RaveStatus.STOPPED, "Wiedergabe nach Neustart gestoppt");
                    BlockPos b = booth();
                    cmd(String.format("tp @s %.1f %d %.1f 0 30", b.getX() + 0.5, b.getY(), b.getZ() - 1.5));
                    return true;
                }));
                PHASES.add(new Phase("warten", 5_000, () -> sleep(2500)));
                PHASES.add(openBooth());
                PHASES.add(new Phase("Bibliothek nach Neustart", 15_000, () -> {
                    Payloads.Library lib = st().library();
                    if (lib == null) return false;
                    ok(!lib.tracks().isEmpty() && !lib.playlist().isEmpty(), "Hochgeladene MP3 und Playlist nach Neustart vorhanden ("
                            + lib.tracks().size() + " Titel)");
                    mc().player.closeContainer();
                    cmd("give @s minecraft:diamond_pickaxe");
                    return true;
                }));
                PHASES.add(new Phase("Spitzhacke in die Hand", 10_000, () -> {
                    int slot = findSlot(Items.DIAMOND_PICKAXE);
                    if (slot < 0) return false;
                    mc().player.getInventory().selected = slot;
                    return sleep(1500);
                }));
                PHASES.add(new Phase("Einpacken", 15_000, new Step() {
                    boolean hit;

                    @Override
                    public boolean run() {
                        if (!hit) {
                            mc().gameMode.startDestroyBlock(booth(), Direction.NORTH);
                            hit = true;
                        }
                        if (st().stage().present()) return false;
                        return sleep(1500);
                    }
                }));
                PHASES.add(new Phase("Bauset zurueck", 5_000, () -> {
                    int count = 0;
                    var inv = mc().player.getInventory();
                    for (int i = 0; i < inv.getContainerSize(); i++) if (inv.getItem(i).is(ModItems.STAGE_KIT.get())) count += inv.getItem(i).getCount();
                    ok(count == 1, "Bauset genau einmal zurueckerhalten (Anzahl " + count + ")");
                    ok(!mc().level.getBlockState(booth()).is(de.blockrave.registry.ModBlocks.DJ_BOOTH.get()), "DJ-Pult entfernt");
                    cmd("tp @s 0.5 -60 0.5 0 12");
                    return true;
                }));
                PHASES.add(new Phase("warten", 5_000, () -> sleep(2500)));
                PHASES.addAll(buildPlacement());
            }
            default -> {}
        }
        PHASES.add(new Phase("Ende", 1_000, () -> true));
    }

    @SubscribeEvent
    public static void onTick(ClientTickEvent.Post e) {
        if (PHASES.isEmpty()) {
            setup();
            started = System.currentTimeMillis();
            phaseStart = started;
            log("Start, Rolle " + ROLE + ", " + PHASES.size() + " Schritte");
        }
        if (index >= PHASES.size()) return;
        Phase p = PHASES.get(index);
        boolean done;
        try {
            done = (index == 0 || (mc().player != null && st() != null)) && p.step().run();
        } catch (Exception ex) {
            ok(false, "Schritt '" + p.name() + "' Ausnahme: " + ex);
            done = true;
        }
        long now = System.currentTimeMillis();
        if (!done && now - phaseStart > p.timeoutMs()) {
            ok(false, "Zeitueberschreitung in Schritt '" + p.name() + "'");
            done = true;
        }
        if (done) {
            index++;
            phaseStart = now;
            waitUntil = 0;
            if (index >= PHASES.size()) {
                log(failures == 0 ? "ERGEBNIS: alle Pruefungen bestanden" : "ERGEBNIS: " + failures + " Pruefung(en) fehlgeschlagen");
                Runtime.getRuntime().halt(failures == 0 ? 0 : 1);
            }
        }
    }

    static {
        // Absicherung: niemals laenger als 10 Minuten laufen
        Thread t = new Thread(() -> {
            try {
                Thread.sleep(600_000);
                BlockRave.LOG.error("BlockRave-E2E[{}]: FAIL globale Zeitueberschreitung in Schritt {} (Bildschirm: {})", ROLE,
                        index < PHASES.size() ? PHASES.get(index).name() : "-",
                        Minecraft.getInstance().screen == null ? "-" : Minecraft.getInstance().screen.getClass().getName());
                Runtime.getRuntime().halt(4);
            } catch (InterruptedException ignored) {
            }
        }, "BlockRave-E2E-Watchdog");
        t.setDaemon(true);
        if (!ROLE.isEmpty()) t.start();
    }
}
