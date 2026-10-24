package de.blockrave.client.screen;

import de.blockrave.client.placement.PlacementController;
import de.blockrave.network.Payloads;
import net.minecraft.client.gui.GuiGraphics;
import net.minecraft.client.gui.components.Button;
import net.minecraft.client.gui.screens.Screen;
import net.minecraft.network.chat.Component;

import java.util.List;

/**
 * Ausdrueckliche Bestaetigung vor dem Aufbau. Zeigt Position, Ausrichtung und die serverseitig
 * ermittelten Bloecke, die entfernt werden (und nicht wiederhergestellt werden). Die Welt bleibt
 * rechts sichtbar, die betroffenen Bloecke sind dort rot markiert.
 */
public class PlacementConfirmScreen extends Screen {
    private static final String[] DIRS = {"Süden", "Westen", "Norden", "Osten"};
    private final PlacementController pc;
    private Button build;
    private Payloads.PlacementResult result;

    public PlacementConfirmScreen(PlacementController pc) {
        super(Component.translatable("blockrave.confirm.title"));
        this.pc = pc;
    }

    @Override
    protected void init() {
        int x = 14, w = 252;
        int y = height - 34;
        build = addRenderableWidget(Button.builder(Component.translatable("blockrave.confirm.build"), b -> {
            build.active = false;
            pc.confirm();
        }).bounds(x, y, 120, 20).build());
        addRenderableWidget(Button.builder(Component.translatable("blockrave.confirm.cancel"), b -> {
            pc.cancel();
            onClose();
        }).bounds(x + w - 128, y, 128, 20).build());
        addRenderableWidget(Button.builder(Component.translatable("blockrave.confirm.adjust"), b -> {
            pc.unlock();
            onClose();
        }).bounds(x, y - 24, 120, 20).build());
        addRenderableWidget(Button.builder(Component.translatable("blockrave.confirm.recheck"), b -> {
            result = null;
            build.active = false;
            pc.requestCheck();
        }).bounds(x + w - 128, y - 24, 128, 20).build());
        build.active = false;
        if (pc.lastResult() != null) onResult(pc.lastResult());
    }

    public void onResult(Payloads.PlacementResult r) {
        result = r;
        if (build != null) {
            build.active = r.status() == Payloads.PlacementResult.CHECK_OK || r.status() == Payloads.PlacementResult.CHANGED;
        }
    }

    @Override
    public void renderBackground(GuiGraphics g, int mouseX, int mouseY, float partialTick) {
        // Kein Weichzeichner: die Vorschau in der Welt soll sichtbar bleiben.
        GuiStyle.panel(g, 8, 8, 264, height - 16);
    }

    @Override
    public void render(GuiGraphics g, int mouseX, int mouseY, float partialTick) {
        super.render(g, mouseX, mouseY, partialTick);
        int x = 16, y = 16;
        g.drawString(font, title, x, y, GuiStyle.TEXT, false);
        y += 14;
        if (pc.anchor() != null) {
            g.drawString(font, Component.translatable("blockrave.confirm.position", pc.anchor().getX(), pc.anchor().getY(),
                    pc.anchor().getZ()), x, y, GuiStyle.TEXT, false);
            y += 11;
            g.drawString(font, Component.translatable("blockrave.confirm.direction", DIRS[pc.rotation()], DIRS[(pc.rotation() + 2) % 4]),
                    x, y, GuiStyle.TEXT, false);
            y += 11;
            g.drawString(font, Component.translatable("blockrave.confirm.size"), x, y, GuiStyle.TEXT, false);
            y += 16;
        }
        if (result == null) {
            g.drawString(font, Component.translatable("blockrave.confirm.checking"), x, y, GuiStyle.TEXT, false);
            return;
        }
        switch (result.status()) {
            case Payloads.PlacementResult.CHECK_FAILED, Payloads.PlacementResult.PLACE_FAILED -> {
                y = wrap(g, Component.translatable("blockrave.confirm.not_possible"), x, y, GuiStyle.RED);
                wrap(g, result.message(), x, y, GuiStyle.RED);
                return;
            }
            case Payloads.PlacementResult.CHANGED -> y = wrap(g, result.message(), x, y, GuiStyle.RED);
            default -> {}
        }
        if (result.affectedCount() == 0) {
            y = wrap(g, Component.translatable("blockrave.confirm.no_blocks"), x, y, GuiStyle.GREEN);
        } else {
            y = wrap(g, Component.translatable("blockrave.confirm.blocks", result.affectedCount()), x, y, GuiStyle.RED);
            for (Component c : result.summary()) {
                g.drawString(font, c, x + 6, y, GuiStyle.TEXT, false);
                y += 10;
            }
            if (result.containerCount() > 0) {
                y = wrap(g, Component.translatable("blockrave.confirm.containers", result.containerCount()), x, y + 2, GuiStyle.RED);
            }
            y = wrap(g, Component.translatable("blockrave.confirm.permanent"), x, y + 4, GuiStyle.RED);
        }
        wrap(g, Component.translatable("blockrave.confirm.kit"), x, y + 4, GuiStyle.TEXT);
    }

    private int wrap(GuiGraphics g, Component c, int x, int y, int color) {
        List<net.minecraft.util.FormattedCharSequence> lines = font.split(c, 246);
        for (var l : lines) {
            g.drawString(font, l, x, y, color, false);
            y += 10;
        }
        return y + 2;
    }

    @Override
    public boolean isPauseScreen() {
        return false;
    }

    @Override
    public void onClose() {
        // ESC: zurueck zur Vorschau (nichts wird gebaut)
        if (pc.isActive() && pc.isLocked()) pc.unlock();
        super.onClose();
    }
}
