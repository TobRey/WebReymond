<?php

/**
 * Der Empfänger des Kontaktformulars – auf dem Server des Kunden.
 *
 * Bewusst eine einzelne Datei ohne Rahmenwerk und ohne Datenbank: Sie
 * läuft auf jedem Hosting, das PHP kennt, und lässt sich in zehn Jahren
 * noch lesen.
 *
 * Was sie kann:
 *   - prüft die Eingaben und weist Unfug ab
 *   - erkennt Werbemüll an drei Zeichen: einem unsichtbaren Feld, einer
 *     unmenschlich kurzen Ausfülldauer und zu vielen Zusendungen aus
 *     derselben Leitung
 *   - schickt eine E-Mail und legt die Anfrage zusätzlich als Datei ab,
 *     damit nichts verloren geht, wenn der Mailversand klemmt
 *   - antwortet als JSON (das Formular schickt ohne Neuladen) und
 *     kommt auch ohne JavaScript zurecht
 */

declare(strict_types=1);

$config = require __DIR__ . '/data/config.php';

$dataDir = __DIR__ . '/data';

// Die Ablage trägt bewusst die Endung .php und beginnt mit einem
// Abbruch. Ruft jemand sie direkt auf, führt der Server sie aus und
// gibt nichts zurück – statt die Anfragen aller Besucher auszuliefern.
// Auf .htaccess allein ist kein Verlass: Sie greift bei nginx gar
// nicht und bei falsch eingerichtetem Apache auch nicht.
const GUARD = "<?php exit; ?>\n";

$leadsFile = $dataDir . '/leads.php';

const MAX_PER_HOUR = 5;
const MIN_SECONDS = 3;

// ---------------------------------------------------------------- Hilfen

function field(string $name, int $max = 200): string
{
    $value = (string) ($_POST[$name] ?? '');
    $value = str_replace(["\r", "\0"], '', $value);
    return mb_substr(trim($value), 0, $max);
}

function clientIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * Antwort geben – als JSON, wenn das Formular sie erwartet, sonst als
 * gewöhnliche Seite. So funktioniert das Formular auch dann, wenn im
 * Browser kein JavaScript läuft.
 */
function respond(bool $ok, string $message, int $status = 200): never
{
    $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains((string) ($_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''), 'cors');

    http_response_code($status);
    header('Referrer-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . ($ok ? 'Danke' : 'Es hat nicht geklappt') . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;margin:0;display:grid;place-items:center;'
        . 'min-height:100dvh;padding:2rem;text-align:center;line-height:1.6}'
        . 'a{color:inherit}</style></head><body><div><p>' . $safe . '</p>'
        . '<p><a href="index.html">Zurück zur Website</a></p></div></body></html>';
    exit;
}

/** Datei sicher schreiben: erst daneben, dann umbenennen. */
function writeAtomic(string $path, string $content): bool
{
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        return false;
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0640);
    return true;
}

/** Eine geschützte Ablage lesen – der Abbruch am Anfang wird übersprungen. */
function readGuarded(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = (string) file_get_contents($path);
    $end = strpos($raw, "?>");
    if ($end !== false) {
        $raw = substr($raw, $end + 2);
    }

    $data = json_decode(trim($raw), true);
    return is_array($data) ? $data : [];
}

/** Eine geschützte Ablage schreiben. */
function writeGuarded(string $path, array $data): bool
{
    return writeAtomic($path, GUARD . json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
}

// ---------------------------------------------------------------- Prüfen

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Diese Adresse nimmt nur abgeschickte Formulare an.', 405);
}

// 1. Das unsichtbare Feld. Menschen sehen es nicht und füllen es nie aus.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    // Automaten bekommen dieselbe freundliche Antwort wie alle anderen –
    // sonst lernen sie, woran sie erkannt wurden.
    respond(true, 'Vielen Dank! Die Nachricht ist angekommen.');
}

