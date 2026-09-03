# reymond-tobias.ch

Persönliche Website von Tobias Reymond: eine Seite, zwei Sprachen, ein
geschützter Bereich zum Pflegen der Inhalte. Kein Framework, keine
Abhängigkeiten, kein Baukasten – reines PHP, HTML, CSS und JavaScript.

Die Anleitung für den Betrieb liegt als Seite bei: `doc.html`
(im Browser unter `/doc.html`).

## Aufbau

```
index.php            Startseite deutsch   – setzt $lang/$base und lädt lib/page.php
en/index.php         Startseite englisch
lib/defaults.php     Alle Grundtexte, deutsch und englisch
lib/content.php      Inhalte laden, zusammenführen, sicher ausgeben
lib/strings.php      Feste Beschriftungen (Menü, Formular, Fusszeile)
lib/page.php         Aufbau der Startseite, für beide Sprachen
lib/store.php        Dateien atomar lesen und schreiben, Sicherungskopien
content/content.json Was im Bearbeitungsbereich geändert wurde
content/media/       Hochgeladene Bilder
admin/               Bearbeitungsbereich (Anmeldung, Texte, Bilder, Intranet …)
api/contact.php      Kontaktformular
api/count.php        Eigene Besucherzählung, ohne IP-Adressen
data/                Private Daten: Konto, Zahlen, Sicherungen (nicht öffentlich)
```

## Wie ein Text auf die Seite kommt

1. `lib/defaults.php` enthält für jede Stelle einen **fertigen** Text – keine
   Platzhalter. Ohne `content.json` sieht die Seite bereits vollständig aus.
2. Wird im Bearbeitungsbereich etwas geändert, landet es in
   `content/content.json` und geht dem Grundtext vor.
3. `rt_content($lang)` führt beides zusammen; `lib/page.php` gibt es aus.
   Zusammengesetzt wird auf dem Server – die Seite braucht kein JavaScript,
   um ihre Inhalte anzuzeigen.

Ein leeres Textfeld fällt auf den Grundtext zurück. Listen dagegen gelten so,
wie sie gespeichert wurden: Ein dort entfernter Eintrag bleibt weg.

## Listen (Werdegang, Projekte, Kenntnisse …)

Listen haben keine feste Länge. Im Bearbeitungsbereich lassen sich Einträge
hinzufügen, verschieben und entfernen; jeder Eintrag darf einen eigenen Verweis
tragen (`link_text` + `link_url`).

Eine neue Liste anlegen:

1. In `lib/defaults.php` unter beiden Sprachen einen Schlüssel mit einem Array
   von Einträgen anlegen.
2. In `admin/inc/schema.php` in der passenden Gruppe unter `lists` beschreiben,
   welche Felder ein Eintrag hat.
3. In `lib/page.php` mit `rt_list($c, 'schluessel')` darüber laufen.

Formular, Prüfung und Speichern ergeben sich daraus von selbst.

## Verweise

`rt_url()` in `lib/content.php` prüft jede Adresse. Erlaubt sind `https://`,
`http://`, `mailto:`, `tel:`, seiteneigene Pfade und Sprungmarken (`#kontakt`);
`beispiel.ch/seite` wird zu `https://beispiel.ch/seite` ergänzt. Alles andere –
insbesondere `javascript:` und `data:` – wird verworfen. Externe Ziele bekommen
`target="_blank" rel="noopener"` und einen Hinweis für Screenreader.

## Anforderungen und Deployment

* PHP 8.1 oder neuer, Apache mit `mod_rewrite` und `mod_headers`
  (nginx: siehe Hinweis in `doc.html`).
* Beschreibbar sein müssen `data/` und `content/` samt Unterordnern.
* Erster Aufruf: `/admin/setup.php`, Passwort setzen, danach **die Datei
  `admin/setup.php` vom Server löschen**.
* Die Startseite wird von PHP erzeugt; `DirectoryIndex` steht deshalb auf
  `index.php index.html`.

## Lokal ansehen

```
php -S 127.0.0.1:8080 -t .
```

Dann `http://127.0.0.1:8080/` öffnen. Für den Bearbeitungsbereich zuerst
`/admin/setup.php` aufrufen.
