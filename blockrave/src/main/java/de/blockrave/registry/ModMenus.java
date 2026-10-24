package de.blockrave.registry;

import de.blockrave.BlockRave;
import de.blockrave.menu.DjBoothMenu;
import net.minecraft.core.registries.Registries;
import net.minecraft.world.inventory.MenuType;
import net.neoforged.neoforge.common.extensions.IMenuTypeExtension;
import net.neoforged.neoforge.registries.DeferredHolder;
import net.neoforged.neoforge.registries.DeferredRegister;

public final class ModMenus {
    public static final DeferredRegister<MenuType<?>> MENUS = DeferredRegister.create(Registries.MENU, BlockRave.MOD_ID);

    public static final DeferredHolder<MenuType<?>, MenuType<DjBoothMenu>> DJ_BOOTH =
            MENUS.register("dj_booth", () -> IMenuTypeExtension.create(DjBoothMenu::new));

    private ModMenus() {}
}
