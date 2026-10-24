package de.blockrave.client.placement;

import de.blockrave.client.ClientNet;
import de.blockrave.client.screen.PlacementConfirmScreen;
import de.blockrave.network.Payloads;
import de.blockrave.registry.ModItems;
import de.blockrave.stage.StageFootprint;
import net.minecraft.client.Minecraft;
import net.minecraft.core.BlockPos;
import net.minecraft.network.chat.Component;
import net.minecraft.world.phys.BlockHitResult;
import net.minecraft.world.phys.HitResult;

import java.util.HashSet;
import java.util.List;
import java.util.Set;

/**
 * Clientseitige Bauvorschau: Position folgt dem Blick, Drehung per Taste, Bestaetigung per erneutem
 * Rechtsklick (oeffnet die Bestaetigungsansicht mit serverseitiger Pruefung), Abbruch per Taste.
 * Ohne ausdrueckliche Bestaetigung wird nichts an der Welt veraendert.
 */
public final class PlacementController {
    public static final PlacementController INSTANCE = new PlacementController();

    private boolean active;
    private boolean locked;
    private BlockPos anchor;
    private int rotation;
    private StageFootprint footprint;
    private Set<BlockPos> conflicts = Set.of();
    private boolean serverMarked;
    private int requestId;
    private Payloads.PlacementResult lastResult;
    private int dirtyTicks;
    private long revision;

    private PlacementController() {}

    public boolean isActive() {
        return active;
    }

    public boolean isLocked() {
        return locked;
    }

    public StageFootprint footprint() {
        return footprint;
    }

    public Set<BlockPos> conflicts() {
        return conflicts;
    }

    public boolean serverMarked() {
        return serverMarked;
    }

    /** Aendert sich bei jeder Aenderung von Position/Drehung/Markierung (fuer Render-Caches). */
    public long revision() {
        return revision;
    }

    public int rotation() {
        return rotation;
    }

    public Payloads.PlacementResult lastResult() {
        return lastResult;
    }

    public static boolean holdsKit() {
        var p = Minecraft.getInstance().player;
        return p != null && (p.getMainHandItem().is(ModItems.STAGE_KIT.get()) || p.getOffhandItem().is(ModItems.STAGE_KIT.get()));
    }

    /** Rechtsklick mit dem Bauset. */
    public void onUse() {
        Minecraft mc = Minecraft.getInstance();
        if (mc.player == null) return;
        if (!active) {
            active = true;
            locked = false;
            rotation = mc.player.getDirection().get2DDataValue();
            anchor = null;
            updateAnchor();
            mc.player.displayClientMessage(Component.translatable("blockrave.preview.started"), true);
            return;
        }
        if (anchor != null && !locked) openConfirm();
    }

    public void rotate() {
        if (!active || locked) return;
        rotation = (rotation + 1) % 4;
        recompute();
    }

    public void cancel() {
        if (!active) return;
        active = false;
        locked = false;
        footprint = null;
        conflicts = Set.of();
        lastResult = null;
        revision++;
        var p = Minecraft.getInstance().player;
        if (p != null) p.displayClientMessage(Component.translatable("blockrave.preview.cancelled"), true);
    }

    /** Zurueck aus der Bestaetigung in die freie Vorschau. */
    public void unlock() {
        locked = false;
        serverMarked = false;
        lastResult = null;
        recompute();
    }

    private void openConfirm() {
        locked = true;
        lastResult = null;
        requestCheck();
        Minecraft.getInstance().setScreen(new PlacementConfirmScreen(this));
    }

    public void requestCheck() {
        if (anchor == null) return;
        requestId++;
        ClientNet.send(new Payloads.PlacementCheck(requestId, anchor, rotation));
    }

    public void confirm() {
        Payloads.PlacementResult r = lastResult;
        if (r == null || anchor == null) return;
        if (r.status() != Payloads.PlacementResult.CHECK_OK && r.status() != Payloads.PlacementResult.CHANGED) return;
        requestId++;
        ClientNet.send(new Payloads.PlacementConfirm(requestId, anchor, rotation, r.hash()));
    }

    public void tick() {
        if (!active) return;
        Minecraft mc = Minecraft.getInstance();
        if (mc.player == null || !holdsKit()) {
            cancel();
            return;
        }
        if (!locked) {
            updateAnchor();
            if (dirtyTicks > 0 && --dirtyTicks == 0) computeConflicts();
        }
    }

    private void updateAnchor() {
        Minecraft mc = Minecraft.getInstance();
        HitResult hit = mc.player.pick(64, 1f, false);
        if (hit.getType() != HitResult.Type.BLOCK) return;
        // Der Buehnenboden ersetzt die Schicht des angeblickten Blocks (vordere Mitte der Tanzflaeche).
        BlockPos pos = ((BlockHitResult) hit).getBlockPos().immutable();
        if (!pos.equals(anchor)) {
            anchor = pos;
            recompute();
        }
    }

    private void recompute() {
        if (anchor == null) return;
        footprint = new StageFootprint(anchor, rotation);
        conflicts = Set.of();
        serverMarked = false;
        dirtyTicks = 4; // kurz entprellen, dann betroffene Bloecke berechnen
        revision++;
    }

    private void computeConflicts() {
        var level = Minecraft.getInstance().level;
        if (level == null || footprint == null) return;
        List<BlockPos> list = footprint.affectedPositions(level);
        conflicts = new HashSet<>(list);
        revision++;
    }

    /** Antwort des Servers (Pruefung, Aenderung, Aufbau). */
    public void onResult(Payloads.PlacementResult r) {
        if (!active) return;
        lastResult = r;
        switch (r.status()) {
            case Payloads.PlacementResult.CHECK_OK, Payloads.PlacementResult.CHANGED -> {
                // Massgeblich ist die Markierung des Servers (exakt die Bloecke, die entfernt werden)
                Set<BlockPos> s = new HashSet<>();
                for (long l : r.affected()) s.add(BlockPos.of(l));
                conflicts = s;
                serverMarked = true;
                revision++;
            }
            case Payloads.PlacementResult.PLACED -> {
                active = false;
                locked = false;
                footprint = null;
                conflicts = Set.of();
                revision++;
                Minecraft mc = Minecraft.getInstance();
                mc.setScreen(null);
                if (mc.player != null) mc.player.displayClientMessage(r.message(), false);
            }
            default -> {}
        }
        if (Minecraft.getInstance().screen instanceof PlacementConfirmScreen s) s.onResult(r);
    }

    public BlockPos anchor() {
        return anchor;
    }
}
