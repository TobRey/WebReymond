package de.blockrave.stage;

import de.blockrave.BlockRave;
import de.blockrave.audio.PlaybackController;
import de.blockrave.core.stage.StageLayout;
import de.blockrave.core.storage.AtomicFiles;
import de.blockrave.network.Payloads;
import de.blockrave.registry.ModBlocks;
import de.blockrave.registry.ModItems;
import net.minecraft.core.BlockPos;
import net.minecraft.core.registries.BuiltInRegistries;
import net.minecraft.network.chat.Component;
import net.minecraft.resources.ResourceKey;
import net.minecraft.resources.ResourceLocation;
import net.minecraft.server.MinecraftServer;
import net.minecraft.server.level.ServerLevel;
import net.minecraft.server.level.ServerPlayer;
import net.minecraft.core.registries.Registries;
import net.minecraft.tags.ItemTags;
import net.minecraft.world.item.ItemStack;
import net.minecraft.world.level.Level;
import net.minecraft.world.level.block.Block;
import net.minecraft.world.level.block.Blocks;
import net.minecraft.world.level.block.state.BlockState;
import net.minecraft.world.level.border.WorldBorder;
import net.minecraft.world.level.storage.LevelResource;
import net.minecraft.world.phys.Vec3;
import net.neoforged.neoforge.common.NeoForge;
import net.neoforged.neoforge.event.level.BlockEvent;
import de.blockrave.network.Net;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Verwaltet die eine Festivalbuehne des Servers: Pruefung, Aufbau, Einpacken und Speicherung.
 * Laeuft ausschliesslich auf dem Server-Hauptthread; dadurch sind gleichzeitige Platzierungen
 * oder doppeltes Einpacken ausgeschlossen.
 */
public final class StageManager {
    private static final int SET_FLAGS = Block.UPDATE_CLIENTS | Block.UPDATE_KNOWN_SHAPE;
    private static volatile StageManager instance;

    private final MinecraftServer server;
    private final Path dataDir;
    private final Path stageFile;
    private StageState state;
    private StageFootprint footprint;
    private final PlaybackController playback;

    private StageManager(MinecraftServer server) {
        this.server = server;
        this.dataDir = server.getWorldPath(LevelResource.ROOT).resolve("blockrave");
        this.stageFile = dataDir.resolve("stage.json");
        loadStage();
        this.playback = new PlaybackController(server, this, dataDir);
    }

    public static StageManager get(MinecraftServer server) {
        StageManager m = instance;
        if (m == null || m.server != server) throw new IllegalStateException("BlockRave ist fuer diesen Server nicht gestartet");
        return m;
    }

    public static StageManager getOrNull() {
        return instance;
    }

    public static void start(MinecraftServer server) {
        instance = new StageManager(server);
    }

    public static void stop() {
        StageManager m = instance;
        instance = null;
        if (m != null) m.playback.shutdown();
    }

    public PlaybackController playback() {
        return playback;
    }

    public MinecraftServer server() {
        return server;
    }

    // ------------------------------------------------------------------ Laden/Speichern

    private void loadStage() {
        try {
            state = AtomicFiles.readWithBackup(stageFile, StageState::fromJson);
            if (state != null) {
                footprint = new StageFootprint(new BlockPos(state.anchorX, state.anchorY, state.anchorZ), state.rotation);
                BlockRave.LOG.info("BlockRave: Buehne geladen ({} bei {} {} {}, {} Bauteile)", state.dimension,
                        state.anchorX, state.anchorY, state.anchorZ, state.positions.length);
            }
        } catch (IOException | RuntimeException e) {
            BlockRave.LOG.error("BlockRave: Buehnenzustand konnte nicht gelesen werden – Buehne gilt als nicht vorhanden", e);
            state = null;
        }
    }

