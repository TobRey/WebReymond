package de.blockrave.client.live.windows;

import com.sun.jna.Callback;
import com.sun.jna.CallbackReference;
import com.sun.jna.Memory;
import com.sun.jna.Native;
import com.sun.jna.Pointer;
import com.sun.jna.WString;
import com.sun.jna.ptr.IntByReference;
import com.sun.jna.ptr.PointerByReference;
import de.blockrave.BlockRave;
import de.blockrave.client.live.CaptureBackend;
import de.blockrave.client.live.CaptureSource;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.Optional;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;

/**
 * Live-Aufnahme unter Windows ueber WASAPI (Windows Core Audio), direkt per JNA &ndash; ohne
 * separates Hilfsprogramm.
 *
 * <ul>
 *   <li>Prozess-Loopback (ab Windows 10 Build 20438 / Windows 11): nur rekordbox.exe oder
 *       der gesamte PC-Klang ohne Minecraft (verhindert Rueckkopplung).</li>
 *   <li>Endpunkt-Loopback: was auf einem Ausgabegeraet im Shared Mode abgespielt wird.</li>
 *   <li>Aufnahmegeraete (Line-In, Stereomix, virtuelles Kabel). Mikrofone werden nie automatisch
 *       gewaehlt.</li>
 * </ul>
 * Grenzen (laut Microsoft): Loopback funktioniert nur fuer Shared-Mode-Streams; Audio, das per ASIO
 * oder im Exklusivmodus ausgegeben wird, laeuft nicht durch die Windows-Audio-Engine und kann so
 * nicht erfasst werden.
 */
public final class WasapiBackend implements CaptureBackend {
    // GUIDs aus mmdeviceapi.h / audioclient.h / objidl.h
    private static final String CLSID_MMDeviceEnumerator = "bcde0395-e52f-467c-8e3d-c4579291692e";
    private static final String IID_IMMDeviceEnumerator = "a95664d2-9614-4f35-a746-de8db63617e6";
    private static final String IID_IAudioClient = "1cb9ad4c-dbfa-4c32-b178-c2f568a703b2";
    private static final String IID_IAudioCaptureClient = "c8adbd64-e71e-48a0-a4de-185c395cd317";
    private static final String IID_IUnknown = "00000000-0000-0000-c000-000000000046";
    private static final String IID_IAgileObject = "94ea2b94-e9cc-49e0-c0ff-ee64ca8f5b90";
    private static final String IID_IActivateAudioInterfaceCompletionHandler = "41d949ab-9862-444a-80f6-c261334da5eb";
    private static final String PKEY_Device_FriendlyName_FMTID = "a45c254e-df1c-4efd-8020-67d146a850e0";
    private static final String VIRTUAL_AUDIO_DEVICE_PROCESS_LOOPBACK = "VAD\\Process_Loopback";

    private static final int E_RENDER = 0, E_CAPTURE = 1, E_CONSOLE = 0;
    private static final int DEVICE_STATE_ACTIVE = 1;
    private static final int AUDCLNT_SHAREMODE_SHARED = 0;
    private static final int STREAMFLAGS_LOOPBACK = 0x00020000;
    private static final int STREAMFLAGS_EVENTCALLBACK = 0x00040000;
    private static final int STREAMFLAGS_AUTOCONVERTPCM = 0x80000000;
    private static final int STREAMFLAGS_SRC_DEFAULT_QUALITY = 0x08000000;
    private static final int BUFFERFLAGS_SILENT = 0x2;
    private static final long BUFFER_HNS = 2_000_000; // 200 ms
    private static final short VT_BLOB = 65;
    private static final short VT_LPWSTR = 31;

    public static final String ID_REKORDBOX = "process:rekordbox";
    public static final String ID_ALL_BUT_MINECRAFT = "process:exclude-self";

    public WasapiBackend() {
        if (Native.POINTER_SIZE != 8) throw new IllegalStateException("nur 64-Bit-Java wird unterstuetzt");
        // Bibliotheken laden (wirft, falls nicht vorhanden)
        Com.Ole32.I.hashCode();
    }

