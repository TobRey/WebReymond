<?php use App\Core\View; ?>
<article class="document">
    <h1>Hilfe und Bedienung</h1>

    <h2>Grundprinzip</h2>
    <p>Du bist FBI-Agent und bearbeitest einen Vermisstenfall. Es gibt keinen vorgegebenen Weg: Du entscheidest,
    wen du befragst, welches Geraet du durchsuchst und welche Spur du verfolgst. Das System merkt sich, was du
    bereits weisst, und schaltet davon abhaengig neue Moeglichkeiten frei.</p>

    <h2>Die Bereiche</h2>
    <ul class="list">
        <li><strong>Fallakte</strong> - Auftrag, vermisste Person, Rahmendaten.</li>
        <li><strong>Personen</strong> - alle Gespraechspartner. Klicke eine Person an, um frei zu schreiben.</li>
        <li><strong>Beweise</strong> - alles, was du gesichert hast. Von hier aus kannst du Personen konfrontieren.</li>
        <li><strong>Geraete</strong> - Handys, Laptops, Ueberwachungsrechner. Erst entsperren, dann durchsuchen.</li>
        <li><strong>Wand</strong> - Personen, Orte und Beweise verbinden.</li>
        <li><strong>Karte</strong> - Orte des Falls, Wege und Entfernungen.</li>
        <li><strong>Notizen</strong> - eigene Aufzeichnungen, jederzeit ergaenzbar.</li>
        <li><strong>Bericht</strong> - der Abschluss. Vorher gruendlich pruefen: ein falscher Bericht hat Folgen.</li>
    </ul>

    <h2>Verhoere</h2>
    <p>Schreibe ganz normale Fragen. Kurze, konkrete Fragen funktionieren am besten
    (&bdquo;Wo waren Sie um 22:30 Uhr?&ldquo;). Mit dem Mikrofon-Symbol kannst du diktieren, sofern dein Browser
    die Spracherkennung unterstuetzt - der erkannte Text erscheint zuerst im Eingabefeld und kann korrigiert werden.
    Ueber den Button <em>Beweis vorlegen</em> konfrontierst du eine Person mit einem gesicherten Beweis. Das ist
    oft der einzige Weg, eine Luege aufzubrechen.</p>

    <h2>Hinweise</h2>
    <p>Die Gluehbirne oben rechts gibt einen Hinweis zum aktuellen Stand - in drei Stufen, von der leisen Andeutung
    bis zur fast direkten Hilfe. Pro Fall stehen dir standardmaessig zwei Hinweise zu. Administratoren haben unbegrenzt viele.</p>

    <h2>Steuerung</h2>
    <ul class="list">
        <li><kbd>1</kbd> bis <kbd>9</kbd> - Bereiche wechseln</li>
        <li><kbd>H</kbd> - Hinweis anfordern</li>
        <li><kbd>N</kbd> - Notiz anlegen</li>
        <li><kbd>Esc</kbd> - Overlay schliessen</li>
    </ul>

    <h2>Barrierefreiheit und Komfort</h2>
    <p>In den Einstellungen im Spiel lassen sich Lautstaerke, Untertitel, Bildeffekte, Schriftgroesse und
    Schreckmomente getrennt regeln. Die Einstellung &bdquo;Bewegung reduzieren&ldquo; wird zusaetzlich automatisch
    vom Browser uebernommen (<code>prefers-reduced-motion</code>).</p>
</article>
