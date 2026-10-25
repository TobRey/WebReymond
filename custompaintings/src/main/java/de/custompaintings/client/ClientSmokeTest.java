package de.custompaintings.client;

import de.custompaintings.Config;
import de.custompaintings.core.ImageCodec;
import de.custompaintings.core.ImageFit;
import de.custompaintings.server.PaintingImages;
import net.minecraft.client.Minecraft;
import net.minecraft.client.gui.screens.AccessibilityOnboardingScreen;
import net.minecraft.client.gui.screens.TitleScreen;
import net.minecraft.core.BlockPos;
import net.minecraft.core.Direction;
import net.minecraft.core.registries.Registries;
import net.minecraft.server.level.ServerLevel;
import net.minecraft.server.level.ServerPlayer;
import net.minecraft.world.Difficulty;
import net.minecraft.world.entity.Entity;
import net.minecraft.world.entity.decoration.Painting;
import net.minecraft.world.entity.decoration.PaintingVariant;
import net.minecraft.world.entity.decoration.PaintingVariants;
import net.minecraft.world.level.GameRules;
import net.minecraft.world.level.GameType;
import net.minecraft.world.level.LevelSettings;
import net.minecraft.world.level.WorldDataConfiguration;
import net.minecraft.world.level.block.Blocks;
import net.minecraft.world.level.levelgen.WorldOptions;
import net.minecraft.world.level.levelgen.presets.WorldPresets;
import net.neoforged.bus.api.SubscribeEvent;
import net.neoforged.neoforge.client.event.ClientTickEvent;
import net.neoforged.neoforge.client.event.ScreenEvent;

import java.util.UUID;
import java.util.concurrent.atomic.AtomicReference;

/**
 * Automatischer Client-Rauchtest (nur mit -Dcustompaintings.clientSmokeTest=true): Einzelspielerwelt, Wand mit
 * Gemaelde, eigenes Bild hochladen, per Bildschirmpixel pruefen, dass es sichtbar und richtig herum ist,
 * Oberflaeche oeffnen, Originalmotiv wiederherstellen, bekanntes Bild ohne erneute Uebertragung setzen.
 */
public final class ClientSmokeTest {
    private static final TestSupport.Runner R = new TestSupport.Runner("CustomPaintings-Smoke");
    private static boolean worldRequested;
    private static int ticksInWorld;
    private static final AtomicReference<UUID> paintingUuid = new AtomicReference<>();
    private static volatile boolean built;
    private static Painting painting;
    private static byte[] png;
    private static String hash;
    private static int[][] vanillaColors;

    private ClientSmokeTest() {}

    static {
        Thread t = new Thread(() -> {
            try {
                Thread.sleep(300_000);
                R.log("FAIL globale Zeitueberschreitung in Schritt " + R.currentPhase());
                Runtime.getRuntime().halt(4);
            } catch (InterruptedException ignored) {
            }
        }, "CustomPaintings-Smoke-Watchdog");
        t.setDaemon(true);
        t.start();
        setup();
    }

    @SubscribeEvent
    public static void onScreen(ScreenEvent.Init.Post e) {
        if (worldRequested || !(e.getScreen() instanceof TitleScreen || e.getScreen() instanceof AccessibilityOnboardingScreen)) return;
        worldRequested = true;
        Minecraft mc = Minecraft.getInstance();
        R.log("Titelbildschirm erreicht, lege Testwelt an");
        GameRules rules = new GameRules();
        rules.getRule(GameRules.RULE_DAYLIGHT).set(false, null);
        rules.getRule(GameRules.RULE_DOMOBSPAWNING).set(false, null);
        LevelSettings settings = new LevelSettings("custompaintings-smoke-" + System.currentTimeMillis(), GameType.CREATIVE, false,
                Difficulty.PEACEFUL, true, rules, WorldDataConfiguration.DEFAULT);
        mc.createWorldOpenFlows().createFreshLevel(settings.levelName(), settings, new WorldOptions(1234L, false, false),
                access -> access.registryOrThrow(Registries.WORLD_PRESET).getHolderOrThrow(WorldPresets.FLAT).value().createWorldDimensions(),
                e.getScreen());
    }

    @SubscribeEvent
    public static void onTick(ClientTickEvent.Post e) {
        Minecraft mc = Minecraft.getInstance();
        if (mc.player != null && mc.level != null && mc.getSingleplayerServer() != null) ticksInWorld++;
        R.tick(() -> mc.player != null && mc.level != null && mc.getSingleplayerServer() != null);
    }

    private static Minecraft mc() {
        return Minecraft.getInstance();
    }

