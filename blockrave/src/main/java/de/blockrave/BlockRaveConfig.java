package de.blockrave;

import net.neoforged.neoforge.common.ModConfigSpec;

/**
 * Konfiguration. Server-Werte liegen pro Welt in {@code serverconfig/blockrave-server.toml},
 * Client-Werte in {@code config/blockrave-client.toml}. Alle Werte sind in docs/KONFIGURATION.md beschrieben.
 */
public final class BlockRaveConfig {
    private BlockRaveConfig() {}

    // ---------------------------------------------------------------- Server
    public static final ModConfigSpec SERVER_SPEC;
    public static final ModConfigSpec.IntValue MAX_UPLOAD_MB;
    public static final ModConfigSpec.IntValue MAX_STORAGE_MB;
    public static final ModConfigSpec.IntValue MAX_TRACKS;
    public static final ModConfigSpec.IntValue MAX_TRACK_MINUTES;
    public static final ModConfigSpec.IntValue MAX_PARALLEL_UPLOADS;
    public static final ModConfigSpec.IntValue UPLOAD_RATE_KBPS;
    public static final ModConfigSpec.IntValue HEARING_RANGE;
    public static final ModConfigSpec.IntValue SHOW_RANGE;
    public static final ModConfigSpec.IntValue MP3_BUFFER_MS;
    public static final ModConfigSpec.IntValue LIVE_DELAY_MS;
    public static final ModConfigSpec.IntValue OPUS_BITRATE;
    public static final ModConfigSpec.BooleanValue ALLOW_UPLOADS;
    public static final ModConfigSpec.BooleanValue ALLOW_LIVE;

    // ---------------------------------------------------------------- Client
    public static final ModConfigSpec CLIENT_SPEC;
    public static final ModConfigSpec.IntValue LOCAL_VOLUME;
    public static final ModConfigSpec.BooleanValue MUTED;
    public static final ModConfigSpec.EnumValue<Quality> EFFECT_QUALITY;
    public static final ModConfigSpec.IntValue PARTICLE_AMOUNT;
    public static final ModConfigSpec.BooleanValue FOG_ENABLED;
    public static final ModConfigSpec.EnumValue<StrobeMode> STROBE_MODE;
    public static final ModConfigSpec.BooleanValue MONITOR_OWN_LIVE;
    public static final ModConfigSpec.IntValue LIVE_BITRATE;
    public static final ModConfigSpec.ConfigValue<String> LIVE_SOURCE;

    public enum Quality { NIEDRIG, MITTEL, HOCH }

    public enum StrobeMode { VOLL, REDUZIERT, AUS }

