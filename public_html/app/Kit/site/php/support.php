<?php

/**
 * Der Support-Bereich – auf der Website des Kunden.
 *
 * Eine Frage stellen, später die Antwort lesen. Kein Sofort-Chat:
 * Niemand sitzt auf der anderen Seite und wartet, und niemand soll das
 * glauben. Was hier steht, sagt das auch.
 *
 * Die Frage geht über ein Kennwort, das nur für diese eine Website gilt,
 * an WebAtze. Auf diesem Server bleibt nur die Kennung des Fadens – in
 * einem Cookie, damit der Kunde seine Antwort wiederfindet.
 */

declare(strict_types=1);

$config = require __DIR__ . '/data/config.php';

$endpoint = rtrim((string) ($config['assistant_url'] ?? ''), '/');
$token = (string) ($config['assistant_token'] ?? '');
$brand = (string) ($config['brand'] ?? 'Website');

const FADEN_COOKIE = 'wa_support';

// ---------------------------------------------------------------- Hilfen

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Eine Anfrage an WebAtze. */
function frage_an_webatze(string $endpoint, string $token, string $pfad, array $daten): array
{
    if ($endpoint === '' || $token === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'Der Support ist für diese Website nicht eingerichtet.'];
    }

    $ch = curl_init($endpoint . $pfad);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($daten, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-WebAtze-Token: ' . $token,
        ],
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'Der Support ist gerade nicht erreichbar. '
            . 'Bitte später noch einmal versuchen.'];
    }

    $data = json_decode((string) $raw, true);

    return is_array($data) ? $data : ['ok' => false, 'error' => 'Unerwartete Antwort.'];
}

// ---------------------------------------------------------------- Ablauf

