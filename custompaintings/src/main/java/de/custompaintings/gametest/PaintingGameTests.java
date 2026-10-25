package de.custompaintings.gametest;

import de.custompaintings.CustomPaintings;
import de.custompaintings.ModAttachments;
import de.custompaintings.core.ImageCodec;
import de.custompaintings.network.Payloads;
import de.custompaintings.server.PaintingImages;
import io.netty.buffer.Unpooled;
import net.minecraft.core.BlockPos;
import net.minecraft.core.Direction;
import net.minecraft.core.registries.Registries;
import net.minecraft.gametest.framework.GameTest;
import net.minecraft.gametest.framework.GameTestHelper;
import net.minecraft.nbt.CompoundTag;
import net.minecraft.network.FriendlyByteBuf;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.network.chat.Component;
import net.minecraft.server.level.ServerLevel;
import net.minecraft.server.level.ServerPlayer;
import net.minecraft.world.entity.EntityType;
import net.minecraft.world.entity.decoration.Painting;
import net.minecraft.world.entity.decoration.PaintingVariants;
import net.minecraft.world.level.GameType;
import net.minecraft.world.level.block.Blocks;
import net.neoforged.neoforge.gametest.GameTestHolder;
import net.neoforged.neoforge.gametest.PrefixGameTestTemplate;

import java.awt.Color;
import java.awt.Graphics2D;
import java.awt.image.BufferedImage;
import java.util.Arrays;

/** GameTests auf einem echten dedizierten GameTest-Server: {@code ./gradlew runGameTestServer}. */
@GameTestHolder(CustomPaintings.MOD_ID)
@PrefixGameTestTemplate(false)
public final class PaintingGameTests {
    private PaintingGameTests() {}

    private static Painting hangPainting(GameTestHelper h, BlockPos rel) {
        ServerLevel level = h.getLevel();
        BlockPos pos = h.absolutePos(rel);
        for (int x = -3; x <= 3; x++) for (int y = -2; y <= 3; y++) level.setBlockAndUpdate(pos.offset(x, y, 1), Blocks.STONE.defaultBlockState());
        var holder = level.registryAccess().registryOrThrow(Registries.PAINTING_VARIANT).getHolderOrThrow(PaintingVariants.FIGHTERS);
        Painting p = new Painting(level, pos, Direction.NORTH, holder);
        level.addFreshEntity(p);
        return p;
    }

    private static ServerPlayer playerNear(GameTestHelper h, Painting p, double distance) {
        ServerPlayer sp = h.makeMockServerPlayerInLevel();
        sp.setGameMode(GameType.SURVIVAL);
        sp.teleportTo(p.getX(), p.getY() - 1, p.getZ() - distance);
        return sp;
    }

    private static byte[] testPng(int w, int h, Color left) {
        BufferedImage img = new BufferedImage(w, h, BufferedImage.TYPE_INT_RGB);
        Graphics2D g = img.createGraphics();
        g.setColor(left);
        g.fillRect(0, 0, w / 2, h);
        g.setColor(Color.BLUE);
        g.fillRect(w / 2, 0, w - w / 2, h);
        g.dispose();
        return ImageCodec.encodePng(img);
    }

    /** Ueberlaedt eine Datei so, wie es ein Client tut (SetImage + Bloecke). */
    private static PaintingImages.Result upload(PaintingImages svc, ServerPlayer sp, Painting p, byte[] data, String hash) {
        PaintingImages.Result r = svc.handleSetImage(sp, new Payloads.SetImage(p.getId(), hash, data.length));
        if (r.status() != Payloads.UploadReply.SEND_DATA) return r;
        for (int off = 0; off < data.length; off += Payloads.UPLOAD_CHUNK) {
            svc.handleChunk(sp, new Payloads.UploadChunk(hash, off, Arrays.copyOfRange(data, off, Math.min(data.length, off + Payloads.UPLOAD_CHUNK))));
        }
        return r;
    }

