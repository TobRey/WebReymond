package de.custompaintings.client;

import de.custompaintings.Config;
import de.custompaintings.core.ImageCodec;
import de.custompaintings.core.ImageFit;
import net.minecraft.client.Minecraft;
import net.minecraft.commands.arguments.EntityAnchorArgument;
import net.minecraft.core.BlockPos;
import net.minecraft.core.Direction;
import net.minecraft.world.InteractionHand;
import net.minecraft.world.entity.decoration.Painting;
import net.minecraft.world.entity.decoration.PaintingVariant;
import net.minecraft.world.item.Items;
import net.minecraft.world.phys.BlockHitResult;
import net.minecraft.world.phys.Vec3;
import net.neoforged.bus.api.SubscribeEvent;
import net.neoforged.neoforge.client.event.ClientTickEvent;

import java.nio.file.Files;
import java.nio.file.Path;

/**
 * Mehrspieler-Ende-zu-Ende-Test (CI) mit echten Clients gegen einen echten dedizierten Server. Aktiv nur mit
 * {@code -Dcustompaintings.e2e=A|B|A2}:
 * <ul>
 *   <li>A: Gemaelde mit dem normalen Gegenstand aufhaengen, eigenes Bild hochladen, sichtbar pruefen</li>
 *   <li>B: tritt spaeter bei und muss das Bild sehen (vom Server geladen)</li>
 *   <li>A2: nach Server-Neustart ist das Bild noch da; Originalmotiv wiederherstellen</li>
 * </ul>
 */
public final class MultiplayerE2E {
    private static final String ROLE = System.getProperty("custompaintings.e2e", "");
    private static final Path DIR = Path.of(System.getProperty("custompaintings.e2e.dir", "."));
    private static final TestSupport.Runner R = new TestSupport.Runner("CustomPaintings-E2E[" + ROLE + "]");
    private static Painting painting;
    private static String expectedHash = "";

    private MultiplayerE2E() {}

    public static boolean enabled() {
        return !ROLE.isEmpty();
    }

    static {
        if (enabled()) {
            Thread t = new Thread(() -> {
                try {
                    Thread.sleep(480_000);
                    R.log("FAIL globale Zeitueberschreitung in Schritt " + R.currentPhase());
                    Runtime.getRuntime().halt(4);
                } catch (InterruptedException ignored) {
                }
            }, "CustomPaintings-E2E-Watchdog");
            t.setDaemon(true);
            t.start();
            setup();
        }
    }

    @SubscribeEvent
    public static void onTick(ClientTickEvent.Post e) {
        Minecraft mc = Minecraft.getInstance();
        R.tick(() -> mc.player != null && mc.level != null);
    }

    private static Minecraft mc() {
        return Minecraft.getInstance();
    }

    private static void cmd(String c) {
        mc().player.connection.sendCommand(c);
    }

    private static void touch(String name, String content) {
        try {
            Files.createDirectories(DIR);
            Files.writeString(DIR.resolve(name), content);
        } catch (Exception e) {
            R.log("Markerdatei " + name + " nicht schreibbar: " + e);
        }
    }

    private static String read(String name) {
        try {
            return Files.readString(DIR.resolve(name)).trim();
        } catch (Exception e) {
            return null;
        }
    }

    /** Vor das Gemaelde stellen und genau auf seine Mitte blicken (Abstand je nach Groesse). */
    private static void standInFront(Painting p) {
        PaintingVariant v = p.getVariant().value();
        double dist = Math.max(v.width(), v.height()) * 0.9 + 1.2;
        Direction facing = p.getDirection();
        Vec3 c = p.position();
        double x = c.x + facing.getStepX() * dist, z = c.z + facing.getStepZ() * dist;
        cmd(String.format(java.util.Locale.ROOT, "tp @s %.2f %.2f %.2f", x, c.y - 1.62, z));
    }

    private static void lookAtPainting() {
        mc().player.lookAt(EntityAnchorArgument.Anchor.EYES, painting.position());
    }

    private static void checkVisible(String what) {
        int[][] c = TestSupport.samplePaintingColors(painting);
        R.ok(TestSupport.reddish(c[0]) && TestSupport.bluish(c[1]), what + ": links " + TestSupport.fmt(c[0])
                + " (rot erwartet), rechts " + TestSupport.fmt(c[1]) + " (blau erwartet)");
    }

