package de.blockrave.registry;

import de.blockrave.BlockRave;
import net.minecraft.core.registries.Registries;
import net.minecraft.network.chat.Component;
import net.minecraft.world.item.CreativeModeTab;
import net.neoforged.neoforge.registries.DeferredHolder;
import net.neoforged.neoforge.registries.DeferredRegister;

public final class ModCreativeTabs {
    public static final DeferredRegister<CreativeModeTab> TABS = DeferredRegister.create(Registries.CREATIVE_MODE_TAB, BlockRave.MOD_ID);

    public static final DeferredHolder<CreativeModeTab, CreativeModeTab> MAIN = TABS.register("main", () -> CreativeModeTab.builder()
            .title(Component.translatable("itemGroup.blockrave"))
            .icon(() -> ModItems.STAGE_KIT.get().getDefaultInstance())
            .displayItems((params, out) -> out.accept(ModItems.STAGE_KIT.get()))
            .build());

    private ModCreativeTabs() {}
}
