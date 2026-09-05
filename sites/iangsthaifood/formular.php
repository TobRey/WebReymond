<?php
/**
 * Nimmt das Kontaktformular entgegen, prüft jede Eingabe und verschickt
 * die Anfrage per E-Mail. Ohne Captcha: Honigtopf, Zeitfalle und eine
 * Begrenzung je Absender und je E-Mail-Adresse.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/seite.php';

header('Cache-Control: no-store, private');

$lang = ($_POST['lang'] ?? $_GET['lang'] ?? 'de') === 'en' ? 'en' : 'de';
$zurueck = $lang === 'en' ? 'en/contact.html' : 'kontakt.html';

$W = $lang === 'en' ? [
    'title'   => 'Message sent — Iang’s Thai Food',
    'eyebrow' => 'Contact form',
    'ok_h'    => 'Thank you — your message has arrived',
    'ok_p'    => 'We will get back to you as soon as the kitchen allows. If it is urgent, please call us.',
    'err_h'   => 'That did not work',
    'err_p'   => 'Please go back and check the marked points.',
    'back'    => 'Back to the contact page',
    'call'    => 'Call us',
    'spam_h'  => 'No answer?',
    'spam_p'  => 'Please check your spam folder — our reply sometimes ends up there.',
    'stored'  => 'Your message has been saved on the server and will be picked up. Nothing is lost.',
    'nomail'  => 'The message could not be sent by email. Please call us instead.',
    'e_name'  => 'Please tell us your name.',
    'e_mail'  => 'The email address does not look right.',
    'e_msg'   => 'Your message needs to be between 10 and 4000 characters.',
    'e_rate'  => 'Quite a few messages have arrived from here in the last hour. Please try again later.',
    'e_meth'  => 'This page only works together with the contact form.',
] : [
    'title'   => 'Nachricht gesendet — Iang’s Thai Food',
    'eyebrow' => 'Kontaktformular',
    'ok_h'    => 'Danke – deine Nachricht ist angekommen',
    'ok_p'    => 'Wir melden uns, sobald die Küche es zulässt. Wenn es pressiert, ruf uns bitte an.',
    'err_h'   => 'Das hat nicht geklappt',
    'err_p'   => 'Geh bitte zurück und schau dir die genannten Punkte kurz an.',
    'back'    => 'Zurück zur Kontaktseite',
    'call'    => 'Anrufen',
    'spam_h'  => 'Keine Antwort erhalten?',
    'spam_p'  => 'Schau bitte im Spam-Ordner nach – manchmal landet unsere Antwort dort.',
    'stored'  => 'Deine Nachricht wurde auf dem Server gespeichert und wird abgeholt. Verloren geht nichts.',
    'nomail'  => 'Die Nachricht konnte nicht per E-Mail verschickt werden. Bitte ruf uns stattdessen an.',
    'e_name'  => 'Bitte sag uns deinen Namen.',
    'e_mail'  => 'Die E-Mail-Adresse sieht nicht richtig aus.',
    'e_msg'   => 'Deine Nachricht muss zwischen 10 und 4000 Zeichen lang sein.',
    'e_rate'  => 'Von hier kamen in der letzten Stunde ziemlich viele Nachrichten. Bitte versuch es später noch einmal.',
    'e_meth'  => 'Diese Seite funktioniert nur zusammen mit dem Kontaktformular.',
];

$fehler = [];
$erfolg = false;
$hinweis = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $fehler[] = $W['e_meth'];
} else {
    $honig   = (string) ($_POST['webseite'] ?? '');
    $zeit    = (string) ($_POST['zeit'] ?? '');
    $name    = saubere_eingabe($_POST['name'] ?? '', 80);
    $email   = saubere_eingabe($_POST['email'] ?? '', 120);
    $telefon = saubere_eingabe($_POST['telefon'] ?? '', 40);
    $text    = saubere_eingabe($_POST['nachricht'] ?? '', 4000);

    // Zeitfalle: wer in unter drei Sekunden abschickt, ist kein Mensch.
    $zuSchnell = false;
    if ($zeit !== '' && ctype_digit($zeit)) {
        $vergangen = time() - (int) round(((int) $zeit) / 1000);
        $zuSchnell = $vergangen < 3 || $vergangen > 86400;
    }

    if ($honig !== '' || $zuSchnell) {
        // Freundlich bestätigen und nichts versenden.
        $erfolg = true;
    } else {
        $kennung = absender_kennung();
        $adresskennung = substr(hash_hmac('sha256', strtolower($email), cfg('support_token')), 0, 32);

        if (zeichen($name) < 2) {
            $fehler[] = $W['e_name'];
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fehler[] = $W['e_mail'];
        }
        if (zeichen($text) < 10 || zeichen($text) > 4000) {
            $fehler[] = $W['e_msg'];
        }
        if ($fehler === []) {
            if (!versuche_erlaubt('anfrage-ip', $kennung, 5, 3600, true, 'anfragen')
                || !versuche_erlaubt('anfrage-adr', $adresskennung, 3, 3600, true, 'anfragen')) {
                $fehler[] = $W['e_rate'];
            }
        }

        if ($fehler === []) {
            $betreff = 'Anfrage über iangsthaifood.com von ' . $name;
            $rumpf = "Neue Nachricht über das Kontaktformular\n"
                . "----------------------------------------\n\n"
                . 'Name:     ' . $name . "\n"
                . 'E-Mail:   ' . $email . "\n"
                . 'Telefon:  ' . ($telefon !== '' ? $telefon : '—') . "\n"
                . 'Sprache:  ' . ($lang === 'en' ? 'Englisch' : 'Deutsch') . "\n"
                . 'Zeit:     ' . date('d.m.Y H:i') . "\n\n"
                . "Nachricht:\n" . $text . "\n";

            $ziel = cfg('contact_email');
            $verschickt = false;

            if ($ziel !== '' && filter_var($ziel, FILTER_VALIDATE_EMAIL)) {
                $verschickt = mail_senden($ziel, $betreff, $rumpf, $email);
            }

            if (!$verschickt) {
                // Keine Empfängeradresse hinterlegt oder Versand gescheitert:
                // die Anfrage wird auf dem Server abgelegt, damit nichts verloren geht.
                $ordner = datenordner('anfragen');
                $datei = $ordner . '/' . date('Y-m-d_His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.txt';
                @file_put_contents($datei, $rumpf, LOCK_EX);
                @chmod($datei, 0660);
                $hinweis = $ziel === '' ? $W['stored'] : $W['nomail'];
            }

            $erfolg = true;
        }
    }
}

seite_kopf([
    'lang' => $lang,
    'titel' => $W['title'],
    'kopf_id' => 111,
    'aktiv' => 'kontakt',
    'noindex' => true,
]);
?>
  <section class="hero hero--slim" id="aufmacher" data-section="hero" data-section-id="112">
    <div class="hero__glow js-parallax" data-parallax="0.08" aria-hidden="true"></div>
    <div class="hero__rule" aria-hidden="true"></div>
    <div class="wrap">
      <div class="hero__inner">
        <p class="eyebrow"><?= h($W['eyebrow']) ?></p>
        <h1><?= h($erfolg ? $W['ok_h'] : $W['err_h']) ?></h1>
        <p class="lead"><?= h($erfolg ? $W['ok_p'] : $W['err_p']) ?></p>
      </div>
    </div>
  </section>

  <section id="ergebnis" data-section="text" data-section-id="113">
    <div class="wrap wrap--narrow">
<?php if ($erfolg): ?>
      <div class="msg msg--ok" role="status">
        <p><?= h($W['ok_p']) ?></p>
      </div>
<?php if ($hinweis !== ''): ?>
      <div class="msg msg--info"><p><?= h($hinweis) ?></p></div>
<?php endif; ?>
      <div class="note">
        <p><strong><?= h($W['spam_h']) ?></strong> <?= h($W['spam_p']) ?></p>
      </div>
<?php else: ?>
      <div class="msg msg--err" role="alert">
        <ul>
<?php foreach ($fehler as $f): ?>
          <li><?= h($f) ?></li>
<?php endforeach; ?>
        </ul>
      </div>
<?php endif; ?>
      <div class="btn-row">
        <a class="btn btn--primary" href="<?= h($zurueck) ?>"><?= h($W['back']) ?></a>
        <a class="btn btn--ghost" href="tel:+41764427044"><?= h($W['call']) ?> 076 442 70 44</a>
      </div>
    </div>
  </section>
<?php
seite_fuss(['lang' => $lang, 'fuss_id' => 114]);
