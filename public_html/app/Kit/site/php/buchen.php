<?php

/**
 * Terminbuchung – auf dem Server des Kunden.
 *
 * Eine einzelne Datei, kein Rahmenwerk, keine Datenbank. Sie läuft auf
 * jedem Hosting, das PHP kennt, und sie läuft ohne JavaScript: Wer ein
 * Datum wählt, lädt die Seite neu und sieht die freien Zeiten. Das ist
 * altmodisch und dafür unkaputtbar – ein Buchungsformular, das bei
 * abgeschaltetem Skript nichts tut, verliert Kunden.
 *
 * Der heikle Teil ist nicht die Anzeige, sondern das Speichern: Zwei
 * Leute können dieselbe Zeit im selben Augenblick wählen. Deshalb wird
 * die Datei exklusiv gesperrt, danach noch einmal nachgesehen, ob die
 * Zeit wirklich frei ist, und erst dann geschrieben. Ohne das gäbe es
 * Doppelbuchungen – selten, aber genau dann, wenn es weh tut.
 *
 * Bestätigt wird sofort per E-Mail an beide Seiten. Kommt die Mail
 * nicht an, steht die Buchung trotzdem in der Ablage: Der Kunde sieht
 * sie in seinem Bearbeitungsbereich.
 */

declare(strict_types=1);

require __DIR__ . '/buchen-zeiten.php';

const GUARD = "<?php exit; ?>\n";

/** Höchstens so viele Buchungen aus derselben Leitung pro Stunde. */
const MAX_PER_HOUR = 4;

/** So schnell füllt kein Mensch das Formular aus. */
const MIN_SECONDS = 3;

$dataDir = __DIR__ . '/data';
$config = require $dataDir . '/config.php';
$setup = require $dataDir . '/buchung.php';

$file = $dataDir . '/buchungen.php';

$brand = (string) ($config['brand'] ?? 'Termin');
$slotMinutes = max(5, min(240, (int) ($setup['takt'] ?? 30)));
$leadHours = max(0, min(720, (int) ($setup['vorlauf_stunden'] ?? 24)));
$horizonDays = max(1, min(365, (int) ($setup['horizont_tage'] ?? 60)));
$services = (array) ($setup['leistungen'] ?? []);
$hours = (array) ($setup['zeiten'] ?? []);
$closed = (array) ($setup['geschlossen'] ?? []);

if ($services === []) {
    $services = [['name' => 'Termin', 'dauer' => $slotMinutes]];
}

$errors = [];
$done = false;

// ------------------------------------------------------------- Speichern

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $result = buchen($file, $services, $setup, $slotMinutes, $leadHours, $horizonDays, $hours, $closed);

    $errors = $result['errors'];

    if ($errors === [] && $result['buchung'] !== []) {
        benachrichtigen($result['buchung'], $config, $setup, $brand);
        $done = true;
    } elseif ($errors === []) {
        // Die Falle hat zugeschnappt. Dieselbe Antwort wie bei Erfolg –
        // eine Maschine soll nicht erfahren, dass sie erkannt wurde.
        $done = true;
    }
}

// --------------------------------------------------------------- Anzeigen

$serviceIndex = max(0, min(count($services) - 1, (int) ($_GET['leistung'] ?? $_POST['leistung'] ?? 0)));
$service = $services[$serviceIndex];

$day = erlaubterTag((string) ($_GET['tag'] ?? ''), $leadHours, $horizonDays);

$slots = $day === ''
    ? []
    : freieZeitenAus(lesen($file), $day, (int) ($service['dauer'] ?? $slotMinutes),
        $slotMinutes, $leadHours, $hours, $closed);

$tage = naechsteTage($leadHours, $horizonDays, $hours, $closed);

// ==================================================================
// Speichern
// ==================================================================

/**
 * @return array{errors:array<int,string>, buchung:array<string,mixed>}
 */
