package de.blockrave.block;

import com.mojang.serialization.MapCodec;
import de.blockrave.menu.DjBoothMenu;
import de.blockrave.stage.StageManager;
import net.minecraft.core.BlockPos;
import net.minecraft.core.Direction;
import net.minecraft.network.chat.Component;
import net.minecraft.server.level.ServerPlayer;
import net.minecraft.world.InteractionResult;
import net.minecraft.world.SimpleMenuProvider;
import net.minecraft.world.entity.player.Player;
import net.minecraft.world.item.context.BlockPlaceContext;
import net.minecraft.world.level.BlockGetter;
import net.minecraft.world.level.Level;
import net.minecraft.world.level.block.Block;
import net.minecraft.world.level.block.HorizontalDirectionalBlock;
import net.minecraft.world.level.block.state.BlockState;
import net.minecraft.world.level.block.state.StateDefinition;
import net.minecraft.world.phys.BlockHitResult;
import net.minecraft.world.phys.shapes.CollisionContext;
import net.minecraft.world.phys.shapes.VoxelShape;

/**
 * Zentrales DJ-Pult. Rechtsklick oeffnet die Bedienoberflaeche (fuer alle Spieler, ohne OP).
 * Abbauen mit einer Spitzhacke packt die gesamte Buehne ein (siehe {@link StageManager}).
 */
public class DjBoothBlock extends HorizontalDirectionalBlock {
    public static final MapCodec<DjBoothBlock> CODEC = simpleCodec(DjBoothBlock::new);
    private static final VoxelShape SHAPE = box(0, 0, 0, 16, 14, 16);

    public DjBoothBlock(Properties props) {
        super(props);
        registerDefaultState(stateDefinition.any().setValue(FACING, Direction.NORTH));
    }

    @Override
    protected MapCodec<? extends HorizontalDirectionalBlock> codec() {
        return CODEC;
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
        return SHAPE;
    }

    @Override
    protected InteractionResult useWithoutItem(BlockState state, Level level, BlockPos pos, Player player, BlockHitResult hit) {
        if (level.isClientSide) return InteractionResult.SUCCESS;
        if (player instanceof ServerPlayer sp) {
            StageManager manager = StageManager.get(sp.server);
            if (!manager.isStageBooth(level, pos)) {
                sp.displayClientMessage(Component.translatable("blockrave.msg.booth_not_registered"), true);
                return InteractionResult.CONSUME;
            }
            sp.openMenu(new SimpleMenuProvider((id, inv, p) -> new DjBoothMenu(id, inv, pos),
                    Component.translatable("container.blockrave.dj_booth")), buf -> buf.writeBlockPos(pos));
            manager.playback().onMenuOpened(sp);
        }
        return InteractionResult.CONSUME;
    }
}
