package de.blockrave.block;

import com.mojang.serialization.MapCodec;
import net.minecraft.world.level.block.Block;

/** Einfaches Buehnenteil ohne Ausrichtung (Boden, Plattform). */
public class StageBlock extends Block {
    public static final MapCodec<StageBlock> CODEC = simpleCodec(StageBlock::new);

    public StageBlock(Properties props) {
        super(props);
    }

    @Override
    protected MapCodec<? extends Block> codec() {
        return CODEC;
    }
}
