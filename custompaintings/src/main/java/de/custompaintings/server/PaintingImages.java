package de.custompaintings.server;

import de.custompaintings.Config;
import de.custompaintings.CustomPaintings;
import de.custompaintings.ModAttachments;
import de.custompaintings.core.ChunkAssembler;
import de.custompaintings.core.ImageCodec;
import de.custompaintings.core.ImageFit;
import de.custompaintings.core.ImageStore;
import de.custompaintings.network.Network;
import de.custompaintings.network.Payloads;
import net.minecraft.network.chat.Component;
import net.minecraft.server.MinecraftServer;
import net.minecraft.server.level.ServerPlayer;
import net.minecraft.world.InteractionHand;
import net.minecraft.world.entity.Entity;
import net.minecraft.world.entity.decoration.Painting;
import net.minecraft.world.level.storage.LevelResource;
import net.neoforged.neoforge.common.CommonHooks;

import java.io.IOException;
import java.util.Arrays;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Serverseite: prueft Berechtigungen, nimmt Bilder entgegen, speichert sie inhaltsadressiert in
 * {@code <Welt>/custompaintings/} und teilt allen Spielern in Sichtweite mit, welches Bild ein Gemaelde zeigt.
 */
public final class PaintingImages {
    private static volatile PaintingImages instance;

    /** Ergebnis einer Aktion (auch fuer GameTests, deren Testspieler keine Pakete empfangen). */
    public record Result(int status, Component message) {
        public boolean ok() {
            return status != Payloads.UploadReply.FAILED;
        }
    }

    private static final class Upload {
        final int entityId;
        final String hash;
        final ChunkAssembler data;
        volatile long lastActivity = System.currentTimeMillis();

        Upload(int entityId, String hash, int size) {
            this.entityId = entityId;
            this.hash = hash;
            this.data = new ChunkAssembler(size);
        }
    }

    private final MinecraftServer server;
    private final ImageStore store;
    private final Map<UUID, Upload> uploads = new ConcurrentHashMap<>();
    /** Einfache Ratenbegrenzung fuer Bildanfragen: {Sekunde, Anzahl} je Spieler. */
    private final Map<UUID, long[]> requestRate = new ConcurrentHashMap<>();
    private volatile long storedBytes;

    private PaintingImages(MinecraftServer server) {
        this.server = server;
        this.store = new ImageStore(server.getWorldPath(LevelResource.ROOT).resolve(CustomPaintings.MOD_ID));
        this.storedBytes = store.totalBytes();
    }

    public static void start(MinecraftServer server) {
        instance = new PaintingImages(server);
        CustomPaintings.LOG.info("Custom Paintings: Bildspeicher {} ({} KB belegt)", instance.store.dir(), instance.storedBytes / 1024);
    }

    public static void stop() {
        instance = null;
    }

    public static PaintingImages get() {
        PaintingImages p = instance;
        if (p == null) throw new IllegalStateException("Custom Paintings: Server nicht gestartet");
        return p;
    }

    public ImageStore store() {
        return store;
    }

    // ------------------------------------------------------------------ Berechtigung

    /** @return null, wenn der Spieler das Gemaelde aendern darf, sonst der Grund. */
    public static Component denyReason(ServerPlayer player, Entity entity) {
        if (!(entity instanceof Painting painting) || painting.isRemoved() || painting.level() != player.level())
            return Component.translatable("custompaintings.msg.not_found");
        if (player.isSpectator()) return Component.translatable("custompaintings.msg.spectator");
        double max = Config.MAX_EDIT_DISTANCE.get();
        if (player.distanceToSqr(painting) > max * max) return Component.translatable("custompaintings.msg.too_far");
        if (Config.OPERATORS_ONLY.get() && !player.hasPermissions(2)) return Component.translatable("custompaintings.msg.ops_only");
        if (!player.mayBuild() || !player.mayInteract(player.level(), painting.blockPosition()))
            return Component.translatable("custompaintings.msg.protected");
        // Schutz-Mods (Claims usw.) haengen an der normalen Interaktion mit dem Gemaelde
        if (CommonHooks.onInteractEntity(player, painting, InteractionHand.MAIN_HAND) != null)
            return Component.translatable("custompaintings.msg.protected");
        return null;
    }

