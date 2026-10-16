# Getestete Funktionen und reale Einschränkungen

## Was getestet wurde

### Automatisierte Backend-Tests (`php tests/run.php`, MariaDB 10.11, PHP 8.3)
48 Tests, alle bestanden – mit simulierten Spotify-/OpenAI-/Stripe-Antworten (lokale Fixtures, erscheinen nie in der echten Anwendung):

- Genau **ein Besitzer** bei paralleler Erstregistrierung (DB-Eindeutigkeit `platform_owner.id = 1`, zweiter Versuch wird regulär behandelt und bei geschlossener Registrierung abgewiesen)
- Genau **ein Konto pro Spotify-ID** (Unique-Index + erneuter Login öffnet dasselbe Konto)
- Falscher/abgelaufener OAuth-State, falscher Einrichtungsschlüssel, geschlossene Registrierung
- Abgelaufene Access-Tokens (automatischer Refresh unter Zeilensperre), widerrufene Refresh-Tokens (Status „widerrufen“, erneutes Verbinden), Spotify-Rate-Limit mit `Retry-After`
- CSRF-Schutz (Token + Herkunftsprüfung), Logout auf allen Geräten
- Playlist-Liste mit 120 Playlists (3 Seiten Paginierung), Inhalte über mehrere Seiten, Cache-Nutzung
- Erstellen/Umbenennen, Spotify-Sichtbarkeit getrennt von SwipeGroove-Veröffentlichung, fremde Playlists nicht bearbeitbar
- Duplikaterkennung nur per identischer Track-ID; Entfernen bestimmter Vorkommen
- Snapshot-Konflikterkennung (Änderung in der Spotify-App → 409, nichts wird überschrieben)
- Verschieben: zuerst zum Ziel hinzufügen, dann aus der Quelle entfernen; Teilfehler → Status „partial“, Wiederaufnahme ohne doppeltes Hinzufügen, idempotent
- Zusammenführen (Reihenfolge, Duplikate überspringen), Duplizieren (Reihenfolge inkl. Duplikaten)
- Leere Playlists: nur nach erfolgreicher Einzelprüfung; Prüffehler → „nicht verifiziert“; vor dem Entfernen erneute Prüfung; fremde Listen werden nur entfolgt
- Cover-Upload: Neucodierung als quadratisches JPEG ≤ 256 KB (Base64)
- Umsortieren (lokaler Cache passt sich an), Spotify-Suche mit Limit 10
- Swipe-Feed: enthaltene, ausgeblendete und bereits gezeigte Songs werden ausgeschlossen; Ausblendungen sind an Zielplaylist gebunden; Zurücksetzen
- Swipe-Aktionen idempotent (`action_id`), schnelle Doppel-Swipes → 409, keine doppelten Songs; fehlgeschlagener Spotify-Aufruf wird als „failed“ erfasst
- Rückgängig: entfernt nur das durch die Aktion hinzugefügte Vorkommen (bestehende Vorkommen und spätere fremde Änderungen bleiben), auch wenn der Song inzwischen extern entfernt wurde
- Wiedergabe: standardmässig deaktiviert; fehlende Scopes und „Premium required“ werden ehrlich gemeldet
- Private Profile: Bio/Banner/Playlists/Following nicht sichtbar – auch nicht per Direktlink, Kommentar-/Like-Listen, Feed oder API; Freunde haben keinen Sonderzugriff; Kontoprivatsphäre hat Vorrang vor Playlist-Freigabe
- Blockieren: Profil/Playlists unsichtbar, Anfragen/Nachrichten unmöglich, Follows entfernt, aus Suche ausgeblendet
- Folgen vs. Freundschaft getrennt; Anfragen senden/annehmen/ablehnen/zurückziehen; Benachrichtigungen
- Kommentare bearbeiten/löschen, Löschung durch Playlist-Besitzer, Zähler
- Meldungen, Moderation, Sperren (Sessions beendet, Inhalte unveröffentlicht), Audit-Log, Besitzer nicht sperrbar
- Datenexport ohne Tokens, Kontolöschung mit Bestätigung; Besitzerkonto nicht löschbar
- Nachrichten: Anfrageablauf, Dritte ohne Zugriff, inkrementelles Polling, Links, Einstellungen (Freunde/Anfragen), Admin sieht Chats nur über Meldung mit minimalem Kontext (protokolliert)
- KI-Cover: Premium-Pflicht, Besitzer unbegrenzt, Kontingent zählt nur erfolgreiche Bilder, Inhaltsrichtlinie → 422, **8 parallele Prozesse überschreiten ein Kontingent von 3 nicht**, Idempotenz, Cover-Setzen nur auf eigene bearbeitbare Playlist, Bildzugriff nur für den Besitzer, nur die Beschreibung geht an den Anbieter
- Stripe: Signaturprüfung (falscher Secret, verändertes Payload, alter Zeitstempel), Checkout-URL, Webhook idempotent (Duplikate), Live-Ereignisse im Testmodus ignoriert, Kündigung zum Periodenende, Zahlungsfehler, abgelaufenes Abo deaktiviert Premium, fehlgeschlagene Verarbeitung erneut zustellbar
- Betrieb im Unterordner (`/groove`) in allen Tests