    private void saveStage() throws IOException {
        if (state == null) {
            Files.deleteIfExists(stageFile);
            Files.deleteIfExists(stageFile.resolveSibling("stage.json.bak"));
        } else {
            AtomicFiles.write(stageFile, state.toJson());
        }
    }

    // ------------------------------------------------------------------ Abfragen

    public boolean hasStage() {
        return state != null;
    }

    public ResourceKey<Level> dimension() {
        return state == null ? null : ResourceKey.create(Registries.DIMENSION, ResourceLocation.parse(state.dimension));
    }

    public ServerLevel level() {
        ResourceKey<Level> key = dimension();
        return key == null ? null : server.getLevel(key);
    }

    /** Mittelpunkt der Buehnenfront (Bezugspunkt fuer Hoerreichweite). */
    public Vec3 center() {
        if (footprint == null) return null;
        double[] c = StageLayout.get().fixtures().center();
        double[] w = footprint.transform().toWorld(c[0], c[1], c[2]);
        return new Vec3(w[0], w[1], w[2]);
    }

    public boolean isStageBooth(Level level, BlockPos pos) {
        return state != null && level.dimension().location().toString().equals(state.dimension)
                && pos.asLong() == state.boothPos;
    }

    public Payloads.StageInfo stageInfo() {
        if (state == null) return new Payloads.StageInfo(false, "", BlockPos.ZERO, 0);
        return new Payloads.StageInfo(true, state.dimension, new BlockPos(state.anchorX, state.anchorY, state.anchorZ), state.rotation);
    }

    public void sendStageInfo(ServerPlayer player) {
        Net.send(player, stageInfo());
    }

    // ------------------------------------------------------------------ Platzierung

    /** Ergebnis der Platzpruefung. */
    public record Check(boolean ok, Component error, List<BlockPos> affected, int containers, long hash,
                        List<Component> summary) {}

    /** Ergebnis eines Aufbauversuchs ({@link Payloads.PlacementResult}-Status). */
    public record ConfirmResult(int status, Component message, Check check) {}

    private static boolean holdsKit(ServerPlayer p) {
        return p.getMainHandItem().is(ModItems.STAGE_KIT.get()) || p.getOffhandItem().is(ModItems.STAGE_KIT.get());
    }

    public Check check(ServerPlayer player, StageFootprint fp) {
        ServerLevel level = player.serverLevel();
        if (state != null) {
            return fail(Component.translatable("blockrave.place.exists", state.anchorX, state.anchorY, state.anchorZ, state.dimension));
        }
        if (!holdsKit(player)) return fail(Component.translatable("blockrave.place.no_kit"));
        if (!player.mayBuild()) return fail(Component.translatable("blockrave.place.no_build"));
        if (player.position().distanceTo(Vec3.atCenterOf(fp.anchor())) > 96) {
            return fail(Component.translatable("blockrave.place.too_far"));
        }
        if (fp.min().getY() < level.getMinBuildHeight() || fp.max().getY() >= level.getMaxBuildHeight()) {
            return fail(Component.translatable("blockrave.place.height", level.getMinBuildHeight(), level.getMaxBuildHeight() - 1));
        }
        WorldBorder border = level.getWorldBorder();
        if (!border.isWithinBounds(fp.min()) || !border.isWithinBounds(fp.max())
                || !border.isWithinBounds(new BlockPos(fp.min().getX(), 0, fp.max().getZ()))
                || !border.isWithinBounds(new BlockPos(fp.max().getX(), 0, fp.min().getZ()))) {
            return fail(Component.translatable("blockrave.place.border"));
        }
        for (int cx = fp.min().getX() >> 4; cx <= fp.max().getX() >> 4; cx++) {
            for (int cz = fp.min().getZ() >> 4; cz <= fp.max().getZ() >> 4; cz++) {
                if (!level.hasChunk(cx, cz)) return fail(Component.translatable("blockrave.place.unloaded"));
            }
        }
        for (int x = fp.min().getX(); x <= fp.max().getX(); x++) {
            for (int z = fp.min().getZ(); z <= fp.max().getZ(); z++) {
                if (!level.mayInteract(player, new BlockPos(x, fp.min().getY(), z))) {
                    return fail(Component.translatable("blockrave.place.protected"));
                }
            }
        }
        List<BlockPos> affected = fp.affectedPositions(level);
        Map<Block, Integer> counts = new HashMap<>();
        int containers = 0;
        for (BlockPos p : affected) {
            counts.merge(level.getBlockState(p).getBlock(), 1, Integer::sum);
            if (level.getBlockEntity(p) != null) containers++;
        }
        List<Map.Entry<Block, Integer>> sorted = new ArrayList<>(counts.entrySet());
        sorted.sort((a, b) -> b.getValue() - a.getValue());
        List<Component> summary = new ArrayList<>();
        for (int i = 0; i < Math.min(6, sorted.size()); i++) {
            var e = sorted.get(i);
            summary.add(Component.translatable("blockrave.place.summary_line", e.getValue(), e.getKey().getName()));
        }
        if (sorted.size() > 6) summary.add(Component.translatable("blockrave.place.summary_more", sorted.size() - 6));
        return new Check(true, Component.empty(), affected, containers, fp.worldHash(level), summary);
    }

