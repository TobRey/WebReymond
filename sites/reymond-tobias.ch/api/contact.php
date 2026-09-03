<?php
/**
 * Kontaktformular.
 *
 * Schutz gegen Werbemuell ohne Captcha:
 *   1. Honigtopf   – ein fuer Menschen unsichtbares Feld muss leer bleiben.
 *   2. Zeitfalle   – zwischen Aufruf und Absenden muessen ein paar Sekunden liegen.
 *   3. Begrenzung  – je E-Mail-Adresse hoechstens drei Nachrichten in 24 Stunden.
 *
 * Jede Eingabe wird geprueft; bei der Ausgabe wird konsequent escaped.
 */

require __DIR__ . '/../lib/store.php';

const RT_MAIL_TO       = 'tobias.reymond05@gmail.com';
const RT_MAIL_DOMAIN   = 'reymond-tobias.ch';
const RT_MIN_SECONDS   = 3;        // schneller schafft es kein Mensch
const RT_MAX_SECONDS   = 21600;    // 6 Stunden, danach ist das Formular veraltet
const RT_MAX_PER_MAIL  = 3;        // je Adresse in 24 Stunden
const RT_MAX_PER_HOUR  = 20;       // insgesamt, als Notbremse

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$lang = (isset($_POST['lang']) && $_POST['lang'] === 'en') ? 'en' : 'de';

$T = array(
    'de' => array(
        'ok'        => 'Danke, deine Nachricht ist unterwegs. Ich melde mich zurück.',
        'method'    => 'Ungültiger Aufruf.',
        'name'      => 'Bitte trag deinen Namen ein.',
        'email'     => 'Diese E-Mail-Adresse sieht nicht richtig aus.',
        'message'   => 'Bitte schreib noch ein paar Worte in die Nachricht.',
        'consent'   => 'Ohne dein Einverständnis kann ich die Anfrage nicht bearbeiten.',
        'tooFast'   => 'Das ging zu schnell. Bitte sende das Formular noch einmal ab.',
        'tooOld'    => 'Das Formular war zu lange offen. Bitte lade die Seite neu.',
        'limit'     => 'Von dieser Adresse sind bereits mehrere Nachrichten eingegangen. Bitte versuch es morgen wieder oder schreib direkt eine E-Mail.',
        'busy'      => 'Gerade sind sehr viele Nachrichten unterwegs. Bitte versuch es später noch einmal.',
        'failed'    => 'Die Nachricht liess sich nicht versenden. Bitte schreib direkt an ' . RT_MAIL_TO . '.',
        'backTitle' => 'Nachricht gesendet',
        'back'      => 'Zurück zur Website',
        'home'      => '/',
        'errTitle'  => 'Das hat nicht geklappt',
    ),
    'en' => array(
        'ok'        => 'Thank you, your message is on its way. I will get back to you.',
        'method'    => 'Invalid request.',
        'name'      => 'Please enter your name.',
        'email'     => 'This email address does not look right.',
        'message'   => 'Please add a few words to your message.',
        'consent'   => 'Without your agreement I cannot process the enquiry.',
        'tooFast'   => 'That was too quick. Please submit the form once more.',
        'tooOld'    => 'The form was open for too long. Please reload the page.',
        'limit'     => 'Several messages have already arrived from this address. Please try again tomorrow or send an email directly.',
        'busy'      => 'A lot of messages are coming in right now. Please try again later.',
        'failed'    => 'The message could not be sent. Please write directly to ' . RT_MAIL_TO . '.',
        'backTitle' => 'Message sent',
        'back'      => 'Back to the website',
        'home'      => '/en/',
        'errTitle'  => 'That did not work',
    ),
);
$t = $T[$lang];

