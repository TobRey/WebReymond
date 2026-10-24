package de.blockrave.client.upload;

import de.blockrave.BlockRave;
import de.blockrave.client.ClientNet;
import de.blockrave.network.Payloads;
import net.minecraft.Util;
import net.minecraft.client.Minecraft;
import net.minecraft.network.chat.Component;
import org.lwjgl.PointerBuffer;
import org.lwjgl.system.MemoryStack;
import org.lwjgl.util.tinyfd.TinyFileDialogs;

import java.io.IOException;
import java.nio.ByteBuffer;
import java.nio.channels.FileChannel;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardOpenOption;
import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.atomic.AtomicInteger;

/**
 * Datei-Upload vom eigenen PC: Dateiauswahl, Uebertragung in Bloecken von 16 KiB mit
 * Fenster-Flusskontrolle (nur so viele unbestaetigte Bloecke wie der Server erlaubt) auf einem
 * Hintergrund-Thread. Der Renderthread wird nie blockiert.
 */
public final class ClientUploader {
    private static final int MAX_PARALLEL = 2;
    private static final AtomicInteger IDS = new AtomicInteger(1);

    public enum State { WAITING, STARTING, SENDING, PROCESSING, DONE, FAILED }

    public static final class Task {
        public final int clientId = IDS.getAndIncrement();
        public final Path path;
        public final String name;
        public final long size;
        public volatile State state = State.WAITING;
        public volatile String uploadId = "";
        public volatile long acked;
        public volatile long sent;
        public volatile int window = 8;
        public volatile Component message = Component.empty();
        volatile boolean cancelled;

        Task(Path path, long size) {
            this.path = path;
            this.name = path.getFileName().toString();
            this.size = size;
        }

        public float progress() {
            return size <= 0 ? 0 : Math.min(1f, (float) acked / size);
        }
    }

    private final List<Task> tasks = new CopyOnWriteArrayList<>();
    private volatile boolean dialogOpen;

    public List<Task> tasks() {
        return tasks;
    }

    public boolean isDialogOpen() {
        return dialogOpen;
    }

    /** Oeffnet den Dateiauswahldialog (nicht auf dem Renderthread, ausser macOS verlangt es). */
    public void chooseFiles() {
        if (dialogOpen) return;
        dialogOpen = true;
        Runnable r = () -> {
            try {
                String result;
                try (MemoryStack stack = MemoryStack.stackPush()) {
                    PointerBuffer filters = stack.mallocPointer(2);
                    filters.put(stack.UTF8("*.mp3"));
                    filters.put(stack.UTF8("*.MP3"));
                    filters.flip();
                    result = TinyFileDialogs.tinyfd_openFileDialog("MP3-Dateien für BlockRave auswählen", "", filters,
                            "MP3-Dateien (*.mp3)", true);
                }
                if (result != null && !result.isEmpty()) {
                    List<Path> files = new ArrayList<>();
                    for (String s : result.split("\\|")) if (!s.isBlank()) files.add(Path.of(s));
                    Minecraft.getInstance().execute(() -> files.forEach(this::add));
                }
            } catch (Throwable t) {
                BlockRave.LOG.warn("BlockRave: Dateiauswahl fehlgeschlagen", t);
            } finally {
                dialogOpen = false;
            }
        };
        if (Util.getPlatform() == Util.OS.OSX) {
            Minecraft.getInstance().execute(r); // macOS: Dialoge nur auf dem Hauptthread
        } else {
            Thread t = new Thread(r, "BlockRave-FileDialog");
            t.setDaemon(true);
            t.start();
        }
    }

    /** Datei zum Upload einreihen (ohne Dialog; z.B. fuer automatisierte Tests). */
    public void upload(Path p) {
        add(p);
    }