    private static Check fail(Component c) {
        return new Check(false, c, List.of(), 0, 0, List.of());
    }

    private static long[] toLongs(List<BlockPos> list) {
        int n = Math.min(list.size(), Payloads.MAX_AFFECTED);
        long[] r = new long[n];
        for (int i = 0; i < n; i++) r[i] = list.get(i).asLong();
        return r;
    }

    /** Serverseitige Pruefung fuer die Bestaetigungsansicht. */
    public void handleCheck(ServerPlayer player, Payloads.PlacementCheck req) {
        StageFootprint fp = new StageFootprint(req.anchor(), req.rotation());
        Check c = check(player, fp);
        int status = c.ok() ? Payloads.PlacementResult.CHECK_OK : Payloads.PlacementResult.CHECK_FAILED;
        Net.send(player, new Payloads.PlacementResult(req.requestId(), status, c.error(), fp.anchor(),
                fp.rotation(), c.affected().size(), c.containers(), c.hash(), c.summary(), toLongs(c.affected())));
    }

    /** Ausdrueckliche Bestaetigung: erneute Pruefung, dann Aufbau. */
    public void handleConfirm(ServerPlayer player, Payloads.PlacementConfirm req) {
        StageFootprint fp = new StageFootprint(req.anchor(), req.rotation());
        ConfirmResult r = confirm(player, fp, req.hash());
        Check c = r.check();
        if (r.status() == Payloads.PlacementResult.CHANGED) {
            Net.send(player, new Payloads.PlacementResult(req.requestId(), r.status(), r.message(),
                    fp.anchor(), fp.rotation(), c.affected().size(), c.containers(), c.hash(), c.summary(), toLongs(c.affected())));
        } else {
            Net.send(player, new Payloads.PlacementResult(req.requestId(), r.status(), r.message(),
                    fp.anchor(), fp.rotation(), 0, 0, 0, List.of(), new long[0]));
        }
    }

