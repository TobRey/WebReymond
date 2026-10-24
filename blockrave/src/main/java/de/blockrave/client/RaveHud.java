package de.blockrave.client;

import de.blockrave.client.live.LiveSender;
import de.blockrave.client.placement.PlacementController;
import de.blockrave.client.screen.GuiStyle;
import de.blockrave.client.upload.ClientUploader;
import net.minecraft.client.DeltaTracker;
import net.minecraft.client.Minecraft;
import net.minecraft.client.gui.Font;
import net.minecraft.client.gui.GuiGraphics;
import net.minecraft.client.gui.LayeredDraw;
import net.minecraft.network.chat.Component;

/** Hinweise im Spiel: Bauvorschau-Steuerung, Sendestatus und laufende Uploads. */
public final class RaveHud implements LayeredDraw.Layer {
    private static final String[] DIRS = {"Süden", "Westen", "Norden", "Osten"};

    @Override
    public void render(GuiGraphics g, DeltaTracker delta) {
        Minecraft mc = Minecraft.getInstance();
        if (mc.options.hideGui || mc.player == null) return;
        Font font = mc.font;
        int y = 6;
        PlacementController pc = PlacementController.INSTANCE;
        if (pc.isActive() && !pc.isLocked()) {
            g.drawString(font, Component.translatable("blockrave.preview.hud_title", DIRS[pc.rotation()]), 6, y, 0x00E5FF);
            y += 11;
            g.drawString(font, Component.translatable("blockrave.preview.hud_keys", BlockRaveClient.ROTATE.getTranslatedKeyMessage(),
                    BlockRaveClient.CANCEL.getTranslatedKeyMessage()), 6, y, 0xFFFFFF);
            y += 11;
            int n = pc.conflicts().size();
            g.drawString(font, n == 0 ? Component.translatable("blockrave.preview.hud_free")
                    : Component.translatable("blockrave.preview.hud_conflicts", n), 6, y, n == 0 ? 0x55FF55 : 0xFF5555);
            y += 14;
        }
        ClientRaveState s = ClientRaveState.get();
        if (s == null) return;
        if (s.live.state() == LiveSender.State.SENDING) {
            boolean blink = (System.currentTimeMillis() / 500) % 2 == 0;
            g.fill(6, y + 1, 12, y + 7, blink ? 0xFFFF2020 : 0xFF801010);
            g.drawString(font, Component.translatable("blockrave.hud.live", GuiStyle.time((System.currentTimeMillis() - s.live.sendingSinceMs()) * 1000),
                    String.format("%.1f", s.live.estimatedDelayMs() / 1000.0)), 16, y, 0xFF5555);
            y += 11;
            if (s.live.noSignal()) {
                g.drawString(font, Component.translatable("blockrave.live.no_signal"), 16, y, 0xFFAA00);
                y += 11;
            }
        }
        for (ClientUploader.Task t : s.uploads.tasks()) {
            if (t.state == ClientUploader.State.SENDING || t.state == ClientUploader.State.PROCESSING) {
                Component c = t.state == ClientUploader.State.SENDING
                        ? Component.translatable("blockrave.upload.sending", t.name, (int) (t.progress() * 100))
                        : Component.translatable("blockrave.upload.processing", t.name);
                g.drawString(font, c, 6, y, 0xFFFFFF);
                y += 11;
            }
        }
    }
}
