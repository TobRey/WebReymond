package de.blockrave.item;

import de.blockrave.network.ClientBridge;
import net.minecraft.network.chat.Component;
import net.minecraft.world.InteractionHand;
import net.minecraft.world.InteractionResult;
import net.minecraft.world.InteractionResultHolder;
import net.minecraft.world.entity.player.Player;
import net.minecraft.world.item.Item;
import net.minecraft.world.item.ItemStack;
import net.minecraft.world.item.TooltipFlag;
import net.minecraft.world.item.context.UseOnContext;
import net.minecraft.world.level.Level;

import java.util.List;

/**
 * Verpackte Festivalbuehne. Benutzen startet clientseitig die Vorschau; gebaut wird erst nach
 * ausdruecklicher Bestaetigung und serverseitiger Pruefung.
 */
public class StageKitItem extends Item {
    public StageKitItem(Properties props) {
        super(props);
    }

    @Override
    public InteractionResult useOn(UseOnContext ctx) {
        return InteractionResult.PASS; // geht weiter an use()
    }

    @Override
    public InteractionResultHolder<ItemStack> use(Level level, Player player, InteractionHand hand) {
        if (level.isClientSide) {
            ClientBridge.Holder.get().onStageKitUse(player, hand);
        }
        return InteractionResultHolder.sidedSuccess(player.getItemInHand(hand), level.isClientSide);
    }

    @Override
    public void appendHoverText(ItemStack stack, TooltipContext ctx, List<Component> tooltip, TooltipFlag flag) {
        tooltip.add(Component.translatable("item.blockrave.stage_kit.tooltip1"));
        tooltip.add(Component.translatable("item.blockrave.stage_kit.tooltip2"));
    }
}
