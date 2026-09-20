# Sky Kingdoms

Ein 2D-Aufbauspiel über den Wolken – für gewöhnliches PHP-Webhosting.

**Keine Datenbank. Kein Node.js. Kein Docker. Kein SSH. Kein Cronjob.**
Hochladen, entpacken, `/install/` aufrufen, spielen.

---

## Worum es geht

Du baust ein Königreich aus schwebenden Inseln. Das Besondere: Rohstoffe
erscheinen nicht einfach als Zahl. Eine Eisenmine fördert Erz in ihren eigenen
Puffer. Träger holen es ab, tragen es über eine Brücke zur Lagerinsel – und
**erst dort** kannst du es ausgeben. Wer keine Wege baut, produziert ins Leere.
Wer zu viel über eine Brücke schickt, bekommt Stau.

Dazu: **keine Wartezeiten**. Wer die Rohstoffe hat, baut sofort. Und
**kein Maximallevel** – jedes Gebäude, jede Brücke, jede Route und jede
Forschung lässt sich unbegrenzt weiter ausbauen.

---

## Schnellstart

```bash
# Auf dem eigenen Rechner
php -S localhost:8000
# dann http://localhost:8000/install/ aufrufen
```

Auf dem Webspace: ZIP hochladen, entpacken, `/install/` aufrufen.
Ausführlich in **[INSTALLATION.md](INSTALLATION.md)**.

---

## Dokumentation

| Datei | Inhalt |
|---|---|
| [INSTALLATION.md](INSTALLATION.md) | Einrichtung auf cPanel, Schritt für Schritt |
| [GAME_DESIGN.md](GAME_DESIGN.md) | Spielregeln, alle Formeln, Datenablage, Erweiterung |
| [SECURITY.md](SECURITY.md) | Was eingebaut ist und warum |
| [CHANGELOG.md](CHANGELOG.md) | Änderungen je Version |

---

## Aufbau

```
index.php          Einstieg für Anmeldung, Spiel und Profil
api/               JSON-Schnittstelle   (?a=aktion)
admin/             Verwaltung           (?p=seite)
install/           Installationsassistent
app/Core/          URL, Sitzungen, CSRF, Rate-Limits, Konten, Ansichten
app/Store/         Dateibasierter Datenspeicher (ersetzt die Datenbank)
app/Game/          Formeln, Welt, Logistik, Simulation, Kampf, Handel
app/Http/          Steuerungsklassen
app/Views/         Vorlagen
assets/            CSS, JavaScript, Symbole   (alles lokal, keine CDNs)
config/            game.php und balance.php – hier stehen alle Zahlen
storage/           Spielstände, Protokolle, Sitzungen  (gesperrt)
tests/  tools/     Prüfungen und Werkzeuge
```

Das Spiel läuft in **jedem** Ordner: im Hauptverzeichnis einer Domain, in
einem Unterordner, auf einer Subdomain – mit und ohne `mod_rewrite`.

---

## Prüfen

```bash
php tests/run.php          # 197 Prüfungen
php tools/smoke.php        # Vollständiger Durchlauf, Wurzel und Unterordner
php tools/build-zip.php    # Auslieferungspaket erzeugen
```

---

## Anpassen

- **Spielname, Farben, Logo**: Adminbereich → Einstellungen, oder `config/game.php`
- **Alle Spielwerte**: `config/balance.php`, im Adminbereich einzeln überschreibbar
- **Neue Gebäude, Rohstoffe, Inseln**: siehe „Erweitern" in GAME_DESIGN.md

---

## Technische Eckdaten

- PHP 8.2+ · benötigt `json`, `mbstring`, `session`
- Optional `zip` (Sicherungen), `gd` (Symbole erzeugen), `openssl` (SMTP), `curl` (Selbsttest)
- Keine Composer-Abhängigkeiten, keine externen Dienste, keine CDNs
- Frontend als ES-Module, Canvas 2D, alle Grafiken selbst gezeichnet
- Ausgelegt für einige hundert Konten auf Shared Hosting