$faden = (int) ($_COOKIE[FADEN_COOKIE] ?? 0);
$meldung = '';
$fehler = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Unsichtbares Feld gegen Automaten – wie beim Kontaktformular.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $meldung = 'Danke, die Frage ist angekommen.';
    } else {
        $antwort = frage_an_webatze($endpoint, $token, '/assistant/v1/support', [
            'thread' => $faden,
            'message' => (string) ($_POST['message'] ?? ''),
            'subject' => (string) ($_POST['subject'] ?? ''),
            'name' => (string) ($_POST['name'] ?? ''),
            'email' => (string) ($_POST['email'] ?? ''),
        ]);

        if (!empty($antwort['ok'])) {
            $faden = (int) ($antwort['thread'] ?? $faden);

            // Ein halbes Jahr – so lange findet der Kunde seinen Faden
            // wieder, ohne sich irgendwo anmelden zu müssen.
            setcookie(FADEN_COOKIE, (string) $faden, [
                'expires' => time() + 180 * 86400,
                'path' => '/',
                'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            $meldung = (string) ($antwort['message'] ?? 'Die Frage ist angekommen.');
        } else {
            $fehler = (string) ($antwort['error'] ?? 'Das hat nicht geklappt.');
        }
    }
}

// Den bisherigen Verlauf holen
$verlauf = [];
$betreff = '';

if ($faden > 0) {
    $antwort = frage_an_webatze($endpoint, $token, '/assistant/v1/support/faden', ['thread' => $faden]);

    if (!empty($antwort['ok'])) {
        $verlauf = (array) ($antwort['messages'] ?? []);
        $betreff = (string) ($antwort['subject'] ?? '');
    } else {
        // Der Faden gibt es nicht mehr – dann fängt der Kunde neu an,
        // statt auf eine Fehlermeldung zu starren.
        $faden = 0;
    }
}

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Support · <?= e($brand) ?></title>
<link rel="stylesheet" href="../assets/css/site.css">
<style>
.sup{max-width:44rem;margin-inline:auto;padding:clamp(2rem,6vw,5rem) 1.25rem 6rem}
.sup h1{margin:0 0 .4rem}
.sup__lede{margin:0 0 2rem;color:var(--c-text-muted)}
.sup__faden{display:flex;flex-direction:column;gap:.9rem;margin-bottom:2rem}
.sup__eintrag{max-width:34rem;padding:1rem 1.2rem;border-radius:1rem;
  border:1px solid var(--c-border);background:var(--c-surface-2)}
.sup__eintrag.is-eigen{margin-inline-start:auto;
  background:color-mix(in srgb,var(--c-primary) 10%,var(--c-surface-2));
  border-color:color-mix(in srgb,var(--c-primary) 30%,transparent)}
.sup__wer{display:flex;gap:.75rem;margin-bottom:.35rem;font-size:.82rem;
  color:var(--c-text-muted);font-weight:600}
.sup__eintrag p{margin:0;white-space:pre-line;overflow-wrap:anywhere}
.sup__hinweis{padding:1rem 1.25rem;border-radius:.6rem;
  border-inline-start:3px solid var(--c-primary);
  background:color-mix(in srgb,var(--c-primary) 8%,transparent);margin-bottom:1.5rem}
.sup__hinweis--fehler{border-color:#c0392b;background:rgb(192 57 43/.08)}
.s-hp{position:absolute;left:-9999px}
</style>
</head>
<body>

<main class="sup">
    <h1>Support</h1>
    <p class="sup__lede">
        Hier kannst du Fragen zu deiner Website stellen. Wir antworten in der Regel
        innerhalb eines Arbeitstages – die Antwort erscheint auf dieser Seite.
    </p>

    <?php if ($meldung !== ''): ?>
        <div class="sup__hinweis"><?= e($meldung) ?></div>
    <?php endif; ?>

    <?php if ($fehler !== ''): ?>
        <div class="sup__hinweis sup__hinweis--fehler"><?= e($fehler) ?></div>
    <?php endif; ?>

    <?php if ($verlauf !== []): ?>
        <h2><?= e($betreff) ?></h2>
        <div class="sup__faden">
            <?php foreach ($verlauf as $eintrag): ?>
                <div class="sup__eintrag<?= !empty($eintrag['own']) ? ' is-eigen' : '' ?>">
                    <span class="sup__wer">
                        <?= e((string) ($eintrag['from'] ?? '')) ?>
                        <time><?= e(date('d.m. H:i', strtotime((string) ($eintrag['at'] ?? 'now')))) ?></time>
                    </span>
                    <p><?= e((string) ($eintrag['body'] ?? '')) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form class="s-contact__form" method="post" action="">
        <div class="s-hp" aria-hidden="true">
            <label for="sup-site">Website</label>
            <input type="text" id="sup-site" name="website" tabindex="-1" autocomplete="off">
        </div>

        <?php if ($faden === 0): ?>
            <div class="s-field">
                <label for="sup-name">Name</label>
                <input type="text" id="sup-name" name="name" maxlength="120" autocomplete="name">
            </div>

            <div class="s-field">
                <label for="sup-email">
                    E-Mail
                    <span style="font-weight:400;color:var(--c-text-muted)">
                        – freiwillig, für eine kurze Meldung, sobald die Antwort da ist
                    </span>
                </label>
                <input type="email" id="sup-email" name="email" maxlength="190" autocomplete="email">
            </div>

            <div class="s-field">
                <label for="sup-subject">Worum geht es?</label>
                <input type="text" id="sup-subject" name="subject" maxlength="150">
            </div>
        <?php endif; ?>

        <div class="s-field">
            <label for="sup-message"><?= $faden > 0 ? 'Noch etwas?' : 'Deine Frage' ?></label>
            <textarea id="sup-message" name="message" rows="5" required maxlength="4000"></textarea>
        </div>

        <button type="submit" class="s-btn s-btn--primary">Abschicken</button>
    </form>

    <p class="sup__lede" style="margin-top:2.5rem">
        <a href="../index.html">Zurück zur Website</a>
    </p>
</main>

</body>
</html>
