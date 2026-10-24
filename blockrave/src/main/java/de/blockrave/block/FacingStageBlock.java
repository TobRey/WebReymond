package de.blockrave.block;

import com.mojang.serialization.MapCodec;
import net.minecraft.core.BlockPos;
import net.minecraft.core.Direction;
import net.minecraft.world.item.context.BlockPlaceContext;
import net.minecraft.world.level.BlockGetter;
import net.minecraft.world.level.block.Block;
import net.minecraft.world.level.block.HorizontalDirectionalBlock;
import net.minecraft.world.level.block.state.BlockState;
import net.minecraft.world.level.block.state.StateDefinition;
import net.minecraft.world.phys.shapes.CollisionContext;
import net.minecraft.world.phys.shapes.Shapes;
import net.minecraft.world.phys.shapes.VoxelShape;

/** Ausgerichtetes Buehnenteil (Lautsprecher, LED-Panel, Scheinwerfer ...). FACING zeigt zum Publikum. */
public class FacingStageBlock extends HorizontalDirectionalBlock {
    public enum Shape {
        FULL(Shapes.block()),
        FIXTURE_FLOOR(Shapes.or(box(3, 0, 3, 13, 3, 13), box(4, 3, 4, 12, 13, 12))),
        FIXTURE_HANGING(Shapes.or(box(3, 13, 3, 13, 16, 13), box(4, 3, 4, 12, 13, 12))),
        SMALL_BOX(box(2, 2, 2, 14, 14, 14)),
        LOW_BOX(box(2, 0, 2, 14, 9, 14)),
        DESK(box(0, 0, 0, 16, 14, 16));

        final VoxelShape shape;

        Shape(VoxelShape s) {
            this.shape = s;
        }
    }

    private final Shape shape;
    private final MapCodec<FacingStageBlock> codec;

    public FacingStageBlock(Properties props, Shape shape) {
        super(props);
        this.shape = shape;
        this.codec = simpleCodec(p -> new FacingStageBlock(p, shape));
        registerDefaultState(stateDefinition.any().setValue(FACING, Direction.NORTH));
    }

    @Override
    protected MapCodec<? extends HorizontalDirectionalBlock> codec() {
        return codec;
    }

    @Override
    protected void createBlockStateDefinition(StateDefinition.Builder<Block, BlockState> builder) {
        builder.add(FACING);
    }

    @Override
    public BlockState getStateForPlacement(BlockPlaceContext ctx) {
        return defaultBlockState().setValue(FACING, ctx.getHorizontalDirection().getOpposite());
    }

    @Override
    protected VoxelShape getShape(BlockState state, BlockGetter level, BlockPos pos, CollisionContext ctx) {
        return shape.shape;
    }
}