function buchen(
    string $file,
    array $services,
    array $setup,
    int $slotMinutes,
    int $leadHours,
    int $horizonDays,
    array $hours,
    array $closed
): array {
    $errors = [];

    // Die Falle für Maschinen.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        return ['errors' => [], 'buchung' => []];
    }

    $started = (int) ($_POST['gestartet'] ?? 0);

    if ($started > 0 && time() - $started < MIN_SECONDS) {
        return ['errors' => ['Das ging zu schnell. Bitte noch einmal versuchen.'], 'buchung' => []];
    }

    if (zuViele($file)) {
        return ['errors' => ['Aus Ihrer Leitung kamen gerade viele Buchungen. Bitte '
            . 'melden Sie sich telefonisch.'], 'buchung' => []];
    }

    $index = max(0, min(count($services) - 1, (int) ($_POST['leistung'] ?? 0)));
    $service = $services[$index];
    $duration = max(5, (int) ($service['dauer'] ?? $slotMinutes));

    $day = erlaubterTag((string) ($_POST['tag'] ?? ''), $leadHours, $horizonDays);
    $time = (string) ($_POST['zeit'] ?? '');

    if ($day === '') {
        $errors[] = 'Bitte wählen Sie einen Tag.';
    }

    if (preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
        $errors[] = 'Bitte wählen Sie eine Zeit.';
    }

    $name = feld('name', 120);
    $email = feld('email', 190);
    $phone = feld('telefon', 60);
    $note = feld('nachricht', 2000);

    if (mb_strlen($name) < 2) {
        $errors[] = 'Bitte tragen Sie Ihren Namen ein.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Bitte tragen Sie eine E-Mail-Adresse ein, an die wir die Bestätigung schicken können.';
    }

    if (!empty($setup['telefon_pflicht']) && mb_strlen($phone) < 6) {
        $errors[] = 'Bitte tragen Sie eine Telefonnummer ein.';
    }

    if ($errors !== []) {
        return ['errors' => $errors, 'buchung' => []];
    }

    // Ab hier wird geschrieben. Die Sperre umschliesst Lesen, Prüfen und
    // Schreiben – sonst könnten sich zwei Buchungen überholen.
    $handle = @fopen($file, 'c+');

    if ($handle === false) {
        return ['errors' => ['Die Buchung liess sich nicht speichern. Bitte rufen Sie an.'], 'buchung' => []];
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return ['errors' => ['Gerade ist es voll. Bitte noch einmal versuchen.'], 'buchung' => []];
        }

        $bookings = lesenOffen($handle);

        // Noch einmal nachsehen: Zwischen Anzeige und Absenden kann die
        // Zeit vergeben worden sein.
        $free = freieZeitenAus($bookings, $day, $duration, $slotMinutes, $leadHours, $hours, $closed);

        if (!in_array($time, $free, true)) {
            return [
                'errors' => ['Diese Zeit ist inzwischen vergeben. Bitte wählen Sie eine andere.'],
                'buchung' => [],
            ];
        }

        $booking = [
            'id' => bin2hex(random_bytes(8)),
            'leistung' => (string) ($service['name'] ?? 'Termin'),
            'dauer' => $duration,
            'tag' => $day,
            'zeit' => $time,
            'name' => $name,
            'email' => $email,
            'telefon' => $phone,
            'nachricht' => $note,
            'zustand' => 'offen',
            'ip' => leitung(),
            'erstellt' => date('c'),
        ];

        $bookings[] = $booking;

        // Alles, was länger als ein Jahr her ist, kann weg.
        $grenze = date('Y-m-d', strtotime('-1 year'));
        $bookings = array_values(array_filter(
            $bookings,
            static fn (array $b): bool => (string) ($b['tag'] ?? '') >= $grenze
        ));

        schreibenOffen($handle, $bookings);

        return ['errors' => [], 'buchung' => $booking];
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Die Bestätigungen. */
function benachrichtigen(array $booking, array $config, array $setup, string $brand): void
{
    if ($booking === []) {
        return;
    }

    $when = datum((string) $booking['tag']) . ' um ' . (string) $booking['zeit'] . ' Uhr';

    $to = trim((string) ($setup['empfaenger'] ?? $config['contact_email'] ?? ''));
    $from = $to !== '' ? $to : ('noreply@' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

    $headers = 'From: ' . kopf($brand) . ' <' . $from . ">\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "MIME-Version: 1.0\r\n";

    // An den Gast
    @mail(
        (string) $booking['email'],
        '=?UTF-8?B?' . base64_encode('Ihr Termin am ' . $when) . '?=',
        'Guten Tag ' . (string) $booking['name'] . "\n\n"
        . "Ihr Termin ist eingetragen:\n\n"
        . '  ' . (string) $booking['leistung'] . "\n"
        . '  ' . $when . "\n"
        . '  Dauer etwa ' . (int) $booking['dauer'] . " Minuten\n\n"
        . "Passt es doch nicht? Antworten Sie einfach auf diese Nachricht.\n\n"
        . "Freundliche Grüsse\n"
        . $brand . "\n",
        $headers
    );

    // An den Betrieb
    if ($to !== '') {
        @mail(
            $to,
            '=?UTF-8?B?' . base64_encode('Neue Buchung: ' . $when) . '?=',
            "Neue Buchung\n\n"
            . '  ' . (string) $booking['leistung'] . "\n"
            . '  ' . $when . "\n\n"
            . '  ' . (string) $booking['name'] . "\n"
            . '  ' . (string) $booking['email'] . "\n"
            . ((string) $booking['telefon'] !== '' ? '  ' . (string) $booking['telefon'] . "\n" : '')
            . ((string) $booking['nachricht'] !== '' ? "\n" . (string) $booking['nachricht'] . "\n" : '')
            . "\nAlle Buchungen: /admin?ansicht=buchungen\n",
            'From: ' . kopf($brand) . ' <' . $from . ">\r\n"
            . 'Reply-To: ' . (string) $booking['email'] . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "MIME-Version: 1.0\r\n"
        );
    }
}

// ==================================================================
// Ablage
// ==================================================================

/** @return array<int, array<string, mixed>> */
function lesen(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    return entpacken((string) file_get_contents($file));
}

/** Aus einem offenen, gesperrten Zeiger lesen. */
function lesenOffen($handle): array
{
    rewind($handle);

    return entpacken((string) stream_get_contents($handle));
}

/** In denselben offenen Zeiger schreiben – die Sperre bleibt bestehen. */
function schreibenOffen($handle, array $data): void
{
    $body = GUARD . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, $body);
    fflush($handle);
}

/** Die Ablage beginnt mit einem Abbruch – dahinter steht das JSON. */
function entpacken(string $raw): array
{
    $end = strpos($raw, '?>');
    $data = $end !== false ? json_decode(trim(substr($raw, $end + 2)), true) : [];

    return is_array($data) ? $data : [];
}

function zuViele(string $file): bool
{
    $ip = leitung();
    $since = time() - 3600;
    $count = 0;

    foreach (lesen($file) as $booking) {
        if ((string) ($booking['ip'] ?? '') === $ip
            && strtotime((string) ($booking['erstellt'] ?? '')) > $since) {
            $count++;
        }
    }

    return $count >= MAX_PER_HOUR;
}

// ==================================================================
// Kleinkram
// ==================================================================

function feld(string $name, int $max): string
{
    $value = (string) ($_POST[$name] ?? '');
    $value = str_replace(["\r", "\0"], '', $value);

    return mb_substr(trim($value), 0, $max);
}

function leitung(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function kopf(string $value): string
{
    return '=?UTF-8?B?' . base64_encode(str_replace(["\r", "\n"], '', $value)) . '?=';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require __DIR__ . '/buchen-ansicht.php';
