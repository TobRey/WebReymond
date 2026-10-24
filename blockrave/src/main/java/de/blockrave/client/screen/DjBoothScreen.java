package de.blockrave.client.screen;

import de.blockrave.BlockRaveConfig;
import de.blockrave.client.ClientNet;
import de.blockrave.client.ClientRaveState;
import de.blockrave.client.live.CaptureSource;
import de.blockrave.client.live.LiveSender;
import de.blockrave.client.upload.ClientUploader;
import de.blockrave.menu.DjBoothMenu;
import de.blockrave.network.DjAction;
import de.blockrave.network.Payloads;
import net.minecraft.client.Minecraft;
import net.minecraft.client.gui.GuiGraphics;
import net.minecraft.client.gui.components.AbstractSliderButton;
import net.minecraft.client.gui.components.Button;
import net.minecraft.client.gui.screens.inventory.AbstractContainerScreen;
import net.minecraft.network.chat.Component;
import net.minecraft.world.entity.player.Inventory;

import java.util.ArrayList;
import java.util.List;

/**
 * Bedienoberflaeche des DJ-Pults im klassischen Inventarstil. Alle Aenderungen gehen als Befehle an
 * den Server, der sie nacheinander anwendet und den neuen Zustand an alle verteilt; die Anzeige
 * zeigt immer den Serverzustand (keine widerspruechlichen lokalen Zustaende).
 */
public class DjBoothScreen extends AbstractContainerScreen<DjBoothMenu> {
    private enum Tab { PLAYLIST, QUEUE, LIBRARY }

    private static final int ROWS = 8;
    private static final int ROW_H = 11;
    private static final int LIST_X = 8, LIST_Y = 100, LIST_W = 230;

    private Tab tab = Tab.PLAYLIST;
    private int selected = -1;
    private int scroll;
    private boolean liveView;
    private boolean confirmDelete;
    private final List<Button> mp3Widgets = new ArrayList<>();
    private final List<Button> liveWidgets = new ArrayList<>();
    private final List<Button> contextButtons = new ArrayList<>();
    private Button sourceButton, liveButton, stopOtherButton, muteButton, deleteButton;
    private int lastLibraryRevision = -1;

    public DjBoothScreen(DjBoothMenu menu, Inventory inv, Component title) {
        super(menu, inv, title);
        imageWidth = 330;
        imageHeight = 246;
    }

    private static ClientRaveState state() {
        return ClientRaveState.get();
    }

    private static void cmd(int action, String id, int arg) {
        ClientNet.send(new Payloads.DjCommand(action, id == null ? "" : id, arg));
    }

