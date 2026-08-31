<?php

/**
 * Erzeugt zwanzig Designstudien für die Referenzen.
 *
 *     php tools/demo-referenzen.php
 *
 * Jede Studie ist eine eigenständige HTML-Datei unter
 * public_html/storage/showcase/<kennung>/index.html – dieselbe Stelle,
 * an der auch die Kopien echter Kundenwebsites liegen. Für die
 * Referenzkarte gibt es damit nur einen Weg, nicht zwei.
 *
 * Warum keine echten Kunden: WebAtze ist neu. Zwanzig erfundene Kunden
 * als echte Referenzen auszugeben wäre gegenüber Besuchern nicht ehrlich.
 * Sie stehen deshalb als Studien da – die Sektion ist voll, und es wird
 * nichts behauptet, was nicht stimmt.
 *
 * Die Studien sind reines CSS. Zwanzig WebGL-Leinwände nebeneinander auf
 * einer Seite würden jeden Rechner in die Knie zwingen; die Tiefe kommt
 * hier aus Perspektive, Schichtung, Licht und Schatten.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Nur auf der Kommandozeile.\n");
}

$root = dirname(__DIR__);
$target = $root . '/public_html/storage/showcase';

require_once __DIR__ . '/lib/studien.php';
require_once __DIR__ . '/lib/render-studie.php';

$studien = studien();

if (count($studien) !== 20) {
    fwrite(STDERR, 'Erwartet werden 20 Studien, definiert sind ' . count($studien) . ".\n");
    exit(1);
}

// --- Schreiben -----------------------------------------------------

$geschrieben = 0;
$bytes = 0;

foreach ($studien as $studie) {
    $dir = $target . '/' . $studie['slug'];

    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        fwrite(STDERR, "Ordner liess sich nicht anlegen: {$dir}\n");
        exit(1);
    }

    $html = render_studie($studie);

    file_put_contents($dir . '/index.html', $html);
    $geschrieben++;
    $bytes += strlen($html);
}

// --- Die Einträge für die Datenbank --------------------------------

$rows = [];

foreach ($studien as $index => $studie) {
    $rows[] = [
        'slug' => $studie['slug'],
        'title' => $studie['name'],
        'subtitle' => $studie['untertitel'],
        'body_de' => $studie['text_de'],
        'body_en' => $studie['text_en'],
        'tags' => implode(', ', $studie['tags']),
        'live_url' => '',
        'preview_path' => '/referenz-ansicht/' . $studie['slug'] . '/',
        'accent_color' => $studie['akzent'],
        'sort_order' => $index,
    ];
}

$seedFile = $root . '/public_html/app/Support/showcase-seed.php';

file_put_contents($seedFile, "<?php\n\n"
    . "/**\n"
    . " * Die Designstudien für die Referenzen.\n"
    . " *\n"
    . " * Erzeugt von tools/demo-referenzen.php – nicht von Hand ändern.\n"
    . " * Eingetragen werden sie bei der Einrichtung (install.php), und nur\n"
    . " * dann, wenn die Referenzen noch leer sind. Wer sie nicht will,\n"
    . " * entfernt sie im Adminbereich unter Referenzen.\n"
    . " *\n"
    . " * Die zugehörigen Seiten liegen unter storage/showcase/<kennung>/.\n"
    . " */\n\n"
    . "declare(strict_types=1);\n\n"
    . 'return ' . var_export($rows, true) . ";\n");

printf(
    "\n  %d Studien geschrieben (%s)\n  %s\n  %s\n\n",
    $geschrieben,
    number_format($bytes / 1024, 0, ',', "'") . ' KB',
    str_replace($root . '/', '', $target),
    str_replace($root . '/', '', $seedFile)
);
