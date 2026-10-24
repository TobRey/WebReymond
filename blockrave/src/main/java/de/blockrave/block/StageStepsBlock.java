package de.blockrave.block;

import com.mojang.serialization.MapCodec;
import net.minecraft.world.level.block.Blocks;
import net.minecraft.world.level.block.StairBlock;

/** Buehnentreppe. */
public class StageStepsBlock extends StairBlock {
    public static final MapCodec<StageStepsBlock> CODEC = simpleCodec(StageStepsBlock::new);

    public StageStepsBlock(Properties props) {
        super(Blocks.POLISHED_BLACKSTONE.defaultBlockState(), props);
    }

    @Override
    public MapCodec<? extends StairBlock> codec() {
        return CODEC;
    }
}