    @Override
    protected void init() {
        super.init();
        mp3Widgets.clear();
        liveWidgets.clear();
        contextButtons.clear();
        int x = leftPos, y = topPos;
        Payloads.RaveStatus st = state() != null ? state().status() : null;
        liveView = st != null && st.mode() == Payloads.RaveStatus.MODE_LIVE;

        addRenderableWidget(Button.builder(Component.literal("MP3"), b -> {
            liveView = false;
            cmd(DjAction.SET_MODE, "", 0);
            updateVisibility();
        }).bounds(x + 230, y + 4, 44, 14).build());
        addRenderableWidget(Button.builder(Component.literal("Live"), b -> {
            liveView = true;
            cmd(DjAction.SET_MODE, "", 1);
            updateVisibility();
            if (state() != null) state().live.refreshSources(null);
        }).bounds(x + 278, y + 4, 44, 14).build());

        // ---------------- MP3
        mp3(Button.builder(Component.translatable("blockrave.gui.play"), b -> cmd(DjAction.PLAY, "", 0)).bounds(x + 8, y + 60, 64, 18).build());
        mp3(Button.builder(Component.translatable("blockrave.gui.pause"), b -> cmd(DjAction.PAUSE, "", 0)).bounds(x + 74, y + 60, 48, 18).build());
        mp3(Button.builder(Component.translatable("blockrave.gui.stop"), b -> cmd(DjAction.STOP, "", 0)).bounds(x + 124, y + 60, 48, 18).build());
        mp3(Button.builder(Component.translatable("blockrave.gui.next"), b -> cmd(DjAction.NEXT, "", 0)).bounds(x + 174, y + 60, 48, 18).build());
        mp3(Button.builder(Component.translatable("blockrave.gui.choose_file"), b -> {
            if (state() != null) state().uploads.chooseFiles();
        }).bounds(x + 226, y + 60, 96, 18).build());
        mp3(Button.builder(Component.translatable("blockrave.gui.tab_playlist"), b -> setTab(Tab.PLAYLIST)).bounds(x + 8, y + 82, 74, 16).build());
        mp3(Button.builder(Component.translatable("blockrave.gui.tab_queue"), b -> setTab(Tab.QUEUE)).bounds(x + 84, y + 82, 78, 16).build());
        mp3(Button.builder(Component.translatable("blockrave.gui.tab_library"), b -> setTab(Tab.LIBRARY)).bounds(x + 164, y + 82, 74, 16).build());
        for (int i = 0; i < 5; i++) {
            final int slot = i;
            Button b = Button.builder(Component.empty(), btn -> contextAction(slot)).bounds(x + 244, y + 100 + i * 18, 78, 16).build();
            contextButtons.add(b);
            mp3(b);
        }

        // ---------------- Live
        sourceButton = live(Button.builder(Component.empty(), b -> cycleSource()).bounds(x + 8, y + 60, 290, 18).build());
        live(Button.builder(Component.literal("↻"), b -> {
            if (state() != null) state().live.refreshSources(null);
        }).bounds(x + 302, y + 60, 20, 18).build());
        liveButton = live(Button.builder(Component.empty(), b -> toggleLive()).bounds(x + 8, y + 82, 150, 18).build());
        stopOtherButton = live(Button.builder(Component.translatable("blockrave.gui.live_stop_other"), b -> cmd(DjAction.LIVE_STOP, "", 0))
                .bounds(x + 8, y + 176, 180, 16).build());

        // ---------------- Lautstaerke & Einstellungen
        int vol = st != null ? st.masterVolume() : 80;
        addRenderableWidget(new AbstractSliderButton(x + 8, y + 224, 118, 16, Component.empty(), vol / 100.0) {
            {
                updateMessage();
            }

            @Override
            protected void updateMessage() {
                setMessage(Component.translatable("blockrave.gui.master", (int) Math.round(value * 100)));
            }

            @Override
            protected void applyValue() {}

            @Override
            public void onRelease(double mouseX, double mouseY) {
                super.onRelease(mouseX, mouseY);
                cmd(DjAction.SET_VOLUME, "", (int) Math.round(value * 100));
            }
        });
        addRenderableWidget(new AbstractSliderButton(x + 130, y + 224, 100, 16, Component.empty(),
                BlockRaveConfig.LOCAL_VOLUME.get() / 100.0) {
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

            @Override
            public void onRelease(double mouseX, double mouseY) {
                super.onRelease(mouseX, mouseY);
                BlockRaveConfig.CLIENT_SPEC.save();
            }
        });
        muteButton = addRenderableWidget(Button.builder(Component.empty(), b -> {
            BlockRaveConfig.MUTED.set(!BlockRaveConfig.MUTED.get());
            BlockRaveConfig.CLIENT_SPEC.save();
        }).bounds(x + 234, y + 224, 42, 16).build());
        addRenderableWidget(Button.builder(Component.translatable("blockrave.gui.graphics"), b ->
                Minecraft.getInstance().setScreen(new GraphicsSettingsScreen(this))).bounds(x + 280, y + 224, 42, 16).build());

        if (liveView && state() != null) state().live.refreshSources(null);
        cmd(DjAction.REQUEST_LIBRARY, "", 0);
        updateVisibility();
    }

    private Button mp3(Button b) {
        mp3Widgets.add(b);
        return addRenderableWidget(b);
    }

    private Button live(Button b) {
        liveWidgets.add(b);
        return addRenderableWidget(b);
    }