    @Override
    public boolean isSupported() {
        return true;
    }

    @Override
    public String unsupportedReason() {
        return "";
    }

    // ------------------------------------------------------------------ Quellen

    private static Optional<Long> rekordboxPid() {
        return ProcessHandle.allProcesses()
                .filter(p -> p.info().command().map(c -> c.toLowerCase(Locale.ROOT).endsWith("\\rekordbox.exe")).orElse(false))
                .map(ProcessHandle::pid)
                .findFirst();
    }

    @Override
    public List<CaptureSource> listSources() {
        List<CaptureSource> out = new ArrayList<>();
        if (rekordboxPid().isPresent()) {
            out.add(new CaptureSource(ID_REKORDBOX, "Nur rekordbox (Prozess-Loopback)", CaptureSource.Kind.PROCESS_INCLUDE,
                    "Erfasst nur, was rekordbox über die Windows-Audioausgabe abspielt (nicht über ASIO)."));
        }
        out.add(new CaptureSource(ID_ALL_BUT_MINECRAFT, "Gesamter PC-Klang ohne Minecraft", CaptureSource.Kind.PROCESS_EXCLUDE,
                "Alles außer Minecraft (keine Rückkopplung). Benötigt Windows 11 bzw. Windows 10 Build 20438+."));
        int hr = Com.Ole32.I.CoInitializeEx(null, Com.COINIT_MULTITHREADED);
        try {
            Pointer enumerator = createEnumerator();
            try {
                String defaultRender = defaultId(enumerator, E_RENDER);
                for (Device d : devices(enumerator, E_RENDER)) {
                    String label = "Ausgang: " + d.name + (d.id.equals(defaultRender) ? " (Standard)" : "") + " – Loopback";
                    out.add(new CaptureSource("render:" + d.id, label, CaptureSource.Kind.ENDPOINT_LOOPBACK,
                            "Enthält auch Minecraft-Klänge, falls Minecraft auf diesem Gerät ausgibt."));
                }
                for (Device d : devices(enumerator, E_CAPTURE)) {
                    out.add(new CaptureSource("capture:" + d.id, "Eingang: " + d.name, CaptureSource.Kind.INPUT,
                            "Aufnahmegerät – nur wählen, wenn hier der DJ-Mix anliegt (z.B. Line-In, Stereomix, virtuelles Kabel). Kein Mikrofon!"));
                }
            } finally {
                Com.release(enumerator);
            }
        } finally {
            if (hr >= 0) Com.Ole32.I.CoUninitialize();
        }
        return out;
    }

    private record Device(String id, String name) {}

    private static Pointer createEnumerator() {
        PointerByReference ref = new PointerByReference();
        Com.check(Com.Ole32.I.CoCreateInstance(Com.guid(CLSID_MMDeviceEnumerator), null, Com.CLSCTX_ALL,
                Com.guid(IID_IMMDeviceEnumerator), ref), "CoCreateInstance(MMDeviceEnumerator)");
        return ref.getValue();
    }

    private static String defaultId(Pointer enumerator, int flow) {
        PointerByReference dev = new PointerByReference();
        // IMMDeviceEnumerator::GetDefaultAudioEndpoint = 4
        if (Com.call(enumerator, 4, flow, E_CONSOLE, dev) < 0) return "";
        try {
            return deviceId(dev.getValue());
        } finally {
            Com.release(dev.getValue());
        }
    }

    private static String deviceId(Pointer device) {
        PointerByReference str = new PointerByReference();
        // IMMDevice::GetId = 5
        Com.check(Com.call(device, 5, str), "IMMDevice::GetId");
        String s = str.getValue().getWideString(0);
        Com.Ole32.I.CoTaskMemFree(str.getValue());
        return s;
    }

