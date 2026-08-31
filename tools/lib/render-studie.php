<?php

/**
 * Baut aus einer Studie eine fertige HTML-Seite.
 *
 * Gerendert wird für 1440 Pixel Breite – so gross zeigt die Referenzkarte
 * den Rahmen, bevor sie ihn auf etwa 28 Prozent verkleinert. Was hier
 * winzig wäre, verschwindet dort ganz; deshalb ist alles eine Spur
 * grösser und kontrastreicher als auf einer Seite, die man aus der Nähe
 * liest.
 *
 * Die Tiefe kommt aus CSS: Perspektive, gestapelte Ebenen, Licht und
 * Schatten. Zwanzig WebGL-Leinwände auf einer Seite würde kein Rechner
 * mitmachen.
 */

declare(strict_types=1);

function studie_e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Die Schriftstimmung. */
function studie_schrift(string $art): array
{
    return match ($art) {
        'serif' => [
            'display' => 'Georgia, "Iowan Old Style", "Times New Roman", serif',
            'body' => 'Georgia, "Iowan Old Style", serif',
            'tracking' => '-0.02em',
        ],
        'mono' => [
            'display' => 'ui-monospace, "SF Mono", "Cascadia Mono", Menlo, monospace',
            'body' => 'ui-monospace, "SF Mono", Menlo, monospace',
            'tracking' => '-0.03em',
        ],
        'rund' => [
            'display' => '"Avenir Next", "Segoe UI", "Trebuchet MS", system-ui, sans-serif',
            'body' => '"Avenir Next", "Segoe UI", system-ui, sans-serif',
            'tracking' => '-0.015em',
        ],
        'schmal' => [
            'display' => '"Haettenschweiler", "Arial Narrow", "Helvetica Neue", Impact, sans-serif',
            'body' => '"Helvetica Neue", Arial, system-ui, sans-serif',
            'tracking' => '-0.03em',
        ],
        default => [
            'display' => '"Helvetica Neue", Helvetica, Arial, system-ui, sans-serif',
            'body' => '"Helvetica Neue", Helvetica, Arial, system-ui, sans-serif',
            'tracking' => '-0.025em',
        ],
    };
}

/**
 * Der Aufmacher – hier unterscheiden sich die zwanzig Studien wirklich.
 *
 * Jeder Stil bringt seinen eigenen Aufbau und seine eigene Tiefenwirkung
 * mit. Ein einziges Gerüst in zwanzig Farben wäre keine Vielfalt.
 */