    private void add(Path p) {
        long size;
        try {
            size = Files.size(p);
        } catch (IOException e) {
            return;
        }
        Task t = new Task(p, size);
        tasks.add(t);
        while (tasks.size() > 20) {
            Task first = tasks.get(0);
            if (first.state == State.DONE || first.state == State.FAILED) tasks.remove(0);
            else break;
        }
        pump();
    }

    /** Startet wartende Uploads, solange das Limit es erlaubt. */
    public void pump() {
        long active = tasks.stream().filter(t -> t.state == State.STARTING || t.state == State.SENDING).count();
        for (Task t : tasks) {
            if (active >= MAX_PARALLEL) break;
            if (t.state == State.WAITING) {
                t.state = State.STARTING;
                ClientNet.send(new Payloads.UploadBegin(t.clientId, t.name, t.size));
                active++;
            }
        }
    }

    public void cancel(Task t) {
        t.cancelled = true;
        if (!t.uploadId.isEmpty() && (t.state == State.SENDING || t.state == State.STARTING)) {
            ClientNet.send(new Payloads.UploadCancel(t.uploadId));
        }
        t.state = State.FAILED;
        t.message = Component.translatable("blockrave.upload.cancelled");
        synchronized (t) {
            t.notifyAll();
        }
    }

    public void onStatus(Payloads.UploadStatus s) {
        Task t = null;
        for (Task x : tasks) if (x.clientId == s.clientUploadId()) t = x;
        if (t == null) return;
        switch (s.state()) {
            case Payloads.UploadStatus.ACCEPTED -> {
                t.uploadId = s.uploadId();
                t.window = Math.max(1, s.windowChunks());
                t.state = State.SENDING;
                startSender(t);
            }
            case Payloads.UploadStatus.PROGRESS -> {
                t.acked = s.received();
                synchronized (t) {
                    t.notifyAll();
                }
            }
            case Payloads.UploadStatus.PROCESSING -> {
                t.acked = t.size;
                t.state = State.PROCESSING;
            }
            case Payloads.UploadStatus.DONE -> {
                t.acked = t.size;
                t.state = State.DONE;
                t.message = s.message();
            }
            case Payloads.UploadStatus.FAILED -> {
                t.state = State.FAILED;
                t.message = s.message();
                synchronized (t) {
                    t.notifyAll();
                }
            }
            default -> {}
        }
        pump();
    }

    private void startSender(Task t) {
        Thread th = new Thread(() -> send(t), "BlockRave-Upload-" + t.clientId);
        th.setDaemon(true);
        th.start();
    }

    private void send(Task t) {
        try (FileChannel ch = FileChannel.open(t.path, StandardOpenOption.READ)) {
            ByteBuffer buf = ByteBuffer.allocate(Payloads.MAX_CHUNK);
            long offset = 0;
            while (offset < t.size && !t.cancelled && t.state == State.SENDING) {
                synchronized (t) {
                    while ((offset - t.acked) >= (long) t.window * Payloads.MAX_CHUNK && !t.cancelled && t.state == State.SENDING) {
                        t.wait(250);
                    }
                }
                if (t.cancelled || t.state != State.SENDING) return;
                buf.clear();
                int n = ch.read(buf, offset);
                if (n <= 0) throw new IOException("Datei kuerzer als erwartet");
                byte[] data = new byte[n];
                buf.flip();
                buf.get(data);
                if (!ClientNet.send(new Payloads.UploadChunk(t.uploadId, offset, data))) throw new IOException("Verbindung getrennt");
                offset += n;
                t.sent = offset;
            }
        } catch (IOException e) {
            t.state = State.FAILED;
            t.message = Component.translatable("blockrave.upload.read_error", e.getMessage());
            ClientNet.send(new Payloads.UploadCancel(t.uploadId));
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    /** Disconnect: alle laufenden Uebertragungen beenden. */
    public void shutdown() {
        for (Task t : tasks) {
            t.cancelled = true;
            synchronized (t) {
                t.notifyAll();
            }
        }
        tasks.clear();
    }
}
