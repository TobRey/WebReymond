package de.blockrave.menu;

import de.blockrave.registry.ModBlocks;
import de.blockrave.registry.ModMenus;
import net.minecraft.core.BlockPos;
import net.minecraft.network.RegistryFriendlyByteBuf;
import net.minecraft.world.entity.player.Inventory;
import net.minecraft.world.entity.player.Player;
import net.minecraft.world.inventory.AbstractContainerMenu;
import net.minecraft.world.item.ItemStack;

/**
 * Menue des DJ-Pults (ohne Inventarplaetze). Dient serverseitig als Nachweis, dass ein Spieler das
 * Pult tatsaechlich bedient: Steuerbefehle werden nur bei geoeffnetem, gueltigem Menue akzeptiert.
 */
public class DjBoothMenu extends AbstractContainerMenu {
    private final BlockPos boothPos;

    public DjBoothMenu(int id, Inventory inv, BlockPos boothPos) {
        super(ModMenus.DJ_BOOTH.get(), id);
        this.boothPos = boothPos;
    }

    /** Client-Konstruktor (Netzwerk). */
    public DjBoothMenu(int id, Inventory inv, RegistryFriendlyByteBuf buf) {
        this(id, inv, buf.readBlockPos());
    }

    public BlockPos boothPos() {
        return boothPos;
    }

    @Override
    public ItemStack quickMoveStack(Player player, int index) {
        return ItemStack.EMPTY;
    }

    @Override
    public boolean stillValid(Player player) {
        return player.level().getBlockState(boothPos).is(ModBlocks.DJ_BOOTH.get())
                && player.distanceToSqr(boothPos.getX() + 0.5, boothPos.getY() + 0.5, boothPos.getZ() + 0.5) <= 10 * 10;
    }
}
