# 0015 – Ein Fehler beim Start muss auf dem Bildschirm stehen, nicht in der Konsole

**Status:** akzeptiert (August 2026)
**Ergänzt** [0013](0013-mogli-fuellt-den-bildschirm.md) – die bildschirmfüllende Seite bleibt
unverändert; hier geht es darum, was passiert, wenn sie nicht anläuft.

## Kontext

Gemeldet wurde ein Satz: „Wenn ich auf Starten klicke, passiert nichts." iPhone, Safari,
eigener Webspace. Kein Absturz, keine Meldung, keine Fehlerseite. Das Menü stand da wie
immer.

Genau das ist die Falle. **Das Menü ist statisches HTML.** Es steht auch dann tadellos da,
wenn kein einziger Knopf verdrahtet wurde – wenn das JavaScript also nie durchgelaufen ist.
Ein Fehler beim Start und ein kaputter Startknopf sehen von aussen identisch aus, und auf
einem Handy gibt es keine Konsole, in der der Unterschied stünde.

Ich habe den Startpfad Zeile für Zeile durchgesehen und **konnte die Ursache nicht bestimmen.**
Drei Kandidaten liessen sich benennen und beheben (unten), aber welcher davon auf jenem Gerät
zuschlug – oder ob es ein vierter war – war von hier aus nicht zu entscheiden. Das ist der
eigentliche Mangel: nicht der einzelne Fehler, sondern dass er sich nicht melden konnte.

Der wahrscheinlichste Auslöser ist ohnehin keiner, den man im Code findet: auf einem Webspace,
auf den dieselbe Adresse mehrfach neu bespielt wurde, hat der Browser leicht eine ältere
`index.html` und ein neueres `src/main.js` gleichzeitig im Zwischenspeicher. Die beiden passen
dann nicht zusammen, `getElementById` gibt `null`, der erste Zugriff darauf wirft – und der
Rest von `boot()` läuft nie.

## Entscheidung

Vier Dinge, in dieser Reihenfolge nach Wichtigkeit:

1. **Ein Meldungsstreifen** ganz oben in `index.html`, unsichtbar, solange nichts schiefgeht.
   Er zeigt Meldung, Datei und Zeile, ist auswählbar und sagt in einem Satz, was zu tun ist.
   Gesammelt wird ab der ersten Zeile – von einem kurzen Skript **direkt in `index.html`**,
   nicht aus einem Modul: ein Modul, das gar nicht erst ausgeführt wird, kann sich unmöglich
   selbst darüber beschweren. Ist die Seite fertig geladen und das Spiel meldet sich trotzdem
   nicht als bedienbar, zeigt der Streifen, was er hat – notfalls den Satz „Das Spiel wurde
   nicht ausgeführt". Gewartet wird dabei auf `load` und nicht auf eine feste Frist: sonst
   schlüge der Streifen bei langsamer Verbindung an, während in Wahrheit nur noch geladen
   wird.

2. **In `boot()` erst verdrahten, dann verzieren.** Vorher standen drei Verzierungen ganz oben
   (Name eintragen, Rekord anzeigen, Vollbildknopf ein- oder ausblenden). Warf eine davon,
   wurde kein einziger Knopf mehr verdrahtet. Jetzt laufen `bindButtons()`, die Tippfläche,
   die Sprachwahl und die Sichtbarkeit zuerst, alles Weitere danach und jeder Block für sich
   abgesichert. Zusätzlich gibt `$()` statt `null` ein loses Ersatzstück zurück und schreibt
   die fehlende `id` in den Streifen: die Kleinigkeit fehlt dann wirklich, aber sie reisst den
   Start nicht mehr mit.

3. **`.catch()` auf etwas, das kein Promise ist.** `webkitRequestFullscreen` (Safari auf iPad
   und Mac, ältere Android-WebViews) gibt `undefined` zurück. Das `.catch()` darauf warf einen
   TypeError – in der zweiten Zeile von `startGame()`, also bevor überhaupt etwas passierte.
   Auf dem iPhone selbst schlägt das nicht zu (dort gibt es kein Element-Vollbild), auf iPad
   und Mac schon.

4. **Gegen alte Stände im Zwischenspeicher:** eine `.htaccess` im Hauptordner (kein
   Zwischenspeicher für `index.html`, richtiger MIME-Typ für `.js` – bei falschem Typ führt
   kein Browser ein ES-Modul aus, ohne das zu sagen), und die Versionsnummer an der
   Modul-Adresse: `src/main.js?v=2.0.1`. Ein Test hält diese Zahl mit `version.js` zusammen.

5. **Eine Prüfseite, `pruefung.html`.** Nachgereicht, weil Punkt 1 bis 4 den Fehler nicht
   behoben haben und ich die betroffene Seite von hier aus nicht erreichen kann. Sie prüft den
   Server vom Gerät des Nutzers aus: Fassung der ausgelieferten `index.html`, jede JS-Datei
   einzeln auf Erreichbarkeit, Vollständigkeit und MIME-Typ (dem Modulbaum aus `main.js`
   folgend, ohne fest eingetragene Dateiliste), eingehängte Fremdskripte, PHP, und zum Schluss
   ein echter `import()`. Sie ist bewusst in altem JavaScript geschrieben und lädt nichts nach –
   sonst könnte sie genau den Fall nicht melden, für den es sie gibt. Ein Test hält das fest.