    private static List<Device> devices(Pointer enumerator, int flow) {
        List<Device> list = new ArrayList<>();
        PointerByReference coll = new PointerByReference();
        // IMMDeviceEnumerator::EnumAudioEndpoints = 3
        Com.check(Com.call(enumerator, 3, flow, DEVICE_STATE_ACTIVE, coll), "EnumAudioEndpoints");
        try {
            IntByReference count = new IntByReference();
            Com.call(coll.getValue(), 3, count); // GetCount
            for (int i = 0; i < count.getValue(); i++) {
                PointerByReference dev = new PointerByReference();
                if (Com.call(coll.getValue(), 4, i, dev) < 0) continue; // Item
                try {
                    list.add(new Device(deviceId(dev.getValue()), friendlyName(dev.getValue())));
                } catch (RuntimeException e) {
                    BlockRave.LOG.debug("BlockRave: Audiogeraet uebersprungen: {}", e.getMessage());
                } finally {
                    Com.release(dev.getValue());
                }
            }
        } finally {
            Com.release(coll.getValue());
        }
        return list;
    }

    private static String friendlyName(Pointer device) {
        PointerByReference store = new PointerByReference();
        // IMMDevice::OpenPropertyStore = 4 (STGM_READ = 0)
        if (Com.call(device, 4, 0, store) < 0) return "Unbekanntes Gerät";
        try {
            Memory key = new Memory(20);
            key.write(0, Com.guid(PKEY_Device_FriendlyName_FMTID).getByteArray(0, 16), 0, 16);
            key.setInt(16, 14);
            Memory pv = new Memory(24);
            pv.clear();
            // IPropertyStore::GetValue = 5
            if (Com.call(store.getValue(), 5, key, pv) < 0) return "Unbekanntes Gerät";
            String name = "Unbekanntes Gerät";
            if (pv.getShort(0) == VT_LPWSTR && pv.getPointer(8) != null) name = pv.getPointer(8).getWideString(0);
            Com.Ole32.I.PropVariantClear(pv);
            return name;
        } finally {
            Com.release(store.getValue());
        }
    }

    // ------------------------------------------------------------------ Aufnahme

    @Override
    public Stream open(CaptureSource source, Sink sink) throws Exception {
        WasapiStream s = new WasapiStream(source, sink);
        s.startAndWait();
        return s;
    }

    /** Aufnahme-Thread mit eigener COM-Initialisierung; alle Ressourcen werden in close() freigegeben. */
    private static final class WasapiStream implements Stream {
        private final CaptureSource source;
        private final Sink sink;
        private final Thread thread;
        private volatile boolean running = true;
        private final CountDownLatch started = new CountDownLatch(1);
        private volatile Exception startError;
        private volatile int latencyMs = 30;

        WasapiStream(CaptureSource source, Sink sink) {
            this.source = source;
            this.sink = sink;
            this.thread = new Thread(this::run, "BlockRave-Capture");
            this.thread.setDaemon(true);
            this.thread.setPriority(Thread.MAX_PRIORITY);
        }

        void startAndWait() throws Exception {
            thread.start();
            if (!started.await(8, TimeUnit.SECONDS)) {
                close();
                throw new IllegalStateException("Zeitüberschreitung beim Öffnen der Audioquelle");
            }
            if (startError != null) {
                close();
                throw startError;
            }
        }

        @Override
        public int latencyMillis() {
            return latencyMs;
        }

