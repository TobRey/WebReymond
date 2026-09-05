<?php
/**
 * Support: der direkte Draht zur Betreuung der Website.
 *
 * Diese Seite ist nur für den Betrieb, nicht für Besucher und nicht für
 * Automaten. Deshalb: Zugangscode, Ticket-Cookie, Begrenzung der Versuche,
 * Honigtopf, Zeitfalle und keine Auffindbarkeit über Suchmaschinen.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/seite.php';
require_once __DIR__ . '/inc/zugang.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, private');

const FADEN_COOKIE = 'iang_faden';

/* Faden-Nummer wird ebenfalls signiert, damit niemand fremde Fäden aufruft. */

function faden_cookie(int $faden): string
{
    return $faden . '.' . hash_hmac('sha256', 'faden|' . $faden, cfg('support_token'));
}

function faden_aus_cookie(): int
{
    $wert = (string) ($_COOKIE[FADEN_COOKIE] ?? '');
    $teile = explode('.', $wert, 2);
    if (count($teile) !== 2 || !ctype_digit($teile[0])) {
        return 0;
    }
    if (!hash_equals(hash_hmac('sha256', 'faden|' . (int) $teile[0], cfg('support_token')), $teile[1])) {
        return 0;
    }
    return (int) $teile[0];
}

/* Örtlicher Zwischenspeicher des Verlaufs, falls WebAtze nicht antwortet. */

function verlauf_datei(int $faden): string
{
    return datenordner('support') . '/faden-' . $faden . '.json';
}

function verlauf_lesen(int $faden): array
{
    $datei = verlauf_datei($faden);
    if (!is_readable($datei)) {
        return [];
    }
    $daten = json_decode((string) file_get_contents($datei), true);
    return is_array($daten) ? $daten : [];
}