    private static void check(GameTestHelper h, boolean ok, String msg) {
        if (!ok) h.fail(msg);
    }

    /**
     * Hochladen (mehrere Bloecke), Pruefung, Speicherung, Zuordnung am Gemaelde, Persistenz ueber NBT,
     * erneutes Setzen ohne Uebertragung, Zuruecksetzen.
     */
    @GameTest(template = "wall")
    public static void uploadAssignPersistReset(GameTestHelper h) {
        PaintingImages svc = PaintingImages.get();
        Painting p = hangPainting(h, new BlockPos(5, 2, 4));
        ServerPlayer sp = playerNear(h, p, 3);
        byte[] png = testPng(256, 128, Color.RED);
        // gross genug fuer mehrere Upload-Bloecke: zusaetzliches Rauschen
        BufferedImage noisy = new BufferedImage(256, 128, BufferedImage.TYPE_INT_RGB);
        java.util.Random rnd = new java.util.Random(5);
        for (int y = 0; y < 128; y++) for (int x = 0; x < 256; x++) noisy.setRGB(x, y, rnd.nextInt(0xFFFFFF));
        png = ImageCodec.encodePng(noisy);
        check(h, png.length > 2 * Payloads.UPLOAD_CHUNK, "Testbild zu klein fuer mehrere Bloecke: " + png.length);
        String hash = ImageCodec.sha256(png);

        PaintingImages.Result r = upload(svc, sp, p, png, hash);
        check(h, r.status() == Payloads.UploadReply.SEND_DATA, "Server sollte Daten anfordern: " + r.message().getString());
        check(h, hash.equals(PaintingImages.imageOf(p)), "Bild nicht am Gemaelde: '" + PaintingImages.imageOf(p) + "'");
        check(h, svc.store().has(hash), "Bild nicht gespeichert");

        // Persistenz: Gemaelde speichern und als neue Entity laden
        CompoundTag tag = new CompoundTag();
        p.saveWithoutId(tag);
        Painting copy = new Painting(EntityType.PAINTING, h.getLevel());
        copy.load(tag);
        check(h, hash.equals(PaintingImages.imageOf(copy)), "Bild-ID nicht in den Gemaeldedaten gespeichert");

        // Zweites Gemaelde mit demselben Bild: sofort gesetzt, ohne Uebertragung
        Painting p2 = hangPainting(h, new BlockPos(5, 2, 12));
        ServerPlayer sp2 = playerNear(h, p2, 3);
        PaintingImages.Result r2 = svc.handleSetImage(sp2, new Payloads.SetImage(p2.getId(), hash, png.length));
        check(h, r2.status() == Payloads.UploadReply.DONE, "bekanntes Bild sollte sofort gesetzt werden");
        check(h, hash.equals(PaintingImages.imageOf(p2)), "Bild nicht an zweitem Gemaelde");

        // Zuruecksetzen
        PaintingImages.Result r3 = svc.handleSetImage(sp, new Payloads.SetImage(p.getId(), "", 0));
        check(h, r3.status() == Payloads.UploadReply.DONE && !p.hasData(ModAttachments.IMAGE), "Zuruecksetzen fehlgeschlagen");
        check(h, hash.equals(PaintingImages.imageOf(p2)), "Zuruecksetzen darf andere Gemaelde nicht aendern");
        h.succeed();
    }