### Browsertests (Chromium, Playwright; iPhone-Viewport 390×844 und Desktop 1280×860)
Gegen einen lokalen Fake-Spotify-Server (`tests/e2e/`), Anwendung im Unterordner `/groove`:
- Installationsassistent inkl. Sperre danach
- Setup mit falschem/richtigem Einrichtungsschlüssel, PKCE-Autorisierungs-URL mit nur Playlist-Scopes, Onboarding
- Übersicht mit 60 Playlists, Filter, Suche; Playlist-Detail (Duplikate finden/entfernen, Pfeil-Sortierung, Veröffentlichen, Mehrfachauswahl + Kopieren mit Vorgangsanzeige)
- Swipe-Feed: Buttons, Rückgängig, echte Wischgeste; Zielplaylist enthält danach keine Duplikate; ehrlicher Leerzustand ohne Filter
- Community-Feed, Playlist-Detail mit Kommentar und Like
- Sprachwechsel DE/EN
- Admin: Registrierung öffnen, OpenAI-Schlüssel nachtragen
- Zweiter Nutzer (Desktop mit Seitenleiste): Folgen, Freundschaftsanfrage, Nachrichtenanfrage; Annahme und Antwort per Polling; privates Profil
- Cover-Generator ohne Schlüssel deaktiviert, mit nicht erreichbarem Anbieter ehrliche Fehlermeldung
- Leere Playlists entfernen (13 Listen), rechtliche Seiten, Abmelden
- Keine JavaScript-Fehler

## Was NICHT live getestet werden konnte

- **Echte Spotify-API**: Aus der Build-Umgebung war `developer.spotify.com` nicht erreichbar und es lagen keine Zugangsdaten vor. Die Endpunkte entsprechen dem Stand der Web-API-Änderungen vom Februar 2026 (`/playlists/{id}/items`, `POST /me/playlists`, `/me/library`), verifiziert über die aktualisierten offiziellen Client-Bibliotheken (spotipy 2.26, rspotify 0.16) und Spotifys Migrationsleitfaden (indirekt). **Vor dem Livebetrieb mit einem echten Konto prüfen**; Abweichungen fallen im Adminbereich unter *Integrationen → Letzte API-Fehler* sofort auf.
- **OpenAI Images API** und **Stripe**: nur gegen Simulationen getestet; Request-Formate folgen den offiziellen SDK-Definitionen (openai-python `images.generate`, Stripe-Signaturschema v1).
- **Spotify-Entfolgen** nutzt `DELETE /me/library?uris=spotify:playlist:…` (neuer generischer Endpunkt) mit Fallback auf `DELETE /playlists/{id}/followers`, falls der neue Endpunkt für Playlists 404/405/500 liefert (von Entwicklern 2026 gemeldet).

## Reale Einschränkungen (Spotify, Stand Oktober 2026)

1. **Development Mode**: max. 5 freigeschaltete Nutzer, App-Besitzer braucht Spotify Premium, 1 Client-ID. Öffentlicher Betrieb erfordert Extended Quota Mode (nur Organisationen, Kriterium 250k MAU). SwipeGroove ist technisch mehrbenutzerfähig; die Freigabe ist **nicht** erteilt.
2. **Keine Empfehlungs-API mehr**: Recommendations, Related Artists, Audio Features/Analysis, Featured/Category-Playlists und 30-Sekunden-Vorschauen sind für neue Apps seit November 2024 nicht verfügbar. **Playlist-Match ist deshalb ein Suchansatz**: Künstler der Zielplaylist → weitere Titel dieser Künstler (Suche `artist:"…"` + Alben des Künstlers), gewichtet nach Häufigkeit. Es werden keine BPM-, Stimmungs- oder Audiodaten verwendet oder behauptet. „Entdecken“ nutzt Künstler aus den anderen Playlists des Nutzers und Jahresfilter. Qualität: brauchbar für künstlernahe Vorschläge, kein personalisierter Algorithmus. Dies wird in der Oberfläche ausgewiesen.
3. **Suche** liefert maximal 10 Treffer pro Anfrage (Dev-Mode-Limit); „Mehr laden“ paginiert.
4. **Fremde Playlists**: Inhalte sind nur für eigene und kollaborative Playlists lesbar (`/playlists/{id}/items`). Gefolgte fremde Listen erscheinen in der Übersicht, sind aber nur lesbar/entfolgbar.
5. **Löschen** existiert in der API nicht – nur Entfolgen/aus Bibliothek entfernen; die Oberfläche beschriftet und erklärt das.
6. **Wiedergabe**: Web Playback SDK bräuchte ein Access-Token im Browser (widerspricht der Vorgabe „Tokens nur serverseitig“). Umgesetzt ist stattdessen **Spotify Connect** (Server steuert das aktive Spotify-Gerät des Nutzers; Premium nötig). Standardmässig **aus**, bis kommerzielle Freigabe nachgewiesen ist; Fallback „Auf Spotify anhören“ immer vorhanden. Keine Beat-Synchronisation.
7. **Refresh-Tokens** laufen nach 6 Monaten ab (seit Juni/Juli 2026); erneute Anmeldung nötig, die App weist darauf hin.
8. `/me` liefert keine E-Mail, kein Land und keinen Premium-Status mehr → Registrierung kommt ohne E-Mail aus; Premium wird bei Wiedergabe über die Fehlermeldung erkannt.
9. **ISRC/external_ids** sind nicht verlässlich verfügbar → Duplikate nur per Track-ID (keine Zusammenführung von Remixen/Live-Versionen).

## Weitere Hinweise

- PHP 8.4 wird unterstützt (keine implizit nullbaren Parameter, keine veralteten Funktionen); getestet wurde mit PHP 8.3.6, da im Build-System nicht anders verfügbar.
- Rechtstexte sind Strukturvorlagen mit markierten Platzhaltern – keine geprüfte Rechtsberatung.
- Drag-and-drop-Sortierung (Desktop) ist implementiert, konnte im Headless-Browser nicht per Geste geprüft werden; die mobile Pfeilsortierung wurde getestet.
- Bildpreise/Modellnamen von OpenAI stammen aus Drittquellen (openai.com nicht erreichbar) – im Adminbereich anpassbar.
