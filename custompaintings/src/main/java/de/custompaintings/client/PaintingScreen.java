package de.custompaintings.client;

import com.mojang.blaze3d.platform.NativeImage;
import de.custompaintings.Config;
import de.custompaintings.CustomPaintings;
import de.custompaintings.core.ImageCodec;
import de.custompaintings.core.ImageFit;
import net.minecraft.client.Minecraft;
import net.minecraft.client.gui.GuiGraphics;
import net.minecraft.client.gui.components.Button;
import net.minecraft.client.gui.components.CycleButton;
import net.minecraft.client.gui.screens.Screen;
import net.minecraft.client.renderer.texture.DynamicTexture;
import net.minecraft.network.chat.Component;
import net.minecraft.resources.ResourceLocation;
import net.minecraft.world.entity.decoration.Painting;
import net.minecraft.world.entity.decoration.PaintingVariant;
import org.lwjgl.PointerBuffer;
import org.lwjgl.system.MemoryStack;
import org.lwjgl.util.tinyfd.TinyFileDialogs;

import java.awt.image.BufferedImage;
import java.nio.file.Path;
import java.util.List;

/**
 * Oberflaeche zum Gestalten eines Gemaeldes (Shift + Rechtsklick): Bild waehlen oder ins Fenster ziehen,
 * Anpassung waehlen, Vorschau ansehen, uebernehmen oder das Originalmotiv wiederherstellen.
 */
public final class PaintingScreen extends Screen {
    private static final ResourceLocation PREVIEW = CustomPaintings.id("preview");
    private static final int BACKGROUND = 0x000000;

    private final Painting painting;
    private final int wBlocks, hBlocks;
    private final int[] target;

    private volatile BufferedImage source;
    private volatile String sourceName = "";
    private volatile byte[] png;
    private volatile Component status = Component.empty();
    private volatile boolean working;
    private ImageFit.Mode mode = ImageFit.Mode.FILL;
    private DynamicTexture previewTex;
    private int previewW, previewH;
    private static volatile boolean dialogOpen;

    private Button applyButton, resetButton, chooseButton;

    public PaintingScreen(Painting painting) {
        super(Component.translatable("custompaintings.gui.title"));
        this.painting = painting;
        PaintingVariant v = painting.getVariant().value();
        this.wBlocks = v.width();
        this.hBlocks = v.height();
        this.target = ImageFit.targetSize(wBlocks, hBlocks, Config.PIXELS_PER_BLOCK.get(), ImageFit.MAX_SIDE);
        if (!ClientUploader.INSTANCE.busy()) ClientUploader.INSTANCE.clear(); // alte Meldungen nicht anzeigen
    }

    public Painting painting() {
        return painting;
    }

    @Override
    protected void init() {
        int cx = width / 2;
        int y = height - 52;
        chooseButton = addRenderableWidget(Button.builder(Component.translatable("custompaintings.gui.choose"), b -> chooseFile())
                .bounds(cx - 154, y, 150, 20).build());
        addRenderableWidget(CycleButton.<ImageFit.Mode>builder(m -> Component.translatable("custompaintings.mode." + m.name().toLowerCase()))
                .withValues(ImageFit.Mode.values()).withInitialValue(mode)
                .create(cx + 4, y, 150, 20, Component.translatable("custompaintings.gui.mode"), (b, m) -> {
                    mode = m;
                    process();
                }));
        y += 24;
        applyButton = addRenderableWidget(Button.builder(Component.translatable("custompaintings.gui.apply"), b -> apply())
                .bounds(cx - 154, y, 100, 20).build());
        resetButton = addRenderableWidget(Button.builder(Component.translatable("custompaintings.gui.reset"), b -> resetToOriginal())
                .bounds(cx - 50, y, 100, 20).build());
        addRenderableWidget(Button.builder(Component.translatable("custompaintings.gui.close"), b -> onClose())
                .bounds(cx + 54, y, 100, 20).build());
    }

