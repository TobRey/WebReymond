package de.blockrave.client;

import de.blockrave.BlockRave;
import de.blockrave.BlockRaveConfig;
import de.blockrave.client.placement.PlacementController;
import de.blockrave.client.render.PreviewRenderer;
import de.blockrave.client.render.StageEffectsRenderer;
import de.blockrave.client.screen.GraphicsSettingsScreen;
import net.minecraft.client.Minecraft;
import net.minecraft.network.chat.Component;
import net.minecraft.world.phys.Vec3;
import net.neoforged.api.distmarker.Dist;
import net.neoforged.bus.api.SubscribeEvent;
import net.neoforged.fml.common.EventBusSubscriber;
import net.neoforged.neoforge.client.event.ClientPlayerNetworkEvent;
import net.neoforged.neoforge.client.event.ClientTickEvent;
import net.neoforged.neoforge.client.event.RenderLevelStageEvent;

/** Client-Ereignisse im Spiel: Verbindungsaufbau/-abbau, Tick, Rendering, Tasten. */
@EventBusSubscriber(modid = BlockRave.MOD_ID, value = Dist.CLIENT)
public final class ClientGameEvents {
    private static final StageEffectsRenderer EFFECTS = new StageEffectsRenderer();
    private static final PreviewRenderer PREVIEW = new PreviewRenderer();

    private ClientGameEvents() {}

    @SubscribeEvent
    public static void onLogin(ClientPlayerNetworkEvent.LoggingIn e) {
        ClientRaveState.connect();
    }

    @SubscribeEvent
    public static void onLogout(ClientPlayerNetworkEvent.LoggingOut e) {
        PlacementController.INSTANCE.cancel();
        ClientRaveState.disconnect();
    }

    @SubscribeEvent
    public static void onTick(ClientTickEvent.Post e) {
        Minecraft mc = Minecraft.getInstance();
        ClientRaveState s = ClientRaveState.get();
        if (s != null) s.tick();
        if (mc.player == null) return;
        PlacementController pc = PlacementController.INSTANCE;
        while (BlockRaveClient.ROTATE.consumeClick()) pc.rotate();
        while (BlockRaveClient.CANCEL.consumeClick()) pc.cancel();
        while (BlockRaveClient.MUTE.consumeClick()) {
            BlockRaveConfig.MUTED.set(!BlockRaveConfig.MUTED.get());
            BlockRaveConfig.CLIENT_SPEC.save();
            mc.player.displayClientMessage(Component.translatable(BlockRaveConfig.MUTED.get() ? "blockrave.msg.muted" : "blockrave.msg.unmuted"), true);
        }
        while (BlockRaveClient.SETTINGS.consumeClick()) mc.setScreen(new GraphicsSettingsScreen(null));
        pc.tick();
    }

    @SubscribeEvent
    public static void onRender(RenderLevelStageEvent e) {
        if (e.getStage() != RenderLevelStageEvent.Stage.AFTER_PARTICLES) return;
        ClientRaveState s = ClientRaveState.get();
        Vec3 cam = e.getCamera().getPosition();
        if (s != null) s.updateAudio(e.getCamera());
        EFFECTS.render(e.getPoseStack(), cam, e.getPartialTick().getGameTimeDeltaPartialTick(false));
        PREVIEW.render(e.getPoseStack(), cam);
    }
}