// 2. Die Ausfülldauer. Wer in unter drei Sekunden fertig ist, tippt nicht.
$started = (int) ($_POST['_t'] ?? 0);
if ($started > 0 && time() - $started < MIN_SECONDS) {
    respond(true, 'Vielen Dank! Die Nachricht ist angekommen.');
}

// 3. Wie viele Zusendungen kamen zuletzt aus dieser Leitung?
$ip = clientIp();
$throttleFile = $dataDir . '/throttle.php';
$throttle = readGuarded($throttleFile);

$now = time();
$key = hash('sha256', $ip);
$recent = array_values(array_filter(
    (array) ($throttle[$key] ?? []),
    static fn ($t): bool => (int) $t > $now - 3600
));

if (count($recent) >= MAX_PER_HOUR) {
    respond(false, 'Es sind gerade viele Nachrichten eingegangen. '
        . 'Bitte in einer Stunde erneut versuchen.', 429);
}

$name = field('name', 120);
$email = field('email', 190);
$phone = field('phone', 60);
$subject = field('subject', 190);
$message = mb_substr(trim(str_replace("\0", '', (string) ($_POST['message'] ?? ''))), 0, 5000);

if ($name === '' || $message === '') {
    respond(false, 'Bitte Namen und Nachricht ausfüllen.', 422);
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Bitte eine gültige E-Mail-Adresse angeben.', 422);
}

// ---------------------------------------------------------------- Ablegen

// Erst speichern, dann verschicken. Klemmt der Mailversand beim Anbieter,
// steht die Anfrage trotzdem im Bearbeitungsbereich – verloren geht nichts.
$entry = [
    'id' => bin2hex(random_bytes(8)),
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'subject' => $subject,
    'message' => $message,
    'ip' => $ip,
    'created_at' => date('c'),
    'read' => false,
    'mail_sent' => false,
];

$leads = readGuarded($leadsFile);

// ---------------------------------------------------------------- Senden

$to = (string) ($config['contact_email'] ?? '');
$brand = (string) ($config['brand'] ?? 'Website');

if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
    $body = "Neue Anfrage über die Website\n"
        . str_repeat('-', 40) . "\n\n"
        . 'Name:     ' . $name . "\n"
        . 'E-Mail:   ' . $email . "\n"
        . ($phone !== '' ? 'Telefon:  ' . $phone . "\n" : '')
        . ($subject !== '' ? 'Betreff:  ' . $subject . "\n" : '')
        . "\n" . $message . "\n";

    // Der Absender ist die eigene Domain – fremde Adressen im Absender
    // lassen viele Anbieter als Fälschung abweisen. Die Adresse des
    // Absenders steht in "Reply-To", damit ein Klick auf Antworten
    // trotzdem an die richtige Stelle geht.
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $from = 'website@' . preg_replace('/^www\./', '', $host);

    $headers = [
        'From' => sprintf('%s <%s>', mb_encode_mimeheader($brand, 'UTF-8'), $from),
        'Reply-To' => $email,
        'Content-Type' => 'text/plain; charset=utf-8',
        'MIME-Version' => '1.0',
        'X-Mailer' => 'PHP',
    ];

    $entry['mail_sent'] = @mail(
        $to,
        mb_encode_mimeheader('Anfrage über die Website: ' . ($subject !== '' ? $subject : $name), 'UTF-8'),
        $body,
        $headers
    );
}

array_unshift($leads, $entry);
$leads = array_slice($leads, 0, 500);

writeGuarded($leadsFile, $leads);

$recent[] = $now;
$throttle[$key] = $recent;

// Alte Einträge wegräumen, damit die Datei nicht wächst.
foreach ($throttle as $k => $times) {
    $times = array_values(array_filter((array) $times, static fn ($t): bool => (int) $t > $now - 3600));
    if ($times === []) {
        unset($throttle[$k]);
    } else {
        $throttle[$k] = $times;
    }
}

writeGuarded($throttleFile, $throttle);

respond(true, 'Vielen Dank! Die Nachricht ist angekommen – wir melden uns bald.');