    private void setTab(Tab t) {
        tab = t;
        selected = -1;
        scroll = 0;
        confirmDelete = false;
        updateVisibility();
    }

    // ------------------------------------------------------------------ Listen

    private List<String[]> rows() {
        List<String[]> out = new ArrayList<>(); // {id, Anzeige, Dauer}
        ClientRaveState s = state();
        Payloads.Library lib = s == null ? null : s.library();
        if (lib == null) return out;
        java.util.Map<String, Payloads.TrackInfo> byId = new java.util.HashMap<>();
        for (Payloads.TrackInfo t : lib.tracks()) byId.put(t.id(), t);
        List<String> ids = switch (tab) {
            case PLAYLIST -> lib.playlist();
            case QUEUE -> lib.queue();
            case LIBRARY -> lib.tracks().stream().map(Payloads.TrackInfo::id).toList();
        };
        for (String id : ids) {
            Payloads.TrackInfo t = byId.get(id);
            if (t == null) continue;
            String extra = tab == Tab.LIBRARY ? " · " + t.uploader() : "";
            out.add(new String[]{id, t.title() + extra, GuiStyle.time(t.durationMicros())});
        }
        return out;
    }

    private String selectedId() {
        List<String[]> r = rows();
        return selected >= 0 && selected < r.size() ? r.get(selected)[0] : null;
    }

    private void contextAction(int slot) {
        String id = selectedId();
        if (id == null) return;
        switch (tab) {
            case PLAYLIST -> {
                switch (slot) {
                    case 0 -> { cmd(DjAction.PLAYLIST_MOVE, id, -1); selected = Math.max(0, selected - 1); }
                    case 1 -> { cmd(DjAction.PLAYLIST_MOVE, id, 1); selected++; }
                    case 2 -> cmd(DjAction.PLAY_TRACK, id, 0);
                    case 3 -> cmd(DjAction.QUEUE_ADD, id, 0);
                    case 4 -> { cmd(DjAction.PLAYLIST_REMOVE, id, 0); selected = -1; }
                    default -> {}
                }
            }
            case QUEUE -> {
                switch (slot) {
                    case 0 -> { cmd(DjAction.QUEUE_MOVE, Integer.toString(selected), -1); selected = Math.max(0, selected - 1); }
                    case 1 -> { cmd(DjAction.QUEUE_MOVE, Integer.toString(selected), 1); selected++; }
                    case 2 -> { cmd(DjAction.QUEUE_REMOVE, "", selected); selected = -1; }
                    default -> {}
                }
            }
            case LIBRARY -> {
                switch (slot) {
                    case 0 -> cmd(DjAction.PLAYLIST_ADD, id, 0);
                    case 1 -> cmd(DjAction.QUEUE_ADD, id, 0);
                    case 2 -> cmd(DjAction.PLAY_TRACK, id, 0);
                    case 3 -> {
                        if (confirmDelete) {
                            cmd(DjAction.DELETE_TRACK, id, 0);
                            confirmDelete = false;
                            selected = -1;
                        } else {
                            confirmDelete = true;
                        }
                    }
                    default -> {}
                }
            }
        }
        updateVisibility();
    }

    private static final String[][] CONTEXT = {
            {"blockrave.gui.up", "blockrave.gui.down", "blockrave.gui.play_now", "blockrave.gui.to_queue", "blockrave.gui.remove"},
            {"blockrave.gui.up", "blockrave.gui.down", "blockrave.gui.remove", "", ""},
            {"blockrave.gui.to_playlist", "blockrave.gui.to_queue", "blockrave.gui.play_now", "blockrave.gui.delete", ""},
    };

    // ------------------------------------------------------------------ Live

    private void cycleSource() {
        ClientRaveState s = state();
        if (s == null) return;
        LiveSender l = s.live;
        List<CaptureSource> list = l.sources();
        if (list.isEmpty()) return;
        int i = list.indexOf(l.selected());
        l.select(list.get((i + 1) % list.size()));
        BlockRaveConfig.CLIENT_SPEC.save();
    }