6. **Die Version an JEDER Modul-Adresse, per `importmap`.** Nachgereicht in 2.0.3, nachdem der
   Bericht der Prüfseite vom Gerät zurückkam. Punkt 4 war nämlich halb falsch: `?v=` hing nur an
   `main.js`, die 31 Dateien darunter wurden unter ihrer blanken Adresse geholt — und meine
   eigene `.htaccess` sagte dem Browser gleichzeitig, `.js` sieben Tage lang nicht mehr
   nachzufragen, während `index.html` auf `no-cache` stand. Neue Seite, eine Woche alte Module.
   Das ist keine Vermutung: im Browser nebeneinander nachgestellt (siehe unten). Behoben durch
   eine von `tools/make-importmap.mjs` erzeugte `importmap` — und `.js`/`.css` stehen jetzt
   ebenfalls auf „jedes Mal nachfragen".

7. **Dieselbe Tabelle für den Admin-Bereich.** Punkt 6 hatte in 2.0.3 nur `index.html` erfasst.
   Eine `importmap` gilt je **Dokument**, nicht je Ordner – `admin/index.html` lud sein Modul
   weiterhin ohne Version, und der Admin-Bereich behielt den Fehler unverändert. Aufgefallen ist
   das erst, als eine neue Funktion dort nach dem Hochladen schlicht nicht da war. Seit 2.1.1
   erzeugt `tools/make-importmap.mjs` für jede Seite eine eigene Tabelle, und zwar aus ihrem
   tatsächlichen Modulbaum statt aus einer Verzeichnisliste: was eine Seite lädt, kann so nicht
   mehr vergessen werden. Der Test läuft jetzt über **alle** Seiten.

## Begründung

Punkt 2 bis 4 beheben drei benennbare Ursachen. Punkt 1 ist trotzdem der wichtigste, weil er
als einziger auch die vierte behebt, die niemand kennt: er macht aus „es passiert nichts" eine
Meldung, die man abfotografieren und schicken kann.

Der Streifen kostet nichts, solange alles läuft – ein verstecktes `div` und zwei Zuhörer.

Punkt 5 kam dazu, als sich zeigte, dass der Streifen allein nicht reicht: er meldet nur, was im
Browser passiert. Liegt der Fehler beim Server – falscher MIME-Typ, halb hochgeladene Datei,
eine alte `index.html` –, dann sieht der Browser gar keinen Fehler, sondern nur nichts. Die
Prüfseite fragt deshalb den Server selbst.

## Alternativen

- **Nur die drei Fehler beheben.** Naheliegend, aber es wäre geraten gewesen: keiner davon war
  auf dem gemeldeten Gerät nachweisbar. Beim nächsten Mal stünde man wieder am selben Punkt.
- **Eine Fehlerbericht-Adresse, die automatisch sendet.** Wäre bequemer, verlangt aber einen
  Endpunkt, Zustimmung und eine Datenschutzerklärung. Für ein Spiel ohne Datenbank zu viel.
- **`console.error` und gut.** Genau das war der Zustand vorher.

## Nachweis für Punkt 6

Ein Server, der `.js` sieben Tage aufheben lässt (wie meine alte `.htaccess`), zweimal besucht:
erst mit der alten, dann mit der neuen Fassung. Der Zwischenspeicher bleibt dazwischen bestehen,
wie auf einem echten Gerät.

| Schema | erster Besuch | zweiter Besuch |
|---|---|---|
| alt — nur `main.js?v=` | 2.0.2 | **2.0.2** (der Browser nimmt das alte Modul) |
| neu — `importmap` | 2.0.3 | 9.9.9 |

Und für Punkt 7 dasselbe mit dem Admin-Bereich: erst den Stand vor der neuen Funktion besuchen,
dann die neue Fassung ausliefern.

| Stand auf dem Server | GIF-Knöpfe im Admin |
|---|---|
| 2.0.3 (vor der Funktion) | 0 |
| 2.1.0 — Tabelle nur im Spiel | **0** (der Browser nimmt das alte Modul) |
| 2.1.1 — Tabelle auch im Admin | 8 |

## Konsequenzen

- Ein Startfehler ist ab jetzt aus der Ferne diagnostizierbar, ohne das Gerät in der Hand zu
  haben.
- Ein fehlendes Element macht das Spiel nicht mehr unbedienbar, sondern nur unvollständig.
- Der Preis: zwei Stellen, die den Streifen zeichnen (das Skript in `index.html` und
  `engine/crash.js`). Das ist unvermeidlich – die eine muss ohne die andere auskommen.
- Ein alter Stand im Zwischenspeicher kann nicht mehr getroffen werden, ohne dass jemand etwas
  löschen muss — und es hängt auch nicht daran, ob der Hoster die `.htaccess` beachtet.
- `index.html` enthält einen erzeugten Block. Wer eine Datei unter `src/` anlegt oder umbenennt,
  muss `tools/make-importmap.mjs` laufen lassen; ein Test erzwingt das.
- Drei neue Tests: die Version in der Modul-Adresse muss zu `version.js` passen, und der
  Streifen muss samt seiner `display: none`-Regel im HTML und CSS vorhanden sein.

## Wiedervorlage

Sobald eine Meldung vom Gerät vorliegt. Zeigt sie eine Ursache, die hier nicht steht, gehört
sie nachgetragen. Bleibt der Streifen ein Jahr lang bei allen leer, kann der Wachhund
raus – der Rest bleibt.
