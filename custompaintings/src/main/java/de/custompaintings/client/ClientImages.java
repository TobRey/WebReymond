package de.custompaintings.client;

import com.mojang.blaze3d.platform.NativeImage;
import de.custompaintings.Config;
import de.custompaintings.CustomPaintings;
import de.custompaintings.core.ChunkAssembler;
import de.custompaintings.core.ImageCodec;
import de.custompaintings.network.Payloads;
import net.minecraft.client.Minecraft;
import net.minecraft.client.renderer.texture.DynamicTexture;
import net.minecraft.resources.ResourceLocation;
import net.neoforged.neoforge.network.PacketDistributor;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Clientseitige Verwaltung: welches Gemaelde (Entity-ID) welches Bild zeigt, Laden der Bilddaten vom Server
 * (mit optionalem Festplatten-Cache) und die daraus erzeugten Texturen.
 */
public final class ClientImages {
    public static final ClientImages INSTANCE = new ClientImages();

    private enum State { LOADING, READY, FAILED }

    private static final class Tex {
        volatile State state = State.LOADING;
        volatile long requestedAt;
        ResourceLocation location;
        DynamicTexture texture;
    }

    private final Map<Integer, String> paintingImage = new ConcurrentHashMap<>();
    private final Map<String, Tex> textures = new ConcurrentHashMap<>();
    private final Map<String, ChunkAssembler> downloads = new ConcurrentHashMap<>();

    private ClientImages() {}

    // ------------------------------------------------------------------ Zuordnung

    /** Server meldet das Bild eines Gemaeldes (beliebiger Thread). */
    public void setPaintingImage(int entityId, String hash) {
        if (hash.isEmpty()) {
            paintingImage.remove(entityId);
            return;
        }
        if (!ImageCodec.isHash(hash)) return;
        paintingImage.put(entityId, hash);
        Minecraft.getInstance().execute(() -> ensure(hash));
    }

    public String imageOf(int entityId) {
        return paintingImage.getOrDefault(entityId, "");
    }

    /** Fertige Textur fuer ein Gemaelde oder null (dann zeigt es das normale Motiv). Render-Thread. */
    public ResourceLocation textureFor(int entityId) {
        String hash = paintingImage.get(entityId);
        if (hash == null) return null;
        Tex t = textures.get(hash);
        if (t == null || t.state != State.READY) {
            if (t == null || (t.state != State.READY && System.currentTimeMillis() - t.requestedAt > 15_000)) ensure(hash);
            return null;
        }
        return t.location;
    }

    public boolean isReady(String hash) {
        Tex t = textures.get(hash);
        return t != null && t.state == State.READY;
    }

    // ------------------------------------------------------------------ Laden

    /** Render-Thread: Bild bereitstellen (Cache, sonst beim Server anfordern). */
    private void ensure(String hash) {
        Tex t = textures.computeIfAbsent(hash, h -> new Tex());
        if (t.state == State.READY) return;
        if (t.state == State.LOADING && t.requestedAt != 0 && System.currentTimeMillis() - t.requestedAt < 15_000) return;
        t.state = State.LOADING;
        t.requestedAt = System.currentTimeMillis();
        byte[] cached = readCache(hash);
        if (cached != null) {
            register(hash, cached);
            return;
        }
        downloads.remove(hash);
        PacketDistributor.sendToServer(new Payloads.RequestImage(hash));
    }

    /** Netzwerk-Thread: ein Datenblock vom Server. */
    public void onImageData(Payloads.ImageData p) {
        if (!ImageCodec.isHash(p.hash()) || p.total() <= 0 || p.total() > Payloads.MAX_FILE) return;
        if (!paintingImage.containsValue(p.hash()) || isReady(p.hash())) return; // nicht (mehr) benoetigt
        ChunkAssembler a = downloads.compute(p.hash(), (k, old) -> p.offset() == 0 || old == null ? new ChunkAssembler(p.total()) : old);
        synchronized (a) {
            if (a.size() != p.total() || !a.append(p.offset(), p.data())) {
                downloads.remove(p.hash());
                return;
            }
            if (!a.complete()) return;
        }
        downloads.remove(p.hash());
        byte[] data = a.data();
        if (!p.hash().equals(ImageCodec.sha256(data))) {
            CustomPaintings.LOG.warn("Custom Paintings: Bild {} beschaedigt empfangen", p.hash());
            return;
        }
        writeCache(p.hash(), data);
        Minecraft.getInstance().execute(() -> register(p.hash(), data));
    }

    /** Eigenes, gerade hochgeladenes Bild sofort verwenden (kein erneuter Download). Render-Thread. */
    public void preload(String hash, byte[] png) {
        writeCache(hash, png);
        textures.computeIfAbsent(hash, h -> new Tex());
        register(hash, png);
    }

    /** Render-Thread: PNG in eine Textur umwandeln. */
    private void register(String hash, byte[] png) {
        Tex t = textures.computeIfAbsent(hash, h -> new Tex());
        if (t.state == State.READY) return;
        try {
            NativeImage img = NativeImage.read(png);
            DynamicTexture tex = new DynamicTexture(img);
            ResourceLocation loc = CustomPaintings.id("painting/" + hash);
            Minecraft.getInstance().getTextureManager().register(loc, tex);
            t.texture = tex;
            t.location = loc;
            t.state = State.READY;
        } catch (IOException | RuntimeException e) {
            CustomPaintings.LOG.warn("Custom Paintings: Bild {} nicht darstellbar: {}", hash, e.toString());
            t.state = State.FAILED;
        }
    }

    // ------------------------------------------------------------------ Festplatten-Cache

    private static Path cacheDir() {
        return Minecraft.getInstance().gameDirectory.toPath().resolve("custompaintings-cache");
    }

    private static byte[] readCache(String hash) {
        if (!Config.DISK_CACHE.get()) return null;
        Path f = cacheDir().resolve(hash + ".png");
        try {
            if (!Files.isRegularFile(f)) return null;
            byte[] data = Files.readAllBytes(f);
            return hash.equals(ImageCodec.sha256(data)) ? data : null;
        } catch (IOException e) {
            return null;
        }
    }

    private static void writeCache(String hash, byte[] data) {
        if (!Config.DISK_CACHE.get()) return;
        try {
            Files.createDirectories(cacheDir());
            Path f = cacheDir().resolve(hash + ".png");
            if (!Files.exists(f)) Files.write(f, data);
        } catch (IOException e) {
            CustomPaintings.LOG.debug("Custom Paintings: Cache nicht beschreibbar: {}", e.toString());
        }
    }

    // ------------------------------------------------------------------ Aufraeumen

    /** Verbindung beendet: Zuordnungen und Texturen freigeben. Render-Thread. */
    public void clear() {
        paintingImage.clear();
        downloads.clear();
        for (Tex t : textures.values()) {
            if (t.location != null) Minecraft.getInstance().getTextureManager().release(t.location);
        }
        textures.clear();
    }
}