/** Antwort ausgeben – als JSON fuer das Skript, sonst als kleine Seite. */
function rt_reply($ok, $message, $t, $lang, $name = '')
{
    $wantsJson = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'fetch')
        || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        if (!$ok) { http_response_code(422); }
        echo json_encode(array('ok' => $ok, 'message' => $message), JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    if (!$ok) { http_response_code(422); }

    $title = $ok ? $t['backTitle'] : $t['errTitle'];
    $greet = ($ok && $name !== '') ? rt_h($name) . ' – ' : '';
    $htmlLang = ($lang === 'en') ? 'en' : 'de-CH';
    ?><!DOCTYPE html>
<html lang="<?php echo $htmlLang; ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo rt_h($title); ?> — Reymond Tobias</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<main class="doc-page">
  <div class="wrap wrap--narrow" style="min-height:60svh;display:grid;align-content:center;padding-bottom:5rem">
    <p class="eyebrow"><?php echo rt_h($lang === 'en' ? 'Contact' : 'Kontakt'); ?></p>
    <h1><?php echo rt_h($title); ?></h1>
    <div class="notice notice--<?php echo $ok ? 'ok' : 'bad'; ?>" style="margin-top:1.5rem;max-width:60ch">
      <?php echo $greet . rt_h($message); ?>
    </div>
    <p style="margin-top:2rem">
      <a class="btn btn--primary" href="<?php echo rt_h($t['home']); ?>#<?php echo $lang === 'en' ? 'contact' : 'kontakt'; ?>"><?php echo rt_h($t['back']); ?></a>
    </p>
  </div>
</main>
</body>
</html><?php
    exit;
}

/* ------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    rt_reply(false, $t['method'], $t, $lang);
}

/* 1. Honigtopf ----------------------------------------------------- */
$honey = isset($_POST['website']) ? trim((string) $_POST['website']) : '';
if ($honey !== '') {
    // Freundlich antworten, aber nichts versenden.
    rt_reply(true, $t['ok'], $t, $lang);
}

/* 2. Zeitfalle ----------------------------------------------------- */
$started = isset($_POST['started']) ? (int) $_POST['started'] : 0;
if ($started > 0) {
    $elapsed = time() - $started;
    if ($elapsed >= 0 && $elapsed < RT_MIN_SECONDS) {
        rt_reply(false, $t['tooFast'], $t, $lang);
    }
    if ($elapsed > RT_MAX_SECONDS) {
        rt_reply(false, $t['tooOld'], $t, $lang);
    }
}

/* 3. Eingaben pruefen ---------------------------------------------- */
$name    = rt_clean($_POST['name']    ?? '', 80);
$email   = rt_clean($_POST['email']   ?? '', 120);
$subject = rt_clean($_POST['betreff'] ?? '', 120);
$message = rt_clean($_POST['nachricht'] ?? '', 4000, true);
$consent = isset($_POST['einverstanden']) && $_POST['einverstanden'] === '1';

if ($name === '' || rt_len($name) < 2) { rt_reply(false, $t['name'], $t, $lang); }

$email = filter_var($email, FILTER_VALIDATE_EMAIL);
if ($email === false || strlen($email) > 120 || preg_match('/[\r\n]/', $email)) {
    rt_reply(false, $t['email'], $t, $lang);
}
if (rt_len($message) < 10) { rt_reply(false, $t['message'], $t, $lang); }
if (!$consent) { rt_reply(false, $t['consent'], $t, $lang); }

/* 4. Begrenzung je Adresse ----------------------------------------- */
$secretFile = RT_PRIVATE . '/secret.php';
$secret = rt_read($secretFile, array());
if (empty($secret['key'])) {
    $secret = array('key' => rt_token(32), 'created' => date('c'));
    rt_write($secretFile, $secret);
}
$fingerprint = hash_hmac('sha256', rt_lower($email), $secret['key']);

$blocked = false;
$busy    = false;
$now     = time();