    // ------------------------------------------------------------------ Bild waehlen

    private void chooseFile() {
        if (dialogOpen) return;
        dialogOpen = true;
        Thread t = new Thread(() -> {
            try {
                String result;
                try (MemoryStack stack = MemoryStack.stackPush()) {
                    String[] patterns = {"*.png", "*.jpg", "*.jpeg", "*.bmp", "*.gif", "*.PNG", "*.JPG", "*.JPEG", "*.BMP", "*.GIF"};
                    PointerBuffer filters = stack.mallocPointer(patterns.length);
                    for (String p : patterns) filters.put(stack.UTF8(p));
                    filters.flip();
                    result = TinyFileDialogs.tinyfd_openFileDialog(Component.translatable("custompaintings.gui.dialog").getString(),
                            "", filters, "Bilder (PNG, JPEG, BMP, GIF)", false);
                }
                if (result != null && !result.isBlank()) {
                    Path p = Path.of(result);
                    Minecraft.getInstance().execute(() -> loadFile(p));
                }
            } catch (Throwable e) {
                CustomPaintings.LOG.warn("Custom Paintings: Dateiauswahl fehlgeschlagen", e);
                status = Component.translatable("custompaintings.gui.dialog_failed");
            } finally {
                dialogOpen = false;
            }
        }, "CustomPaintings-Dateiauswahl");
        t.setDaemon(true);
        t.start();
    }

    @Override
    public void onFilesDrop(List<Path> files) {
        if (!files.isEmpty()) loadFile(files.get(0));
    }

    /** Datei im Hintergrund einlesen. */
    public void loadFile(Path file) {
        working = true;
        status = Component.translatable("custompaintings.gui.loading", file.getFileName().toString());
        Thread t = new Thread(() -> {
            try {
                BufferedImage img = ImageCodec.readSource(file);
                Minecraft.getInstance().execute(() -> useImage(img, file.getFileName().toString()));
            } catch (Exception e) {
                status = Component.translatable("custompaintings.gui.read_failed", String.valueOf(e.getMessage()));
                working = false;
            }
        }, "CustomPaintings-Laden");
        t.setDaemon(true);
        t.start();
    }

    /** Bild verwenden (Hauptthread; auch fuer automatische Tests). */
    public void useImage(BufferedImage img, String name) {
        source = img;
        sourceName = name;
        process();
    }

    /** Auf das Gemaeldeformat bringen und Vorschau erzeugen (Hintergrund, dann Hauptthread). */
    private void process() {
        BufferedImage src = source;
        if (src == null) return;
        ImageFit.Mode m = mode;
        working = true;
        status = Component.translatable("custompaintings.gui.processing");
        Thread t = new Thread(() -> {
            try {
                BufferedImage fitted = ImageFit.fit(src, target[0], target[1], m, BACKGROUND);
                byte[] data = ImageCodec.encodePng(fitted);
                Minecraft.getInstance().execute(() -> {
                    if (m != mode || src != source) return; // inzwischen geaendert
                    png = data;
                    updatePreview(data, fitted.getWidth(), fitted.getHeight());
                    status = Component.translatable("custompaintings.gui.ready", sourceName, src.getWidth(), src.getHeight(),
                            data.length / 1024);
                    working = false;
                });
            } catch (Exception e) {
                CustomPaintings.LOG.warn("Custom Paintings: Bild konnte nicht verarbeitet werden", e);
                status = Component.translatable("custompaintings.gui.read_failed", String.valueOf(e.getMessage()));
                working = false;
            }
        }, "CustomPaintings-Verarbeitung");
        t.setDaemon(true);
        t.start();
    }

    private void updatePreview(byte[] data, int w, int h) {
        try {
            NativeImage img = NativeImage.read(data);
            previewTex = new DynamicTexture(img); // register() gibt die vorige Vorschau-Textur frei
            Minecraft.getInstance().getTextureManager().register(PREVIEW, previewTex);
            previewW = w;
            previewH = h;
        } catch (Exception e) {
            CustomPaintings.LOG.warn("Custom Paintings: Vorschau fehlgeschlagen", e);
        }
    }

