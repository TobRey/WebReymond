package de.blockrave.client.live.windows;

import com.sun.jna.Function;
import com.sun.jna.Library;
import com.sun.jna.Memory;
import com.sun.jna.Native;
import com.sun.jna.Pointer;
import com.sun.jna.WString;
import com.sun.jna.ptr.PointerByReference;

/**
 * Minimaler COM-Zugriff ueber JNA (Aufruf ueber die vtable). Nur Windows, nur 64 Bit.
 * Methodenindizes entsprechen der Deklarationsreihenfolge in den Windows-SDK-Headern
 * (mmdeviceapi.h, audioclient.h, propsys.h; IUnknown belegt 0..2).
 */
final class Com {
    private Com() {}

    static final int S_OK = 0;
    static final int COINIT_MULTITHREADED = 0x0;
    static final int CLSCTX_ALL = 0x17;

    interface Ole32 extends Library {
        Ole32 I = Native.load("ole32", Ole32.class);

        int CoInitializeEx(Pointer reserved, int coInit);

        void CoUninitialize();

        int CoCreateInstance(Pointer clsid, Pointer outer, int clsCtx, Pointer iid, PointerByReference out);

        void CoTaskMemFree(Pointer p);

        int PropVariantClear(Pointer pv);
    }

    interface Mmdevapi extends Library {
        Mmdevapi I = Native.load("mmdevapi", Mmdevapi.class);

        int ActivateAudioInterfaceAsync(WString deviceInterfacePath, Pointer riid, Pointer activationParams,
                                        Pointer completionHandler, PointerByReference operation);
    }

    interface Kernel32 extends Library {
        Kernel32 I = Native.load("kernel32", Kernel32.class);

        Pointer CreateEventW(Pointer attributes, boolean manualReset, boolean initialState, WString name);

        int WaitForSingleObject(Pointer handle, int millis);

        boolean CloseHandle(Pointer handle);
    }

    static int call(Pointer obj, int index, Object... args) {
        Pointer vtbl = obj.getPointer(0);
        Pointer fn = vtbl.getPointer((long) index * Native.POINTER_SIZE);
        Object[] all = new Object[args.length + 1];
        all[0] = obj;
        System.arraycopy(args, 0, all, 1, args.length);
        return Function.getFunction(fn, Function.ALT_CONVENTION).invokeInt(all);
    }

    static void release(Pointer obj) {
        if (obj != null && Pointer.nativeValue(obj) != 0) call(obj, 2);
    }

    static void check(int hr, String what) {
        if (hr < 0) throw new ComException(what, hr);
    }

    /** GUID im Speicherlayout von Windows (Data1..3 little endian). */
    static Memory guid(String s) {
        String h = s.replace("-", "").replace("{", "").replace("}", "");
        Memory m = new Memory(16);
        m.setInt(0, (int) Long.parseLong(h.substring(0, 8), 16));
        m.setShort(4, (short) Integer.parseInt(h.substring(8, 12), 16));
        m.setShort(6, (short) Integer.parseInt(h.substring(12, 16), 16));
        for (int i = 0; i < 8; i++) m.setByte(8 + i, (byte) Integer.parseInt(h.substring(16 + i * 2, 18 + i * 2), 16));
        return m;
    }

    static boolean guidEquals(Pointer a, Memory b) {
        byte[] x = a.getByteArray(0, 16);
        byte[] y = b.getByteArray(0, 16);
        return java.util.Arrays.equals(x, y);
    }

    static final class ComException extends RuntimeException {
        final int hr;

        ComException(String what, int hr) {
            super(what + " fehlgeschlagen (HRESULT 0x" + Integer.toHexString(hr).toUpperCase(java.util.Locale.ROOT) + ")" + hint(hr));
            this.hr = hr;
        }

        private static String hint(int hr) {
            return switch (hr) {
                case 0x8889000A -> " – Gerät wird exklusiv von einer anderen Anwendung verwendet (Exklusivmodus/ASIO)";
                case 0x88890004 -> " – Gerät wurde entfernt oder deaktiviert";
                case 0x88890008 -> " – Format wird nicht unterstützt";
                case 0x80070005 -> " – Zugriff verweigert (Windows-Datenschutz: Mikrofonzugriff für Desktop-Apps?)";
                case 0x80004001 -> " – von dieser Windows-Version nicht unterstützt";
                default -> "";
            };
        }
    }
}
