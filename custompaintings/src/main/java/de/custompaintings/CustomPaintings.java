package de.custompaintings;

import com.mojang.logging.LogUtils;
import de.custompaintings.network.Network;
import de.custompaintings.server.ServerEvents;
import net.minecraft.resources.ResourceLocation;
import net.neoforged.bus.api.IEventBus;
import net.neoforged.fml.ModContainer;
import net.neoforged.fml.common.Mod;
import net.neoforged.fml.config.ModConfig;
import net.neoforged.neoforge.common.NeoForge;
import org.slf4j.Logger;

/**
 * Custom Paintings &ndash; eigene Bilder auf normalen Minecraft-Gemaelden.
 *
 * <p>Gemeinsamer Einstiegspunkt fuer Client und dedizierten Server. Clientcode liegt ausschliesslich im
 * Paket {@code de.custompaintings.client} und wird nur ueber den {@code Dist.CLIENT}-Einstiegspunkt geladen.
 */
@Mod(CustomPaintings.MOD_ID)
public final class CustomPaintings {
    public static final String MOD_ID = "custompaintings";
    public static final Logger LOG = LogUtils.getLogger();

    public CustomPaintings(IEventBus modBus, ModContainer container) {
        ModAttachments.TYPES.register(modBus);
        modBus.addListener(Network::register);
        container.registerConfig(ModConfig.Type.SERVER, Config.SERVER_SPEC);
        container.registerConfig(ModConfig.Type.CLIENT, Config.CLIENT_SPEC);
        NeoForge.EVENT_BUS.register(ServerEvents.class);
    }

    public static ResourceLocation id(String path) {
        return ResourceLocation.fromNamespaceAndPath(MOD_ID, path);
    }
}