    private void toggleLive() {
        ClientRaveState s = state();
        if (s == null) return;
        if (s.live.state() == LiveSender.State.SENDING || s.live.state() == LiveSender.State.REQUESTED) s.live.stop();
        else s.live.requestStart();
    }

    // ------------------------------------------------------------------ Aktualisierung

    private void updateVisibility() {
        for (Button b : mp3Widgets) b.visible = !liveView;
        for (Button b : liveWidgets) b.visible = liveView;
        ClientRaveState s = state();
        int t = tab.ordinal();
        for (int i = 0; i < contextButtons.size(); i++) {
            Button b = contextButtons.get(i);
            String key = CONTEXT[t][i];
            b.visible = !liveView && !key.isEmpty();
            b.active = selectedId() != null;
            if (!key.isEmpty()) b.setMessage(Component.translatable(key));
            if (tab == Tab.LIBRARY && i == 3 && confirmDelete) b.setMessage(Component.translatable("blockrave.gui.delete_confirm"));
        }
        muteButton.setMessage(Component.translatable(BlockRaveConfig.MUTED.get() ? "blockrave.gui.muted" : "blockrave.gui.mute"));
        if (s != null) {
            LiveSender l = s.live;
            CaptureSource src = l.selected();
            sourceButton.setMessage(Component.translatable("blockrave.gui.source", src == null ? "–" : src.name()));
            sourceButton.active = l.backend().isSupported() && l.state() != LiveSender.State.SENDING && l.state() != LiveSender.State.REQUESTED;
            boolean sending = l.state() == LiveSender.State.SENDING || l.state() == LiveSender.State.REQUESTED;
            liveButton.setMessage(Component.translatable(sending ? "blockrave.gui.live_stop" : "blockrave.gui.live_start"));
            liveButton.active = l.backend().isSupported() && (sending || src != null);
            Payloads.RaveStatus st = s.status();
            boolean otherLive = st != null && st.state() == Payloads.RaveStatus.LIVE && !sending;
            stopOtherButton.visible = liveView && otherLive;
        }
    }

    @Override
    protected void containerTick() {
        super.containerTick();
        updateVisibility();
        ClientRaveState s = state();
        if (s != null && s.library() != null && s.library().revision() != lastLibraryRevision) {
            lastLibraryRevision = s.library().revision();
            if (selected >= rows().size()) selected = -1;
        }
    }

    // ------------------------------------------------------------------ Zeichnen

    @Override
    protected void renderBg(GuiGraphics g, float partialTick, int mouseX, int mouseY) {
        GuiStyle.panel(g, leftPos, topPos, imageWidth, imageHeight);
        GuiStyle.inset(g, leftPos + 8, topPos + 22, 314, 34);
        if (!liveView) GuiStyle.inset(g, leftPos + LIST_X, topPos + LIST_Y, LIST_W, ROWS * ROW_H + 4);
        else GuiStyle.inset(g, leftPos + 8, topPos + 104, 314, 70);
    }

    @Override
    protected void renderLabels(GuiGraphics g, int mouseX, int mouseY) {
        g.drawString(font, title, 8, 7, GuiStyle.TEXT, false);
        ClientRaveState s = state();
        if (s == null) return;
        Payloads.RaveStatus st = s.status();
        renderNowPlaying(g, s, st);
        if (liveView) renderLive(g, s, st);
        else renderMp3(g, s, mouseX - leftPos, mouseY - topPos);
        // Statuszeile (Servermeldung + Audiozustand)
        Component msg = st != null ? st.message() : Component.empty();
        g.drawString(font, font.plainSubstrByWidth(msg.getString(), 314), 8, 200, GuiStyle.TEXT, false);
        g.drawString(font, font.plainSubstrByWidth(s.audioStatus().getString(), 314), 8, 211, 0x606060, false);
    }

