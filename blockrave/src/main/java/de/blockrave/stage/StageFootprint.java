package de.blockrave.stage;

import de.blockrave.core.stage.LocalDir;
import de.blockrave.core.stage.PartType;
import de.blockrave.core.stage.StageLayout;
import de.blockrave.core.stage.StagePart;
import de.blockrave.core.stage.StageTransform;
import de.blockrave.registry.ModBlocks;
import net.minecraft.core.BlockPos;
import net.minecraft.core.Direction;
import net.minecraft.world.level.BlockGetter;
import net.minecraft.world.level.block.Block;
import net.minecraft.world.level.block.HorizontalDirectionalBlock;
import net.minecraft.world.level.block.RotatedPillarBlock;
import net.minecraft.world.level.block.StairBlock;
import net.minecraft.world.level.block.state.BlockState;

import java.util.ArrayList;
import java.util.List;

/**
 * Exakter Eingriffsbereich einer Buehne an einer Position mit Ausrichtung. Wird identisch von der
 * Client-Vorschau und der serverseitigen Pruefung verwendet: Jede Position im Buehnenquader wird
 * entweder durch ein Bauteil ersetzt oder geleert (Innenraum). Ausserhalb wird nichts veraendert.
 */
public final class StageFootprint {
    public record PlacedPart(BlockPos pos, BlockState state, PartType type) {}

    private final BlockPos anchor;
    private final int rotation;
    private final StageTransform transform;
    private final BlockPos min;
    private final BlockPos max;
    private final List<PlacedPart> parts;
    private final BlockPos boothPos;

    public StageFootprint(BlockPos anchor, int rotation) {
        this.anchor = anchor.immutable();
        this.rotation = Math.floorMod(rotation, 4);
        this.transform = new StageTransform(anchor.getX(), anchor.getY(), anchor.getZ(), this.rotation);
        int[] b = transform.bounds();
        this.min = new BlockPos(b[0], b[1], b[2]);
        this.max = new BlockPos(b[3], b[4], b[5]);
        List<PlacedPart> list = new ArrayList<>();
        BlockPos booth = null;
        for (StagePart p : StageLayout.get().parts()) {
            BlockPos pos = worldPos(p.x(), p.y(), p.z());
            BlockState st = stateFor(p);
            list.add(new PlacedPart(pos, st, p.type()));
            if (p.type() == PartType.DJ_BOOTH) booth = pos;
        }
        this.parts = List.copyOf(list);
        this.boothPos = booth;
    }

    public BlockPos worldPos(int lx, int ly, int lz) {
        return new BlockPos(transform.worldX(lx, lz), transform.worldY(ly), transform.worldZ(lx, lz));
    }

    private Direction worldDir(LocalDir d) {
        int idx = transform.worldHorizontalIndex(d);
        if (idx == -1) return Direction.UP;
        if (idx == -2) return Direction.DOWN;
        return Direction.from2DDataValue(idx);
    }

    private BlockState stateFor(StagePart p) {
        Block block = ModBlocks.blockFor(p.type());
        BlockState s = block.defaultBlockState();
        switch (p.type()) {
            case TRUSS_VERTICAL -> s = s.setValue(RotatedPillarBlock.AXIS, Direction.Axis.Y);
            case TRUSS_ALONG_X -> s = s.setValue(RotatedPillarBlock.AXIS, worldDir(LocalDir.RIGHT).getAxis());
            case TRUSS_ALONG_Z -> s = s.setValue(RotatedPillarBlock.AXIS, worldDir(LocalDir.BACK).getAxis());
            case STAGE_STEPS -> s = s.setValue(StairBlock.FACING, worldDir(p.facing()));
            default -> {
                if (s.hasProperty(HorizontalDirectionalBlock.FACING) && p.facing() != LocalDir.UP && p.facing() != LocalDir.DOWN) {
                    s = s.setValue(HorizontalDirectionalBlock.FACING, worldDir(p.facing()));
                }
            }
        }
        return s;
    }

    public BlockPos anchor() { return anchor; }
    public int rotation() { return rotation; }
    public StageTransform transform() { return transform; }
    public BlockPos min() { return min; }
    public BlockPos max() { return max; }
    public List<PlacedPart> parts() { return parts; }
    public BlockPos boothPos() { return boothPos; }

    public boolean contains(BlockPos p) {
        return p.getX() >= min.getX() && p.getX() <= max.getX() && p.getY() >= min.getY() && p.getY() <= max.getY()
                && p.getZ() >= min.getZ() && p.getZ() <= max.getZ();
    }

    /** Alle Positionen, an denen sich aktuell etwas befindet (Block oder Fluessigkeit), das entfernt/ersetzt wuerde. */
    public List<BlockPos> affectedPositions(BlockGetter level) {
        List<BlockPos> out = new ArrayList<>();
        BlockPos.MutableBlockPos m = new BlockPos.MutableBlockPos();
        for (int x = min.getX(); x <= max.getX(); x++) {
            for (int y = min.getY(); y <= max.getY(); y++) {
                for (int z = min.getZ(); z <= max.getZ(); z++) {
                    m.set(x, y, z);
                    BlockState st = level.getBlockState(m);
                    if (!st.isAir()) out.add(m.immutable());
                }
            }
        }
        return out;
    }

    /** Pruefsumme ueber den kompletten Quader (Blockzustaende und Blockentities). */
    public long worldHash(BlockGetter level) {
        long h = 0xcbf29ce484222325L ^ anchor.asLong() ^ ((long) rotation << 60);
        BlockPos.MutableBlockPos m = new BlockPos.MutableBlockPos();
        for (int x = min.getX(); x <= max.getX(); x++) {
            for (int y = min.getY(); y <= max.getY(); y++) {
                for (int z = min.getZ(); z <= max.getZ(); z++) {
                    m.set(x, y, z);
                    BlockState st = level.getBlockState(m);
                    int id = Block.getId(st);
                    h ^= id + (level.getBlockEntity(m) != null ? 0x40000000L : 0);
                    h *= 0x100000001b3L;
                    h ^= m.asLong();
                    h *= 0x100000001b3L;
                }
            }
        }
        return h;
    }
}
