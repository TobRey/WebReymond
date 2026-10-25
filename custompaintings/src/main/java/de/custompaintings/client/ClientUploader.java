package de.custompaintings.client;

import de.custompaintings.CustomPaintings;
import de.custompaintings.core.ImageCodec;
import de.custompaintings.network.Payloads;
import net.minecraft.client.Minecraft;
import net.minecraft.network.chat.Component;
import net.neoforged.neoforge.network.PacketDistributor;

import java.util.Arrays;

/**
 * Laedt ein fertig zugeschnittenes PNG fuer ein Gemaelde hoch. Ablauf: Bild-ID (SHA-256) melden; kennt der
 * Server das Bild schon, ist es sofort gesetzt, sonst werden die Daten in Bloecken nachgereicht.
 */
public final class ClientUploader {
    public static final ClientUploader INSTANCE = new ClientUploader();

    public enum State { IDLE, WAITING, SENDING, DONE, FAILED }

    private volatile State state = State.IDLE;
    private volatile Component message = Component.empty();
    private volatile float progress;
    private volatile int entityId = -1;
    private volatile String hash = "";
    private volatile byte[] data;
    private volatile boolean dataSent;

    private ClientUploader() {}

    public State state() {
        return state;
    }

    public Component message() {
        return message;
    }

    public float progress() {
        return progress;
    }

    public String hash() {
        return hash;
    }

    /** Wurden beim letzten Upload Bilddaten uebertragen (false = Server kannte das Bild schon)? */
    public boolean dataSent() {
        return dataSent;
    }

    public boolean busy() {
        return state == State.WAITING || state == State.SENDING;
    }

    /** Bild setzen (PNG, bereits auf das Gemaeldeformat gebracht). */
    public void upload(int entityId, byte[] png) {
        if (busy()) return;
        this.entityId = entityId;
        this.data = png;
        this.hash = ImageCodec.sha256(png);
        this.progress = 0;
        this.dataSent = false;
        this.state = State.WAITING;
        this.message = Component.translatable("custompaintings.gui.checking");
        PacketDistributor.sendToServer(new Payloads.SetImage(entityId, hash, png.length));
    }

    /** Originalmotiv wiederherstellen. */
    public void reset(int entityId) {
        if (busy()) return;
        this.entityId = entityId;
        this.data = null;
        this.hash = "";
        this.state = State.WAITING;
        this.message = Component.translatable("custompaintings.gui.checking");
        PacketDistributor.sendToServer(new Payloads.SetImage(entityId, "", 0));
    }

    /** Antwort des Servers (Hauptthread). */
    public void onReply(Payloads.UploadReply r) {
        if (r.entityId() != entityId || !r.hash().equals(hash) || !busy()) return;
        switch (r.status()) {
            case Payloads.UploadReply.SEND_DATA -> sendData();
            case Payloads.UploadReply.DONE -> {
                progress = 1;
                state = State.DONE;
                message = r.message();
                byte[] d = data;
                if (d != null && !hash.isEmpty()) ClientImages.INSTANCE.preload(hash, d);
                data = null;
            }
            default -> {
                state = State.FAILED;
                message = r.message();
                data = null;
            }
        }
    }

    private void sendData() {
        byte[] d = data;
        String h = hash;
        if (d == null) return;
        state = State.SENDING;
        dataSent = true;
        message = Component.translatable("custompaintings.gui.uploading");
        Thread t = new Thread(() -> {
            try {
                for (int off = 0; off < d.length; off += Payloads.UPLOAD_CHUNK) {
                    byte[] part = Arrays.copyOfRange(d, off, Math.min(d.length, off + Payloads.UPLOAD_CHUNK));
                    PacketDistributor.sendToServer(new Payloads.UploadChunk(h, off, part));
                    progress = Math.min(0.99f, (off + part.length) / (float) d.length);
                    if ((off / Payloads.UPLOAD_CHUNK) % 16 == 15) Thread.sleep(5); // Netzwerk nicht fluten
                }
            } catch (Exception e) {
                CustomPaintings.LOG.warn("Custom Paintings: Upload fehlgeschlagen", e);
                Minecraft.getInstance().execute(() -> {
                    state = State.FAILED;
                    message = Component.translatable("custompaintings.msg.transfer");
                });
            }
        }, "CustomPaintings-Upload");
        t.setDaemon(true);
        t.start();
    }

    /** Neue Verbindung: alten Zustand vergessen. */
    public void clear() {
        state = State.IDLE;
        message = Component.empty();
        data = null;
        hash = "";
        entityId = -1;
    }
}
