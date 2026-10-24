package de.blockrave.registry;

import de.blockrave.BlockRave;
import de.blockrave.block.DjBoothBlock;
import de.blockrave.block.FacingStageBlock;
import de.blockrave.block.StageBlock;
import de.blockrave.block.StageStepsBlock;
import de.blockrave.block.TrussBlock;
import de.blockrave.core.stage.PartType;
import net.minecraft.world.level.block.Block;
import net.minecraft.world.level.block.SoundType;
import net.minecraft.world.level.block.state.BlockBehaviour;
import net.minecraft.world.level.material.MapColor;
import net.minecraft.world.level.material.PushReaction;
import net.neoforged.neoforge.registries.DeferredBlock;
import net.neoforged.neoforge.registries.DeferredRegister;

import java.util.EnumMap;
import java.util.Map;

public final class ModBlocks {
    public static final DeferredRegister.Blocks BLOCKS = DeferredRegister.createBlocks(BlockRave.MOD_ID);

    /** Buehnenteile: nur ueber das DJ-Pult abbaubar (Einpacken), keine Drops, nicht verschiebbar. */
    private static BlockBehaviour.Properties part(MapColor color, SoundType sound, int light) {
        return BlockBehaviour.Properties.of()
                .mapColor(color)
                .strength(-1.0F, 3_600_000.0F)
                .noLootTable()
                .sound(sound)
                .lightLevel(s -> light)
                .pushReaction(PushReaction.BLOCK)
                .isValidSpawn((s, l, p, e) -> false);
    }

    public static final DeferredBlock<StageBlock> DANCE_FLOOR = BLOCKS.registerBlock("dance_floor",
            StageBlock::new, part(MapColor.COLOR_PURPLE, SoundType.GLASS, 7));
    public static final DeferredBlock<StageBlock> STAGE_DECK = BLOCKS.registerBlock("stage_deck",
            StageBlock::new, part(MapColor.COLOR_BLACK, SoundType.METAL, 0));
    public static final DeferredBlock<FacingStageBlock> STAGE_FRONT = BLOCKS.registerBlock("stage_front",
            p -> new FacingStageBlock(p, FacingStageBlock.Shape.FULL), part(MapColor.SNOW, SoundType.GLASS, 13));
    public static final DeferredBlock<StageStepsBlock> STAGE_STEPS = BLOCKS.registerBlock("stage_steps",
            StageStepsBlock::new, part(MapColor.COLOR_BLACK, SoundType.METAL, 0));
    public static final DeferredBlock<FacingStageBlock> SPEAKER = BLOCKS.registerBlock("speaker",
            p -> new FacingStageBlock(p, FacingStageBlock.Shape.FULL), part(MapColor.COLOR_BLACK, SoundType.WOOD, 0));
    public static final DeferredBlock<TrussBlock> TRUSS = BLOCKS.registerBlock("truss",
            TrussBlock::new, part(MapColor.METAL, SoundType.METAL, 0).noOcclusion());
    public static final DeferredBlock<FacingStageBlock> LED_PANEL = BLOCKS.registerBlock("led_panel",
            p -> new FacingStageBlock(p, FacingStageBlock.Shape.FULL), part(MapColor.COLOR_BLACK, SoundType.GLASS, 9));
    public static final DeferredBlock<FacingStageBlock> MOVING_HEAD = BLOCKS.registerBlock("moving_head",
            p -> new FacingStageBlock(p, FacingStageBlock.Shape.FIXTURE_FLOOR), part(MapColor.COLOR_GRAY, SoundType.METAL, 5).noOcclusion());
    public static final DeferredBlock<FacingStageBlock> MOVING_HEAD_HANGING = BLOCKS.registerBlock("moving_head_hanging",
            p -> new FacingStageBlock(p, FacingStageBlock.Shape.FIXTURE_HANGING), part(MapColor.COLOR_GRAY, SoundType.METAL, 5).noOcclusion());
    public static final DeferredBlock<FacingStageBlock> LASER = BLOCKS.registerBlock("laser",
            p -> new FacingStageBlock(p, FacingStageBlock.Shape.SMALL_BOX), part(MapColor.COLOR_GRAY, SoundType.METAL, 4).noOcclusion());
    public static final DeferredBlock<FacingStageBlock> STROBE = BLOCKS.registerBlock("strobe",
            p -> new FacingStageBlock(p, FacingStageBlock.Shape.SMALL_BOX), part(MapColor.COLOR_GRAY, SoundType.METAL, 4).noOcclusion());
    public static final DeferredBlock<FacingStageBlock> FOG_MACHINE = BLOCKS.registerBlock("fog_machine",
            p -> new FacingStageBlock(p, FacingStageBlock.Shape.LOW_BOX), part(MapColor.COLOR_GRAY, SoundType.METAL, 0).noOcclusion());
    public static final DeferredBlock<FacingStageBlock> DJ_BOOTH_SIDE = BLOCKS.registerBlock("dj_booth_side",
            p -> new FacingStageBlock(p, FacingStageBlock.Shape.DESK), part(MapColor.COLOR_BLACK, SoundType.METAL, 6).noOcclusion());
    /** Zentrales DJ-Pult: Rechtsklick oeffnet die Bedienung, mit Spitzhacke wird die Buehne eingepackt. */
    public static final DeferredBlock<DjBoothBlock> DJ_BOOTH = BLOCKS.registerBlock("dj_booth",
            DjBoothBlock::new, BlockBehaviour.Properties.of()
                    .mapColor(MapColor.COLOR_BLACK)
                    .strength(2.0F, 3_600_000.0F)
                    .requiresCorrectToolForDrops()
                    .noLootTable()
                    .sound(SoundType.METAL)
                    .lightLevel(s -> 6)
                    .noOcclusion()
                    .pushReaction(PushReaction.BLOCK));

    private ModBlocks() {}

    /** Zuordnung Bauplan-Teil -&gt; Block. */
    public static Block blockFor(PartType type) {
        return switch (type) {
            case DANCE_FLOOR -> DANCE_FLOOR.get();
            case STAGE_DECK -> STAGE_DECK.get();
            case STAGE_FRONT -> STAGE_FRONT.get();
            case STAGE_STEPS -> STAGE_STEPS.get();
            case SPEAKER -> SPEAKER.get();
            case TRUSS_VERTICAL, TRUSS_ALONG_X, TRUSS_ALONG_Z -> TRUSS.get();
            case LED_PANEL -> LED_PANEL.get();
            case MOVING_HEAD -> MOVING_HEAD.get();
            case MOVING_HEAD_HANGING -> MOVING_HEAD_HANGING.get();
            case LASER -> LASER.get();
            case STROBE -> STROBE.get();
            case FOG_MACHINE -> FOG_MACHINE.get();
            case DJ_BOOTH -> DJ_BOOTH.get();
            case DJ_BOOTH_SIDE -> DJ_BOOTH_SIDE.get();
        };
    }

    public static Map<PartType, Block> allParts() {
        Map<PartType, Block> m = new EnumMap<>(PartType.class);
        for (PartType t : PartType.values()) m.put(t, blockFor(t));
        return m;
    }
}