rt_update_locked(RT_PRIVATE . '/formlimit.php', function ($data) use ($fingerprint, $now, &$blocked, &$busy) {
    if (!isset($data['mails']) || !is_array($data['mails'])) { $data['mails'] = array(); }
    if (!isset($data['hours']) || !is_array($data['hours'])) { $data['hours'] = array(); }

    // Abgelaufene Eintraege entfernen (24 Stunden).
    foreach ($data['mails'] as $key => $entry) {
        if (!is_array($entry) || ($now - (int) ($entry['first'] ?? 0)) > 86400) {
            unset($data['mails'][$key]);
        }
    }
    foreach ($data['hours'] as $key => $count) {
        if ((int) $key < ($now - 7200)) { unset($data['hours'][$key]); }
    }

    $slot = (string) (intdiv($now, 3600) * 3600);
    $perHour = (int) ($data['hours'][$slot] ?? 0);
    if ($perHour >= RT_MAX_PER_HOUR) { $busy = true; return $data; }

    $entry = $data['mails'][$fingerprint] ?? array('count' => 0, 'first' => $now);
    if ((int) $entry['count'] >= RT_MAX_PER_MAIL) { $blocked = true; return $data; }

    $entry['count'] = (int) $entry['count'] + 1;
    $entry['first'] = (int) ($entry['first'] ?: $now);
    $data['mails'][$fingerprint] = $entry;
    $data['hours'][$slot] = $perHour + 1;

    return $data;
});

if ($busy)    { rt_reply(false, $t['busy'], $t, $lang); }
if ($blocked) { rt_reply(false, $t['limit'], $t, $lang); }

/* 5. Nachricht zusammenstellen und versenden ----------------------- */
$subjectLine = ($subject !== '')
    ? $subject
    : ($lang === 'en' ? 'Enquiry via reymond-tobias.ch' : 'Anfrage über reymond-tobias.ch');

$mailSubject = 'Website: ' . $subjectLine;
if (function_exists('mb_encode_mimeheader')) {
    $encodedSubject = mb_encode_mimeheader($mailSubject, 'UTF-8', 'B', "\r\n");
} else {
    $encodedSubject = '=?UTF-8?B?' . base64_encode($mailSubject) . '?=';
}

$body  = ($lang === 'en' ? "New enquiry from reymond-tobias.ch\n" : "Neue Anfrage über reymond-tobias.ch\n");
$body .= str_repeat('-', 48) . "\n\n";
$body .= ($lang === 'en' ? 'Name:    ' : 'Name:    ') . $name . "\n";
$body .= 'E-Mail:  ' . $email . "\n";
$body .= ($lang === 'en' ? 'Subject: ' : 'Betreff: ') . ($subject !== '' ? $subject : '—') . "\n";
$body .= ($lang === 'en' ? 'Language: ' : 'Sprache: ') . $lang . "\n";
$body .= ($lang === 'en' ? 'Received: ' : 'Eingang: ') . date('d.m.Y H:i') . "\n\n";
$body .= str_repeat('-', 48) . "\n\n";
$body .= $message . "\n";

$envelope = 'website@' . RT_MAIL_DOMAIN;
$headers  = array(
    'From: Website reymond-tobias.ch <' . $envelope . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'Auto-Submitted: auto-generated',
);

$sent = @mail(
    RT_MAIL_TO,
    $encodedSubject,
    $body,
    implode("\r\n", $headers),
    '-f' . $envelope
);

if (!$sent) {
    /* Damit nichts verloren geht, wird die Anfrage zusaetzlich abgelegt. */
    rt_update_locked(RT_PRIVATE . '/contact-failed.php', function ($data) use ($name, $email, $subject, $message) {
        $data[] = array(
            'at'      => date('c'),
            'name'    => $name,
            'email'   => $email,
            'subject' => $subject,
            'message' => $message,
        );
        if (count($data) > 200) { $data = array_slice($data, -200); }
        return $data;
    });
    rt_reply(false, $t['failed'], $t, $lang);
}

rt_reply(true, $t['ok'], $t, $lang, $name);