        private void run() {
            int hrInit = Com.Ole32.I.CoInitializeEx(null, Com.COINIT_MULTITHREADED);
            Pointer client = null, capture = null, event = null;
            try {
                client = activate();
                boolean loopback = source.kind() != CaptureSource.Kind.INPUT;
                Format fmt = initialize(client, loopback);
                event = Com.Kernel32.I.CreateEventW(null, false, false, null);
                Com.check(Com.call(client, 13, event), "IAudioClient::SetEventHandle");
                PointerByReference cap = new PointerByReference();
                Com.check(Com.call(client, 14, Com.guid(IID_IAudioCaptureClient), cap), "IAudioClient::GetService");
                capture = cap.getValue();
                long[] lat = new long[1];
                if (Com.call(client, 5, lat) >= 0) latencyMs = (int) Math.max(10, lat[0] / 10_000);
                Com.check(Com.call(client, 10), "IAudioClient::Start");
                BlockRave.LOG.info("BlockRave: Live-Aufnahme gestartet: {} ({} Hz, {} Kanaele, {})", source.name(), fmt.rate,
                        fmt.channels, fmt.isFloat ? "float" : fmt.bits + " bit");
                started.countDown();
                loop(capture, event, fmt);
                Com.call(client, 11); // Stop
            } catch (Exception e) {
                if (started.getCount() > 0) {
                    startError = e;
                    started.countDown();
                } else {
                    sink.onError(e.getMessage());
                }
            } finally {
                Com.release(capture);
                Com.release(client);
                if (event != null) Com.Kernel32.I.CloseHandle(event);
                if (hrInit >= 0) Com.Ole32.I.CoUninitialize();
                BlockRave.LOG.info("BlockRave: Live-Aufnahme beendet, Ressourcen freigegeben");
            }
        }

        private void loop(Pointer capture, Pointer event, Format fmt) {
            IntByReference packet = new IntByReference();
            PointerByReference data = new PointerByReference();
            IntByReference frames = new IntByReference();
            IntByReference flags = new IntByReference();
            float[] buf = new float[0];
            while (running) {
                Com.Kernel32.I.WaitForSingleObject(event, 100);
                while (running) {
                    int hr = Com.call(capture, 5, packet); // GetNextPacketSize
                    if (hr < 0) throw new Com.ComException("GetNextPacketSize", hr);
                    if (packet.getValue() == 0) break;
                    hr = Com.call(capture, 3, data, frames, flags, Pointer.NULL, Pointer.NULL); // GetBuffer
                    if (hr < 0) throw new Com.ComException("IAudioCaptureClient::GetBuffer", hr);
                    int n = frames.getValue();
                    int samples = n * fmt.channels;
                    if (buf.length < samples) buf = new float[samples];
                    if ((flags.getValue() & BUFFERFLAGS_SILENT) != 0) {
                        java.util.Arrays.fill(buf, 0, samples, 0f);
                    } else {
                        convert(data.getValue(), buf, samples, fmt);
                    }
                    Com.call(capture, 4, n); // ReleaseBuffer
                    sink.onAudio(buf, n, fmt.channels, fmt.rate);
                }
            }
        }

        private static void convert(Pointer p, float[] out, int samples, Format fmt) {
            if (fmt.isFloat && fmt.bits == 32) {
                p.read(0, out, 0, samples);
            } else if (fmt.bits == 16) {
                short[] s = p.getShortArray(0, samples);
                for (int i = 0; i < samples; i++) out[i] = s[i] / 32768f;
            } else if (fmt.bits == 32) {
                int[] s = p.getIntArray(0, samples);
                for (int i = 0; i < samples; i++) out[i] = s[i] / 2147483648f;
            } else if (fmt.bits == 24) {
                byte[] b = p.getByteArray(0, samples * 3);
                for (int i = 0; i < samples; i++) {
                    int v = (b[i * 3] & 0xFF) | ((b[i * 3 + 1] & 0xFF) << 8) | (b[i * 3 + 2] << 16);
                    out[i] = v / 8388608f;
                }
            } else {
                java.util.Arrays.fill(out, 0, samples, 0f);
            }
        }

        private record Format(int rate, int channels, int bits, boolean isFloat) {}

