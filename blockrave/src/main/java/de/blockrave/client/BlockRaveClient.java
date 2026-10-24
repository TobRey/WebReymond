package de.blockrave.client;

import com.mojang.blaze3d.platform.InputConstants;
import de.blockrave.BlockRave;
import de.blockrave.client.screen.DjBoothScreen;
import de.blockrave.network.ClientBridge;
import de.blockrave.registry.ModMenus;
import net.minecraft.client.KeyMapping;
import net.neoforged.api.distmarker.Dist;
import net.neoforged.bus.api.IEventBus;
import net.neoforged.fml.ModContainer;
import net.neoforged.fml.common.Mod;
import net.neoforged.neoforge.client.event.RegisterGuiLayersEvent;
import net.neoforged.neoforge.client.event.RegisterKeyMappingsEvent;
import net.neoforged.neoforge.client.event.RegisterMenuScreensEvent;
import net.neoforged.neoforge.client.gui.ConfigurationScreen;
import net.neoforged.neoforge.client.gui.IConfigScreenFactory;
import net.neoforged.neoforge.client.settings.KeyConflictContext;
import org.lwjgl.glfw.GLFW;

/** Client-Einstiegspunkt (wird auf dedizierten Servern nie geladen). */
@Mod(value = BlockRave.MOD_ID, dist = Dist.CLIENT)
public final class BlockRaveClient {
    public static final String KEY_CATEGORY = "key.categories.blockrave";
    public static final KeyMapping ROTATE = new KeyMapping("key.blockrave.rotate", KeyConflictContext.IN_GAME,
            InputConstants.Type.KEYSYM, GLFW.GLFW_KEY_R, KEY_CATEGORY);
    public static final KeyMapping CANCEL = new KeyMapping("key.blockrave.cancel", KeyConflictContext.IN_GAME,
            InputConstants.Type.KEYSYM, GLFW.GLFW_KEY_BACKSPACE, KEY_CATEGORY);
    public static final KeyMapping MUTE = new KeyMapping("key.blockrave.mute", KeyConflictContext.IN_GAME,
            InputConstants.Type.KEYSYM, GLFW.GLFW_KEY_M, KEY_CATEGORY);
    public static final KeyMapping SETTINGS = new KeyMapping("key.blockrave.settings", KeyConflictContext.IN_GAME,
            InputConstants.UNKNOWN.getType(), InputConstants.UNKNOWN.getValue(), KEY_CATEGORY);

    public BlockRaveClient(IEventBus modBus, ModContainer container) {
        ClientBridge.Holder.set(new ClientBridgeImpl());
        container.registerExtensionPoint(IConfigScreenFactory.class, ConfigurationScreen::new);
        modBus.addListener(BlockRaveClient::registerScreens);
        modBus.addListener(BlockRaveClient::registerKeys);
        modBus.addListener(BlockRaveClient::registerLayers);
    }

    private static void registerScreens(RegisterMenuScreensEvent e) {
        e.register(ModMenus.DJ_BOOTH.get(), DjBoothScreen::new);
    }

    private static void registerKeys(RegisterKeyMappingsEvent e) {
        e.register(ROTATE);
        e.register(CANCEL);
        e.register(MUTE);
        e.register(SETTINGS);
    }

    private static void registerLayers(RegisterGuiLayersEvent e) {
        e.registerAboveAll(BlockRave.id("hud"), new RaveHud());
    }
}
