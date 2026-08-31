<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Templates\Page;

/**
 * Die Anleitung, die beim Kunden unter /doc liegt.
 *
 * Geschrieben wird sie hier und nicht von der KI. Der Grund ist
 * einfach: Eine Anleitung muss stimmen. Was diese Website kann, wissen
 * wir genau – wir haben sie gerade gebaut. Ein Text, der stattdessen
 * erfunden würde, wäre flüssiger und an den entscheidenden Stellen
 * falsch, und eine falsche Anleitung ist schlimmer als keine.
 *
 * Beschrieben wird nur, was tatsächlich mitgeliefert wurde. Ohne
 * Bearbeitungsbereich steht kein Wort darüber darin.
 *
 * Der Ton ist bewusst schlicht: keine Fachwörter, keine englischen
 * Begriffe, wo es deutsche gibt. Wer die Anleitung liest, sitzt
 * gerade vor einem Problem und will es gelöst haben.
 */
final class DocBuilder
{
    /**
     * doc.html neben die Website legen.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $site
     * @return int Anzahl geschriebener Dateien
     */
    public static function write(array $project, array $site, string $outputDir): int
    {
        $brief = json_decode((string) ($project['brief'] ?? '{}'), true) ?: [];

        if (empty($brief['wants_docs'])) {
            return 0;
        }

        $html = self::render($project, $site, $brief);

        write_file_atomic(rtrim($outputDir, '/') . '/doc.html', $html);

        return 1;
    }

