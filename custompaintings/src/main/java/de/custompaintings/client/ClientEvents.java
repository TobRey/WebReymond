package de.custompaintings.client;

import net.minecraft.client.Minecraft;
import net.minecraft.world.InteractionHand;
import net.minecraft.world.InteractionResult;
import net.minecraft.world.entity.decoration.Painting;
import net.neoforged.bus.api.SubscribeEvent;
import net.neoforged.neoforge.client.event.ClientPlayerNetworkEvent;
import net.neoforged.neoforge.event.entity.player.PlayerInteractEvent;

/** Client-Ereignisse: Shift + Rechtsklick auf ein Gemaelde oeffnet die Gestaltung. */
public final class ClientEvents {
    private ClientEvents() {}

    @SubscribeEvent
    public static void onInteract(PlayerInteractEvent.EntityInteract e) {
        if (!e.getLevel().isClientSide() || !(e.getTarget() instanceof Painting painting) || !e.getEntity().isShiftKeyDown()) return;
        e.setCanceled(true);
        e.setCancellationResult(InteractionResult.SUCCESS);
        if (e.getHand() != InteractionHand.MAIN_HAND) return; // nur einmal oeffnen
        Minecraft.getInstance().setScreen(new PaintingScreen(painting));
    }

    @SubscribeEvent
    public static void onLogout(ClientPlayerNetworkEvent.LoggingOut e) {
        Minecraft.getInstance().execute(() -> {
            ClientImages.INSTANCE.clear();
            ClientUploader.INSTANCE.clear();
        });
    }
}
