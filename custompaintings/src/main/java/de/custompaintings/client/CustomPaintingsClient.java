package de.custompaintings.client;

import de.custompaintings.CustomPaintings;
import de.custompaintings.network.ClientBridge;
import net.minecraft.world.entity.EntityType;
import net.neoforged.api.distmarker.Dist;
import net.neoforged.bus.api.IEventBus;
import net.neoforged.fml.ModContainer;
import net.neoforged.fml.common.Mod;
import net.neoforged.neoforge.client.event.EntityRenderersEvent;
import net.neoforged.neoforge.client.gui.ConfigurationScreen;
import net.neoforged.neoforge.client.gui.IConfigScreenFactory;
import net.neoforged.neoforge.common.NeoForge;

/** Client-Einstiegspunkt (wird auf dedizierten Servern nie geladen). */
@Mod(value = CustomPaintings.MOD_ID, dist = Dist.CLIENT)
public final class CustomPaintingsClient {
    public CustomPaintingsClient(IEventBus modBus, ModContainer container) {
        // Bildbearbeitung (ImageIO/BufferedImage) ohne Fenstersystem von AWT: vermeidet Konflikte mit GLFW (macOS)
        if (System.getProperty("java.awt.headless") == null) System.setProperty("java.awt.headless", "true");
        ClientBridge.Holder.set(new ClientBridgeImpl());
        container.registerExtensionPoint(IConfigScreenFactory.class, ConfigurationScreen::new);
        modBus.addListener(CustomPaintingsClient::registerRenderers);
        NeoForge.EVENT_BUS.register(ClientEvents.class);
        if (Boolean.getBoolean("custompaintings.clientSmokeTest")) NeoForge.EVENT_BUS.register(ClientSmokeTest.class);
        if (MultiplayerE2E.enabled()) NeoForge.EVENT_BUS.register(MultiplayerE2E.class);
    }

    private static void registerRenderers(EntityRenderersEvent.RegisterRenderers e) {
        // ersetzt den Vanilla-Renderer; ohne eigenes Bild sieht ein Gemaelde exakt wie gewohnt aus
        e.registerEntityRenderer(EntityType.PAINTING, CustomPaintingRenderer::new);
    }
}
