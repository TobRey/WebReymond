# WordPress-Theme „Reymond Tobias“

Die DJ-Website als vollwertiges WordPress-Theme – gleiche Gestaltung, gleiche
Animationen wie die statische Fassung unter `sites/reymond-tobias/`, aber alle
Inhalte im Backend pflegbar.

Der Ordner `reymond-tobias/` ist das Theme. Für den Upload wird genau dieser
Ordner als ZIP verpackt (siehe unten).

## Installieren

1. **Design → Themes → Theme hinzufügen → Theme hochladen**
2. `reymond-tobias-theme.zip` auswählen, installieren, **aktivieren**
3. Fertig. Beim Aktivieren legt das Theme selbst an:
   - die drei Seiten **Start**, **Musik**, **Kontakt** samt Texten
   - die Startseite als statische Startseite
   - das **Hauptmenü** mit allen drei Seiten
   - **sechs Titel** für den Player und **drei Beispieltermine**
   - und legt die WordPress-Vorgaben „Beispiel-Seite“ und „Hallo Welt!“ in den
     Papierkorb (nur, wenn sie unverändert sind)

Danach steht die Seite genau so da wie die statische Fassung.

## Was wo bearbeitet wird

| Inhalt | Ort im Backend |
|---|---|
| Bannerbild, Titel, Einleitung, Kennzahlen | Design → Customizer → **Reymond Tobias** → Startseite: Banner |
| Laufband, Stichwörter, Überschriften, Schlusszeile | Customizer → Reymond Tobias → Startseite: Abschnitte |
| E-Mail, Telefon, Ort, Social-Links | Customizer → Reymond Tobias → Kontakt und Social |
| Text im Abschnitt „Über“ | Seiten → **Start** (normaler Editor) |
| Einleitung auf Musik- und Kontaktseite | Seiten → Musik bzw. Kontakt |
| Titel im Player | **Musik** in der linken Leiste |
| Termine | **Termine** in der linken Leiste |
| Seitentitel im Kopf und Fuss | Einstellungen → Allgemein → Titel der Website |

### Bannerbild

Customizer → Reymond Tobias → Startseite: Banner → **Bannerbild**.
Solange keines gesetzt ist, erscheint der mitgelieferte Platzhalter.
Steht die Person rechts im Bild, passt der Titel links daneben – genau dafür
ist die Abdunklung gebaut. Bei anderem Bildausschnitt in
`assets/css/style.css` die Zeile `object-position: 72% 28%;` anpassen.

### Titel für den Player

**Musik → Titel hinzufügen**:

- **Titel** des Beitrags = der grosse Name im Player
- **Beitragsbild** = Cover (quadratisch, z. B. 1200 × 1200). Ohne Beitragsbild
  greift eines der mitgelieferten Schwarz-Weiss-Muster.
- **Stilrichtung**, **Dauer** und **Audiodatei** im Feld darunter
- Die Reihenfolge steuert „Reihenfolge“ im Kasten „Attribute“

Fehlt die Audiodatei, läuft der Player im Vorschaumodus: Anzeige und
Animation laufen weiter, darunter steht ein Hinweis. Im Backend erscheint
zusätzlich eine Notiz, wie viele Titel noch keine Datei haben.

## Kontaktformular

Der Versand läuft über WordPress selbst (`wp_mail`) – kein Zusatz-Plugin,
kein fremder Dienst. Eingebaut sind Nonce-Prüfung, eine unsichtbare
Spam-Falle und eine Sperre bei mehr als fünf Anfragen pro Stunde und
Anschluss. Ohne JavaScript funktioniert das Formular unverändert.

Empfehlung für den Betrieb: ein SMTP-Plugin einrichten, sonst landen Mails von
günstigen Hostern häufig im Spam.

## Bedienung des Players

| Taste | Wirkung |
|---|---|
| Leertaste | Abspielen / Pause |
| Umschalt + → | Nächster Titel |
| Umschalt + ← | Vorheriger Titel |

## Technisches

- Klassisches PHP-Theme, keine Abhängigkeiten, kein Build-Schritt
- Schriften (Anton, Space Grotesk) liegen im Theme: **keine Anfragen an Google**
- Alle Ausgaben werden escaped, alle Eingaben bereinigt
- Getestet mit WordPress 6.9 und PHP 8.4
- Barrierefreiheit: „Bewegung reduzieren“ im Betriebssystem schaltet die
  Animationen ab

## ZIP für den Upload erstellen

```bash
cd sites/wordpress
zip -r reymond-tobias-theme.zip reymond-tobias
```