    /**
     * Prueft erneut und baut auf, wenn der Weltzustand noch dem bestaetigten entspricht.
     * Verbraucht genau ein Bauset.
     */
    public ConfirmResult confirm(ServerPlayer player, StageFootprint fp, long confirmedHash) {
        Check c = check(player, fp);
        if (!c.ok()) return new ConfirmResult(Payloads.PlacementResult.PLACE_FAILED, c.error(), c);
        if (c.hash() != confirmedHash) {
            // Bereich hat sich seit der Bestaetigung veraendert: neue Bestaetigung verlangen.
            return new ConfirmResult(Payloads.PlacementResult.CHANGED, Component.translatable("blockrave.place.changed"), c);
        }
        ServerLevel level = player.serverLevel();
        // Schutzsysteme (Claims/Spawnschutz-Mods) fuer jeden zu entfernenden Block befragen
        for (BlockPos p : c.affected()) {
            BlockEvent.BreakEvent ev = new BlockEvent.BreakEvent(level, p, level.getBlockState(p), player);
            if (NeoForge.EVENT_BUS.post(ev).isCanceled()) {
                return new ConfirmResult(Payloads.PlacementResult.PLACE_FAILED, Component.translatable("blockrave.place.protected"), c);
            }
        }
        // Bauset verbrauchen (genau einmal) – erst danach wird gebaut.
        ItemStack kit = player.getMainHandItem().is(ModItems.STAGE_KIT.get()) ? player.getMainHandItem() : player.getOffhandItem();
        kit.shrink(1);
        place(level, fp, player);
        return new ConfirmResult(Payloads.PlacementResult.PLACED,
                Component.translatable("blockrave.place.done", c.affected().size()), c);
    }

    /** Gespeicherter Zustand (nur lesend verwenden). */
    public StageState state() {
        return state;
    }

    public StageFootprint footprint() {
        return footprint;
    }

    private void place(ServerLevel level, StageFootprint fp, ServerPlayer player) {
        long t0 = System.nanoTime();
        Map<Long, BlockState> partStates = new HashMap<>();
        for (StageFootprint.PlacedPart p : fp.parts()) partStates.put(p.pos().asLong(), p.state());
        // 1) Innenraum leeren (nur innerhalb des bestaetigten Quaders)
        BlockPos.MutableBlockPos m = new BlockPos.MutableBlockPos();
        for (int x = fp.min().getX(); x <= fp.max().getX(); x++) {
            for (int y = fp.min().getY(); y <= fp.max().getY(); y++) {
                for (int z = fp.min().getZ(); z <= fp.max().getZ(); z++) {
                    m.set(x, y, z);
                    if (!partStates.containsKey(m.asLong()) && !level.getBlockState(m).isAir()) {
                        level.setBlock(m, Blocks.AIR.defaultBlockState(), SET_FLAGS);
                    }
                }
            }
        }
        // 2) Bauteile setzen und erfassen
        LinkedHashMap<String, Integer> palette = new LinkedHashMap<>();
        long[] positions = new long[fp.parts().size()];
        int[] blocks = new int[fp.parts().size()];
        int i = 0;
        for (StageFootprint.PlacedPart p : fp.parts()) {
            level.setBlock(p.pos(), p.state(), SET_FLAGS);
            String name = BuiltInRegistries.BLOCK.getKey(p.state().getBlock()).toString();
            positions[i] = p.pos().asLong();
            blocks[i] = palette.computeIfAbsent(name, k -> palette.size());
            i++;
        }
        // Spieler, die im Buehnenquader stecken, vor die Buehne setzen
        Vec3 safe = Vec3.atBottomCenterOf(fp.worldPos(StageLayout.ANCHOR_X, 1, -2));
        for (ServerPlayer sp : level.players()) {
            BlockPos bp = sp.blockPosition();
            if (fp.contains(bp) && !level.getBlockState(bp).isAir()) sp.teleportTo(safe.x, safe.y, safe.z);
        }
        StageState s = new StageState();
        s.dimension = level.dimension().location().toString();
        s.anchorX = fp.anchor().getX();
        s.anchorY = fp.anchor().getY();
        s.anchorZ = fp.anchor().getZ();
        s.rotation = fp.rotation();
        s.boothPos = fp.boothPos().asLong();
        s.palette = new ArrayList<>(palette.keySet());
        s.positions = positions;
        s.blocks = blocks;
        s.placedBy = player.getGameProfile().getName();
        s.placedAtEpochMs = System.currentTimeMillis();
        this.state = s;
        this.footprint = fp;
        try {
            saveStage();
        } catch (IOException e) {
            BlockRave.LOG.error("BlockRave: Buehnenzustand konnte nicht gespeichert werden", e);
        }
        BlockRave.LOG.info("BlockRave: Buehne von {} in {} bei {} (Drehung {}) aufgebaut, {} Bauteile, {} ms",
                s.placedBy, s.dimension, fp.anchor().toShortString(), fp.rotation(), positions.length,
                (System.nanoTime() - t0) / 1_000_000);
        broadcastStageInfo();
        playback.onStageChanged();
    }

