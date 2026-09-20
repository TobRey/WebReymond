# Technische Entscheidungen

Kurze Begründungen der wichtigsten Weichenstellungen – damit später
nachvollziehbar ist, warum etwas so gebaut ist.

---

## 1. Dateien statt Datenbank

**Entscheidung:** Alle Daten liegen als JSON-Dokumente unter `storage/data`.

**Warum:** Auf einfachem Webhosting ist das Anlegen einer Datenbank die häufigste
Hürde: Zugangsdaten, Benutzerrechte, Zeichensatz, verlorene Passwörter. Fast alle
Zugriffe dieses Spiels betreffen genau einen Spieler – dessen gesamtes Königreich
passt in ein Dokument, das in einem Zug gelesen und geschrieben wird.

**Preis:** Übergreifende Abfragen (Rangliste, Gegnersuche) brauchen eine eigene
Momentaufnahme. Bei sehr vielen gleichzeitigen Spielern wäre SQL überlegen.

**Ausweg:** Die gesamte Speicherschicht liegt hinter `app/Store/Store.php` mit
sechs Methoden. Eine SQL-Fassung wäre ein Austausch dieser einen Klasse.

---

## 2. Jede Datendatei ist eine PHP-Datei

**Entscheidung:** Datendateien heissen `…json.php` und beginnen mit
`<?php exit; ?>`.

**Warum:** `.htaccess` ist die übliche Absicherung – sie wirkt aber nur unter
Apache mit `AllowOverride`. Wird sie ignoriert, lägen alle Spielstände offen.
Eine PHP-Datei mit `exit` in der ersten Zeile gibt dagegen auf **jedem** Server,
der PHP ausführt, nichts preis.

**Ergänzung:** Beim Speichern werden `<` und `>` als Unicode-Escapes abgelegt
(`JSON_HEX_TAG`). Dadurch kann selbst ein Spielername wie `<?php …?>` keinen
zweiten PHP-Block in der Datei erzeugen.

---

## 3. Ereignisbasierte Simulation statt Zeitschritten

**Entscheidung:** Die Offline-Berechnung springt von Ereignis zu Ereignis
(Puffer voll, Lager voll, Nahrung leer), statt in festen Zeitschritten zu rechnen.

**Warum:** Ein Tag Abwesenheit wären 86 400 Sekundenschritte – auf Shared Hosting
undenkbar. Zwischen zwei Ereignissen sind alle Flüsse konstant, der Zustand also
exakt berechenbar. Ein Tag braucht so meist unter zehn Schritte.

**Preis:** Der Aufbau des Fluss-Netzes ist anspruchsvoller als eine Schleife.
Dafür ist das Ergebnis exakt und nicht von der Schrittweite abhängig.

---

## 4. Serverautoritärer Kampf mit Nachrechnung

**Entscheidung:** Der Browser schickt nur Entscheidungen, der Server rechnet die
Mission vollständig nach.

**Warum:** Alles andere wäre manipulierbar. Übertragen wird `{Zeitpunkt, Spur}`
oder `{Zeitpunkt, Fähigkeit}` – daraus lässt sich kein Vorteil erfinden.

**Preis:** Die Simulation existiert zweimal (PHP und JavaScript) und muss gleich
bleiben. Deshalb verwendet sie nur Grundrechenarten und `sqrt` sowie einen
Zufallsgenerator mit festem Startwert, der in beiden Sprachen identisch ist.

---

## 5. Gezeichnete Grafik statt Bilddateien

**Entscheidung:** Gebäude, Inseln und Figuren werden zur Laufzeit auf Canvas
gezeichnet; nur das Logo und die App-Symbole sind Dateien.

**Warum:** Keine Urheberrechtsfragen, keine Ladezeiten, kein Nachladen bei Zoom,
beliebige Ausbaustufen ohne 140 zusätzliche Dateien. Inselkörper werden einmal
vorgezeichnet und danach als Bild gesetzt – das hält die Bildrate hoch.

**Preis:** Neue Gebäude brauchen eine Zeichenvorschrift statt einer Bilddatei.
Fehlt sie, wird automatisch ein passables Standardhaus gezeichnet.

---

## 6. Keine Wartezeiten

**Entscheidung:** Bauen und Verbessern geschehen sofort.

**Warum:** Wartezeiten sind ein Monetarisierungswerkzeug. Ohne Echtgeldkäufe
sind sie nur lästig. Die Spannung entsteht hier aus Logistik und Engpässen –
nicht aus dem Blick auf eine Uhr.

---

## 7. Unbegrenzte Stufen mit Formeln

**Entscheidung:** Keine Stufentabellen, sondern `basis × wachstum^(L−1)`.

**Warum:** Tausende Stufen von Hand zu pflegen ist unmöglich. Mit Formeln
genügen zwei Zahlen je Merkmal, und die Balance lässt sich zentral verschieben.
Die Kostensteigerung (5,75 %) liegt bewusst über der Wirkungssteigerung (1 %),
sonst würde das Spiel entgleiten.

---

## 8. Wurzel-relative Adressen

**Entscheidung:** Es entstehen ausschliesslich Adressen wie `/unterordner/api/` –
nie mit Domain, nie mit Serverpfad.

**Warum:** Das Spiel soll in jedem Ordner und unter jeder Domain laufen, auch
nach einem Umzug. Der Basispfad wird aus `SCRIPT_NAME` abgeleitet; jeder
Einstiegspunkt meldet über `SK_ENTRY_DEPTH`, wie tief er liegt.

**Ausnahme:** E-Mails und das Web-App-Manifest brauchen vollständige Adressen.
Sie stammen aus der bei der Installation erkannten, im Adminbereich änderbaren
`app_url`.

---

## 9. Physische Einstiegspunkte statt Rewrite-Zwang

**Entscheidung:** `api/index.php`, `admin/index.php` und `install/index.php`
existieren wirklich; `mod_rewrite` liefert nur hübschere Adressen.

**Warum:** Auf fremden Servern ist nie sicher, ob Rewriting verfügbar ist.
So funktioniert alles auch ohne – und niemand muss etwas konfigurieren.