function studie_hero(array $s): string
{
    $titel = studie_e($s['titel']);
    $lead = studie_e($s['lead']);
    $branche = studie_e(mb_strtoupper($s['branche']));
    $name = studie_e($s['name']);

    $knopf = '<div class="cta"><a class="btn" href="#">Kontakt aufnehmen</a>'
        . '<a class="btn btn--leise" href="#">Mehr erfahren</a></div>';

    return match ($s['stil']) {

        // Nordlicht: Lichtschleier über der Nacht
        'aurora' => <<<HTML
        <div class="aurora" aria-hidden="true">
            <span class="aurora__band aurora__band--1"></span>
            <span class="aurora__band aurora__band--2"></span>
            <span class="aurora__band aurora__band--3"></span>
        </div>
        <div class="wrap hero hero--mitte">
            <p class="eyebrow">{$branche}</p>
            <h1 class="riesig">{$titel}</h1>
            <p class="lead">{$lead}</p>
            {$knopf}
        </div>
        HTML,

        // Vertikal: harte Blöcke, versetzt gestapelt
        'brutal' => <<<HTML
        <div class="wrap hero hero--split">
            <div>
                <p class="eyebrow eyebrow--kasten">{$branche}</p>
                <h1 class="kasten">{$titel}</h1>
                <p class="lead">{$lead}</p>
                {$knopf}
            </div>
            <div class="bloecke" aria-hidden="true">
                <span class="block block--1"></span>
                <span class="block block--2"></span>
                <span class="block block--3"></span>
            </div>
        </div>
        HTML,

        // Marbach: Satzspiegel wie in einer Zeitschrift
        'editorial' => <<<HTML
        <div class="wrap hero hero--editorial">
            <div class="rule"></div>
            <p class="eyebrow">{$branche} &middot; seit 1908</p>
            <h1 class="riesig kursiv">{$titel}</h1>
            <div class="spalten">
                <p class="lead">{$lead}</p>
                <p class="lead lead--leise">Die Reben stehen auf Kalk und Molasse.
                   Was daraus wird, entscheidet der Hang – wir helfen nur nach.</p>
            </div>
            {$knopf}
        </div>
        HTML,

        // Puls: weiche Formen, viel Luft
        'sanft' => <<<HTML
        <div class="blobs" aria-hidden="true">
            <span class="blob blob--1"></span>
            <span class="blob blob--2"></span>
        </div>
        <div class="wrap hero hero--split">
            <div>
                <p class="eyebrow">{$branche}</p>
                <h1 class="riesig">{$titel}</h1>
                <p class="lead">{$lead}</p>
                {$knopf}
            </div>
            <div class="karten-stapel" aria-hidden="true">
                <span class="karte karte--hinten"></span>
                <span class="karte karte--mitte"></span>
                <span class="karte karte--vorn"><em>Termin</em><b>Di 14:30</b></span>
            </div>
        </div>
        HTML,

        // Achtsam: alles fliesst
        'organisch' => <<<HTML
        <div class="blobs blobs--gross" aria-hidden="true">
            <span class="blob blob--1"></span>
            <span class="blob blob--2"></span>
            <span class="blob blob--3"></span>
        </div>
        <div class="wrap hero hero--mitte hero--schmal">
            <p class="eyebrow">{$branche}</p>
            <h1 class="riesig kursiv">{$titel}</h1>
            <p class="lead">{$lead}</p>
            {$knopf}
        </div>
        HTML,

        // Kernbau: gekippte Ebenen wie eine Axonometrie
        'iso' => <<<HTML
        <div class="wrap hero hero--split">
            <div>
                <p class="eyebrow">{$branche}</p>
                <h1 class="riesig">{$titel}</h1>
                <p class="lead">{$lead}</p>
                {$knopf}
            </div>
            <div class="iso" aria-hidden="true">
                <span class="iso__ebene iso__ebene--1"></span>
                <span class="iso__ebene iso__ebene--2"></span>
                <span class="iso__ebene iso__ebene--3"></span>
            </div>
        </div>
        HTML,

        // Fuchsbau: weiche, plastische Körper
        'clay' => <<<HTML
        <div class="wrap hero hero--split">
            <div>
                <p class="eyebrow">{$branche}</p>
                <h1 class="riesig">{$titel}</h1>
                <p class="lead">{$lead}</p>
                {$knopf}
            </div>
            <div class="clay" aria-hidden="true">
                <span class="clay__koerper clay__koerper--1"></span>
                <span class="clay__koerper clay__koerper--2"></span>
                <span class="clay__koerper clay__koerper--3"></span>
            </div>
        </div>
        HTML,

        // Neonwerk: Leuchtschrift auf Schwarz
        'neon' => <<<HTML
        <div class="scanlines" aria-hidden="true"></div>
        <div class="wrap hero hero--mitte">
            <p class="eyebrow eyebrow--glut">{$branche}</p>
            <h1 class="riesig glut">{$titel}</h1>
            <p class="lead">{$lead}</p>
            <div class="programm" aria-hidden="true">
                <span><b>FR 12.09</b> Kellerkind</span>
                <span><b>SA 13.09</b> Nachtschicht</span>
                <span><b>FR 19.09</b> Blaulicht</span>
            </div>
            {$knopf}
        </div>
        HTML,

        // Silbereis: sehr dunkel, sehr fein
        'luxe' => <<<HTML
        <div class="wrap hero hero--mitte hero--schmal">
            <span class="haarlinie" aria-hidden="true"></span>
            <p class="eyebrow gesperrt">{$branche}</p>
            <h1 class="riesig kursiv">{$titel}</h1>
            <p class="lead">{$lead}</p>
            <span class="haarlinie" aria-hidden="true"></span>
            {$knopf}
        </div>
        HTML,

        // Terra: Ebenen wie Pflanzschichten
        'schichten' => <<<HTML
        <div class="tiefe" aria-hidden="true">
            <span class="tiefe__schicht tiefe__schicht--3"></span>
            <span class="tiefe__schicht tiefe__schicht--2"></span>
            <span class="tiefe__schicht tiefe__schicht--1"></span>
        </div>
        <div class="wrap hero">
            <p class="eyebrow">{$branche}</p>
            <h1 class="riesig">{$titel}</h1>
            <p class="lead">{$lead}</p>
            {$knopf}
        </div>
        HTML,

        // Codewerk: Raster und feste Schriftbreite
        'terminal' => <<<HTML
        <div class="raster" aria-hidden="true"></div>
        <div class="wrap hero hero--split">
            <div>
                <p class="eyebrow">&gt; {$branche}</p>
                <h1 class="riesig">{$titel}</h1>
                <p class="lead">{$lead}</p>
                {$knopf}
            </div>
            <div class="fenster" aria-hidden="true">
                <span class="fenster__leiste"></span>
                <code>
                    <b>$</b> werkzeug bauen --fuer lager<br>
                    <i>&rarr; 14 Masken erzeugt</i><br>
                    <i>&rarr; Schnittstelle bereit</i><br>
                    <b>$</b> <span class="cursor"></span>
                </code>
            </div>
        </div>
        HTML,

        // Alpenluft: Bergketten in die Tiefe
        'weite' => <<<HTML
        <div class="berge" aria-hidden="true">
            <span class="berg berg--3"></span>
            <span class="berg berg--2"></span>
            <span class="berg berg--1"></span>
        </div>
        <div class="wrap hero hero--mitte">
            <p class="eyebrow">{$branche}</p>
            <h1 class="riesig">{$titel}</h1>
            <p class="lead">{$lead}</p>
            <div class="tafel" aria-hidden="true">
                <span><b>Nächste Fahrt</b>09:20</span>
                <span><b>Berg</b>4&deg;C, sonnig</span>
                <span><b>Retour</b>CHF 38</span>
            </div>
        </div>
        HTML,

        // Rosé: rund und schwebend
        'verspielt' => <<<HTML
        <div class="schweber" aria-hidden="true">
            <span class="kugel kugel--1"></span>
            <span class="kugel kugel--2"></span>
            <span class="kugel kugel--3"></span>
            <span class="kugel kugel--4"></span>
        </div>
        <div class="wrap hero hero--mitte">
            <p class="eyebrow eyebrow--pille">{$branche}</p>
            <h1 class="riesig">{$titel}</h1>
            <p class="lead">{$lead}</p>
            {$knopf}
        </div>
        HTML,

        // Stahlwerk: eine Diagonale durch die Seite
        'industrie' => <<<HTML
        <span class="diagonale" aria-hidden="true"></span>
        <div class="wrap hero hero--split">
            <div>
                <p class="eyebrow eyebrow--kasten">{$branche}</p>
                <h1 class="riesig">{$titel}</h1>
                <p class="lead">{$lead}</p>
                {$knopf}
            </div>
            <div class="traeger" aria-hidden="true">
                <span class="traeger__balken traeger__balken--1"></span>
                <span class="traeger__balken traeger__balken--2"></span>
            </div>
        </div>
        HTML,

        // Lumen: ein einziger Lichtkegel
        'licht' => <<<HTML
        <span class="kegel" aria-hidden="true"></span>
        <div class="wrap hero hero--mitte hero--schmal">
            <p class="eyebrow gesperrt">{$branche}</p>
            <h1 class="riesig">{$titel}</h1>
            <p class="lead">{$lead}</p>
            {$knopf}
        </div>
        HTML,

        // Seeblick: Milchglas über tiefem Wasser
        'glas' => <<<HTML
        <div class="wasser" aria-hidden="true"></div>
        <div class="wrap hero hero--split">
            <div class="scheibe">
                <p class="eyebrow">{$branche}</p>
                <h1 class="riesig kursiv">{$titel}</h1>
                <p class="lead">{$lead}</p>
                {$knopf}
            </div>
            <div class="scheibe scheibe--klein" aria-hidden="true">
                <b>Freie Zimmer</b>
                <span>Do 11.09 &ndash; Sa 13.09</span>
                <span class="preis">ab CHF 180</span>
            </div>
        </div>
        HTML,

        // Volt: Tempo ohne Bewegung
        'speed' => <<<HTML
        <div class="spuren" aria-hidden="true">
            <span></span><span></span><span></span><span></span>
        </div>
        <div class="wrap hero hero--split">
            <div>
                <p class="eyebrow">{$branche}</p>
                <h1 class="riesig schraeg">{$titel}</h1>
                <p class="lead">{$lead}</p>
                {$knopf}
            </div>
            <div class="werte" aria-hidden="true">
                <span><b>80</b>km Reichweite</span>
                <span><b>2.4</b>h laden</span>
                <span><b>25</b>km/h</span>
            </div>
        </div>
        HTML,

        // Papier & Co: Bogen mit Prägung
        'papier' => <<<HTML
        <div class="wrap hero hero--split">
            <div>
                <p class="eyebrow gesperrt">{$branche}</p>
                <h1 class="riesig kursiv">{$titel}</h1>
                <p class="lead">{$lead}</p>
                {$knopf}
            </div>
            <div class="bogen" aria-hidden="true">
                <span class="bogen__blatt bogen__blatt--3"></span>
                <span class="bogen__blatt bogen__blatt--2"></span>
                <span class="bogen__blatt bogen__blatt--1"></span>
            </div>
        </div>
        HTML,

        // Orbit: ein Feld aus Punkten
        'partikel' => <<<HTML
        <div class="punkte" aria-hidden="true"></div>
        <div class="wrap hero hero--mitte">
            <p class="eyebrow">{$branche}</p>
            <h1 class="riesig">{$titel}</h1>
            <p class="lead">{$lead}</p>
            {$knopf}
        </div>
        HTML,

        // Holzgeist: Maserung im Hintergrund
        default => <<<HTML
        <div class="maserung" aria-hidden="true"></div>
        <div class="wrap hero hero--split">
            <div>
                <p class="eyebrow">{$branche}</p>
                <h1 class="riesig">{$titel}</h1>
                <p class="lead">{$lead}</p>
                {$knopf}
            </div>
            <div class="platten" aria-hidden="true">
                <span class="platte platte--1"></span>
                <span class="platte platte--2"></span>
            </div>
        </div>
        HTML,
    };
}

