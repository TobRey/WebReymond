package de.custompaintings.server;

import net.minecraft.server.level.ServerPlayer;
import net.neoforged.bus.api.SubscribeEvent;
import net.neoforged.neoforge.event.entity.player.PlayerEvent;
import net.neoforged.neoforge.event.server.ServerStartedEvent;
import net.neoforged.neoforge.event.server.ServerStoppedEvent;
import net.neoforged.neoforge.event.tick.ServerTickEvent;

/** Server-Ereignisse (laufen auf dediziertem und integriertem Server). */
public final class ServerEvents {
    private static int ticks;

    private ServerEvents() {}

    @SubscribeEvent
    public static void onStarted(ServerStartedEvent e) {
        PaintingImages.start(e.getServer());
    }

    @SubscribeEvent
    public static void onStopped(ServerStoppedEvent e) {
        PaintingImages.stop();
    }

    @SubscribeEvent
    public static void onStartTracking(PlayerEvent.StartTracking e) {
        if (e.getEntity() instanceof ServerPlayer sp) PaintingImages.onStartTracking(sp, e.getTarget());
    }

    @SubscribeEvent
    public static void onLogout(PlayerEvent.PlayerLoggedOutEvent e) {
        if (e.getEntity() instanceof ServerPlayer sp) {
            try {
                PaintingImages.get().onLogout(sp);
            } catch (IllegalStateException ignored) {
                // Server faehrt gerade herunter
            }
        }
    }

    @SubscribeEvent
    public static void onTick(ServerTickEvent.Post e) {
        if (++ticks % 200 == 0) {
            try {
                PaintingImages.get().tick();
            } catch (IllegalStateException ignored) {
            }
        }
    }
}
