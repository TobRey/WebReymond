# Technische Datenschutz-Übersicht: Aufbewahrung und Bereinigung

| Daten | Speicherort | Aufbewahrung / Bereinigung |
|---|---|---|
| Spotify Access-/Refresh-Tokens | `spotify_accounts` (libsodium-verschlüsselt, Schlüssel in `config/config.php`) | bis Trennung der Verbindung, Kontolöschung oder Widerruf; Refresh-Token nie im Browser; nie in Logs. Ausnahme bei aktivierter Wiedergabe: das kurzlebige Access-Token (≤ 1 h) wird für den Web-Player an den Browser gegeben (nur im Arbeitsspeicher) |
| Playlist-Liste (Name, Cover-URL, Anzahl, Snapshot) | `playlist_cache` | TTL 30 min (einstellbar); Zeilen nach 7 Tagen ohne Sync gelöscht (Cron) |
| Playlist-Inhalte (Titel, Künstler, Album, Bild-URL) | `playlist_items_cache` | TTL 15 min (einstellbar); nach 24 h gelöscht (Cron) |
| Veröffentlichte Playlists (Titel, Cover, Songliste bis 500 Titel) | `published_playlists`, `published_playlist_tracks` | bis zur Aufhebung der Veröffentlichung / Kontolöschung |
| Feed-Kandidaten | `feed_candidates` | nach 48 h gelöscht |
| Swipe-Aktionen (Idempotenz, Undo) | `swipe_actions` | nach 90 Tagen gelöscht |
| Ausgeblendete Vorschläge | `swipe_hidden` | bis Zurücksetzen durch Nutzer / Kontolöschung |
| Sessions | `sessions` (nur Hash des Tokens) | 180 Tage gleitend; abgelaufene werden per Cron entfernt |
| OAuth-States / PKCE-Verifier | `oauth_states` | 10 min |
| Rate-Limit-Zähler | `rate_limits` | 2 Zeitfenster |
| Profilbilder | `uploads/` (neu kodiert als WebP, Metadaten entfernt) | bis Ersatz/Entfernen/Kontolöschung |
| KI-Cover | `storage/covers/` (nicht web-zugänglich, Auslieferung nur an den Besitzer) | bis Löschung durch Nutzer / Kontolöschung |
| Nachrichten, Kommentare, Likes, Follows | DB | bis Löschung / Kontolöschung (Kaskade) |
| Integrations-Protokoll (Endpunkt, Status, Dauer – ohne Tokens) | `integration_log` | 30 Tage |
| Fehlerprotokoll | `error_log`, `storage/logs/*.log` | 90 Tage / 60 Tage |
| Stripe-Ereignisse (nur ID, Typ, Status) | `stripe_events` | 90 Tage |
| Audit-Log (Admin-Aktionen, inkl. Einsicht gemeldeter Nachrichten) | `audit_log` | unbegrenzt |

Kontolöschung (Einstellungen) entfernt den Nutzerdatensatz mit allen abhängigen Zeilen (Fremdschlüssel-Kaskaden) sowie Bild- und Cover-Dateien. Spotify-Playlists bleiben unberührt. Datenexport als JSON jederzeit über die Einstellungen.

Keine Weitergabe von Spotify-Daten an Sprach- oder Bildmodelle; an OpenAI geht nur die Nutzer-Bildbeschreibung. Keine Audiodateien werden heruntergeladen, zwischengespeichert oder analysiert.
