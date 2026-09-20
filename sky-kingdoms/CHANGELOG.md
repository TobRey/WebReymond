# Änderungsverlauf

Das Format folgt [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionierung [Semantic Versioning](https://semver.org/lang/de/).

---

## [1.0.0] – 2026-09-20

Erste vollständige Fassung. Lauffähig auf gewöhnlichem PHP-Webhosting ohne
Datenbank, ohne Node.js, ohne Docker, ohne SSH und ohne Cronjob.

### Neu – Grundlage

- **Dateibasierter Datenspeicher** als vollständiger Ersatz für eine Datenbank:
  atomares Schreiben (temporäre Datei + `rename`), Sperren über `flock`,
  automatische Sicherung der vorigen Fassung, Wiederherstellung beschädigter
  Dateien, Verzeichnis-Aufteilung gegen überfüllte Ordner.
- **Pfadunabhängigkeit**: Der Basispfad wird zur Laufzeit erkannt; es entstehen
  ausschliesslich wurzel-relative Adressen. Das Spiel läuft im Hauptverzeichnis
  einer Domain, in jedem Unterordner und auf jeder Subdomain – mit und ohne
  `mod_rewrite`.
- **Installationsassistent** mit Systemprüfung, verständlichen Hinweisen,
  Selbstsperre nach Abschluss und HTTP-Selbsttest der Datenablage.
- **Doppelter Schutz der Spielstände**: `.htaccess` plus PHP-Wächter in jeder
  einzelnen Datendatei.

### Neu – Spiel

- Vier Startinseln (Haupt-, Bauern-, Rohstoff-, Lagerinsel) und sechs weitere
  freischaltbare Inseltypen.
- 28 Gebäudetypen mit echten Produktionsketten vom Erz bis zur Waffe.
- **Echtes Logistiksystem**: Puffer an jedem Gebäude, Routen mit Trägern,
  Brücken mit Kapazität, sichtbarer Stau und benannte Engpässe.
- Acht Transportstufen von Trägern zu Fuss bis zum Luftschiff.
- **Unbegrenzte Stufen** für Gebäude, Brücken, Routen, Forschung und Einheiten;
  Kosten und Wirkung über Formeln, Mehrfachkauf als geschlossene Reihe,
  „Maximum bezahlbar" per Logarithmus.
- **Keine Bau- oder Wartezeiten** – wer bezahlen kann, baut sofort.
- **Offline-Fortschritt** über eine ereignisbasierte Simulation: ein ganzer Tag
  Abwesenheit in wenigen Rechenschritten, ohne Hintergrundprozess.
- Neun Forschungszweige, Aufgaben (täglich und langfristig), acht Erfolge,
  Rangliste, Allianzen, Spielerhandel, Markt.

### Neu – Kämpfe

- Acht Missionsziele vom Konvoiüberfall bis zur Spionage.
- Kurze interaktive 2D-Mission mit Spurwechsel und vier Spezialfähigkeiten.
- **Serverautoritäre Auswertung**: Der Browser schickt nur Entscheidungen,
  der Server rechnet die Mission unabhängig nach.
- Kampfberichte mit Wiederholung, Neulingsschutz, Schild nach Verlusten,
  Angriffsbegrenzung, gedeckelte Beute, keine dauerhafte Zerstörung.

### Neu – Oberfläche

- Selbst gezeichnete 2D-Welt auf Canvas: schwebende Inseln, Wolken mit
  Parallaxe, Tag- und Nachtstimmung, Wasserfälle, Bäume, laufende Träger,
  Pferde und Wagen, Rauch, drehende Mühlenflügel, wehende Fahnen.
- Gebäude verändern ihr Aussehen bei Stufe 10, 25, 50, 100, 250 und 500.
- Mobile-first: Pinch-Zoom, Wischen mit Nachlauf, grosse Trefferflächen,
  Bottom-Sheets, Safe-Area-Unterstützung, Querformat.
- Auf Tablet und Desktop wird aus dem Sheet ein Seitenpanel.
- Installierbare PWA mit Service Worker und Offline-Seite.
- Prozedurale Klänge über WebAudio (keine Tondateien), Vibration,
  Einstellungen für Ton, Musik, Animationen und Grafikqualität.
- Automatische Erkennung schwächerer Geräte.

### Neu – Verwaltung

- Adminbereich mit Übersicht, Spielersuche, Sperren, Rollen,
  nachvollziehbarer Rohstoffkorrektur (mit Pflichtbegründung),
  Balance-Editor, Aufgabenverwaltung, Ankündigungen, Rangliste,
  Protokollen, Wartungsmodus, ZIP-Sicherung, Systemdiagnose und
  SMTP-Einstellungen.
- Lückenloses Audit-Log für jede administrative Änderung.

### Sicherheit

- Argon2id-Passwörter, sichere Sitzungen, Token-Rotation bei
  „Angemeldet bleiben", CSRF-Schutz, Rate-Limits, strenge Schlüsselprüfung
  gegen Pfadmanipulation, `JSON_HEX_TAG` gegen eingeschleusten Code in
  Spielernamen, serverseitige Berechnung sämtlicher Spielwerte,
  Alles-oder-nichts-Buchungen unter Dateisperre.

### Getestet

- 59 Prüfungen in `tests/run.php` (Formeln bis Stufe 200 000, Simulation,
  Vorlagen, Zahlenformat).
- 48 Prüfungen je Durchlauf in `tools/smoke.php` gegen einen echten Webserver –
  einmal im Wurzelverzeichnis, einmal in einem Unterordner.
- Browsertest mit Chromium auf iPhone-Auflösung: Installation, Registrierung,
  Spielaufbau, alle Panels, Adminbereich.

### Bekannte Einschränkungen

- Ausgelegt und getestet für einige hundert Konten auf Shared Hosting.
- Zwei-Faktor-Anmeldung, Echtgeldkäufe und Weltkarten-Ereignisse sind nicht
  enthalten (Datenmodell und Verwaltung sind vorbereitet).
- Prestige und neutrale Inseln sind als Datenmodell angelegt, aber noch nicht
  spielbar.