    // ------------------------------------------------------------------ Bild setzen

    /** Hauptthread. */
    public Result handleSetImage(ServerPlayer player, Payloads.SetImage req) {
        Result r = setImage(player, req);
        Network.send(player, new Payloads.UploadReply(req.entityId(), req.hash(), r.status(), r.message()));
        return r;
    }

    private Result setImage(ServerPlayer player, Payloads.SetImage req) {
        Entity entity = player.serverLevel().getEntity(req.entityId());
        Component deny = denyReason(player, entity);
        if (deny != null) return fail(deny);
        Painting painting = (Painting) entity;
        if (req.hash().isEmpty()) {
            assign(painting, "");
            CustomPaintings.LOG.info("Custom Paintings: {} hat Gemaelde {} auf das Originalmotiv zurueckgesetzt",
                    player.getGameProfile().getName(), painting.getUUID());
            return new Result(Payloads.UploadReply.DONE, Component.translatable("custompaintings.msg.reset"));
        }
        if (!ImageCodec.isHash(req.hash())) return fail(Component.translatable("custompaintings.msg.invalid"));
        if (store.has(req.hash())) {
            assign(painting, req.hash());
            log(player, painting, req.hash(), "vorhandenes Bild");
            return new Result(Payloads.UploadReply.DONE, Component.translatable("custompaintings.msg.done"));
        }
        long maxBytes = Config.MAX_IMAGE_KB.get() * 1024L;
        if (req.size() <= 0 || req.size() > maxBytes)
            return fail(Component.translatable("custompaintings.msg.too_big", Config.MAX_IMAGE_KB.get()));
        if (storedBytes + req.size() > Config.MAX_STORAGE_MB.get() * 1024L * 1024L)
            return fail(Component.translatable("custompaintings.msg.storage_full"));
        uploads.put(player.getUUID(), new Upload(req.entityId(), req.hash(), req.size()));
        return new Result(Payloads.UploadReply.SEND_DATA, Component.empty());
    }

    /** Netzwerk-Thread (oder Testthread): Datenblock anhaengen, bei Vollstaendigkeit auf dem Hauptthread pruefen. */
    public void handleChunk(ServerPlayer player, Payloads.UploadChunk chunk) {
        Upload up = uploads.get(player.getUUID());
        if (up == null || !up.hash.equals(chunk.hash())) return;
        boolean complete;
        synchronized (up) {
            if (!up.data.append(chunk.offset(), chunk.data())) {
                uploads.remove(player.getUUID(), up);
                server.execute(() -> reply(player, up, fail(Component.translatable("custompaintings.msg.transfer"))));
                return;
            }
            up.lastActivity = System.currentTimeMillis();
            complete = up.data.complete();
        }
        if (complete) {
            uploads.remove(player.getUUID(), up);
            server.execute(() -> reply(player, up, finish(player, up)));
        }
    }

    private void reply(ServerPlayer player, Upload up, Result r) {
        Network.send(player, new Payloads.UploadReply(up.entityId, up.hash, r.status(), r.message()));
    }

