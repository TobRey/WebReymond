package de.blockrave.client.live;

/**
 * Eine waehlbare Live-Audioquelle.
 *
 * @param id      eindeutige Kennung (Geraete-ID bzw. Prozessmodus)
 * @param name    Anzeigename
 * @param kind    Art der Erfassung
 * @param warning Hinweis fuer die Oberflaeche (leer = keiner)
 */
public record CaptureSource(String id, String name, Kind kind, String warning) {
    public enum Kind {
        /** Nur die Audioausgabe eines Prozesses (z.B. rekordbox.exe) &ndash; Windows-Prozess-Loopback. */
        PROCESS_INCLUDE,
        /** Gesamter PC-Klang ohne Minecraft &ndash; Windows-Prozess-Loopback (Ausschluss). */
        PROCESS_EXCLUDE,
        /** Alles, was auf einem Ausgabegeraet abgespielt wird (WASAPI-Loopback). */
        ENDPOINT_LOOPBACK,
        /** Aufnahmegeraet (Line-In, Stereomix, virtuelles Kabel ...). Nur nach bewusster Auswahl. */
        INPUT
    }
}
