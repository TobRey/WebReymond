package de.blockrave.block;

import com.mojang.serialization.MapCodec;
import net.minecraft.world.level.block.RotatedPillarBlock;

/** Metalltraverse (Gitterrohr), Achse gibt die Laufrichtung an. */
public class TrussBlock extends RotatedPillarBlock {
    public static final MapCodec<TrussBlock> CODEC = simpleCodec(TrussBlock::new);

    public TrussBlock(Properties props) {
        super(props);
    }

    @Override
    public MapCodec<? extends RotatedPillarBlock> codec() {
        return CODEC;
    }
}