        private Format initialize(Pointer client, boolean loopback) {
            int flags = STREAMFLAGS_EVENTCALLBACK | (loopback ? STREAMFLAGS_LOOPBACK : 0);
            // Bevorzugt: 48 kHz / 16 bit / Stereo mit automatischer Wandlung durch Windows
            Memory wf = new Memory(20);
            wf.clear();
            wf.setShort(0, (short) 1);       // WAVE_FORMAT_PCM
            wf.setShort(2, (short) 2);       // Kanaele
            wf.setInt(4, 48_000);            // Abtastrate
            wf.setInt(8, 48_000 * 4);        // Bytes/s
            wf.setShort(12, (short) 4);      // Blockgroesse
            wf.setShort(14, (short) 16);     // Bits
            wf.setShort(16, (short) 0);      // cbSize
            int hr = Com.call(client, 3, AUDCLNT_SHAREMODE_SHARED,
                    flags | STREAMFLAGS_AUTOCONVERTPCM | STREAMFLAGS_SRC_DEFAULT_QUALITY, BUFFER_HNS, 0L, wf, Pointer.NULL);
            if (hr >= 0) return new Format(48_000, 2, 16, false);
            if (source.kind() == CaptureSource.Kind.PROCESS_EXCLUDE || source.kind() == CaptureSource.Kind.PROCESS_INCLUDE) {
                throw new Com.ComException("IAudioClient::Initialize (Prozess-Loopback)", hr);
            }
            // Fallback: Mischformat des Geraets
            PointerByReference mix = new PointerByReference();
            Com.check(Com.call(client, 8, mix), "IAudioClient::GetMixFormat");
            Pointer m = mix.getValue();
            try {
                int tag = m.getShort(0) & 0xFFFF;
                int ch = m.getShort(2) & 0xFFFF;
                int rate = m.getInt(4);
                int bits = m.getShort(14) & 0xFFFF;
                boolean isFloat = tag == 3;
                if (tag == 0xFFFE) isFloat = m.getInt(24) == 3; // WAVEFORMATEXTENSIBLE.SubFormat.Data1
                Com.check(Com.call(client, 3, AUDCLNT_SHAREMODE_SHARED, flags, BUFFER_HNS, 0L, m, Pointer.NULL),
                        "IAudioClient::Initialize");
                return new Format(rate, ch, bits, isFloat);
            } finally {
                Com.Ole32.I.CoTaskMemFree(m);
            }
        }

        private Pointer activate() throws Exception {
            switch (source.kind()) {
                case PROCESS_INCLUDE, PROCESS_EXCLUDE -> {
                    long pid;
                    int mode;
                    if (source.kind() == CaptureSource.Kind.PROCESS_INCLUDE) {
                        pid = rekordboxPid().orElseThrow(() -> new IllegalStateException("rekordbox.exe läuft nicht"));
                        mode = 0; // PROCESS_LOOPBACK_MODE_INCLUDE_TARGET_PROCESS_TREE
                    } else {
                        pid = ProcessHandle.current().pid();
                        mode = 1; // PROCESS_LOOPBACK_MODE_EXCLUDE_TARGET_PROCESS_TREE
                    }
                    return activateProcessLoopback((int) pid, mode);
                }
                default -> {
                    String id = source.id().substring(source.id().indexOf(':') + 1);
                    Pointer enumerator = createEnumerator();
                    try {
                        PointerByReference dev = new PointerByReference();
                        // IMMDeviceEnumerator::GetDevice = 5
                        Com.check(Com.call(enumerator, 5, new WString(id), dev), "Audiogerät nicht gefunden");
                        try {
                            PointerByReference client = new PointerByReference();
                            // IMMDevice::Activate = 3
                            Com.check(Com.call(dev.getValue(), 3, Com.guid(IID_IAudioClient), Com.CLSCTX_ALL, Pointer.NULL, client),
                                    "IMMDevice::Activate");
                            return client.getValue();
                        } finally {
                            Com.release(dev.getValue());
                        }
                    } finally {
                        Com.release(enumerator);
                    }
                }
            }
        }

        // --- IActivateAudioInterfaceCompletionHandler als minimales, agiles COM-Objekt ---

        private static final List<Object> KEEP_ALIVE = java.util.Collections.synchronizedList(new ArrayList<>());

        public interface QueryInterfaceFn extends Callback {
            int invoke(Pointer self, Pointer riid, Pointer ppv);
        }

        public interface RefFn extends Callback {
            int invoke(Pointer self);
        }

        public interface CompletedFn extends Callback {
            int invoke(Pointer self, Pointer operation);
        }

