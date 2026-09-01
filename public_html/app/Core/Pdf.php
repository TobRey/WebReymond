<?php

declare(strict_types=1);

namespace WebAtze\Core;

/**
 * Ein sehr kleiner PDF-Schreiber.
 *
 * Warum von Hand? Auf einem gemieteten Hosting gibt es kein Composer,
 * und die üblichen Bibliotheken sind ein Vielfaches so gross wie diese
 * ganze Anwendung. Für eine Offerte und eine Rechnung braucht es davon
 * nichts: Text in zwei Schnitten, Linien, Rechtecke, mehrere Seiten.
 * Genau das steht hier – rund dreihundert Zeilen statt zwei Megabyte.
 *
 * Benutzt werden die beiden Schriften, die jeder PDF-Betrachter
 * mitbringt (Helvetica und Helvetica fett). Sie müssen deshalb nicht
 * eingebettet werden, und die Datei bleibt bei wenigen Kilobyte.
 *
 * Die Zeichenbreiten stehen unten in der Tabelle. Ohne sie liesse sich
 * ein Betrag nicht bündig rechts ausrichten – und eine Rechnung, in der
 * die Beträge nicht untereinander stehen, sieht nach Bastelei aus.
 */
final class Pdf
{
    /** A4 in Punkten. */
    public const WIDTH = 595.28;
    public const HEIGHT = 841.89;

    /** @var array<int, string> Der Inhalt je Seite */
    private array $pages = [];

    private string $current = '';

    private string $title;

    /** Die eingebetteten Bilder, nach Name. */
    private array $images = [];

    public function __construct(string $title = '')
    {
        $this->title = $title;
        $this->addPage();
    }

    public function addPage(): void
    {
        if ($this->current !== '') {
            $this->pages[] = $this->current;
        }

        $this->current = '';
    }

    /**
     * Text setzen.
     *
     * Der Nullpunkt liegt bei PDF unten links. Von oben zu rechnen ist
     * für jeden, der das hier liest, natürlicher – also rechnen wir um.
     */
    public function text(float $x, float $top, string $text, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $y = self::HEIGHT - $top;

        $this->current .= sprintf(
            "BT %s /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            self::color($rgb),
            $bold ? 'F2' : 'F1',
            $size,
            $x,
            $y,
            self::escape($text)
        );
    }

    /** Text, dessen rechte Kante bei $right liegt. */
    public function textRight(float $right, float $top, string $text, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $this->text($right - self::widthOf($text, $size, $bold), $top, $text, $size, $bold, $rgb);
    }

    /** Text, der um $center herum steht. */
    public function textCenter(float $center, float $top, string $text, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        $this->text($center - self::widthOf($text, $size, $bold) / 2, $top, $text, $size, $bold, $rgb);
    }