    /**
     * Die Anleitung als eigenständige Seite.
     *
     * Sie hängt an keinem Stylesheet der Website: Wer sie aufruft, hat
     * womöglich gerade etwas kaputtgemacht. Dann soll wenigstens die
     * Anleitung noch lesbar sein.
     */
    public static function render(array $project, array $site, array $brief): string
    {
        $brand = (string) ($project['name'] ?? '');
        $domain = trim((string) ($project['domain'] ?? ''));
        $base = $domain !== '' ? 'https://' . preg_replace('~^https?://~i', '', $domain) : '';

        $chapters = self::chapters($project, $site, $brief, $base);

        $nav = '';
        $body = '';

        foreach ($chapters as $index => $chapter) {
            $id = 'k' . ($index + 1);
            $nav .= '<li><a href="#' . $id . '">' . self::esc($chapter['title']) . '</a></li>';
            $body .= '<section id="' . $id . '"><h2>' . self::esc($chapter['title']) . '</h2>'
                . $chapter['html'] . '</section>';
        }

        // Die Farbe landet unmaskiert im Stylesheet. Deshalb kommt hier
        // nur durch, was auch wirklich eine Farbe ist.
        $primary = (string) ($site['theme']['colors']['primary'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $primary) !== 1) {
            $primary = '#1f2937';
        }
        $escBrand = self::esc($brand);
        $today = date('d.m.Y');

        return <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Anleitung · {$escBrand}</title>
        <meta name="robots" content="noindex, nofollow">
        <style>
        :root { --ton: {$primary}; }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0 1.25rem 5rem;
            font: 1rem/1.7 system-ui, -apple-system, "Segoe UI", sans-serif;
            color: #17181c; background: #fbfbfd;
        }
        .wrap { max-width: 46rem; margin: 0 auto; }
        header { padding: 3rem 0 1.5rem; border-bottom: 1px solid #e4e4ec; }
        h1 { font-size: clamp(1.7rem, 5vw, 2.4rem); margin: 0 0 .4rem; letter-spacing: -.02em; }
        .lead { color: #5a5b6a; margin: 0; }
        nav { margin: 2rem 0 0; }
        nav ol { margin: 0; padding-left: 1.3rem; }
        nav a { color: var(--ton); text-decoration: none; }
        nav a:hover, nav a:focus-visible { text-decoration: underline; }
        section { padding: 2.5rem 0 0; }
        h2 {
            font-size: 1.35rem; margin: 0 0 .8rem; letter-spacing: -.01em;
            padding-bottom: .5rem; border-bottom: 2px solid var(--ton);
            display: inline-block;
        }
        h3 { font-size: 1.05rem; margin: 1.6rem 0 .4rem; }
        p, li { max-width: 40rem; }
        code {
            background: #eef0f6; padding: .12em .4em; border-radius: .25rem;
            font: .92em/1.4 ui-monospace, "SF Mono", Consolas, monospace;
        }
        .box {
            background: #fff; border: 1px solid #e4e4ec; border-left: 4px solid var(--ton);
            border-radius: .5rem; padding: 1rem 1.2rem; margin: 1.2rem 0;
        }
        .box p:first-child { margin-top: 0; }
        .box p:last-child { margin-bottom: 0; }
        ol.schritte { padding-left: 1.3rem; }
        ol.schritte li { margin: .5rem 0; }
        footer {
            margin-top: 4rem; padding-top: 1.5rem; border-top: 1px solid #e4e4ec;
            color: #7b7c8b; font-size: .9rem;
        }
        a { color: var(--ton); }
        @media (prefers-color-scheme: dark) {
            body { background: #101116; color: #e8e8ef; }
            header, footer, section h2 { border-color: #2a2b36; }
            .lead, footer { color: #9b9caa; }
            code { background: #1d1e27; }
            .box { background: #171822; border-color: #2a2b36; }
        }
        </style>
        </head>
        <body>
        <div class="wrap">
        <header>
            <h1>Anleitung zu Ihrer Website</h1>
            <p class="lead">{$escBrand} · Stand {$today}</p>
            <nav><ol>{$nav}</ol></nav>
        </header>
        {$body}
        <footer>
            <p>Diese Anleitung beschreibt genau die Website, die Sie erhalten haben.
            Kommt später etwas dazu, wird sie neu geschrieben.</p>
        </footer>
        </div>
        </body>
        </html>
        HTML;
    }

    /**
     * Die Kapitel – nur die, die auf diese Website zutreffen.
     *
     * @return array<int, array{title:string, html:string}>
     */
    private static function chapters(array $project, array $site, array $brief, string $base): array
    {
        $chapters = [];

        $chapters[] = ['title' => 'Was Sie bekommen haben', 'html' => self::intro($project, $site, $base)];
        $chapters[] = ['title' => 'Ihre Seiten', 'html' => self::pages($site, $base)];

        if (!empty($project['wants_admin'])) {
            $chapters[] = ['title' => 'Selbst ändern', 'html' => self::admin($project, $base)];
            $chapters[] = ['title' => 'Bilder tauschen', 'html' => self::images()];
            $chapters[] = ['title' => 'Abschnitte umstellen', 'html' => self::sections()];
        }

        $chapters[] = ['title' => 'Anfragen aus dem Kontaktformular', 'html' => self::leads($project, $base)];

        if (!empty($brief['wants_stats'])) {
            $chapters[] = ['title' => 'Besucherzahlen', 'html' => self::stats($brief)];
        }

        if (!empty($brief['wants_support'])) {
            $chapters[] = ['title' => 'Eine Frage stellen', 'html' => self::support($base)];
        }

        $chapters[] = ['title' => 'Wenn etwas nicht stimmt', 'html' => self::trouble($project, $base)];
        $chapters[] = ['title' => 'Umzug auf einen anderen Server', 'html' => self::move()];

        return $chapters;
    }

    private static function intro(array $project, array $site, string $base): string
    {
        $count = count((array) ($site['pages'] ?? []));
        $locales = (array) ($site['locales'] ?? ['de']);

        $html = '<p>Ihre Website besteht aus ganz gewöhnlichen Dateien. Sie liegen auf '
            . 'Ihrem Server und gehören Ihnen. Es läuft kein fremdes Programm mit, das '
            . 'abgeschaltet werden könnte, und es gibt kein Abonnement, ohne das die '
            . 'Seite verschwindet.</p>';

        $html .= '<p>Enthalten sind ' . $count . ' ' . ($count === 1 ? 'Seite' : 'Seiten');

        if (count($locales) > 1) {
            $html .= ' in ' . count($locales) . ' Sprachen';
        }

        $html .= '.</p>';

        if (empty($project['wants_admin'])) {
            $html .= '<div class="box"><p>Diese Website hat keinen eigenen '
                . 'Bearbeitungsbereich. Änderungen laufen über uns – melden Sie sich '
                . 'einfach, was anders sein soll.</p></div>';
        }

        if ($base !== '') {
            $html .= '<p>Sie erreichen die Website unter <a href="' . self::esc($base) . '">'
                . self::esc($base) . '</a>.</p>';
        }

        return $html;
    }

    private static function pages(array $site, string $base): string
    {
        $pages = (array) ($site['pages'] ?? []);
        $locales = (array) ($site['locales'] ?? ['de']);
        $primary = (string) ($locales[0] ?? 'de');

        $html = '<p>Jede Seite hat eine eigene Adresse. So finden Sie sie:</p><ul>';

        foreach ($pages as $page) {
            $path = (string) ($page['path'] ?? '/');
            $title = (string) ($page['title'] ?? $path);
            $file = Page::fileNameFor($path, $primary, $primary);
            $url = $base !== '' ? $base . '/' . $file : '/' . $file;

            $html .= '<li><strong>' . self::esc($title) . '</strong> – <code>'
                . self::esc($url) . '</code></li>';
        }

        $html .= '</ul>';

        if (count($locales) > 1) {
            $others = array_slice($locales, 1);
            $names = [];
            foreach ($others as $code) {
                $names[] = Page::LANGUAGE_NAMES[$code] ?? strtoupper((string) $code);
            }
            $html .= '<p>Die weiteren Sprachen liegen in eigenen Ordnern: '
                . self::esc(implode(', ', array_map(
                    static fn ($c): string => '/' . $c . '/',
                    $others
                )))
                . ' für ' . self::esc(implode(', ', $names))
                . '. Die Umschaltung oben auf der Seite führt jeweils zur passenden '
                . 'Fassung derselben Seite.</p>';
        }

        return $html;
    }

    private static function admin(array $project, string $base): string
    {
        $url = ($base !== '' ? $base : '') . '/admin';
        $user = (string) ($project['admin_username'] ?? '');

        $html = '<p>Texte und Bilder ändern Sie selbst, ohne uns zu fragen und ohne '
            . 'etwas installieren zu müssen.</p>';

        $html .= '<ol class="schritte">'
            . '<li>Rufen Sie <code>' . self::esc($url) . '</code> auf.</li>'
            . '<li>Melden Sie sich an'
            . ($user !== '' ? ' – Ihr Benutzername ist <code>' . self::esc($user) . '</code>' : '')
            . '. Das Passwort haben Sie von uns bekommen.</li>'
            . '<li>Wählen Sie links die Seite, dann den Abschnitt.</li>'
            . '<li>Ändern Sie den Text und klicken Sie auf <em>Speichern</em>.</li>'
            . '<li>Zum Schluss auf <em>Veröffentlichen</em>. Erst dann sehen es Ihre Besucher.</li>'
            . '</ol>';

        $html .= '<div class="box"><p><strong>Speichern und Veröffentlichen sind zwei '
            . 'Schritte.</strong> Das ist Absicht: Sie können in Ruhe arbeiten, mehrmals '
            . 'speichern, zwischendurch aufhören – nach aussen ändert sich nichts, bis '
            . 'Sie veröffentlichen.</p></div>';

        $html .= '<h3>Ihr Passwort ändern</h3>'
            . '<p>Im Bearbeitungsbereich unter <em>Zugang</em>. Nehmen Sie ein langes '
            . 'Passwort, das Sie nirgends sonst benutzen. Wir können es nicht nachlesen '
            . '– gespeichert ist nur ein Prüfwert, aus dem sich das Passwort nicht '
            . 'zurückrechnen lässt. Vergessen heisst deshalb: Wir setzen es neu.</p>';

        return $html;
    }

    private static function images(): string
    {
        return '<p>Bilder wechseln Sie im Bearbeitungsbereich direkt am Abschnitt: '
            . '<em>Bild ersetzen</em> anklicken, neue Datei wählen, fertig.</p>'
            . '<h3>Welche Bilder eignen sich</h3>'
            . '<ul>'
            . '<li>JPG oder PNG. Auch WEBP geht.</li>'
            . '<li>Mindestens 1600 Pixel breit für grosse Flächen, sonst wirkt es unscharf.</li>'
            . '<li>Direkt aus der Kamera ist völlig in Ordnung – die Datei wird beim '
            . 'Hochladen automatisch verkleinert.</li>'
            . '</ul>'
            . '<div class="box"><p>Achten Sie darauf, dass Sie die Bilder verwenden '
            . 'dürfen. Ein Bild aus einer Suchmaschine gehört nicht Ihnen, auch wenn '
            . 'es sich herunterladen lässt.</p></div>';
    }

    private static function sections(): string
    {
        return '<p>Eine Seite besteht aus Abschnitten: Kopfbereich, Leistungen, '
            . 'Kontakt und so weiter. Diese Abschnitte können Sie umstellen.</p>'
            . '<ul>'
            . '<li><strong>Reihenfolge:</strong> Am Anfasser links ziehen.</li>'
            . '<li><strong>Ausblenden:</strong> Der Schalter am Abschnitt. Der Inhalt '
            . 'bleibt erhalten, er wird nur nicht angezeigt – Sie können ihn jederzeit '
            . 'wieder einschalten.</li>'
            . '<li><strong>Anderes Aussehen:</strong> Unter <em>Darstellung</em> stehen '
            . '20 Varianten desselben Abschnitts bereit. Der Inhalt bleibt dabei stehen, '
            . 'nur die Anordnung ändert sich.</li>'
            . '</ul>'
            . '<h3>Änderung mit eigenen Worten</h3>'
            . '<p>Beim Abschnitt gibt es das Feld <em>Was soll anders sein?</em>. '
            . 'Schreiben Sie dort in normalem Deutsch hinein, was Sie möchten – etwa '
            . '„Überschrift kürzer und der Knopf soll auffälliger sein". Der '
            . 'Assistent ändert daraufhin diesen einen Abschnitt.</p>'
            . '<div class="box"><p>Er kann dabei nichts anderes anfassen. Kein anderer '
            . 'Abschnitt, keine andere Seite, kein Stück der Technik. Das ist keine '
            . 'Regel, an die er sich hält, sondern eine Grenze, die er nicht '
            . 'überschreiten kann.</p></div>';
    }

    private static function leads(array $project, string $base): string
    {
        $html = '<p>Wenn jemand das Kontaktformular abschickt, passiert zweierlei: '
            . 'Sie bekommen eine E-Mail, und die Anfrage wird zusätzlich auf dem Server '
            . 'gespeichert. So geht nichts verloren, falls eine E-Mail einmal im '
            . 'Spam-Ordner landet.</p>';

        if (!empty($project['wants_admin'])) {
            $html .= '<p>Nachlesen können Sie alle Anfragen im Bearbeitungsbereich '
                . 'unter <em>Anfragen</em>.</p>';
        }

        $html .= '<h3>Die Empfängeradresse ändern</h3>'
            . '<p>Sie steht in der Datei <code>data/config.php</code> auf Ihrem Server, '
            . 'im Feld <code>contact_email</code>. Wer sich mit dem Dateimanager seines '
            . 'Hostings auskennt, ändert sie dort selbst; sonst sagen Sie uns kurz '
            . 'Bescheid.</p>'
            . '<div class="box"><p>Kommen keine E-Mails an, liegt es fast immer am '
            . 'Versand des Hostings, nicht an der Website. Die Anfragen sind dann '
            . 'trotzdem gespeichert.</p></div>';

        return $html;
    }

    private static function stats(array $brief): string
    {
        $html = '<p>Ihre Website zählt, wie oft sie aufgerufen wird. Das läuft auf '
            . 'Ihrem eigenen Server – kein fremder Dienst bekommt etwas davon zu '
            . 'sehen.</p>'
            . '<p>Gespeichert wird: der Tag, welche Seite, und ob der Besuch von einer '
            . 'anderen Website kam. Nicht gespeichert wird, wer da war. Ob jemand '
            . 'zweimal am selben Tag kommt, wird über einen Wert erkannt, der jede '
            . 'Nacht wechselt und sich nicht zurückrechnen lässt – am nächsten Tag ist '
            . 'dieselbe Person nicht mehr wiederzuerkennen, auch für uns nicht.</p>'
            . '<div class="box"><p>Deshalb braucht Ihre Website kein Zustimmungsfenster '
            . 'für Cookies. Es gibt nichts, wofür jemand seine Zustimmung geben '
            . 'müsste.</p></div>';

        if (trim((string) ($brief['report_email'] ?? '')) !== '') {
            $html .= '<p>Jeden Montag geht eine kurze Übersicht an <code>'
                . self::esc((string) $brief['report_email']) . '</code>: Aufrufe, '
                . 'Besucher, die meistbesuchten Seiten und woher die Leute kamen, '
                . 'jeweils im Vergleich zur Vorwoche. Wenn Sie das nicht mehr möchten, '
                . 'genügt eine kurze Antwort auf diese E-Mail.</p>';
        }

        return $html;
    }

    private static function support(string $base): string
    {
        $url = ($base !== '' ? $base : '') . '/support';

        return '<p>Unter <code>' . self::esc($url) . '</code> können Sie uns jederzeit '
            . 'eine Frage stellen. Sie brauchen dafür kein Konto und müssen sich nicht '
            . 'anmelden.</p>'
            . '<ol class="schritte">'
            . '<li>Seite aufrufen, Frage hineinschreiben, abschicken.</li>'
            . '<li>Die Antwort erscheint auf derselben Seite. Rufen Sie sie später '
            . 'wieder auf, ist der ganze Verlauf noch da.</li>'
            . '<li>Haben Sie Ihre E-Mail-Adresse angegeben, schreiben wir Ihnen kurz, '
            . 'sobald eine Antwort da ist.</li>'
            . '</ol>'
            . '<div class="box"><p>Das ist kein Sofortdienst. Wir antworten, sobald wir '
            . 'dazu kommen – meist am selben oder am nächsten Werktag. Bei etwas '
            . 'wirklich Dringendem rufen Sie besser an.</p></div>';
    }

    private static function trouble(array $project, string $base): string
    {
        $html = '<h3>Die Seite sieht plötzlich kaputt aus</h3>'
            . '<p>Meistens hat der Browser noch eine alte Fassung gespeichert. '
            . 'Laden Sie die Seite einmal vollständig neu: <code>Strg</code> und '
            . '<code>F5</code> gleichzeitig (auf dem Mac <code>Cmd</code> und '
            . '<code>Shift</code> und <code>R</code>).</p>';

        if (!empty($project['wants_admin'])) {
            $html .= '<h3>Eine Änderung war ein Fehler</h3>'
                . '<p>Im Bearbeitungsbereich steht unter <em>Verlauf</em>, was wann '
                . 'geändert wurde. Jeder Stand lässt sich zurückholen. Solange Sie '
                . 'nicht veröffentlicht haben, sieht ohnehin niemand ausser Ihnen '
                . 'etwas davon.</p>';
        }

        $html .= '<h3>Die Website ist gar nicht erreichbar</h3>'
            . '<p>Dann liegt es fast immer am Hosting oder an der Domain, nicht an den '
            . 'Dateien. Prüfen Sie zuerst, ob die Rechnung Ihres Anbieters bezahlt ist '
            . '– eine nicht bezahlte Domain wird abgeschaltet, und die Seite ist weg, '
            . 'obwohl technisch alles in Ordnung ist.</p>';

        $html .= '<h3>Es hilft nichts</h3>'
            . '<p>Schreiben Sie uns. Nützlich ist dabei: welche Seite, was Sie gemacht '
            . 'haben, und was stattdessen passiert ist. Ein Bildschirmfoto sagt oft '
            . 'mehr als eine Beschreibung.</p>';

        return $html;
    }

    private static function move(): string
    {
        return '<p>Ihre Website ist an kein Hosting gebunden. Sie besteht aus Dateien, '
            . 'und Dateien lassen sich kopieren.</p>'
            . '<ol class="schritte">'
            . '<li>Laden Sie beim alten Anbieter alle Dateien herunter – den ganzen '
            . 'Ordner, samt <code>data</code> und der versteckten <code>.htaccess</code>.</li>'
            . '<li>Laden Sie sie beim neuen Anbieter in dessen Web-Ordner hoch '
            . '(meist <code>public_html</code>).</li>'
            . '<li>Zeigen Sie die Domain auf den neuen Server. Das dauert bis zu '
            . '24 Stunden.</li>'
            . '</ol>'
            . '<div class="box"><p>Der neue Server braucht PHP – Version 8 oder neuer. '
            . 'Das hat heute jedes gewöhnliche Hosting. Eine Datenbank braucht Ihre '
            . 'Website nicht.</p></div>'
            . '<p>Wenn Sie möchten, machen wir das für Sie. Sagen Sie einfach '
            . 'Bescheid.</p>';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
