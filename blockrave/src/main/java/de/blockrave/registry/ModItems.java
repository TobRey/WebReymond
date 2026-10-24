package de.blockrave.registry;

import de.blockrave.BlockRave;
import de.blockrave.item.StageKitItem;
import net.minecraft.world.item.Item;
import net.minecraft.world.item.Rarity;
import net.neoforged.neoforge.registries.DeferredItem;
import net.neoforged.neoforge.registries.DeferredRegister;

public final class ModItems {
    public static final DeferredRegister.Items ITEMS = DeferredRegister.createItems(BlockRave.MOD_ID);

    /** Verpackte Festivalbuehne mit DJ-Pult (craftbar). */
    public static final DeferredItem<StageKitItem> STAGE_KIT = ITEMS.registerItem("stage_kit", StageKitItem::new,
            new Item.Properties().stacksTo(1).rarity(Rarity.EPIC).fireResistant());

    private ModItems() {}
}
