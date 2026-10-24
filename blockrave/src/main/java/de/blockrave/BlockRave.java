package de.blockrave;

import com.mojang.logging.LogUtils;
import de.blockrave.network.BlockRaveNetwork;
import de.blockrave.registry.ModBlocks;
import de.blockrave.registry.ModCreativeTabs;
import de.blockrave.registry.ModItems;
import de.blockrave.registry.ModMenus;
import net.minecraft.resources.ResourceLocation;
import net.neoforged.bus.api.IEventBus;
import net.neoforged.fml.ModContainer;
import net.neoforged.fml.common.Mod;
import net.neoforged.fml.config.ModConfig;
import net.neoforged.fml.event.config.ModConfigEvent;
import org.slf4j.Logger;

/**
 * BlockRave &ndash; Festivalbuehne mit DJ-Pult, synchronisierter Musik und automatischer Lichtshow.
 *
 * <p>Gemeinsamer Einstiegspunkt fuer Client und dedizierten Server. Clientcode liegt ausschliesslich
 * im Paket {@code de.blockrave.client} und wird nur ueber {@code Dist.CLIENT}-Einstiegspunkte geladen.
 */
@Mod(BlockRave.MOD_ID)
public final class BlockRave {
    public static final String MOD_ID = "blockrave";
    public static final Logger LOG = LogUtils.getLogger();

    public BlockRave(IEventBus modBus, ModContainer container) {
        ModBlocks.BLOCKS.register(modBus);
        ModItems.ITEMS.register(modBus);
        ModMenus.MENUS.register(modBus);
        ModCreativeTabs.TABS.register(modBus);
        modBus.addListener(BlockRaveNetwork::register);
        container.registerConfig(ModConfig.Type.SERVER, BlockRaveConfig.SERVER_SPEC);
        container.registerConfig(ModConfig.Type.CLIENT, BlockRaveConfig.CLIENT_SPEC);
        modBus.addListener(ModConfigEvent.Reloading.class, e -> {
            if (e.getConfig().getSpec() == BlockRaveConfig.SERVER_SPEC) {
                var m = de.blockrave.stage.StageManager.getOrNull();
                if (m != null) m.server().execute(() -> m.playback().applyConfig());
            }
        });
    }

    public static ResourceLocation id(String path) {
        return ResourceLocation.fromNamespaceAndPath(MOD_ID, path);
    }
}
