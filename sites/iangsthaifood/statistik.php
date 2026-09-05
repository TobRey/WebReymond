<?php
/**
 * Die Besucherzahlen dieser Website – geschützt durch denselben
 * Zugangscode wie die Support-Seite.
 *
 * Gezeigt wird nur, welche Seite an welchem Tag wie oft aufgerufen wurde.
 * Mehr steht auch nicht in den Dateien unter data/stats/.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/seite.php';
require_once __DIR__ . '/inc/zugang.php';
require_once __DIR__ . '/inc/zaehlung.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');

$meldungen = [];
$codeMeldung = code_pruefen('statistik.php');
if ($codeMeldung !== null) {
    $meldungen[] = $codeMeldung;
}
if (isset($_GET['willkommen'])) {
    $meldungen[] = ['ok', 'Der Code stimmt. Dein Browser merkt sich den Zugang.'];
}

$offen = angemeldet();
$tage = $offen ? zaehlung_lesen(30) : [];

$proSeite = [];
$proTag = [];
$gesamt30 = 0;
$gesamt7 = 0;
$heute = 0;
$heuteSchluessel = gmdate('Y-m-d');
$grenze7 = gmdate('Y-m-d', time() - 6 * 86400);

foreach ($tage as $tag => $zahlen) {
    $summe = array_sum($zahlen);
    $proTag[$tag] = $summe;
    $gesamt30 += $summe;
    if ($tag >= $grenze7) {
        $gesamt7 += $summe;
    }
    if ($tag === $heuteSchluessel) {
        $heute = $summe;
    }
    foreach ($zahlen as $pfad => $anzahl) {
        $proSeite[$pfad] = ($proSeite[$pfad] ?? 0) + (int) $anzahl;
    }
}
arsort($proSeite);
krsort($proTag);

seite_kopf([
    'lang' => 'de',
    'titel' => 'Besucherzahlen — Iang’s Thai Food',
    'beschreibung' => 'Geschützte Übersicht der Seitenaufrufe.',
    'kopf_id' => 106,
    'noindex' => true,
]);
?>
  <section class="hero hero--slim" id="aufmacher" data-section="hero" data-section-id="107">
    <div class="hero__glow js-parallax" data-parallax="0.08" aria-hidden="true"></div>
    <div class="hero__rule" aria-hidden="true"></div>
    <div class="wrap">
      <div class="hero__inner">
        <p class="eyebrow">Nur für den Betrieb</p>
        <h1>Besucherzahlen</h1>
        <p class="lead">Wie oft welche Seite aufgerufen wurde – ohne IP-Adressen, ohne Cookies und ohne
          Weitergabe an Dritte.</p>
      </div>
    </div>
  </section>

  <section class="band--alt" id="zahlen" data-section="stats" data-section-id="108">
    <div class="wrap">
<?php foreach ($meldungen as [$art, $text]): ?>
      <div class="msg msg--<?= $art === 'err' ? 'err' : 'ok' ?>" role="status"><p><?= h($text) ?></p></div>
<?php endforeach; ?>

<?php if (!$offen): ?>
      <div class="wrap--narrow">
        <div class="section-head">
          <p class="eyebrow">Zugang</p>
          <h2>Bitte gib deinen Zugangscode ein</h2>
          <p>Es ist derselbe Code wie auf der Support-Seite.</p>
        </div>
<?= code_formular('statistik.php') ?>
      </div>
<?php else: ?>
      <div class="section-head">
        <p class="eyebrow">Überblick</p>
        <h2>Die letzten 30 Tage</h2>
      </div>
      <ul class="s-items">
        <li class="card card--flat">
          <h3>Heute</h3>
          <p><strong><?= (int) $heute ?></strong> Seitenaufrufe</p>
        </li>
        <li class="card card--flat">
          <h3>Letzte 7 Tage</h3>
          <p><strong><?= (int) $gesamt7 ?></strong> Seitenaufrufe</p>
        </li>
        <li class="card card--flat">
          <h3>Letzte 30 Tage</h3>
          <p><strong><?= (int) $gesamt30 ?></strong> Seitenaufrufe</p>
        </li>
      </ul>

<?php if ($proSeite === []): ?>
      <p class="muted stack-top-lg">Es liegen noch keine Zahlen vor. Sobald die Website aufgerufen wird,
        erscheinen sie hier.</p>
<?php else: ?>
      <h3 class="stack-top-lg">Je Seite</h3>
      <div class="table-scroll">
        <table class="table">
          <thead><tr><th scope="col">Seite</th><th scope="col">Aufrufe (30 Tage)</th></tr></thead>
          <tbody>
<?php foreach ($proSeite as $pfad => $anzahl): ?>
            <tr><th scope="row"><?= h((string) $pfad) ?></th><td class="num"><?= (int) $anzahl ?></td></tr>
<?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <h3>Je Tag</h3>
      <div class="table-scroll">
        <table class="table">
          <thead><tr><th scope="col">Tag</th><th scope="col">Aufrufe</th></tr></thead>
          <tbody>
<?php foreach ($proTag as $tag => $anzahl): ?>
            <tr><th scope="row"><?= h((string) $tag) ?></th><td class="num"><?= (int) $anzahl ?></td></tr>
<?php endforeach; ?>
          </tbody>
        </table>
      </div>
<?php endif; ?>
<?php endif; ?>
    </div>
  </section>

  <section id="erklaerung" data-section="text" data-section-id="109">
    <div class="wrap wrap--narrow prose">
      <h2>Was hier gezählt wird – und was nicht</h2>
      <p>Für jeden Aufruf wird eine Zahl um eins erhöht: Seite, Tag, fertig. Es werden keine IP-Adressen
        gespeichert, keine Cookies gesetzt und keine Kennungen vergeben. Aus diesen Zahlen lässt sich nicht
        ableiten, wer die Website besucht hat.</p>
      <p>Die Dateien liegen unter <code>data/stats/</code>, eine je Tag. Über den Browser ist der Ordner
        gesperrt.</p>
      <p>Jeden Montag geht eine kurze Zusammenfassung an die Betreuung der Website. Das läuft von allein –
        einzurichten ist dafür nichts.</p>
      <p><a href="doc.html">Zurück zur Anleitung</a></p>
    </div>
  </section>
<?php
seite_fuss(['lang' => 'de', 'fuss_id' => 110]);
