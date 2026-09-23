<?php
/* Faden & Form Atelier – Anfrageformular.
   Prüft die Eingaben, verschickt die Anfrage an das Atelier und leitet zurück
   auf die Kontaktseite (?anfrage=ok bzw. ?anfrage=fehler). */
declare(strict_types=1);

const EMPFAENGER = 'info@faden-form.ch';
const ABSENDER   = 'website@faden-form.ch';
const ZIEL       = '/kontakt/';

function zurueck(string $status): never {
    header('Location: ' . ZIEL . '?anfrage=' . $status . '#anfrage', true, 303);
    exit;
}

function feld(string $name, int $max): string {
    $wert = isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
    $wert = str_replace("\0", '', $wert);
    return mb_substr($wert, 0, $max);
}

function zeile(string $wert): string {
    return preg_replace('/[\r\n]+/', ' ', $wert) ?? '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    zurueck('fehler');
}

// Spamschutz: verstecktes Feld muss leer bleiben
if (feld('website', 200) !== '') {
    zurueck('ok');
}

$thema     = zeile(feld('thema', 80));
$name      = zeile(feld('name', 120));
$email     = zeile(feld('email', 160));
$telefon   = zeile(feld('telefon', 40));
$personen  = zeile(feld('personen', 2));
$termin    = zeile(feld('wunschtermin', 120));
$nachricht = feld('nachricht', 4000);
$ok_ds     = feld('datenschutz', 3) === 'ja';

$themen = ['Nähkurs für Einsteiger', 'Workshop Ändern & Flicken', 'Reparatur', 'Gutschein', 'Etwas anderes'];
if (!in_array($thema, $themen, true)
    || $name === '' || $nachricht === '' || !$ok_ds
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || ($personen !== '' && !ctype_digit($personen))) {
    zurueck('fehler');
}

$text = "Neue Anfrage über faden-form.ch\n\n"
      . "Thema:        {$thema}\n"
      . "Name:         {$name}\n"
      . "E-Mail:       {$email}\n"
      . "Telefon:      " . ($telefon !== '' ? $telefon : '–') . "\n"
      . "Personen:     " . ($personen !== '' ? $personen : '1') . "\n"
      . "Wunschtermin: " . ($termin !== '' ? $termin : '–') . "\n\n"
      . "Nachricht:\n{$nachricht}\n\n"
      . "Datenschutzerklärung akzeptiert: ja\n";

$betreff = '=?UTF-8?B?' . base64_encode('Anfrage: ' . $thema . ' – ' . $name) . '?=';
$kopf = [
    'From: Faden & Form Website <' . ABSENDER . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];

$gesendet = @mail(EMPFAENGER, $betreff, $text, implode("\r\n", $kopf));
zurueck($gesendet ? 'ok' : 'fehler');