    public void broadcastStageInfo() {
        Net.sendToAll(server, stageInfo());
    }

    // ------------------------------------------------------------------ Einpacken

    /**
     * Wird beim Abbauversuch des DJ-Pults aufgerufen.
     *
     * @return true, wenn das normale Abbauen verhindert werden soll
     */
    public boolean onBoothBreak(ServerPlayer player, ServerLevel level, BlockPos pos) {
        if (!isStageBooth(level, pos)) return false; // verwaistes Pult (z.B. per Befehl gesetzt): normal entfernen
        if (!player.getMainHandItem().is(ItemTags.PICKAXES)) {
            player.displayClientMessage(Component.translatable("blockrave.pack.need_pickaxe"), true);
            return true;
        }
        if (!player.mayBuild()) {
            player.displayClientMessage(Component.translatable("blockrave.place.no_build"), true);
            return true;
        }
        Component err = packUp(level);
        if (err != null) {
            player.displayClientMessage(err, false);
            return true;
        }
        ItemStack kit = new ItemStack(ModItems.STAGE_KIT.get());
        if (!player.getInventory().add(kit)) player.drop(kit, false);
        player.displayClientMessage(Component.translatable("blockrave.pack.done"), false);
        BlockRave.LOG.info("BlockRave: Buehne von {} eingepackt", player.getGameProfile().getName());
        return true;
    }

    /** Entfernt die Buehne. Gibt eine Fehlermeldung zurueck oder null bei Erfolg. Gibt KEIN Item aus. */
    public Component packUp(ServerLevel level) {
        if (state == null) return Component.translatable("blockrave.pack.none");
        if (level == null || !level.dimension().location().toString().equals(state.dimension)) {
            return Component.translatable("blockrave.pack.wrong_dimension");
        }
        for (int cx = footprint.min().getX() >> 4; cx <= footprint.max().getX() >> 4; cx++) {
            for (int cz = footprint.min().getZ() >> 4; cz <= footprint.max().getZ() >> 4; cz++) {
                if (!level.hasChunk(cx, cz)) return Component.translatable("blockrave.pack.unloaded");
            }
        }
        playback.onStagePacked();
        Block[] palette = new Block[state.palette.size()];
        for (int i = 0; i < palette.length; i++) {
            palette[i] = BuiltInRegistries.BLOCK.get(ResourceLocation.parse(state.palette.get(i)));
        }
        int removed = 0, skipped = 0;
        BlockPos.MutableBlockPos m = new BlockPos.MutableBlockPos();
        for (int i = 0; i < state.positions.length; i++) {
            m.set(state.positions[i]);
            // Nur entfernen, wenn dort noch genau das erfasste Bauteil steht (fremde Bloecke bleiben).
            if (level.getBlockState(m).is(palette[state.blocks[i]])) {
                level.setBlock(m, Blocks.AIR.defaultBlockState(), SET_FLAGS);
                removed++;
            } else {
                skipped++;
            }
        }
        BlockRave.LOG.info("BlockRave: {} Bauteile entfernt, {} Positionen enthielten fremde Bloecke und blieben unveraendert",
                removed, skipped);
        state = null;
        footprint = null;
        try {
            saveStage();
        } catch (IOException e) {
            BlockRave.LOG.error("BlockRave: Buehnenzustand konnte nicht geloescht werden", e);
        }
        broadcastStageInfo();
        playback.onStageChanged();
        return null;
    }
}
