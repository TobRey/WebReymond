package de.blockrave.server;

import com.mojang.brigadier.CommandDispatcher;
import de.blockrave.BlockRave;
import de.blockrave.registry.ModBlocks;
import de.blockrave.registry.ModItems;
import de.blockrave.stage.StageManager;
import net.minecraft.commands.CommandSourceStack;
import net.minecraft.commands.Commands;
import net.minecraft.network.chat.Component;
import net.minecraft.server.level.ServerLevel;
import net.minecraft.server.level.ServerPlayer;
import net.minecraft.world.item.ItemStack;
import net.neoforged.bus.api.EventPriority;
import net.neoforged.bus.api.SubscribeEvent;
import net.neoforged.fml.common.EventBusSubscriber;
import net.neoforged.neoforge.event.RegisterCommandsEvent;
import net.neoforged.neoforge.event.entity.player.PlayerEvent;
import net.neoforged.neoforge.event.level.BlockEvent;
import net.neoforged.neoforge.event.server.ServerStartingEvent;
import net.neoforged.neoforge.event.server.ServerStoppingEvent;
import net.neoforged.neoforge.event.tick.ServerTickEvent;

/** Serverseitige Ereignisse (laufen auch auf dem integrierten Server im Einzelspieler). */
@EventBusSubscriber(modid = BlockRave.MOD_ID)
public final class ServerEvents {
    private ServerEvents() {}

    @SubscribeEvent
    public static void onServerStarting(ServerStartingEvent e) {
        StageManager.start(e.getServer());
    }

    @SubscribeEvent
    public static void onServerStopping(ServerStoppingEvent e) {
        StageManager.stop();
        BlockRave.LOG.info("BlockRave: beendet, alle Audio-, Upload- und Netzwerkressourcen freigegeben");
    }

    @SubscribeEvent
    public static void onTick(ServerTickEvent.Post e) {
        StageManager m = StageManager.getOrNull();
        if (m != null) m.playback().tick();
    }

    @SubscribeEvent
    public static void onLogin(PlayerEvent.PlayerLoggedInEvent e) {
        StageManager m = StageManager.getOrNull();
        if (m != null && e.getEntity() instanceof ServerPlayer sp) {
            m.sendStageInfo(sp);
            m.playback().onLogin(sp);
        }
    }

    @SubscribeEvent
    public static void onLogout(PlayerEvent.PlayerLoggedOutEvent e) {
        StageManager m = StageManager.getOrNull();
        if (m != null && e.getEntity() instanceof ServerPlayer sp) m.playback().onLogout(sp);
    }

    @SubscribeEvent
    public static void onDimensionChange(PlayerEvent.PlayerChangedDimensionEvent e) {
        StageManager m = StageManager.getOrNull();
        if (m != null && e.getEntity() instanceof ServerPlayer sp) m.sendStageInfo(sp);
    }

    /** Einpacken am DJ-Pult. Laeuft zuletzt, damit Schutz-Mods den Abbau vorher verbieten koennen. */
    @SubscribeEvent(priority = EventPriority.LOWEST)
    public static void onBreak(BlockEvent.BreakEvent e) {
        if (!e.getState().is(ModBlocks.DJ_BOOTH.get())) return;
        if (!(e.getPlayer() instanceof ServerPlayer sp) || !(e.getLevel() instanceof ServerLevel level)) return;
        StageManager m = StageManager.getOrNull();
        if (m != null && m.onBoothBreak(sp, level, e.getPos())) e.setCanceled(true);
    }

    @SubscribeEvent
    public static void onCommands(RegisterCommandsEvent e) {
        register(e.getDispatcher());
    }

    private static void register(CommandDispatcher<CommandSourceStack> d) {
        d.register(Commands.literal("blockrave")
                .then(Commands.literal("status").executes(ctx -> {
                    StageManager m = StageManager.get(ctx.getSource().getServer());
                    var info = m.stageInfo();
                    if (info.present()) {
                        ctx.getSource().sendSuccess(() -> Component.translatable("blockrave.cmd.status",
                                info.dimension(), info.anchor().toShortString()), false);
                    } else {
                        ctx.getSource().sendSuccess(() -> Component.translatable("blockrave.cmd.no_stage"), false);
                    }
                    return 1;
                }))
                // Notfall fuer Admins: Buehne entfernen, wenn das DJ-Pult z.B. per Befehl entfernt wurde.
                .then(Commands.literal("abbauen").requires(s -> s.hasPermission(2)).executes(ctx -> {
                    StageManager m = StageManager.get(ctx.getSource().getServer());
                    Component err = m.packUp(m.level());
                    if (err != null) {
                        ctx.getSource().sendFailure(err);
                        return 0;
                    }
                    if (ctx.getSource().getEntity() instanceof ServerPlayer sp) {
                        ItemStack kit = new ItemStack(ModItems.STAGE_KIT.get());
                        if (!sp.getInventory().add(kit)) sp.drop(kit, false);
                    }
                    ctx.getSource().sendSuccess(() -> Component.translatable("blockrave.pack.done"), true);
                    return 1;
                })));
    }
}