/** Die Regeln, die alle Studien teilen. */
function studie_basis_css(array $s, array $f): string
{
    return <<<CSS
    *,*::before,*::after{box-sizing:border-box}
    body{margin:0;background:{$s['bg']};color:{$s['text']};
      font-family:{$f['body']};font-size:19px;line-height:1.65;
      -webkit-font-smoothing:antialiased;overflow-x:hidden}
    a{color:inherit;text-decoration:none}

    /* Schmuckebenen ragen absichtlich über den Rand hinaus – hier werden
       sie abgeschnitten. Ohne das wächst die Seite in die Breite, und im
       Rahmen der Referenzkarte stünde plötzlich alles zu weit links. */
    main{position:relative;overflow:hidden}

    .wrap{position:relative;z-index:2;max-width:1180px;margin-inline:auto;padding-inline:64px}

    /* Kopfzeile */
    .kopf{position:relative;z-index:5;display:flex;align-items:center;
      justify-content:space-between;gap:32px;padding:28px 0}
    .marke{display:flex;align-items:center;gap:12px;font-family:{$f['display']};
      font-size:23px;font-weight:700;letter-spacing:{$f['tracking']}}
    .marke i{display:block;inline-size:30px;block-size:30px;border-radius:9px;
      background:linear-gradient(135deg,{$s['akzent']},{$s['zweit']})}
    .menue{display:flex;gap:30px;font-size:16px;color:{$s['leise']}}

    /* Aufmacher */
    .hero{position:relative;padding-block:104px 128px}
    .hero--mitte{text-align:center}
    .hero--mitte .lead{margin-inline:auto}
    .hero--mitte .cta{justify-content:center}
    .hero--schmal .lead{max-width:600px}
    .hero--split{display:grid;grid-template-columns:1.06fr 0.94fr;
      gap:64px;align-items:center}

    .eyebrow{margin:0 0 18px;font-size:14px;font-weight:700;
      letter-spacing:0.16em;color:{$s['akzent']}}
    .gesperrt{letter-spacing:0.3em}
    .eyebrow--kasten{display:inline-block;padding:6px 14px;
      background:{$s['akzent']};color:{$s['bg']};letter-spacing:0.12em}
    .eyebrow--pille{display:inline-block;padding:8px 20px;border-radius:99px;
      background:color-mix(in srgb,{$s['akzent']} 14%,transparent)}

    h1.riesig{margin:0 0 24px;font-family:{$f['display']};
      font-size:74px;line-height:1.04;letter-spacing:{$f['tracking']};font-weight:800}
    .kursiv{font-style:italic;font-weight:400}
    .schraeg{transform:skewX(-6deg);transform-origin:left}

    .lead{margin:0 0 34px;max-width:640px;font-size:21px;color:{$s['leise']}}
    .lead--leise{opacity:.75}

    .cta{display:flex;flex-wrap:wrap;gap:14px}
    .btn{display:inline-block;padding:16px 34px;border-radius:99px;
      background:{$s['akzent']};color:{$s['bg']};
      font-family:{$f['body']};font-size:17px;font-weight:700}
    .btn--leise{background:transparent;color:{$s['text']};
      border:1.5px solid color-mix(in srgb,{$s['text']} 26%,transparent)}

    /* Abschnitt darunter */
    .band{position:relative;z-index:2;padding-block:88px;
      border-block-start:1px solid color-mix(in srgb,{$s['text']} 12%,transparent)}
    .kacheln{display:grid;grid-template-columns:repeat(3,1fr);gap:26px}
    .kachel{padding:38px 34px;border-radius:20px;background:{$s['flaeche']};
      border:1px solid color-mix(in srgb,{$s['text']} 10%,transparent);
      box-shadow:0 1px 2px rgb(0 0 0/.04),0 22px 50px -30px rgb(0 0 0/.4)}
    .kachel b{display:block;margin-bottom:10px;font-family:{$f['display']};
      font-size:26px;letter-spacing:{$f['tracking']}}
    .kachel span{color:{$s['leise']};font-size:17px}
    .kachel i{display:block;inline-size:44px;block-size:44px;margin-bottom:22px;
      border-radius:13px;background:linear-gradient(140deg,{$s['akzent']},{$s['zweit']});
      opacity:.9}

    /* Fusszeile */
    .fuss{position:relative;z-index:2;padding:44px 0 64px;font-size:15px;
      color:{$s['leise']};
      border-block-start:1px solid color-mix(in srgb,{$s['text']} 10%,transparent)}
    .fuss .wrap{display:flex;justify-content:space-between;gap:24px}
    CSS;
}