    static {
        ModConfigSpec.Builder b = new ModConfigSpec.Builder();
        b.comment("Uploads und Speicher").push("uploads");
        ALLOW_UPLOADS = b.comment("Duerfen alle Spieler MP3-Dateien hochladen?")
                .translation("blockrave.config.allowUploads").define("allowUploads", true);
        MAX_UPLOAD_MB = b.comment("Maximale Groesse einer MP3-Datei in MiB.")
                .translation("blockrave.config.maxUploadMb").defineInRange("maxUploadMb", 50, 1, 500);
        MAX_STORAGE_MB = b.comment("Maximaler Speicherplatz aller hochgeladenen Titel zusammen in MiB.")
                .translation("blockrave.config.maxStorageMb").defineInRange("maxStorageMb", 2048, 16, 100_000);
        MAX_TRACKS = b.comment("Maximale Anzahl Titel in der Bibliothek.")
                .translation("blockrave.config.maxTracks").defineInRange("maxTracks", 500, 1, 10_000);
        MAX_TRACK_MINUTES = b.comment("Maximale Laenge eines Titels in Minuten.")
                .translation("blockrave.config.maxTrackMinutes").defineInRange("maxTrackMinutes", 90, 1, 600);
        MAX_PARALLEL_UPLOADS = b.comment("Gleichzeitige Uploads pro Spieler.")
                .translation("blockrave.config.maxParallelUploads").defineInRange("maxParallelUploads", 2, 1, 8);
        UPLOAD_RATE_KBPS = b.comment("Maximale Uploadrate pro Spieler in KiB/s (schont Server und Netzwerk).")
                .translation("blockrave.config.uploadRateKbps").defineInRange("uploadRateKbps", 1024, 64, 65_536);
        b.pop();

        b.comment("Wiedergabe").push("playback");
        HEARING_RANGE = b.comment("Hoerreichweite in Bloecken (ab Buehnenmitte). Ausserhalb ist es still.")
                .translation("blockrave.config.hearingRange").defineInRange("hearingRange", 128, 16, 256);
        SHOW_RANGE = b.comment("Reichweite, in der Spieler Lichtshow-Daten (ohne Audio) erhalten.")
                .translation("blockrave.config.showRange").defineInRange("showRange", 256, 32, 512);
        MP3_BUFFER_MS = b.comment("Vorlauf der MP3-Wiedergabe in Millisekunden (Puffer gegen Netzwerkschwankungen).")
                .translation("blockrave.config.mp3BufferMs").defineInRange("mp3BufferMs", 1000, 300, 5000);
        // Neuer Schluessel (frueher "liveDelayMs" mit 1500 ms), damit bestehende Server den kleineren Standard bekommen.
        LIVE_DELAY_MS = b.comment("Puffer-Verzoegerung fuer Live-Streams in Millisekunden. Kleiner = weniger Verzoegerung,",
                        "aber anfaelliger fuer Aussetzer bei schwankendem Ping (LAN/guter Server: 300-500, Internet: 500-1000).")
                .translation("blockrave.config.liveBufferMs").defineInRange("liveBufferMs", 500, 200, 6000);
        OPUS_BITRATE = b.comment("Opus-Bitrate fuer MP3-Wiedergabe in Bit/s.")
                .translation("blockrave.config.opusBitrate").defineInRange("opusBitrate", 160_000, 48_000, 320_000);
        ALLOW_LIVE = b.comment("Duerfen alle Spieler Live-Audio senden?")
                .translation("blockrave.config.allowLive").define("allowLive", true);
        b.pop();
        SERVER_SPEC = b.build();

        ModConfigSpec.Builder c = new ModConfigSpec.Builder();
        c.comment("Audio (nur auf diesem PC)").push("audio");
        LOCAL_VOLUME = c.comment("Lokale Lautstaerke der Rave-Musik in Prozent.")
                .translation("blockrave.config.localVolume").defineInRange("localVolume", 100, 0, 100);
        MUTED = c.comment("Rave-Musik lokal stummschalten.").translation("blockrave.config.muted").define("muted", false);
        MONITOR_OWN_LIVE = c.comment("Eigenen Live-Stream zusaetzlich ueber Minecraft hoeren (Vorsicht: Rueckkopplung bei Loopback!).")
                .translation("blockrave.config.monitorOwnLive").define("monitorOwnLive", false);
        LIVE_BITRATE = c.comment("Opus-Bitrate fuer das eigene Live-Senden in Bit/s.")
                .translation("blockrave.config.liveBitrate").defineInRange("liveBitrate", 160_000, 64_000, 320_000);
        LIVE_SOURCE = c.comment("Zuletzt gewaehlte Live-Audioquelle (wird nie automatisch gestartet).")
                .translation("blockrave.config.liveSource").define("liveSource", "");
        c.pop();
        c.comment("Grafik (nur auf diesem PC; die Show bleibt zeitlich synchron)").push("graphics");
        EFFECT_QUALITY = c.comment("Detailstufe der Lichtkegel, Laser und LED-Flaechen.")
                .translation("blockrave.config.effectQuality").defineEnum("effectQuality", Quality.HOCH);
        PARTICLE_AMOUNT = c.comment("Menge an Nebel- und Feuerwerkspartikeln in Prozent.")
                .translation("blockrave.config.particleAmount").defineInRange("particleAmount", 100, 0, 100);
        FOG_ENABLED = c.comment("Nebeleffekte anzeigen.").translation("blockrave.config.fog").define("fog", true);
        STROBE_MODE = c.comment("Stroboskope und Blitze: VOLL, REDUZIERT (langsamer, schwaecher) oder AUS.")
                .translation("blockrave.config.strobeMode").defineEnum("strobeMode", StrobeMode.VOLL);
        c.pop();
        CLIENT_SPEC = c.build();
    }
}
