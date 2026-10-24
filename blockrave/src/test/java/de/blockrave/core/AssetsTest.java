package de.blockrave.core;

import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import org.junit.jupiter.api.Test;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.List;
import java.util.stream.Stream;

import static org.junit.jupiter.api.Assertions.*;

/** Prueft die Blockmodelle auf Fehler, die im Spiel als violett-schwarze Fehltextur erscheinen. */
class AssetsTest {
    private static Path assets() {
        for (String p : new String[]{"src/main/resources/assets/blockrave", "../src/main/resources/assets/blockrave"}) {
            if (Files.isDirectory(Path.of(p))) return Path.of(p);
        }
        throw new IllegalStateException("Asset-Ordner nicht gefunden");
    }

    @Test
    void modelleReferenzierenNurVorhandeneTexturenUeberVariablen() throws IOException {
        Path root = assets();
        List<String> errors = new ArrayList<>();
        int models = 0;
        try (Stream<Path> files = Files.list(root.resolve("models/block"))) {
            for (Path f : files.filter(p -> p.toString().endsWith(".json")).toList()) {
                models++;
                JsonObject m = JsonParser.parseString(Files.readString(f)).getAsJsonObject();
                JsonObject tex = m.has("textures") ? m.getAsJsonObject("textures") : new JsonObject();
                for (var t : tex.entrySet()) {
                    String v = t.getValue().getAsString();
                    if (v.startsWith("#") || v.startsWith("minecraft:")) continue;
                    Path png = root.resolve("textures/" + v.substring(v.indexOf(':') + 1) + ".png");
                    if (!Files.exists(png)) errors.add(f.getFileName() + ": Textur fehlt " + v);
                }
                if (!m.has("elements")) continue;
                for (JsonElement e : m.getAsJsonArray("elements")) {
                    for (var face : e.getAsJsonObject().getAsJsonObject("faces").entrySet()) {
                        String ref = face.getValue().getAsJsonObject().get("texture").getAsString();
                        if (!ref.startsWith("#")) {
                            errors.add(f.getFileName() + ": Flaeche " + face.getKey() + " nutzt direkten Pfad " + ref);
                        } else if (!tex.has(ref.substring(1))) {
                            errors.add(f.getFileName() + ": Variable " + ref + " nicht definiert");
                        }
                    }
                }
            }
        }
        assertTrue(models > 10, "zu wenige Modelle gefunden: " + models);
        assertTrue(errors.isEmpty(), String.join("\n", errors));
    }

    @Test
    void blockstatesVerweisenAufVorhandeneModelle() throws IOException {
        Path root = assets();
        List<String> errors = new ArrayList<>();
        try (Stream<Path> files = Files.list(root.resolve("blockstates"))) {
            for (Path f : files.toList()) {
                JsonObject s = JsonParser.parseString(Files.readString(f)).getAsJsonObject();
                for (var v : s.getAsJsonObject("variants").entrySet()) {
                    String model = v.getValue().getAsJsonObject().get("model").getAsString();
                    Path p = root.resolve("models/" + model.substring(model.indexOf(':') + 1) + ".json");
                    if (!Files.exists(p)) errors.add(f.getFileName() + ": Modell fehlt " + model);
                }
            }
        }
        assertTrue(errors.isEmpty(), String.join("\n", errors));
    }
}