    private static void setup() {
        R.add("Welt geladen", 120_000, () -> ticksInWorld > 60);
        R.add("Wand und Gemaelde", 15_000, () -> {
            var server = mc().getSingleplayerServer();
            if (paintingUuid.get() == null && !built) {
                built = true;
                server.execute(() -> {
                    ServerLevel level = server.overworld();
                    level.setDayTime(6000);
                    ServerPlayer sp = server.getPlayerList().getPlayer(mc().player.getUUID());
                    BlockPos base = sp.blockPosition();
                    for (int x = -5; x <= 5; x++) for (int y = 0; y <= 6; y++) level.setBlockAndUpdate(base.offset(x, y, 4), Blocks.STONE.defaultBlockState());
                    var holder = level.registryAccess().registryOrThrow(Registries.PAINTING_VARIANT).getHolderOrThrow(PaintingVariants.FIGHTERS);
                    Painting p = new Painting(level, base.offset(0, 2, 3), Direction.NORTH, holder);
                    level.addFreshEntity(p);
                    sp.teleportTo(p.getX(), base.getY(), p.getZ() - 3.5);
                    paintingUuid.set(p.getUUID());
                });
            }
            painting = TestSupport.nearestPainting(12);
            if (painting == null || paintingUuid.get() == null) return false;
            PaintingVariant v = painting.getVariant().value();
            R.ok(v.width() == 4 && v.height() == 2, "Gemaelde 4x2 haengt an der Wand (" + painting.getVariant().getRegisteredName() + ")");
            return true;
        });
        R.add("Ausrichten", 10_000, () -> {
            mc().options.hideGui = true;
            mc().player.lookAt(net.minecraft.commands.arguments.EntityAnchorArgument.Anchor.EYES, painting.position());
            return R.sleep(2500);
        });
        R.add("Originalmotiv", 5_000, () -> {
            vanillaColors = TestSupport.samplePaintingColors(painting);
            R.log("Vanilla-Motiv: links " + TestSupport.fmt(vanillaColors[0]) + " rechts " + TestSupport.fmt(vanillaColors[1]));
            TestSupport.screenshot("vanilla", R);
            return true;
        });
        R.add("Bild hochladen", 30_000, new TestSupport.Step() {
            boolean sent;

            @Override
            public boolean run() {
                if (!sent) {
                    int[] t = ImageFit.targetSize(4, 2, Config.PIXELS_PER_BLOCK.get(), ImageFit.MAX_SIDE);
                    png = ImageCodec.encodePng(ImageFit.fit(TestSupport.leftRedRightBlue(400, 200), t[0], t[1], ImageFit.Mode.FILL, 0));
                    hash = ImageCodec.sha256(png);
                    ClientUploader.INSTANCE.upload(painting.getId(), png);
                    sent = true;
                }
                if (ClientUploader.INSTANCE.state() == ClientUploader.State.FAILED) {
                    R.ok(false, "Upload: " + ClientUploader.INSTANCE.message().getString());
                    return true;
                }
                if (ClientUploader.INSTANCE.state() != ClientUploader.State.DONE || ClientImages.INSTANCE.textureFor(painting.getId()) == null) return false;
                R.ok(ClientUploader.INSTANCE.dataSent(), "Upload uebertragen (" + png.length / 1024 + " KB) und Textur bereit");
                return true;
            }
        });
        R.add("Server hat Zuordnung", 5_000, new TestSupport.Step() {
            volatile String serverHash;
            boolean asked;

            @Override
            public boolean run() {
                if (!asked) {
                    asked = true;
                    var server = mc().getSingleplayerServer();
                    server.execute(() -> {
                        Entity e = server.overworld().getEntity(paintingUuid.get());
                        serverHash = PaintingImages.imageOf(e);
                    });
                }
                if (serverHash == null) return false;
                R.ok(hash.equals(serverHash), "Server speichert Bild-ID am Gemaelde");
                R.ok(PaintingImages.get().store().has(hash), "Bild im Weltordner gespeichert");
                return true;
            }
        });
        R.add("Bild sichtbar", 10_000, () -> {
            if (!R.sleep(1500)) return false;
            int[][] c = TestSupport.samplePaintingColors(painting);
            R.ok(TestSupport.reddish(c[0]) && TestSupport.bluish(c[1]),
                    "Eigenes Bild sichtbar und richtig herum: links " + TestSupport.fmt(c[0]) + " (rot erwartet), rechts "
                            + TestSupport.fmt(c[1]) + " (blau erwartet)");
            TestSupport.screenshot("eigenes_bild", R);
            return true;
        });
        R.add("Oberflaeche", 15_000, new TestSupport.Step() {
            PaintingScreen screen;

            @Override
            public boolean run() {
                if (screen == null) {
                    mc().options.hideGui = false;
                    screen = new PaintingScreen(painting);
                    mc().setScreen(screen);
                    screen.useImage(TestSupport.leftRedRightBlue(300, 300), "test.png");
                    return false;
                }
                if (!screen.hasPreview() || !R.sleep(800)) return false;
                R.ok(mc().screen == screen, "Gestaltungs-Oberflaeche geoeffnet, Vorschau erzeugt");
                TestSupport.screenshot("oberflaeche", R);
                return true;
            }
        });
        R.add("Oberflaeche schliessen", 5_000, () -> {
            mc().setScreen(null);
            mc().options.hideGui = true;
            return R.sleep(500);
        });
        R.add("Originalmotiv wiederherstellen", 15_000, new TestSupport.Step() {
            boolean sent;

            @Override
            public boolean run() {
                if (!sent) {
                    ClientUploader.INSTANCE.reset(painting.getId());
                    sent = true;
                }
                if (ClientUploader.INSTANCE.state() != ClientUploader.State.DONE || ClientImages.INSTANCE.textureFor(painting.getId()) != null) return false;
                if (!R.sleep(1500)) return false;
                int[][] c = TestSupport.samplePaintingColors(painting);
                R.ok(!(TestSupport.reddish(c[0]) && TestSupport.bluish(c[1])),
                        "Originalmotiv wieder sichtbar: links " + TestSupport.fmt(c[0]) + " rechts " + TestSupport.fmt(c[1]));
                return true;
            }
        });
        R.add("Bekanntes Bild erneut setzen", 15_000, new TestSupport.Step() {
            boolean sent;

            @Override
            public boolean run() {
                if (!sent) {
                    ClientUploader.INSTANCE.upload(painting.getId(), png);
                    sent = true;
                }
                if (ClientUploader.INSTANCE.state() != ClientUploader.State.DONE || ClientImages.INSTANCE.textureFor(painting.getId()) == null) return false;
                R.ok(!ClientUploader.INSTANCE.dataSent(), "Server kannte das Bild: ohne erneute Uebertragung gesetzt");
                return true;
            }
        });
    }
}