    /** Berechtigungen und kaputte Daten: nichts darf am Gemaelde landen. */
    @GameTest(template = "wall")
    public static void rejectsInvalidAndForeign(GameTestHelper h) {
        PaintingImages svc = PaintingImages.get();
        Painting p = hangPainting(h, new BlockPos(5, 2, 4));
        byte[] png = testPng(64, 32, Color.GREEN);
        String hash = ImageCodec.sha256(png);

        ServerPlayer far = playerNear(h, p, 20);
        check(h, !upload(svc, far, p, png, hash).ok(), "zu weit entfernter Spieler darf nicht aendern");

        ServerPlayer spectator = playerNear(h, p, 3);
        spectator.setGameMode(GameType.SPECTATOR);
        check(h, !upload(svc, spectator, p, png, hash).ok(), "Zuschauer darf nicht aendern");

        ServerPlayer adventure = playerNear(h, p, 3);
        adventure.setGameMode(GameType.ADVENTURE);
        check(h, !upload(svc, adventure, p, png, hash).ok(), "Abenteuermodus darf nicht aendern");

        ServerPlayer sp = playerNear(h, p, 3);
        check(h, !svc.handleSetImage(sp, new Payloads.SetImage(p.getId(), "../../evil", 10)).ok(), "ungueltige ID");
        check(h, !svc.handleSetImage(sp, new Payloads.SetImage(999_999, hash, png.length)).ok(), "kein Gemaelde");
        check(h, !svc.handleSetImage(sp, new Payloads.SetImage(p.getId(), hash, 100 * 1024 * 1024)).ok(), "zu gross");

        // Daten passen nicht zur angekuendigten Pruefsumme
        byte[] other = testPng(64, 32, Color.YELLOW);
        upload(svc, sp, p, other, hash);
        check(h, PaintingImages.imageOf(p).isEmpty(), "falsche Pruefsumme darf nicht gesetzt werden");

        // Kein Bild (Zufallsdaten mit passender Pruefsumme)
        byte[] junk = new byte[5000];
        new java.util.Random(1).nextBytes(junk);
        upload(svc, sp, p, junk, ImageCodec.sha256(junk));
        check(h, PaintingImages.imageOf(p).isEmpty(), "Zufallsdaten duerfen nicht gesetzt werden");
        check(h, !svc.store().has(ImageCodec.sha256(junk)), "Zufallsdaten duerfen nicht gespeichert werden");

        // Gueltiges Bild vom berechtigten Spieler klappt danach
        upload(svc, sp, p, png, hash);
        check(h, hash.equals(PaintingImages.imageOf(p)), "berechtigter Upload fehlgeschlagen");
        h.succeed();
    }

    @GameTest(template = "wall")
    public static void payloadCodecs(GameTestHelper h) {
        var reg = h.getLevel().registryAccess();
        FriendlyByteBuf b = new FriendlyByteBuf(Unpooled.buffer());
        Payloads.UploadChunk.CODEC.encode(b, new Payloads.UploadChunk("a".repeat(64), 16384, new byte[]{1, 2, 3}));
        Payloads.UploadChunk c = Payloads.UploadChunk.CODEC.decode(b);
        check(h, c.offset() == 16384 && Arrays.equals(c.data(), new byte[]{1, 2, 3}), "UploadChunk");
        RegistryFriendlyByteBuf rb = new RegistryFriendlyByteBuf(Unpooled.buffer(), reg);
        Payloads.UploadReply.CODEC.encode(rb, new Payloads.UploadReply(7, "b".repeat(64), Payloads.UploadReply.DONE, Component.literal("ok")));
        Payloads.UploadReply r = Payloads.UploadReply.CODEC.decode(rb);
        check(h, r.entityId() == 7 && r.status() == Payloads.UploadReply.DONE && r.message().getString().equals("ok"), "UploadReply");
        FriendlyByteBuf big = new FriendlyByteBuf(Unpooled.buffer());
        Payloads.UploadChunk.CODEC.encode(big, new Payloads.UploadChunk("c".repeat(64), 0, new byte[Payloads.UPLOAD_CHUNK + 1]));
        boolean rejected = false;
        try {
            Payloads.UploadChunk.CODEC.decode(big);
        } catch (RuntimeException e) {
            rejected = true;
        }
        check(h, rejected, "zu grosser Block muss abgelehnt werden");
        h.succeed();
    }
}