/** Was nur dieser eine Stil braucht. */
function studie_stil_css(array $s): string
{
    $a = $s['akzent'];
    $z = $s['zweit'];
    $bg = $s['bg'];
    $fl = $s['flaeche'];
    $tx = $s['text'];

    return match ($s['stil']) {

        'aurora' => <<<CSS
        .aurora{position:absolute;inset:-140px -12% auto;block-size:820px;filter:blur(78px);opacity:.85}
        .aurora__band{position:absolute;border-radius:50%}
        .aurora__band--1{inset-block-start:150px;inset-inline-start:2%;inline-size:640px;block-size:340px;
          background:{$a};opacity:.6;transform:rotate(-13deg)}
        .aurora__band--2{inset-block-start:60px;inset-inline-start:34%;inline-size:760px;block-size:300px;
          background:{$z};opacity:.65;transform:rotate(8deg)}
        .aurora__band--3{inset-block-start:300px;inset-inline-start:20%;inline-size:560px;block-size:240px;
          background:{$a};opacity:.35;transform:rotate(-4deg)}
        body{background:radial-gradient(120% 80% at 50% 0%,#101a35 0%,{$bg} 62%)}
        CSS,

        'brutal' => <<<CSS
        h1.kasten{padding:6px 0 10px;border-block:6px solid {$tx}}
        .btn{border-radius:0}
        .btn--leise{border-radius:0;border-width:2px}
        .kachel{border-radius:0;border:2px solid {$tx};box-shadow:8px 8px 0 {$a}}
        .kachel i{border-radius:0}
        .bloecke{position:relative;block-size:400px;perspective:900px}
        .block{position:absolute;border:3px solid {$tx}}
        .block--1{inset:0 96px 96px 0;background:{$a}}
        .block--2{inset:56px 40px 40px 80px;background:{$fl}}
        .block--3{inset:120px 0 0 150px;background:{$z};opacity:.9}
        CSS,

        'editorial' => <<<CSS
        .rule{block-size:1px;background:color-mix(in srgb,{$tx} 28%,transparent);margin-bottom:34px}
        .hero--editorial h1.riesig{font-size:86px;max-width:14ch}
        .spalten{display:grid;grid-template-columns:1fr 1fr;gap:52px;margin-bottom:36px;
          padding-block-start:26px;border-block-start:1px solid color-mix(in srgb,{$tx} 18%,transparent)}
        .spalten .lead{margin:0;font-size:19px}
        .btn{border-radius:2px}
        .btn--leise{border-radius:2px}
        .kachel{border-radius:2px;box-shadow:none;background:transparent;
          border:0;border-block-start:2px solid {$a};padding-inline:0}
        CSS,

        'sanft' => <<<CSS
        .blobs{position:absolute;inset:0;overflow:hidden}
        .blob{position:absolute;border-radius:50%;filter:blur(70px)}
        .blob--1{inset-block-start:-160px;inset-inline-end:-80px;inline-size:620px;block-size:620px;
          background:{$z};opacity:.4}
        .blob--2{inset-block-start:220px;inset-inline-start:-180px;inline-size:480px;block-size:480px;
          background:{$a};opacity:.16}
        .karten-stapel{position:relative;block-size:400px;perspective:1100px}
        .karte{position:absolute;inset-inline:40px;block-size:250px;border-radius:26px;
          background:{$fl};box-shadow:0 30px 60px -28px rgb(14 116 144/.42)}
        .karte--hinten{inset-block-start:0;transform:rotateX(16deg) translateZ(-90px) scale(.9);opacity:.5}
        .karte--mitte{inset-block-start:44px;transform:rotateX(11deg) translateZ(-40px) scale(.96);opacity:.78}
        .karte--vorn{inset-block-start:92px;display:grid;align-content:center;gap:8px;
          padding:0 44px;transform:rotateX(7deg)}
        .karte--vorn em{font-style:normal;font-size:16px;color:{$s['leise']}}
        .karte--vorn b{font-size:44px;color:{$a}}
        CSS,

        'organisch' => <<<CSS
        .blobs--gross{position:absolute;inset:0;overflow:hidden}
        .blob{position:absolute;filter:blur(50px)}
        .blob--1{inset-block-start:-90px;inset-inline-start:-90px;inline-size:520px;block-size:440px;
          background:{$a};opacity:.28;border-radius:58% 42% 39% 61%/47% 53% 47% 53%}
        .blob--2{inset-block-start:120px;inset-inline-end:-110px;inline-size:520px;block-size:520px;
          background:{$z};opacity:.6;border-radius:41% 59% 62% 38%/54% 36% 64% 46%}
        .blob--3{inset-block-start:470px;inset-inline-start:14%;inline-size:380px;block-size:260px;
          background:{$a};opacity:.2;border-radius:50% 50% 36% 64%/38% 62% 38% 62%}
        .btn{border-radius:99px}
        .kachel{border-radius:38px 38px 38px 8px}
        .kachel i{border-radius:50% 50% 42% 58%}
        CSS,

        'iso' => <<<CSS
        .iso{position:relative;block-size:440px;perspective:1400px;
          transform-style:preserve-3d}
        .iso__ebene{position:absolute;inset-inline:20px;block-size:210px;
          border:2px solid color-mix(in srgb,{$s['text']} 30%,transparent);
          transform-origin:center}
        .iso__ebene--1{inset-block-start:180px;background:{$fl};
          transform:rotateX(58deg) rotateZ(-42deg) translateZ(0)}
        .iso__ebene--2{inset-block-start:120px;background:color-mix(in srgb,{$a} 22%,{$fl});
          border-color:{$a};transform:rotateX(58deg) rotateZ(-42deg) translateZ(60px)}
        .iso__ebene--3{inset-block-start:60px;background:{$a};
          border-color:{$a};transform:rotateX(58deg) rotateZ(-42deg) translateZ(120px)}
        .kachel{background:{$fl}}
        CSS,

        'clay' => <<<CSS
        .clay{position:relative;block-size:420px}
        .clay__koerper{position:absolute;border-radius:50%;
          box-shadow:inset -14px -18px 34px rgb(0 0 0/.13),
                     inset 12px 14px 26px rgb(255 255 255/.75),
                     0 30px 50px -22px rgb(120 53 15/.34)}
        .clay__koerper--1{inset-block-start:30px;inset-inline-start:40px;inline-size:230px;block-size:230px;
          background:{$a}}
        .clay__koerper--2{inset-block-start:150px;inset-inline-start:210px;inline-size:180px;block-size:180px;
          background:{$z}}
        .clay__koerper--3{inset-block-start:60px;inset-inline-start:250px;inline-size:120px;block-size:120px;
          background:{$fl};border-radius:34px;transform:rotate(-12deg)}
        .kachel{border-radius:28px;box-shadow:0 22px 44px -26px rgb(120 53 15/.4)}
        CSS,

        'neon' => <<<CSS
        body{background:radial-gradient(90% 60% at 50% -10%,#1a0b33 0%,{$bg} 70%)}
        .scanlines{position:absolute;inset:0;opacity:.3;
          background:repeating-linear-gradient(0deg,transparent 0 3px,rgb(255 255 255/.04) 3px 4px)}
        .glut{color:{$a};text-shadow:0 0 14px color-mix(in srgb,{$a} 70%,transparent),
          0 0 44px color-mix(in srgb,{$a} 45%,transparent)}
        .eyebrow--glut{color:{$z};text-shadow:0 0 12px color-mix(in srgb,{$z} 60%,transparent)}
        .programm{display:flex;justify-content:center;gap:14px;margin-bottom:34px;flex-wrap:wrap}
        .programm span{padding:12px 20px;border-radius:12px;font-size:16px;
          border:1px solid color-mix(in srgb,{$z} 42%,transparent);
          background:color-mix(in srgb,{$z} 8%,transparent)}
        .programm b{color:{$z};margin-inline-end:10px}
        .btn{box-shadow:0 0 26px color-mix(in srgb,{$a} 50%,transparent)}
        .kachel{background:color-mix(in srgb,{$z} 6%,{$fl});
          border-color:color-mix(in srgb,{$z} 26%,transparent)}
        CSS,

        'luxe' => <<<CSS
        .haarlinie{display:block;inline-size:120px;block-size:1px;margin:0 auto 30px;
          background:linear-gradient(90deg,transparent,{$a},transparent)}
        .cta{margin-top:30px}
        h1.riesig{font-size:80px}
        .btn{background:transparent;color:{$a};border:1px solid {$a};border-radius:0;
          letter-spacing:.14em;font-size:14px;padding:17px 40px;text-transform:uppercase}
        .btn--leise{border-color:color-mix(in srgb,{$tx} 22%,transparent);color:{$s['leise']}}
        .kachel{background:{$fl};border-color:color-mix(in srgb,{$a} 22%,transparent);
          border-radius:0;box-shadow:none}
        .kachel i{border-radius:0;block-size:2px;inline-size:52px;
          background:{$a};margin-bottom:26px}
        CSS,

        'schichten' => <<<CSS
        .tiefe{position:absolute;inset:auto 0 0;block-size:520px;overflow:hidden}
        .tiefe__schicht{position:absolute;inset-inline:-6%;border-radius:52% 48% 0 0/34px}
        .tiefe__schicht--3{inset-block-end:170px;block-size:280px;background:{$a};opacity:.14}
        .tiefe__schicht--2{inset-block-end:80px;block-size:260px;background:{$a};opacity:.24}
        .tiefe__schicht--1{inset-block-end:-40px;block-size:240px;background:{$z};opacity:.2}
        .kachel{background:{$fl}}
        CSS,

        'terminal' => <<<CSS
        .raster{position:absolute;inset:0;opacity:.5;
          background-image:linear-gradient(color-mix(in srgb,{$a} 9%,transparent) 1px,transparent 1px),
            linear-gradient(90deg,color-mix(in srgb,{$a} 9%,transparent) 1px,transparent 1px);
          background-size:46px 46px;
          mask-image:radial-gradient(80% 60% at 50% 30%,#000 0%,transparent 78%)}
        .btn{border-radius:6px}
        .btn--leise{border-radius:6px}
        .fenster{border-radius:12px;background:#060b09;overflow:hidden;
          border:1px solid color-mix(in srgb,{$a} 30%,transparent);
          box-shadow:0 34px 60px -30px rgb(0 0 0/.9)}
        .fenster__leiste{display:block;block-size:34px;
          border-block-end:1px solid color-mix(in srgb,{$a} 22%,transparent);
          background:#0b1310}
        .fenster code{display:block;padding:26px 28px 34px;font-size:16px;line-height:2;color:{$s['leise']}}
        .fenster b{color:{$a}}
        .fenster i{color:{$z};font-style:normal}
        .cursor{display:inline-block;inline-size:9px;block-size:18px;
          background:{$a};vertical-align:-3px}
        .kachel{border-radius:10px;background:{$fl}}
        CSS,

        'weite' => <<<CSS
        body{background:linear-gradient(180deg,#cfe8f8 0%,{$bg} 46%)}
        .berge{position:absolute;inset:auto 0 0;block-size:560px;overflow:hidden}
        .berg{position:absolute;inset-block-end:0;inset-inline:-10%;
          clip-path:polygon(0 100%,16% 46%,29% 62%,45% 22%,60% 55%,74% 34%,88% 58%,100% 40%,100% 100%)}
        .berg--3{block-size:330px;background:#a8cfe6;opacity:.55}
        .berg--2{block-size:250px;background:#6fa8cc;opacity:.65;
          clip-path:polygon(0 100%,12% 58%,26% 34%,42% 66%,58% 30%,72% 60%,86% 40%,100% 62%,100% 100%)}
        .berg--1{block-size:170px;background:{$a};opacity:.85;
          clip-path:polygon(0 100%,18% 52%,34% 74%,50% 40%,66% 70%,82% 48%,100% 72%,100% 100%)}
        .tafel{display:flex;justify-content:center;gap:16px;margin-bottom:34px;flex-wrap:wrap}
        .tafel span{display:grid;gap:2px;padding:16px 26px;border-radius:16px;background:{$fl};
          font-size:22px;font-weight:700;
          box-shadow:0 18px 34px -22px rgb(3 105 161/.5)}
        .tafel b{font-size:13px;font-weight:600;color:{$s['leise']};letter-spacing:.08em;
          text-transform:uppercase}
        CSS,

        'verspielt' => <<<CSS
        .schweber{position:absolute;inset:0;overflow:hidden}
        .kugel{position:absolute;border-radius:50%;
          box-shadow:inset -8px -10px 20px rgb(0 0 0/.08),0 20px 34px -18px rgb(190 24 93/.4)}
        .kugel--1{inset-block-start:70px;inset-inline-start:9%;inline-size:110px;block-size:110px;background:{$a};opacity:.75}
        .kugel--2{inset-block-start:250px;inset-inline-start:4%;inline-size:64px;block-size:64px;background:{$z}}
        .kugel--3{inset-block-start:110px;inset-inline-end:11%;inline-size:140px;block-size:140px;background:{$z};opacity:.85}
        .kugel--4{inset-block-start:330px;inset-inline-end:6%;inline-size:80px;block-size:80px;background:{$a};opacity:.55}
        .kachel{border-radius:30px}
        .kachel i{border-radius:50%}
        CSS,

        'industrie' => <<<CSS
        body{background:
          repeating-linear-gradient(135deg,transparent 0 22px,rgb(255 255 255/.014) 22px 44px),{$bg}}
        .diagonale{position:absolute;inset-block:-140px;inset-inline-start:52%;inline-size:44%;
          background:linear-gradient(180deg,{$a},color-mix(in srgb,{$a} 20%,transparent));
          transform:skewX(-13deg);opacity:.16}
        .traeger{position:relative;block-size:400px;perspective:1000px}
        .traeger__balken{position:absolute;border-radius:4px;
          background:linear-gradient(180deg,#57534e,#292524);
          border:1px solid #44403c;box-shadow:0 30px 50px -26px rgb(0 0 0/.9)}
        .traeger__balken--1{inset:60px 60px auto 0;block-size:74px;
          transform:rotateY(-22deg) rotateZ(-8deg)}
        .traeger__balken--2{inset:210px 0 auto 70px;block-size:74px;
          background:linear-gradient(180deg,{$a},#7f1d1d);
          transform:rotateY(-22deg) rotateZ(-8deg)}
        .btn{border-radius:4px}
        .btn--leise{border-radius:4px}
        .kachel{border-radius:4px;background:{$fl}}
        CSS,

        'licht' => <<<CSS
        .kegel{position:absolute;inset-block-start:-200px;inset-inline-start:50%;
          inline-size:900px;block-size:1100px;margin-inline-start:-450px;
          background:radial-gradient(50% 40% at 50% 12%,
            color-mix(in srgb,{$z} 46%,transparent) 0%,transparent 72%);
          clip-path:polygon(44% 0,56% 0,88% 100%,12% 100%);opacity:.75}
        h1.riesig{font-size:82px;font-weight:400;letter-spacing:-.04em}
        .btn{border-radius:0;background:{$tx};color:{$bg}}
        .btn--leise{border-radius:0}
        .kachel{border-radius:0;box-shadow:0 30px 60px -40px rgb(0 0 0/.6)}
        .kachel i{border-radius:0;background:{$tx}}
        CSS,

        'glas' => <<<CSS
        body{background:radial-gradient(120% 90% at 20% 0%,#1c4a52 0%,{$bg} 60%)}
        .wasser{position:absolute;inset:auto 0 0;block-size:420px;
          background:linear-gradient(180deg,transparent,color-mix(in srgb,{$a} 18%,transparent))}
        .scheibe{padding:52px 48px;border-radius:26px;
          background:color-mix(in srgb,#ffffff 9%,transparent);
          border:1px solid color-mix(in srgb,#ffffff 18%,transparent);
          box-shadow:0 40px 80px -40px rgb(0 0 0/.6);
          backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px)}
        .scheibe--klein{display:grid;gap:10px;align-content:center;text-align:center;
          padding:44px 40px}
        .scheibe--klein b{font-size:15px;letter-spacing:.1em;text-transform:uppercase;color:{$a}}
        .scheibe--klein span{color:{$s['leise']}}
        .preis{font-family:Georgia,serif;font-size:38px;color:{$s['text']}}
        .kachel{background:color-mix(in srgb,#ffffff 7%,transparent);
          border-color:color-mix(in srgb,#ffffff 15%,transparent);
          backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
        CSS,

        'speed' => <<<CSS
        body{background:linear-gradient(160deg,#0b1436 0%,{$bg} 58%)}
        .spuren{position:absolute;inset:0;overflow:hidden;opacity:.5}
        .spuren span{position:absolute;block-size:2px;
          background:linear-gradient(90deg,transparent,{$a},transparent);
          transform:skewY(-8deg)}
        .spuren span:nth-child(1){inset-block-start:130px;inset-inline:-10% 30%}
        .spuren span:nth-child(2){inset-block-start:250px;inset-inline:20% -10%;
          background:linear-gradient(90deg,transparent,{$z},transparent)}
        .spuren span:nth-child(3){inset-block-start:380px;inset-inline:-5% 45%;opacity:.6}
        .spuren span:nth-child(4){inset-block-start:470px;inset-inline:35% -10%;
          background:linear-gradient(90deg,transparent,{$z},transparent);opacity:.5}
        .werte{display:grid;gap:18px}
        .werte span{display:flex;align-items:baseline;gap:16px;padding:22px 30px;
          border-radius:14px;background:{$fl};font-size:17px;color:{$s['leise']};
          border-inline-start:4px solid {$a};transform:skewX(-6deg)}
        .werte b{font-size:40px;color:{$s['text']};font-family:{$s['schrift']}}
        .btn{border-radius:8px;transform:skewX(-6deg)}
        .btn--leise{border-radius:8px;transform:skewX(-6deg)}
        CSS,

        'papier' => <<<CSS
        body{background:
          radial-gradient(60% 40% at 20% 10%,rgb(255 255 255/.7),transparent 60%),
          repeating-linear-gradient(0deg,transparent 0 2px,rgb(140 120 95/.028) 2px 3px),
          {$bg}}
        h1.riesig{text-shadow:0 1px 0 rgb(255 255 255/.9),0 -1px 0 rgb(0 0 0/.08)}
        .bogen{position:relative;block-size:420px;perspective:1200px}
        .bogen__blatt{position:absolute;inset-inline:60px;block-size:300px;border-radius:3px;
          border:1px solid rgb(120 100 78/.42);
          box-shadow:0 22px 44px -22px rgb(60 45 30/.55)}
        .bogen__blatt--3{inset-block-start:14px;transform:rotate(-7deg) translateZ(-40px);
          background:color-mix(in srgb,{$z} 26%,{$fl})}
        .bogen__blatt--2{inset-block-start:48px;transform:rotate(-2.5deg) translateZ(-20px);
          background:color-mix(in srgb,{$z} 12%,{$fl})}
        .bogen__blatt--1{inset-block-start:84px;transform:rotate(2.5deg);
          background:{$fl};
          border-inline-start:5px solid {$a}}
        .btn{border-radius:3px}
        .btn--leise{border-radius:3px}
        .kachel{border-radius:3px}
        CSS,

        'partikel' => <<<CSS
        body{background:radial-gradient(100% 70% at 50% 0%,#181640 0%,{$bg} 62%)}
        .punkte{position:absolute;inset:0;
          background-image:radial-gradient({$a} 1.4px,transparent 1.5px),
            radial-gradient({$z} 1.1px,transparent 1.2px);
          background-size:52px 52px,74px 74px;
          background-position:0 0,26px 37px;
          mask-image:radial-gradient(70% 55% at 50% 34%,#000 0%,transparent 76%);
          opacity:.55}
        .kachel{background:color-mix(in srgb,{$a} 7%,{$fl});
          border-color:color-mix(in srgb,{$a} 22%,transparent)}
        CSS,

        default => <<<CSS
        .maserung{position:absolute;inset:0;opacity:.5;
          background:repeating-linear-gradient(97deg,
            transparent 0 12px,color-mix(in srgb,{$a} 5%,transparent) 12px 15px,
            transparent 15px 34px,color-mix(in srgb,{$a} 3%,transparent) 34px 36px);
          mask-image:linear-gradient(180deg,#000,transparent 82%)}
        .platten{position:relative;block-size:400px;perspective:1200px}
        .platte{position:absolute;border-radius:10px;
          box-shadow:0 30px 56px -30px rgb(70 45 20/.55)}
        .platte--1{inset:20px 90px 120px 0;
          background:linear-gradient(135deg,{$a},#8a5a2b);
          transform:rotateY(16deg) rotateX(6deg)}
        .platte--2{inset:150px 0 20px 120px;
          background:linear-gradient(135deg,{$z},#5f8468);
          transform:rotateY(16deg) rotateX(6deg)}
        CSS,
    };
}

/** Die ganze Seite. */
function render_studie(array $s): string
{
    $f = studie_schrift($s['schrift']);

    $basis = studie_basis_css($s, $f);
    $stil = studie_stil_css($s);
    $hero = studie_hero($s);

    $name = studie_e($s['name']);
    $jahr = date('Y');

    $kacheln = '';
    foreach ($s['punkte'] as $punkt) {
        $kacheln .= '<div class="kachel"><i></i><b>' . studie_e($punkt) . '</b>'
            . '<span>Kurz erklärt, was dahintersteckt – und was es kostet.</span></div>';
    }

    $menue = '';
    foreach ($s['punkte'] as $punkt) {
        $menue .= '<a href="#">' . studie_e($punkt) . '</a>';
    }

    return <<<HTML
    <!doctype html>
    <html lang="de">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{$name}</title>
    <style>
    {$basis}
    {$stil}
    @media (prefers-reduced-motion: reduce){*{animation:none!important;transition:none!important}}
    </style>
    </head>
    <body>

    <header class="kopf wrap">
        <span class="marke"><i></i>{$name}</span>
        <nav class="menue">{$menue}<a href="#">Kontakt</a></nav>
    </header>

    <main>
        {$hero}

        <section class="band">
            <div class="wrap">
                <div class="kacheln">{$kacheln}</div>
            </div>
        </section>
    </main>

    <footer class="fuss">
        <div class="wrap">
            <span>&copy; {$jahr} {$name}</span>
            <span>Impressum &middot; Datenschutz</span>
        </div>
    </footer>

    </body>
    </html>
    HTML;
}