    private static void setup() {
        R.add("Verbinden", 120_000, () -> R.sleep(3000));
        switch (ROLE) {
            case "A" -> {
                R.add("Vorbereitung per Befehl", 10_000, () -> {
                    cmd("gamemode creative");
                    cmd("time set 6000");
                    cmd("gamerule doDaylightCycle false");
                    cmd("tp @s 0.5 -60 0.5 0 0");
                    cmd("fill -6 -60 4 6 -54 4 minecraft:stone");
                    cmd("give @s minecraft:painting");
                    return true;
                });
                R.add("warten", 10_000, () -> R.sleep(3000));
                R.add("Gemaelde aufhaengen (normaler Gegenstand)", 20_000, new TestSupport.Step() {
                    boolean used;

                    @Override
                    public boolean run() {
                        if (!used) {
                            var inv = mc().player.getInventory();
                            for (int i = 0; i < 9; i++) if (inv.getItem(i).is(Items.PAINTING)) inv.selected = i;
                            BlockPos wall = new BlockPos(0, -57, 4);
                            mc().gameMode.useItemOn(mc().player, InteractionHand.MAIN_HAND,
                                    new BlockHitResult(Vec3.atCenterOf(wall).add(0, 0, -0.5), Direction.NORTH, wall, false));
                            used = true;
                            return false;
                        }
                        painting = TestSupport.nearestPainting(16);
                        if (painting == null) return false;
                        PaintingVariant v = painting.getVariant().value();
                        R.ok(true, "Gemaelde haengt: " + painting.getVariant().getRegisteredName() + " (" + v.width() + "x" + v.height()
                                + ", normales Minecraft-Motiv)");
                        standInFront(painting);
                        return true;
                    }
                });
                R.add("Eigenes Bild hochladen", 30_000, new TestSupport.Step() {
                    boolean sent;

                    @Override
                    public boolean run() {
                        if (!sent) {
                            PaintingVariant v = painting.getVariant().value();
                            int[] t = ImageFit.targetSize(v.width(), v.height(), Config.PIXELS_PER_BLOCK.get(), ImageFit.MAX_SIDE);
                            byte[] png = ImageCodec.encodePng(ImageFit.fit(TestSupport.leftRedRightBlue(640, 480), t[0], t[1], ImageFit.Mode.FILL, 0));
                            expectedHash = ImageCodec.sha256(png);
                            ClientUploader.INSTANCE.upload(painting.getId(), png);
                            sent = true;
                        }
                        if (ClientUploader.INSTANCE.state() == ClientUploader.State.FAILED) {
                            R.ok(false, "Upload: " + ClientUploader.INSTANCE.message().getString());
                            return true;
                        }
                        if (ClientUploader.INSTANCE.state() != ClientUploader.State.DONE || ClientImages.INSTANCE.textureFor(painting.getId()) == null)
                            return false;
                        R.ok(ClientUploader.INSTANCE.dataSent(), "Bild ueber das Netzwerk hochgeladen und gesetzt");
                        return true;
                    }
                });
                R.add("Sichtbar bei A", 10_000, () -> {
                    mc().options.hideGui = true;
                    lookAtPainting();
                    if (!R.sleep(2000)) return false;
                    checkVisible("Bild bei A sichtbar und richtig herum");
                    touch("painted", expectedHash);
                    return true;
                });
                R.add("auf B warten", 300_000, () -> Files.exists(DIR.resolve("b_done")) && R.sleep(1000));
            }
            case "B" -> {
                R.add("Zur Wand", 10_000, () -> {
                    expectedHash = read("painted");
                    cmd("tp @s 0.5 -60 0.5 0 0");
                    return expectedHash != null && R.sleep(2500);
                });
                R.add("Gemaelde mit Bild empfangen", 30_000, () -> {
                    painting = TestSupport.nearestPainting(16);
                    if (painting == null) return false;
                    if (!expectedHash.equals(ClientImages.INSTANCE.imageOf(painting.getId()))) return false;
                    if (ClientImages.INSTANCE.textureFor(painting.getId()) == null) return false;
                    R.ok(true, "Spaeter beigetreten: Bild-ID erhalten und Bild vom Server geladen");
                    standInFront(painting);
                    return true;
                });
                R.add("Sichtbar bei B", 10_000, () -> {
                    mc().options.hideGui = true;
                    lookAtPainting();
                    if (!R.sleep(2500)) return false;
                    checkVisible("Bild bei B sichtbar und richtig herum");
                    touch("b_done", "1");
                    return true;
                });
            }
            case "A2" -> {
                R.add("Nach Neustart", 30_000, () -> {
                    expectedHash = read("painted");
                    cmd("tp @s 0.5 -60 0.5 0 0");
                    painting = TestSupport.nearestPainting(16);
                    if (painting == null || expectedHash == null) return false;
                    if (!expectedHash.equals(ClientImages.INSTANCE.imageOf(painting.getId())) || ClientImages.INSTANCE.textureFor(painting.getId()) == null)
                        return false;
                    R.ok(true, "Bild nach Server-Neustart noch am Gemaelde");
                    standInFront(painting);
                    return true;
                });
                R.add("Sichtbar nach Neustart", 10_000, () -> {
                    mc().options.hideGui = true;
                    lookAtPainting();
                    if (!R.sleep(2500)) return false;
                    checkVisible("Bild nach Neustart sichtbar");
                    return true;
                });
                R.add("Originalmotiv wiederherstellen", 15_000, new TestSupport.Step() {
                    boolean sent;

                    @Override
                    public boolean run() {
                        if (!sent) {
                            ClientUploader.INSTANCE.reset(painting.getId());
                            sent = true;
                        }
                        if (ClientUploader.INSTANCE.state() != ClientUploader.State.DONE) return false;
                        if (!ClientImages.INSTANCE.imageOf(painting.getId()).isEmpty() || !R.sleep(1500)) return false;
                        int[][] c = TestSupport.samplePaintingColors(painting);
                        R.ok(!(TestSupport.reddish(c[0]) && TestSupport.bluish(c[1])), "Originalmotiv wieder sichtbar");
                        return true;
                    }
                });
            }
            default -> {}
        }
    }
}
