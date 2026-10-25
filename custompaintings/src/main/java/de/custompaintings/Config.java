package de.custompaintings;

import net.neoforged.neoforge.common.ModConfigSpec;

/** Einstellungen (Server: serverconfig/custompaintings-server.toml, Client: config/custompaintings-client.toml). */
public final class Config {
    public static final ModConfigSpec SERVER_SPEC;
    public static final ModConfigSpec.BooleanValue OPERATORS_ONLY;
    public static final ModConfigSpec.IntValue MAX_IMAGE_KB;
    public static final ModConfigSpec.IntValue MAX_STORAGE_MB;
    public static final ModConfigSpec.IntValue MAX_EDIT_DISTANCE;

    public static final ModConfigSpec CLIENT_SPEC;
    public static final ModConfigSpec.IntValue PIXELS_PER_BLOCK;
    public static final ModConfigSpec.BooleanValue DISK_CACHE;

    static {
        ModConfigSpec.Builder b = new ModConfigSpec.Builder();
        b.push("bilder");
        OPERATORS_ONLY = b.comment("Duerfen nur Operatoren Bilder auf Gemaelde setzen? (Standard: alle, die dort bauen duerfen)")
                .translation("custompaintings.config.operatorsOnly").define("operatorsOnly", false);
        MAX_IMAGE_KB = b.comment("Groesste erlaubte Bilddatei pro Gemaelde in KiB (nach dem Zuschneiden, PNG).")
                .translation("custompaintings.config.maxImageKb").defineInRange("maxImageKb", 4096, 64, 16384);
        MAX_STORAGE_MB = b.comment("Speicherplatz fuer alle Bilder zusammen in MiB (Ordner <Welt>/custompaintings).")
                .translation("custompaintings.config.maxStorageMb").defineInRange("maxStorageMb", 2048, 16, 100_000);
        MAX_EDIT_DISTANCE = b.comment("Wie nah (Bloecke) ein Spieler am Gemaelde sein muss, um es zu aendern.")
                .translation("custompaintings.config.maxEditDistance").defineInRange("maxEditDistance", 8, 2, 64);
        b.pop();
        SERVER_SPEC = b.build();

        ModConfigSpec.Builder c = new ModConfigSpec.Builder();
        PIXELS_PER_BLOCK = c.comment("Aufloesung eigener Bilder in Pixel pro Block (Vanilla-Gemaelde: 16). Hoeher = schaerfer, groessere Dateien.")
                .translation("custompaintings.config.pixelsPerBlock").defineInRange("pixelsPerBlock", 64, 16, 128);
        DISK_CACHE = c.comment("Empfangene Bilder auf der Festplatte zwischenspeichern (schnelleres Laden beim naechsten Mal).")
                .translation("custompaintings.config.diskCache").define("diskCache", true);
        CLIENT_SPEC = c.build();
    }

    private Config() {}
}