    /**
     * Ein Absatz mit Umbruch.
     *
     * @return float Wo der Absatz endet – von oben gerechnet
     */
    public function paragraph(float $x, float $top, float $width, string $text, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0]): float
    {
        $lineHeight = $size * 1.45;

        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $paragraph) {
            if (trim($paragraph) === '') {
                $top += $lineHeight * 0.6;
                continue;
            }

            $line = '';

            foreach (preg_split('/\s+/', trim($paragraph)) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line . ' ' . $word;

                if (self::widthOf($candidate, $size, $bold) > $width && $line !== '') {
                    $this->text($x, $top, $line, $size, $bold, $rgb);
                    $top += $lineHeight;
                    $line = $word;
                    continue;
                }

                $line = $candidate;
            }

            if ($line !== '') {
                $this->text($x, $top, $line, $size, $bold, $rgb);
                $top += $lineHeight;
            }
        }

        return $top;
    }

    public function line(float $x1, float $top1, float $x2, float $top2, float $thickness = 0.5, array $rgb = [0.8, 0.8, 0.8]): void
    {
        $this->current .= sprintf(
            "%s %.2F w %.2F %.2F m %.2F %.2F l S\n",
            self::strokeColor($rgb),
            $thickness,
            $x1,
            self::HEIGHT - $top1,
            $x2,
            self::HEIGHT - $top2
        );
    }

    public function rect(float $x, float $top, float $width, float $height, array $rgb = [0.95, 0.95, 0.97]): void
    {
        $this->current .= sprintf(
            "%s %.2F %.2F %.2F %.2F re f\n",
            self::color($rgb),
            $x,
            self::HEIGHT - $top - $height,
            $width,
            $height
        );
    }

    /** Die fertige Datei. */
    /**
     * Ein Bild setzen – für den Briefkopf.
     *
     * Nur JPEG, und das mit Absicht: JPEG-Daten lassen sich unverändert
     * in ein PDF legen (DCTDecode), ohne dass hier ein Bildformat
     * nachgebaut werden müsste. Alles andere wird beim Hochladen über
     * GD nach JPEG umgewandelt – das ist ehrlicher als ein halbfertiger
     * PNG-Dekodierer, der irgendwann an einer Datei scheitert.
     *
     * Masse in Punkten, wie überall in dieser Klasse.
     *
     * @param float $breite gewünschte Breite; die Höhe ergibt sich aus
     *                      dem Seitenverhältnis des Bildes
     * @return float die belegte Höhe
     */
    public function image(string $jpegDaten, float $x, float $top, float $breite): float
    {
        $masse = self::jpegSize($jpegDaten);

        if ($masse === null) {
            return 0.0;
        }

        [$bx, $by] = $masse;
        $hoehe = $breite * ($by / max(1, $bx));

        $name = 'Im' . (count($this->images) + 1);
        $this->images[$name] = $jpegDaten;

        // PDF rechnet von unten, wir von oben.
        $unten = self::HEIGHT - $top - $hoehe;

        $this->current .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
            $breite,
            $hoehe,
            $x,
            $unten,
            $name
        );

        return $hoehe;
    }

    /**
     * Breite und Höhe eines JPEG, ohne GD.
     *
     * Gelesen wird der SOF-Abschnitt. Ohne diese Angaben liesse sich das
     * Seitenverhältnis nicht halten, und ein verzerrtes Logo im
     * Briefkopf fällt sofort auf.
     *
     * @return array{0:int, 1:int}|null
     */
    private static function jpegSize(string $daten): ?array
    {
        $laenge = strlen($daten);

        if ($laenge < 4 || substr($daten, 0, 2) !== "\xFF\xD8") {
            return null;
        }

        $i = 2;

        while ($i < $laenge - 9) {
            if ($daten[$i] !== "\xFF") {
                $i++;
                continue;
            }

            $marker = ord($daten[$i + 1]);

            // SOF0 bis SOF15, ohne DHT/JPG/DAC dazwischen.
            if ($marker >= 0xC0 && $marker <= 0xCF
                && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                $hoehe = (ord($daten[$i + 5]) << 8) + ord($daten[$i + 6]);
                $breite = (ord($daten[$i + 7]) << 8) + ord($daten[$i + 8]);

                return $breite > 0 && $hoehe > 0 ? [$breite, $hoehe] : null;
            }

            $bloecke = (ord($daten[$i + 2]) << 8) + ord($daten[$i + 3]);
            $i += 2 + max(2, $bloecke);
        }

        return null;
    }

    public function output(): string
    {
        $pages = $this->pages;

        if ($this->current !== '') {
            $pages[] = $this->current;
        }

        if ($pages === []) {
            $pages[] = '';
        }

        $count = count($pages);

        // Nummerierung: 1 Katalog, 2 Seitenbaum, dann je Seite zwei
        // Objekte (Seite und Inhalt), zuletzt die zwei Schriften.
        $objects = [];

        $kids = [];
        for ($i = 0; $i < $count; $i++) {
            $kids[] = (3 + $i * 2) . ' 0 R';
        }

        $fontRegular = 3 + $count * 2;
        $fontBold = $fontRegular + 1;

        // Die Bilder bekommen die Nummern danach.
        $bildIds = [];
        $naechste = $fontBold + 1;

        foreach (array_keys($this->images) as $name) {
            $bildIds[$name] = $naechste++;
        }

        $xobjects = '';

        foreach ($bildIds as $name => $nummer) {
            $xobjects .= '/' . $name . ' ' . $nummer . ' 0 R ';
        }

        $resXObject = $xobjects !== '' ? ' /XObject << ' . trim($xobjects) . ' >>' : '';

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $count . ' >>';

        foreach ($pages as $index => $content) {
            $pageId = 3 + $index * 2;
            $contentId = $pageId + 1;

            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R'
                . sprintf(' /MediaBox [0 0 %.2F %.2F]', self::WIDTH, self::HEIGHT)
                . ' /Resources << /Font << /F1 ' . $fontRegular . ' 0 R /F2 ' . $fontBold . ' 0 R >>'
                . $resXObject . ' >>'
                . ' /Contents ' . $contentId . ' 0 R >>';

            $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }

        $objects[$fontRegular] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$fontBold] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($bildIds as $name => $nummer) {
            $daten = $this->images[$name];
            $masse = self::jpegSize($daten) ?? [1, 1];

            $objects[$nummer] = '<< /Type /XObject /Subtype /Image'
                . ' /Width ' . $masse[0] . ' /Height ' . $masse[1]
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode'
                . ' /Length ' . strlen($daten) . " >>\nstream\n" . $daten . "\nendstream";
        }

        $infoId = $naechste;
        $objects[$infoId] = '<< /Title (' . self::escape($this->title) . ') /Producer (WebAtze) /CreationDate (D:'
            . date('YmdHis') . "+00'00') >>";

        // Zusammenschreiben und dabei die Startpositionen merken.
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        ksort($objects);

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefAt = strlen($pdf);
        $max = max(array_keys($objects)) + 1;

        $pdf .= "xref\n0 " . $max . "\n0000000000 65535 f \n";

        for ($id = 1; $id < $max; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        $pdf .= "trailer\n<< /Size " . $max . ' /Root 1 0 R /Info ' . $infoId . " 0 R >>\n"
            . "startxref\n" . $xrefAt . "\n%%EOF\n";

        return $pdf;
    }

    // ------------------------------------------------------------- Kleinkram

    /**
     * Wie breit ist der Text?
     *
     * In Tausendstel der Schriftgrösse, so steht es in der Tabelle.
     */
    public static function widthOf(string $text, float $size, bool $bold = false): float
    {
        $table = $bold ? self::widthsBold() : self::widths();
        $sum = 0;

        foreach (str_split(self::toWinAnsi($text)) as $char) {
            $sum += $table[ord($char)] ?? 556;
        }

        return $sum / 1000 * $size;
    }

    /**
     * UTF-8 nach WinAnsi.
     *
     * PDF-Standardschriften kennen kein UTF-8. Ohne diese Umwandlung
     * würde aus jedem Umlaut ein Paar unlesbarer Zeichen.
     */
    public static function toWinAnsi(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        if ($converted !== false) {
            return $converted;
        }

        // Ohne iconv: wenigstens die Zeichen retten, die hier vorkommen.
        $map = [
            'ä' => "\xE4", 'ö' => "\xF6", 'ü' => "\xFC",
            'Ä' => "\xC4", 'Ö' => "\xD6", 'Ü' => "\xDC",
            'ß' => "\xDF", 'é' => "\xE9", 'è' => "\xE8", 'à' => "\xE0",
            'ç' => "\xE7", '–' => '-', '—' => '-', '„' => '"', '"' => '"',
            '‚' => "'", '‘' => "'", '’' => "'", '…' => '...', '·' => "\xB7",
            '€' => "\x80", '−' => '-',
        ];

        $text = strtr($text, $map);

        // Was übrig bleibt und kein ASCII ist, fliegt raus – lieber eine
        // Lücke als ein Kästchen.
        return preg_replace('/[\x80-\xFF]{2,}/', '', $text) ?? $text;
    }

    /** Klammern und Backslash müssen im PDF entwertet werden. */
    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], self::toWinAnsi($text));
    }

    private static function color(array $rgb): string
    {
        return sprintf('%.3F %.3F %.3F rg', $rgb[0] ?? 0, $rgb[1] ?? 0, $rgb[2] ?? 0);
    }

    private static function strokeColor(array $rgb): string
    {
        return sprintf('%.3F %.3F %.3F RG', $rgb[0] ?? 0, $rgb[1] ?? 0, $rgb[2] ?? 0);
    }

    /**
     * Zeichenbreiten von Helvetica, in Tausendsteln.
     *
     * @return array<int, int>
     */
    private static function widths(): array
    {
        static $table = null;

        if ($table !== null) {
            return $table;
        }

        $table = array_fill(0, 256, 556);

        $ascii = [
            32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667,
            39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333,
            46 => 278, 47 => 278,
            58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015,
            65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778,
            72 => 722, 73 => 278, 74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722,
            79 => 778, 80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722,
            86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611,
            91 => 278, 92 => 278, 93 => 278, 94 => 469, 95 => 556, 96 => 333,
            97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556,
            104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556,
            111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500, 116 => 278, 117 => 556,
            118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500,
            123 => 334, 124 => 260, 125 => 334, 126 => 584,
            // Umlaute und Akzente, so weit sie hier vorkommen
            196 => 667, 214 => 778, 220 => 722, 223 => 556,
            228 => 556, 246 => 556, 252 => 556,
            224 => 556, 232 => 556, 233 => 556, 231 => 500,
            183 => 278,
        ];

        // Ziffern sind bei Helvetica alle gleich breit – deshalb stehen
        // Beträge sauber untereinander.
        for ($i = 48; $i <= 57; $i++) {
            $ascii[$i] = 556;
        }

        foreach ($ascii as $code => $width) {
            $table[$code] = $width;
        }

        return $table;
    }

    /** @return array<int, int> */
    private static function widthsBold(): array
    {
        static $table = null;

        if ($table !== null) {
            return $table;
        }

        $table = self::widths();

        $bold = [
            33 => 333, 34 => 474, 38 => 722, 39 => 238,
            58 => 333, 59 => 333, 63 => 611, 64 => 975,
            65 => 722, 74 => 556, 75 => 722, 76 => 611,
            91 => 333, 93 => 333, 94 => 584,
            98 => 611, 99 => 556, 100 => 611, 102 => 333, 103 => 611, 104 => 611,
            105 => 278, 106 => 278, 107 => 556, 108 => 278, 109 => 889, 110 => 611,
            111 => 611, 112 => 611, 113 => 611, 114 => 389, 115 => 556, 116 => 333,
            117 => 611, 118 => 556, 119 => 778, 120 => 556, 121 => 556, 122 => 500,
            123 => 389, 124 => 280, 125 => 389,
            196 => 722, 223 => 611, 246 => 611, 252 => 611,
        ];

        foreach ($bold as $code => $width) {
            $table[$code] = $width;
        }

        return $table;
    }
}