        private Pointer activateProcessLoopback(int pid, int mode) throws Exception {
            Memory iidUnknown = Com.guid(IID_IUnknown);
            Memory iidAgile = Com.guid(IID_IAgileObject);
            Memory iidHandler = Com.guid(IID_IActivateAudioInterfaceCompletionHandler);
            CountDownLatch done = new CountDownLatch(1);
            QueryInterfaceFn qi = (self, riid, ppv) -> {
                if (Com.guidEquals(riid, iidUnknown) || Com.guidEquals(riid, iidAgile) || Com.guidEquals(riid, iidHandler)) {
                    ppv.setPointer(0, self);
                    return 0;
                }
                ppv.setPointer(0, Pointer.NULL);
                return 0x80004002; // E_NOINTERFACE
            };
            RefFn addRef = self -> 1;
            RefFn release = self -> 1; // Lebensdauer wird von Java verwaltet (siehe unten)
            CompletedFn completed = (self, op) -> {
                done.countDown();
                return 0;
            };
            Memory vtbl = new Memory(4L * Native.POINTER_SIZE);
            vtbl.setPointer(0, CallbackReference.getFunctionPointer(qi));
            vtbl.setPointer(Native.POINTER_SIZE, CallbackReference.getFunctionPointer(addRef));
            vtbl.setPointer(2L * Native.POINTER_SIZE, CallbackReference.getFunctionPointer(release));
            vtbl.setPointer(3L * Native.POINTER_SIZE, CallbackReference.getFunctionPointer(completed));
            Memory handler = new Memory(Native.POINTER_SIZE);
            handler.setPointer(0, vtbl);
            // Windows darf den Handler auch nach dieser Methode noch freigeben: dauerhaft referenzieren
            KEEP_ALIVE.add(new Object[]{qi, addRef, release, completed, vtbl, handler});

            // AUDIOCLIENT_ACTIVATION_PARAMS { ActivationType=PROCESS_LOOPBACK(1), TargetProcessId, ProcessLoopbackMode }
            Memory params = new Memory(12);
            params.setInt(0, 1);
            params.setInt(4, pid);
            params.setInt(8, mode);
            // PROPVARIANT (VT_BLOB): vt @0, cbSize @8, pBlobData @16
            Memory pv = new Memory(24);
            pv.clear();
            pv.setShort(0, VT_BLOB);
            pv.setInt(8, 12);
            pv.setPointer(16, params);

            PointerByReference op = new PointerByReference();
            Com.check(Com.Mmdevapi.I.ActivateAudioInterfaceAsync(new WString(VIRTUAL_AUDIO_DEVICE_PROCESS_LOOPBACK),
                    Com.guid(IID_IAudioClient), pv, handler, op), "ActivateAudioInterfaceAsync (Prozess-Loopback)");
            try {
                if (!done.await(5, TimeUnit.SECONDS)) throw new IllegalStateException("Prozess-Loopback: keine Antwort von Windows");
                IntByReference hr = new IntByReference();
                PointerByReference unk = new PointerByReference();
                // IActivateAudioInterfaceAsyncOperation::GetActivateResult = 3
                Com.check(Com.call(op.getValue(), 3, hr, unk), "GetActivateResult");
                Com.check(hr.getValue(), "Prozess-Loopback aktivieren");
                return unk.getValue();
            } finally {
                Com.release(op.getValue());
                // Rueckrufe bis hier stark referenziert halten (GC-Schutz der JNA-Callbacks)
                java.lang.ref.Reference.reachabilityFence(qi);
                java.lang.ref.Reference.reachabilityFence(addRef);
                java.lang.ref.Reference.reachabilityFence(release);
                java.lang.ref.Reference.reachabilityFence(completed);
                java.lang.ref.Reference.reachabilityFence(handler);
                java.lang.ref.Reference.reachabilityFence(vtbl);
            }
        }

        @Override
        public void close() {
            running = false;
            if (Thread.currentThread() != thread) {
                try {
                    thread.join(2000);
                } catch (InterruptedException e) {
                    Thread.currentThread().interrupt();
                }
            }
        }
    }
}