    /** Hauptthread: Datei pruefen, speichern und dem Gemaelde zuordnen. */
    private Result finish(ServerPlayer player, Upload up) {
        byte[] data = up.data.data();
        if (!up.hash.equals(ImageCodec.sha256(data))) return fail(Component.translatable("custompaintings.msg.transfer"));
        ImageCodec.Validation v = ImageCodec.validateUpload(data, Config.MAX_IMAGE_KB.get() * 1024, ImageFit.MAX_SIDE);
        if (!v.ok()) {
            CustomPaintings.LOG.info("Custom Paintings: Bild von {} abgelehnt: {}", player.getGameProfile().getName(), v.error());
            return fail(Component.translatable("custompaintings.msg.invalid_image", v.error()));
        }
        Entity entity = player.serverLevel().getEntity(up.entityId);
        Component deny = denyReason(player, entity);
        if (deny != null) return fail(deny);
        try {
            store.write(up.hash, data);
            storedBytes += data.length;
        } catch (IOException e) {
            CustomPaintings.LOG.error("Custom Paintings: Bild konnte nicht gespeichert werden", e);
            return fail(Component.translatable("custompaintings.msg.io"));
        }
        Painting painting = (Painting) entity;
        assign(painting, up.hash);
        log(player, painting, up.hash, v.width() + "x" + v.height() + " Pixel, " + data.length / 1024 + " KB");
        return new Result(Payloads.UploadReply.DONE, Component.translatable("custompaintings.msg.done"));
    }

    private static void log(ServerPlayer player, Painting painting, String hash, String what) {
        CustomPaintings.LOG.info("Custom Paintings: {} hat auf Gemaelde {} ({}) ein eigenes Bild gesetzt ({}, ID {})",
                player.getGameProfile().getName(), painting.getUUID(), painting.getVariant().getRegisteredName(), what,
                hash.substring(0, 12));
    }

    private static Result fail(Component msg) {
        return new Result(Payloads.UploadReply.FAILED, msg);
    }

    /** Bild am Gemaelde speichern und allen Spielern in Sichtweite mitteilen. */
    public static void assign(Painting painting, String hash) {
        if (hash.isEmpty()) painting.removeData(ModAttachments.IMAGE);
        else painting.setData(ModAttachments.IMAGE, hash);
        Network.sendToTracking(painting, new Payloads.PaintingImage(painting.getId(), hash));
    }

    public static String imageOf(Entity entity) {
        return entity instanceof Painting p && p.hasData(ModAttachments.IMAGE) ? p.getData(ModAttachments.IMAGE) : "";
    }

    // ------------------------------------------------------------------ Bilder ausliefern

    /** Hauptthread: Bilddaten in Bloecken an den anfragenden Spieler senden. */
    public void handleRequest(ServerPlayer player, Payloads.RequestImage req) {
        if (!ImageCodec.isHash(req.hash()) || !store.has(req.hash())) return;
        long sec = System.currentTimeMillis() / 1000;
        long[] rate = requestRate.computeIfAbsent(player.getUUID(), k -> new long[]{sec, 0});
        if (rate[0] != sec) {
            rate[0] = sec;
            rate[1] = 0;
        }
        if (++rate[1] > 40) return; // mehr als 40 verschiedene Bilder pro Sekunde: Missbrauch
        byte[] data;
        try {
            data = store.read(req.hash());
        } catch (IOException e) {
            CustomPaintings.LOG.warn("Custom Paintings: Bild {} nicht lesbar: {}", req.hash(), e.getMessage());
            return;
        }
        for (int off = 0; off < data.length; off += Payloads.DOWNLOAD_CHUNK) {
            byte[] part = Arrays.copyOfRange(data, off, Math.min(data.length, off + Payloads.DOWNLOAD_CHUNK));
            Network.send(player, new Payloads.ImageData(req.hash(), data.length, off, part));
        }
    }

    /** Spieler sieht ein Gemaelde mit eigenem Bild zum ersten Mal: Zuordnung schicken. */
    public static void onStartTracking(ServerPlayer player, Entity target) {
        String hash = imageOf(target);
        if (!hash.isEmpty()) Network.send(player, new Payloads.PaintingImage(target.getId(), hash));
    }

    public void onLogout(ServerPlayer player) {
        uploads.remove(player.getUUID());
        requestRate.remove(player.getUUID());
    }

    /** Haengende Uploads (Verbindung weg, Client abgestuerzt) nach 60 s verwerfen. */
    public void tick() {
        long now = System.currentTimeMillis();
        uploads.values().removeIf(u -> now - u.lastActivity > 60_000);
    }
}