    private void renderNowPlaying(GuiGraphics g, ClientRaveState s, Payloads.RaveStatus st) {
        int x = 12, y = 26;
        if (st == null) {
            g.drawString(font, Component.translatable("blockrave.gui.connecting"), x, y, GuiStyle.TEXT_LIGHT, false);
            return;
        }
        String stateKey = switch (st.state()) {
            case Payloads.RaveStatus.PLAYING -> "blockrave.gui.state_playing";
            case Payloads.RaveStatus.PAUSED -> "blockrave.gui.state_paused";
            case Payloads.RaveStatus.LIVE -> "blockrave.gui.state_live";
            default -> "blockrave.gui.state_stopped";
        };
        Component line = st.state() == Payloads.RaveStatus.LIVE
                ? Component.translatable(stateKey, st.liveSender(), st.liveSource())
                : Component.translatable(stateKey, st.title());
        g.drawString(font, font.plainSubstrByWidth(line.getString(), 306), x, y, GuiStyle.TEXT_LIGHT, false);
        if (st.state() == Payloads.RaveStatus.PLAYING || st.state() == Payloads.RaveStatus.PAUSED) {
            long pos = st.state() == Payloads.RaveStatus.PLAYING ? s.serverNow() - st.trackStartMicros() : st.positionMicros();
            pos = Math.max(0, Math.min(pos, st.durationMicros()));
            float p = st.durationMicros() > 0 ? (float) pos / st.durationMicros() : 0;
            GuiStyle.bar(g, x, y + 13, 230, 8, p, 0x00E5FF);
            g.drawString(font, GuiStyle.time(pos) + " / " + GuiStyle.time(st.durationMicros()), x + 236, y + 13, GuiStyle.TEXT_LIGHT, false);
        } else if (st.state() == Payloads.RaveStatus.LIVE) {
            g.drawString(font, Component.translatable("blockrave.gui.live_delay", String.format("%.1f", st.liveDelayMs() / 1000.0)),
                    x, y + 13, 0xFF6060, false);
        }
    }

    private void renderMp3(GuiGraphics g, ClientRaveState s, int mx, int my) {
        List<String[]> rows = rows();
        Payloads.RaveStatus st = s.status();
        scroll = Math.max(0, Math.min(scroll, Math.max(0, rows.size() - ROWS)));
        if (rows.isEmpty()) {
            g.drawString(font, Component.translatable(tab == Tab.QUEUE ? "blockrave.gui.queue_empty" : "blockrave.gui.list_empty"),
                    LIST_X + 4, LIST_Y + 4, 0xE0E0E0, false);
        }
        for (int i = 0; i < ROWS && i + scroll < rows.size(); i++) {
            int idx = i + scroll;
            String[] r = rows.get(idx);
            int y = LIST_Y + 2 + i * ROW_H;
            if (idx == selected) g.fill(LIST_X + 1, y - 1, LIST_X + LIST_W - 1, y + ROW_H - 1, 0xFF5A5A5A);
            boolean current = st != null && r[0].equals(st.trackId()) && tab != Tab.QUEUE;
            String label = (tab == Tab.LIBRARY ? "" : (idx + 1) + ". ") + (current ? "▶ " : "") + r[1];
            g.drawString(font, font.plainSubstrByWidth(label, LIST_W - 40), LIST_X + 4, y, current ? 0xFFFF55 : 0xFFFFFF, false);
            g.drawString(font, r[2], LIST_X + LIST_W - 4 - font.width(r[2]), y, 0xE0E0E0, false);
        }
        if (s.library() != null) {
            long used = s.library().usedBytes() / (1024 * 1024), limit = s.library().limitBytes() / (1024 * 1024);
            g.drawString(font, Component.translatable("blockrave.gui.storage", s.library().tracks().size(), used, limit),
                    70, 7, 0x606060, false);
        }
        // Upload-Fortschritt (neuester Eintrag)
        List<ClientUploader.Task> tasks = s.uploads.tasks();
        if (!tasks.isEmpty()) {
            ClientUploader.Task t = tasks.get(tasks.size() - 1);
            String txt = switch (t.state) {
                case WAITING, STARTING -> Component.translatable("blockrave.upload.waiting", t.name).getString();
                case SENDING -> Component.translatable("blockrave.upload.sending", t.name, (int) (t.progress() * 100)).getString();
                case PROCESSING -> Component.translatable("blockrave.upload.processing", t.name).getString();
                case DONE -> t.message.getString();
                case FAILED -> Component.translatable("blockrave.upload.failed", t.name, t.message.getString()).getString();
            };
            int color = t.state == ClientUploader.State.FAILED ? GuiStyle.RED : t.state == ClientUploader.State.DONE ? GuiStyle.GREEN : GuiStyle.TEXT;
            g.drawString(font, font.plainSubstrByWidth(txt, 314), 8, 190, color, false);
        } else if (s.uploads.isDialogOpen()) {
            g.drawString(font, Component.translatable("blockrave.upload.dialog_open"), 8, 190, GuiStyle.TEXT, false);
        }
    }

