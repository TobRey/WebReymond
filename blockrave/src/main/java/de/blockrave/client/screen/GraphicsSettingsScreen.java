package de.blockrave.client.screen;

import de.blockrave.BlockRaveConfig;
import net.minecraft.client.Minecraft;
import net.minecraft.client.gui.GuiGraphics;
import net.minecraft.client.gui.components.AbstractSliderButton;
import net.minecraft.client.gui.components.Button;
import net.minecraft.client.gui.screens.Screen;
import net.minecraft.network.chat.Component;

/**
 * Lokale Einstellungen (nur dieser PC): Effektqualitaet, Partikelmenge, Nebel, Stroboskope/Blitze,
 * Lautstaerke, Stummschaltung und Mithoeren des eigenen Live-Streams. Die Show bleibt dabei zeitlich
 * synchron zu allen anderen Spielern; es aendert sich nur die Darstellung.
 */
public class GraphicsSettingsScreen extends Screen {
    private final Screen parent;

    public GraphicsSettingsScreen(Screen parent) {
        super(Component.translatable("blockrave.settings.title"));
        this.parent = parent;
    }

    private static <E extends Enum<E>> E next(E value) {
        E[] all = value.getDeclaringClass().getEnumConstants();
        return all[(value.ordinal() + 1) % all.length];
    }

    @Override
    protected void init() {
        int x = width / 2 - 100, y = 40;
        addRenderableWidget(Button.builder(qualityLabel(), b -> {
            BlockRaveConfig.EFFECT_QUALITY.set(next(BlockRaveConfig.EFFECT_QUALITY.get()));
            b.setMessage(qualityLabel());
        }).bounds(x, y, 200, 20).build());
        y += 24;
        addRenderableWidget(new AbstractSliderButton(x, y, 200, 20, Component.empty(), BlockRaveConfig.PARTICLE_AMOUNT.get() / 100.0) {
            {
                updateMessage();
            }

            @Override
            protected void updateMessage() {
                setMessage(Component.translatable("blockrave.settings.particles", (int) Math.round(value * 100)));
            }

            @Override
            protected void applyValue() {
                BlockRaveConfig.PARTICLE_AMOUNT.set((int) Math.round(value * 100));
            }
        });
        y += 24;
        addRenderableWidget(Button.builder(fogLabel(), b -> {
            BlockRaveConfig.FOG_ENABLED.set(!BlockRaveConfig.FOG_ENABLED.get());
            b.setMessage(fogLabel());
        }).bounds(x, y, 200, 20).build());
        y += 24;
        addRenderableWidget(Button.builder(strobeLabel(), b -> {
            BlockRaveConfig.STROBE_MODE.set(next(BlockRaveConfig.STROBE_MODE.get()));
            b.setMessage(strobeLabel());
        }).bounds(x, y, 200, 20).build());
        y += 24;
        addRenderableWidget(new AbstractSliderButton(x, y, 200, 20, Component.empty(), BlockRaveConfig.LOCAL_VOLUME.get() / 100.0) {
            {
                updateMessage();
            }

            @Override
            protected void updateMessage() {
                setMessage(Component.translatable("blockrave.gui.local", (int) Math.round(value * 100)));
            }

            @Override
            protected void applyValue() {
                BlockRaveConfig.LOCAL_VOLUME.set((int) Math.round(value * 100));
            }
        });
        y += 24;
        addRenderableWidget(Button.builder(muteLabel(), b -> {
            BlockRaveConfig.MUTED.set(!BlockRaveConfig.MUTED.get());
            b.setMessage(muteLabel());
        }).bounds(x, y, 200, 20).build());
        y += 24;
        addRenderableWidget(Button.builder(monitorLabel(), b -> {
            BlockRaveConfig.MONITOR_OWN_LIVE.set(!BlockRaveConfig.MONITOR_OWN_LIVE.get());
            b.setMessage(monitorLabel());
        }).bounds(x, y, 200, 20).build());
        y += 32;
        addRenderableWidget(Button.builder(Component.translatable("gui.done"), b -> onClose()).bounds(x, y, 200, 20).build());
    }

    private static Component qualityLabel() {
        return Component.translatable("blockrave.settings.quality", Component.translatable("blockrave.quality." + BlockRaveConfig.EFFECT_QUALITY.get().name().toLowerCase()));
    }

    private static Component fogLabel() {
        return Component.translatable("blockrave.settings.fog", Component.translatable(BlockRaveConfig.FOG_ENABLED.get() ? "options.on" : "options.off"));
    }

    private static Component strobeLabel() {
        return Component.translatable("blockrave.settings.strobe", Component.translatable("blockrave.strobe." + BlockRaveConfig.STROBE_MODE.get().name().toLowerCase()));
    }

    private static Component muteLabel() {
        return Component.translatable("blockrave.settings.mute", Component.translatable(BlockRaveConfig.MUTED.get() ? "options.on" : "options.off"));
    }

    private static Component monitorLabel() {
        return Component.translatable("blockrave.settings.monitor", Component.translatable(BlockRaveConfig.MONITOR_OWN_LIVE.get() ? "options.on" : "options.off"));
    }

    @Override
    public void render(GuiGraphics g, int mouseX, int mouseY, float partialTick) {
        super.render(g, mouseX, mouseY, partialTick);
        g.drawCenteredString(font, title, width / 2, 16, 0xFFFFFF);
        g.drawCenteredString(font, Component.translatable("blockrave.settings.note"), width / 2, 28, 0xA0A0A0);
    }

    @Override
    public void onClose() {
        BlockRaveConfig.CLIENT_SPEC.save();
        Minecraft.getInstance().setScreen(parent);
    }
}