    // ------------------------------------------------------------------ Aktionen

    public void apply() {
        byte[] data = png;
        if (data == null || ClientUploader.INSTANCE.busy()) return;
        ClientUploader.INSTANCE.upload(painting.getId(), data);
    }

    public void resetToOriginal() {
        if (ClientUploader.INSTANCE.busy()) return;
        ClientUploader.INSTANCE.reset(painting.getId());
    }

    public boolean hasPreview() {
        return png != null;
    }

    @Override
    public void tick() {
        if (painting.isRemoved()) {
            onClose();
            return;
        }
        boolean busy = ClientUploader.INSTANCE.busy();
        applyButton.active = png != null && !busy && !working;
        resetButton.active = !busy && !ClientImages.INSTANCE.imageOf(painting.getId()).isEmpty();
        chooseButton.active = !busy && !dialogOpen;
    }

    // ------------------------------------------------------------------ Zeichnen

    @Override
    public void render(GuiGraphics g, int mouseX, int mouseY, float partialTick) {
        super.render(g, mouseX, mouseY, partialTick);
        int cx = width / 2;
        g.drawCenteredString(font, title, cx, 10, 0xFFFFFF);
        g.drawCenteredString(font, Component.translatable("custompaintings.gui.size", wBlocks, hBlocks, target[0], target[1]),
                cx, 22, 0xA0A0A0);

        // Vorschau-Bereich im Seitenverhaeltnis des Gemaeldes
        int boxTop = 36, boxBottom = height - 70;
        int maxW = Math.min(width - 40, 360), maxH = Math.max(20, boxBottom - boxTop);
        double s = Math.min(maxW / (double) wBlocks, maxH / (double) hBlocks);
        int bw = Math.max(16, (int) (wBlocks * s)), bh = Math.max(16, (int) (hBlocks * s));
        int bx = cx - bw / 2, by = boxTop + (maxH - bh) / 2;
        g.fill(bx - 3, by - 3, bx + bw + 3, by + bh + 3, 0xFF6B4A2B); // Holzrahmen
        g.fill(bx, by, bx + bw, by + bh, 0xFF202020);
        ResourceLocation shown = null;
        int tw = 0, th = 0;
        if (previewTex != null) {
            shown = PREVIEW;
            tw = previewW;
            th = previewH;
        } else {
            ResourceLocation current = ClientImages.INSTANCE.textureFor(painting.getId());
            if (current != null) {
                shown = current;
                tw = target[0];
                th = target[1];
            }
        }
        if (shown != null) {
            g.blit(shown, bx, by, bw, bh, 0, 0, tw, th, tw, th);
        } else {
            g.drawCenteredString(font, Component.translatable("custompaintings.gui.original"), cx, by + bh / 2 - 9, 0xC0C0C0);
            g.drawCenteredString(font, Component.translatable("custompaintings.gui.drop_hint"), cx, by + bh / 2 + 3, 0x909090);
        }
        // Status
        Component line = status;
        ClientUploader up = ClientUploader.INSTANCE;
        if (up.state() != ClientUploader.State.IDLE && !up.message().getString().isEmpty() && !working) {
            line = up.state() == ClientUploader.State.SENDING
                    ? Component.translatable("custompaintings.gui.uploading_pct", (int) (up.progress() * 100))
                    : up.message();
        }
        int color = up.state() == ClientUploader.State.FAILED ? 0xFF6060 : up.state() == ClientUploader.State.DONE ? 0x60FF60 : 0xFFFFFF;
        g.drawCenteredString(font, line, cx, height - 64, color);
    }

    @Override
    public void removed() {
        if (previewTex != null) {
            Minecraft.getInstance().getTextureManager().release(PREVIEW);
            previewTex = null;
        }
    }

    @Override
    public boolean isPauseScreen() {
        return false;
    }
}