    private void renderLive(GuiGraphics g, ClientRaveState s, Payloads.RaveStatus st) {
        LiveSender l = s.live;
        int x = 12, y = 108;
        if (!l.backend().isSupported()) {
            for (var line : font.split(Component.literal(l.backend().unsupportedReason()), 306)) {
                g.drawString(font, line, x, y, 0xFFFFFF, false);
                y += 10;
            }
            return;
        }
        // Pegelanzeige
        GuiStyle.bar(g, 164, 86, 158, 10, l.level(), l.level() > 0.95f ? 0xFF3030 : 0x39FF14);
        boolean sending = l.state() == LiveSender.State.SENDING;
        if (sending) {
            g.fill(166, 75, 172, 81, (System.currentTimeMillis() / 500) % 2 == 0 ? 0xFFFF2020 : 0xFF801010);
            g.drawString(font, Component.translatable("blockrave.gui.on_air", GuiStyle.time((System.currentTimeMillis() - l.sendingSinceMs()) * 1000)),
                    176, 74, GuiStyle.RED, false);
        }
        List<Component> lines = new ArrayList<>();
        lines.add(l.status());
        if (sending) lines.add(Component.translatable("blockrave.gui.live_est_delay", String.format("%.1f", l.estimatedDelayMs() / 1000.0)));
        if (l.noSignal()) lines.add(Component.translatable("blockrave.live.no_signal"));
        CaptureSource src = l.selected();
        if (src != null && !src.warning().isEmpty()) lines.add(Component.literal(src.warning()));
        if (st != null && st.state() == Payloads.RaveStatus.LIVE && !sending) {
            lines.add(Component.translatable("blockrave.gui.other_live", st.liveSender()));
        }
        lines.add(Component.translatable("blockrave.gui.live_hint"));
        for (Component c : lines) {
            for (var line : font.split(c, 306)) {
                if (y > 166) return;
                g.drawString(font, line, x, y, 0xFFFFFF, false);
                y += 10;
            }
        }
    }

    @Override
    public void render(GuiGraphics g, int mouseX, int mouseY, float partialTick) {
        super.render(g, mouseX, mouseY, partialTick);
        renderTooltip(g, mouseX, mouseY);
    }

    @Override
    public boolean mouseClicked(double mx, double my, int button) {
        if (!liveView) {
            int lx = (int) mx - leftPos - LIST_X, ly = (int) my - topPos - LIST_Y - 2;
            if (lx >= 0 && lx < LIST_W && ly >= 0 && ly < ROWS * ROW_H) {
                int idx = scroll + ly / ROW_H;
                if (idx < rows().size()) {
                    selected = idx;
                    confirmDelete = false;
                    updateVisibility();
                    return true;
                }
            }
        }
        return super.mouseClicked(mx, my, button);
    }

    @Override
    public boolean mouseScrolled(double mx, double my, double sx, double sy) {
        if (!liveView) {
            scroll = Math.max(0, scroll - (int) Math.signum(sy));
            return true;
        }
        return super.mouseScrolled(mx, my, sx, sy);
    }
}