function verlauf_schreiben(int $faden, array $daten): void
{
    @file_put_contents(verlauf_datei($faden), json_encode($daten, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(verlauf_datei($faden), 0660);
}

function verlauf_ergaenzen(int $faden, string $betreff, array $nachricht): void
{
    $daten = verlauf_lesen($faden);
    if (!isset($daten['messages']) || !is_array($daten['messages'])) {
        $daten = ['subject' => $betreff, 'messages' => []];
    }
    if ($betreff !== '') {
        $daten['subject'] = $betreff;
    }
    $daten['messages'][] = $nachricht;
    $daten['messages'] = array_slice($daten['messages'], -60);
    verlauf_schreiben($faden, $daten);
}

/* ------------------------------------------------------------------
   Ablauf
   ------------------------------------------------------------------ */

$angemeldet = angemeldet();
$kennung = absender_kennung();
$meldungen = [];        // [art, text]
$formularwerte = ['betreff' => '', 'nachricht' => '', 'name' => '', 'email' => ''];
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

/* --- Zugangscode prüfen --- */
$codeMeldung = code_pruefen('support.php');
if ($codeMeldung !== null) {
    $meldungen[] = $codeMeldung;
}

/* --- Nachricht senden --- */
if ($post && ($_POST['was'] ?? '') === 'nachricht' && $angemeldet) {
    $honig = (string) ($_POST['webseite'] ?? '');
    $zeit = (string) ($_POST['zeit'] ?? '');

    $formularwerte['betreff']   = saubere_eingabe($_POST['betreff'] ?? '', 120);
    $formularwerte['nachricht'] = saubere_eingabe($_POST['nachricht'] ?? '', 4000);
    $formularwerte['name']      = saubere_eingabe($_POST['name'] ?? '', 80);
    $formularwerte['email']     = saubere_eingabe($_POST['email'] ?? '', 120);

    if ($honig !== '' || !zeitmarke_gueltig($zeit)) {
        // Freundlich bestätigen, nichts versenden. Ein Automat soll nicht
        // lernen, woran er gescheitert ist.
        header('Location: support.php?gesendet=1');
        exit;
    }

    $laenge = zeichen($formularwerte['nachricht']);

    if (!versuche_erlaubt('nachricht', $kennung, 5, 3600, false)) {
        $meldungen[] = ['err', 'Du hast in der letzten Stunde schon einige Nachrichten geschickt. '
            . 'Bitte warte etwas, bevor du weiterschreibst.'];
    } elseif ($laenge < 10) {
        $meldungen[] = ['err', 'Deine Nachricht ist zu kurz. Schreib bitte mindestens 10 Zeichen.'];
    } elseif ($laenge > 4000) {
        $meldungen[] = ['err', 'Deine Nachricht ist zu lang. Mehr als 4000 Zeichen gehen leider nicht.'];
    } elseif ($formularwerte['email'] !== '' && !filter_var($formularwerte['email'], FILTER_VALIDATE_EMAIL)) {
        $meldungen[] = ['err', 'Die E-Mail-Adresse sieht nicht richtig aus. Bitte prüf sie kurz.'];
    } else {
        versuche_erlaubt('nachricht', $kennung, 5, 3600);
        $faden = faden_aus_cookie();
        $betreff = $formularwerte['betreff'] !== ''
            ? $formularwerte['betreff']
            : 'Anfrage von iangsthaifood.com';

        [$ok, $antwort, $fehler] = webatze_anfrage('/assistant/v1/support', [
            'thread'  => $faden,
            'message' => $formularwerte['nachricht'],
            'subject' => $betreff,
            'name'    => $formularwerte['name'],
            'email'   => $formularwerte['email'],
        ]);

        if ($ok) {
            $neuerFaden = (int) ($antwort['thread'] ?? $faden);
            if ($neuerFaden > 0) {
                cookie_setzen(FADEN_COOKIE, faden_cookie($neuerFaden), TICKET_TAGE);
            }
            verlauf_ergaenzen($neuerFaden, $betreff, [
                'from' => $formularwerte['name'] !== '' ? $formularwerte['name'] : 'Du',
                'body' => $formularwerte['nachricht'],
                'at'   => date('c'),
                'own'  => true,
            ]);
            header('Location: support.php?gesendet=1');
            exit;
        }

        // Verbindung weg: die Frage darf nicht verloren gehen.
        $text = "Nachricht von der Website iangsthaifood.com\n"
            . "(Die Verbindung zu WebAtze hat nicht geklappt: " . $fehler . ")\n\n"
            . 'Betreff: ' . $betreff . "\n"
            . 'Name: ' . ($formularwerte['name'] !== '' ? $formularwerte['name'] : '—') . "\n"
            . 'E-Mail: ' . ($formularwerte['email'] !== '' ? $formularwerte['email'] : '—') . "\n"
            . 'Faden: ' . ($faden > 0 ? (string) $faden : 'neu') . "\n\n"
            . $formularwerte['nachricht'] . "\n";

        $perMail = mail_senden(
            cfg('support_email', 'support@web-atze.com'),
            'Support (Ausweichweg): ' . $betreff,
            $text,
            $formularwerte['email']
        );

        verlauf_ergaenzen($faden > 0 ? $faden : 0, $betreff, [
            'from' => $formularwerte['name'] !== '' ? $formularwerte['name'] : 'Du',
            'body' => $formularwerte['nachricht'],
            'at'   => date('c'),
            'own'  => true,
        ]);

        $meldungen[] = $perMail
            ? ['info', 'Der Support-Server war gerade nicht erreichbar. Deine Nachricht wurde stattdessen '
                . 'per E-Mail an die Betreuung geschickt – sie ist also nicht verloren.']
            : ['err', 'Der Support-Server war nicht erreichbar, und auch der Versand per E-Mail hat nicht '
                . 'geklappt. Deine Nachricht steht unten im Verlauf. Bitte versuch es später noch einmal.'];
    }
}

if (isset($_GET['gesendet'])) {
    $meldungen[] = ['ok', 'Danke, deine Nachricht ist unterwegs. Eine Antwort erscheint hier im Verlauf, '
        . 'sobald sie da ist.'];
}
if (isset($_GET['willkommen'])) {
    $meldungen[] = ['ok', 'Der Code stimmt. Dein Browser merkt sich den Zugang, du musst ihn nicht jedes Mal '
        . 'neu eingeben.'];
}

/* --- Verlauf holen --- */
$faden = $angemeldet ? faden_aus_cookie() : 0;
$verlauf = [];
$betreffFaden = '';
$verlaufHinweis = '';

if ($angemeldet && $faden > 0) {
    [$ok, $antwort] = webatze_anfrage('/assistant/v1/support/faden', ['thread' => $faden]);
    if ($ok && isset($antwort['messages']) && is_array($antwort['messages'])) {
        $verlauf = $antwort['messages'];
        $betreffFaden = (string) ($antwort['subject'] ?? '');
        verlauf_schreiben($faden, ['subject' => $betreffFaden, 'messages' => $verlauf]);
    } else {
        $zwischen = verlauf_lesen($faden);
        $verlauf = isset($zwischen['messages']) && is_array($zwischen['messages']) ? $zwischen['messages'] : [];
        $betreffFaden = (string) ($zwischen['subject'] ?? '');
        if ($verlauf !== []) {
            $verlaufHinweis = 'Der Support-Server antwortet gerade nicht. Unten steht der Verlauf, '
                . 'wie er zuletzt auf diesem Server gespeichert wurde.';
        }
    }
} elseif ($angemeldet) {
    // Noch kein Faden: Nachrichten, die den Support-Server nicht erreicht haben,
    // liegen unter der Nummer 0 und werden hier trotzdem gezeigt.
    $zwischen = verlauf_lesen(0);
    $verlauf = isset($zwischen['messages']) && is_array($zwischen['messages']) ? $zwischen['messages'] : [];
    $betreffFaden = (string) ($zwischen['subject'] ?? '');
    if ($verlauf !== []) {
        $verlaufHinweis = 'Diese Nachricht ist noch nicht beim Support-Server angekommen. Sie liegt auf '
            . 'deinem eigenen Server – schick sie bitte später noch einmal ab.';
    }
}

seite_kopf([
    'lang' => 'de',
    'titel' => 'Support — Iang’s Thai Food',
    'beschreibung' => 'Geschützter Bereich für den Betrieb: Nachrichten an die Betreuung der Website.',
    'kopf_id' => 101,
    'noindex' => true,
]);
?>
  <section class="hero hero--slim" id="aufmacher" data-section="hero" data-section-id="102">
    <div class="hero__glow js-parallax" data-parallax="0.08" aria-hidden="true"></div>
    <div class="hero__rule" aria-hidden="true"></div>
    <div class="wrap">
      <div class="hero__inner">
        <p class="eyebrow">Nur für den Betrieb</p>
        <h1>Support</h1>
        <p class="lead">Hier schreibst du der Betreuung deiner Website. Das ist kein Sofort-Chat, und eine
          Antwort innerhalb von Minuten ist nicht zugesagt – aber jede Nachricht wird gelesen und beantwortet.</p>
      </div>
    </div>
  </section>

  <section class="band--alt" id="nachricht" data-section="contact" data-section-id="103">
    <div class="wrap wrap--narrow">
<?php foreach ($meldungen as [$art, $text]): ?>
      <div class="msg msg--<?= $art === 'err' ? 'err' : ($art === 'ok' ? 'ok' : 'info') ?>" role="status">
        <p><?= h($text) ?></p>
      </div>
<?php endforeach; ?>

<?php if (!$angemeldet): ?>
      <div class="section-head">
        <p class="eyebrow">Zugang</p>
        <h2>Bitte gib deinen Zugangscode ein</h2>
        <p>Den Code hast du von der Betreuung erhalten. Er steht aus gutem Grund nirgends auf dieser Website.</p>
      </div>
<?= code_formular('support.php') ?>
<?php else: ?>
      <div class="section-head">
        <p class="eyebrow">Neue Nachricht</p>
        <h2><?= $faden > 0 ? 'Schreib weiter' : 'Schreib der Betreuung' ?></h2>
        <p>Beschreib möglichst genau, was du erwartet hast und was stattdessen passiert ist. Wenn es um eine
          bestimmte Seite geht, nenn bitte ihre Adresse.</p>
      </div>
      <form class="form" method="post" action="support.php">
        <input type="hidden" name="was" value="nachricht">
        <input type="hidden" name="zeit" value="<?= h(zeitmarke()) ?>">
        <div class="hp" aria-hidden="true">
          <label for="webseite">Diese Angabe bitte leer lassen</label>
          <input type="text" id="webseite" name="webseite" tabindex="-1" autocomplete="off">
        </div>
<?php if ($faden === 0): ?>
        <div class="field">
          <label for="betreff">Worum geht es?</label>
          <input type="text" id="betreff" name="betreff" maxlength="120" required
                 value="<?= h($formularwerte['betreff']) ?>">
        </div>
<?php endif; ?>
        <div class="field">
          <label for="name">Dein Name</label>
          <input type="text" id="name" name="name" autocomplete="name" maxlength="80"
                 value="<?= h($formularwerte['name']) ?>">
        </div>
        <div class="field">
          <label for="email">Deine E-Mail <span class="muted">(freiwillig)</span></label>
          <input type="email" id="email" name="email" autocomplete="email" maxlength="120"
                 value="<?= h($formularwerte['email']) ?>">
          <span class="hint">Nur nötig, wenn du die Antwort auch per E-Mail möchtest.</span>
        </div>
        <div class="field">
          <label for="nachricht-text">Deine Nachricht</label>
          <textarea id="nachricht-text" name="nachricht" required minlength="10"
                    maxlength="4000"><?= h($formularwerte['nachricht']) ?></textarea>
          <span class="hint">Zwischen 10 und 4000 Zeichen.</span>
        </div>
        <div class="form__actions">
          <button class="btn btn--primary" type="submit">Nachricht senden</button>
          <span class="small muted">Höchstens fünf Nachrichten pro Stunde.</span>
        </div>
      </form>
<?php endif; ?>
    </div>
  </section>

  <section id="verlauf" data-section="text" data-section-id="104">
    <div class="wrap wrap--narrow">
      <div class="section-head">
        <p class="eyebrow">Verlauf</p>
        <h2>Bisherige Nachrichten</h2>
<?php if ($angemeldet && $betreffFaden !== ''): ?>
        <p>Betreff: <strong><?= h($betreffFaden) ?></strong></p>
<?php endif; ?>
      </div>
<?php if ($verlaufHinweis !== ''): ?>
      <div class="msg msg--info"><p><?= h($verlaufHinweis) ?></p></div>
<?php endif; ?>
<?php if (!$angemeldet): ?>
      <p class="muted">Sobald du den Zugangscode eingegeben hast, steht hier der bisherige Verlauf.</p>
<?php elseif ($verlauf === []): ?>
      <p class="muted">Noch keine Nachrichten. Deine erste steht gleich hier.</p>
<?php else: ?>
      <ul class="thread">
<?php foreach ($verlauf as $eintrag): if (!is_array($eintrag)) { continue; } ?>
        <li class="bubble<?= !empty($eintrag['own']) ? ' bubble--own' : '' ?>">
          <p class="bubble__meta"><?= h((string) ($eintrag['from'] ?? 'Unbekannt')) ?>
<?php if (!empty($eintrag['at'])): ?>
            <span aria-hidden="true">·</span> <?= h((string) $eintrag['at']) ?>
<?php endif; ?>
          </p>
          <p class="bubble__body"><?= h((string) ($eintrag['body'] ?? '')) ?></p>
        </li>
<?php endforeach; ?>
      </ul>
<?php endif; ?>
      <div class="note stack-top-lg">
        <p><strong>Kein Sofort-Chat.</strong> Nachrichten werden gelesen und beantwortet, aber nicht
          rund um die Uhr und nicht innerhalb von Minuten. Wenn die Website ganz ausfällt, schreib das
          bitte in die erste Zeile.</p>
      </div>
    </div>
  </section>
<?php
seite_fuss(['lang' => 'de', 'fuss_id' => 105]);
