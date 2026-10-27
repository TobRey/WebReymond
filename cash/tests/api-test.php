<?php
/**
 * End-to-End-Tests gegen einen laufenden Testserver (tests/serve.sh).
 *   php tests/api-test.php http://127.0.0.1:8099/cash/ <datenverzeichnis>
 * Erwartet ein frisches, leeres Datenverzeichnis.
 */
declare(strict_types=1);

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8099/cash/', '/') . '/';
$DATA = $argv[2] ?? '';
$fails = 0;
$passes = 0;

function check(bool $cond, string $label, mixed $info = null): void
{
    global $fails, $passes;
    if ($cond) {
        $passes++;
        echo "  ✓ $label\n";
    } else {
        $fails++;
        echo "  ✗ $label" . ($info !== null ? ' → ' . json_encode($info, JSON_UNESCAPED_UNICODE) : '') . "\n";
    }
}
function section(string $t): void { echo "\n== $t ==\n"; }

final class Client
{
    public $ch;
    public string $csrf = '';
    public function __construct(public string $base, array $cookies = [])
    {
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_HEADER => false]);
        foreach ($cookies as $line) {
            curl_setopt($this->ch, CURLOPT_COOKIELIST, $line);
        }
    }
    public function req(string $method, string $action, ?array $body = null, array $query = [], array $headers = []): array
    {
        $url = $this->base . 'api.php?a=' . urlencode($action) . ($query ? '&' . http_build_query($query) : '');
        $h = array_merge(['Accept: application/json'], $headers);
        curl_setopt($this->ch, CURLOPT_URL, $url);
        if ($method === 'POST') {
            $h[] = 'Content-Type: application/json';
            $h[] = 'X-CSRF-Token: ' . $this->csrf;
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($body ?? new stdClass()));
        } else {
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        }
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, $h);
        $raw = curl_exec($this->ch);
        $status = curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
        $json = json_decode((string)$raw, true);
        if (is_array($json) && isset($json['csrf'])) {
            $this->csrf = $json['csrf'];
        }
        return ['status' => $status, 'json' => $json, 'raw' => $raw];
    }
    public function get(string $a, array $q = []): array { return $this->req('GET', $a, null, $q); }
    public function post(string $a, array $b = []): array { return $this->req('POST', $a, $b); }
    public function status(): array { $r = $this->get('auth.status'); return $r; }
    public function cookies(): array { return curl_getinfo($this->ch, CURLINFO_COOKIELIST) ?: []; }
    public function cookie(string $name): ?string
    {
        foreach ($this->cookies() as $line) {
            $p = explode("\t", $line);
            if (($p[5] ?? '') === $name) {
                return $p[6] ?? null;
            }
        }
        return null;
    }
    public function act(string $action, array $payload = [], ?string $rid = null): array
    {
        $payload['requestId'] = $rid ?? bin2hex(random_bytes(10));
        return $this->post($action, $payload);
    }
}

function state(Client $c, ?string $month = null): array
{
    $r = $c->get('state', $month ? ['month' => $month] : []);
    return $r['json']['state'] ?? [];
}
function accByName(array $st, string $name): array
{
    foreach ($st['accounts'] as $a) {
        if ($a['name'] === $name) {
            return $a;
        }
    }
    throw new RuntimeException("Konto $name fehlt");
}
function catByName(array $st, string $name): array
{
    foreach ($st['categories'] as $c) {
        if ($c['name'] === $name) {
            return $c;
        }
    }
    throw new RuntimeException("Kategorie $name fehlt");
}

$today = date('Y-m-d');
$month = date('Y-m');

// ------------------------------------------------------------------ Einrichtung
section('Erstes Einrichten');
$owner = new Client($BASE);
$s = $owner->status();
check($s['json']['installed'] === false && $s['json']['user'] === null, 'Noch nicht installiert');
$r = $owner->req('POST', 'auth.setup', ['name' => 'Tobias', 'username' => 'tobias', 'password' => 'sehr-geheim-123', 'remember' => true], [], ['X-CSRF-Token: falsch']);
check($r['status'] === 419, 'Ohne gültiges CSRF-Token abgelehnt', $r['status']);
$owner->status();
$r = $owner->req('POST', 'auth.setup', ['name' => 'X', 'username' => 'x1', 'password' => 'sehr-geheim-123'], [], ['Origin: https://evil.example']);
check($r['status'] === 403, 'Fremde Origin abgelehnt', $r['status']);
$r = $owner->post('auth.setup', ['name' => 'Tobias', 'username' => 'tobias', 'password' => 'kurz']);
check($r['status'] === 400, 'Zu kurzes Passwort abgelehnt');
$r = $owner->post('auth.setup', ['name' => 'Tobias Reymond', 'username' => 'tobias', 'email' => 'tobias@example.ch', 'password' => 'sehr-geheim-123', 'remember' => true]);
check($r['status'] === 200 && $r['json']['user']['role'] === 'owner', 'Hauptaccount erstellt', $r['json']);
check($r['json']['registrationOpen'] === false, 'Registrierung danach deaktiviert');
check($owner->cookie('CASHREM') !== null, 'Angemeldet-bleiben-Cookie gesetzt');
$r2 = (new Client($BASE));
$r2->status();
$x = $r2->post('auth.setup', ['name' => 'Hacker', 'username' => 'hacker', 'password' => 'sehr-geheim-123']);
check($x['status'] === 409, 'Zweites Setup unmöglich', $x['status']);
$x = $r2->post('auth.register', ['name' => 'Hacker', 'username' => 'hacker', 'password' => 'sehr-geheim-123']);
check($x['status'] === 403, 'Öffentliche Registrierung gesperrt', $x['status']);
$x = $r2->get('state');
check($x['status'] === 401, 'Ohne Anmeldung kein Datenzugriff');

$st = state($owner);
check(count($st['accounts']) === 0 && count($st['transactions']) === 0 && count($st['bills']) === 0 && count($st['debts']) === 0, 'Neuer Account ohne erfundene Daten');

section('Onboarding mit Anfangssalden und Thailand-Budget');
$r = $owner->act('onboarding.complete', ['accounts' => [['name' => 'Privatkonto', 'opening' => '', 'type' => 'checking']]]);
check($r['status'] === 400, 'Leerer Anfangssaldo wird nicht erfunden, sondern abgefragt');
$r = $owner->act('onboarding.complete', [
    'accounts' => [['name' => 'Privatkonto', 'opening' => "1'000.00", 'type' => 'checking'], ['name' => 'E-Jugendsparkonto', 'opening' => '500', 'type' => 'savings']],
    'thailand' => ['enabled' => true, 'amount' => '315.00'],
]);
check($r['status'] === 200 && $r['json']['user']['onboarded'] === true, 'Onboarding abgeschlossen', $r['json']['error'] ?? null);
$st = $r['json']['state'];
check(accByName($st, 'Privatkonto')['balance'] === 100000 && accByName($st, 'E-Jugendsparkonto')['balance'] === 50000, 'Anfangssalden korrekt');
check($st['summary']['income'] === 0, 'Anfangssalden zählen nicht als Einnahmen');
$th = $st['budgets'][0] ?? null;
check($th && $th['amount'] === 31500 && $th['inputCurrency'] === 'THB' && $th['remaining'] === 31500 && $th['startDate'] === $today, 'Thailand-Budget CHF 315.00 / THB ab heute', $th);
$r = $owner->act('onboarding.complete', ['accounts' => [['name' => 'X', 'opening' => '0']]]);
check($r['status'] === 409, 'Onboarding nicht doppelt');

$privat = accByName($st, 'Privatkonto')['id'];
$spar = accByName($st, 'E-Jugendsparkonto')['id'];
$lohn = catByName($st, 'Lohn')['id'];
$food = catByName($st, 'Lebensmittel')['id'];
$rest = catByName($st, 'Restaurant & Café')['id'];
$abo = catByName($st, 'Abos & Medien')['id'];

// ------------------------------------------------------------------ Buchungen
section('Buchungen und Kontosalden');
$r = $owner->act('tx.save', ['type' => 'income', 'amount' => '200', 'date' => $today, 'accountId' => $privat, 'categoryId' => $lohn, 'description' => 'Lohn']);
check($r['status'] === 200, 'Einnahme erfasst', $r['json']['error'] ?? null);
$r = $owner->act('tx.save', ['type' => 'expense', 'amount' => '50,25', 'date' => $today, 'accountId' => $privat, 'categoryId' => $food, 'description' => 'Migros']);
check($r['status'] === 200, 'Ausgabe mit Komma-Betrag erfasst');
$r = $owner->act('tx.save', ['type' => 'expense', 'amount' => '10', 'date' => $today, 'accountId' => $privat, 'categoryId' => $lohn]);
check($r['status'] === 400, 'Einnahme-Kategorie für Ausgabe abgelehnt');
$r = $owner->act('tx.save', ['type' => 'expense', 'amount' => '-5', 'date' => $today, 'accountId' => $privat, 'categoryId' => $food]);
check($r['status'] === 400, 'Negativer Betrag abgelehnt');
$r = $owner->act('tx.save', ['type' => 'expense', 'amount' => '5', 'date' => '2026-02-30', 'accountId' => $privat, 'categoryId' => $food]);
check($r['status'] === 400, 'Ungültiges Datum abgelehnt');
$r = $owner->act('tx.save', ['type' => 'transfer', 'amount' => '100', 'date' => $today, 'accountId' => $privat, 'toAccountId' => $spar, 'description' => 'Sparen']);
check($r['status'] === 200, 'Umbuchung erfasst');
$r = $owner->act('tx.save', ['type' => 'transfer', 'amount' => '1', 'date' => $today, 'accountId' => $privat, 'toAccountId' => $privat]);
check($r['status'] === 400, 'Umbuchung auf dasselbe Konto abgelehnt');
$st = $r['json']['state'] ?? state($owner);
$st = state($owner);
check(accByName($st, 'Privatkonto')['balance'] === 104975, 'Privatkonto 1000 + 200 − 50.25 − 100 = 1049.75', accByName($st, 'Privatkonto')['balance']);
check(accByName($st, 'E-Jugendsparkonto')['balance'] === 60000, 'Sparkonto 500 + 100 = 600');
check($st['summary']['income'] === 20000 && $st['summary']['expense'] === 5025, 'Umbuchung zählt nicht als Einnahme/Ausgabe', $st['summary']);
check($st['summary']['totalBalance'] === 164975, 'Gesamtstand stimmt');
check($st['settings']['lastAccountId'] === $privat, 'Zuletzt verwendetes Konto gemerkt');

// Bearbeiten & Löschen
$migros = array_values(array_filter($st['transactions'], fn($t) => $t['description'] === 'Migros'))[0];
$r = $owner->act('tx.save', ['id' => $migros['id'], 'type' => 'expense', 'amount' => '60', 'date' => $today, 'accountId' => $privat, 'categoryId' => $food, 'description' => 'Migros']);
$st = $r['json']['state'];
check(accByName($st, 'Privatkonto')['balance'] === 103999 + 1, 'Buchung bearbeitet → Saldo angepasst (1040.00)', accByName($st, 'Privatkonto')['balance']);
$r = $owner->act('tx.delete', ['id' => $migros['id']]);
$st = $r['json']['state'];
check(accByName($st, 'Privatkonto')['balance'] === 110000, 'Buchung gelöscht → Saldo 1100.00');

section('Doppelte Anfragen (gleiche requestId)');
$rid = 'test-' . bin2hex(random_bytes(6));
$a = $owner->act('tx.save', ['type' => 'expense', 'amount' => '7.50', 'date' => $today, 'accountId' => $privat, 'categoryId' => $rest, 'description' => 'Kaffee'], $rid);
$b = $owner->act('tx.save', ['type' => 'expense', 'amount' => '7.50', 'date' => $today, 'accountId' => $privat, 'categoryId' => $rest, 'description' => 'Kaffee'], $rid);
$n = count(array_filter($b['json']['state']['transactions'], fn($t) => $t['description'] === 'Kaffee'));
check($n === 1 && !empty($b['json']['duplicate']), 'Gleiche Anfrage nur einmal gespeichert', $n);

section('Paralleles Speichern (40 gleichzeitige Anfragen)');
$cookies = $owner->cookies();
$mh = curl_multi_init();
$handles = [];
for ($i = 0; $i < 40; $i++) {
    $ch = curl_init($BASE . 'api.php?a=tx.save');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEFILE => '',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $owner->csrf],
        CURLOPT_POSTFIELDS => json_encode(['type' => 'expense', 'amount' => '1.00', 'date' => $today, 'accountId' => $privat, 'categoryId' => $food, 'description' => 'parallel-' . $i, 'requestId' => 'par-' . $i . '-' . bin2hex(random_bytes(4))])]);
    foreach ($cookies as $line) {
        curl_setopt($ch, CURLOPT_COOKIELIST, $line);
    }
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.1);
} while ($running);
$codes = array_map(fn($ch) => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $handles);
check(count(array_filter($codes, fn($c) => $c === 200)) === 40, 'Alle 40 Anfragen erfolgreich', array_count_values($codes));
$st = state($owner);
$par = array_filter($st['transactions'], fn($t) => str_starts_with($t['description'], 'parallel-'));
check(count($par) === 40, 'Keine verlorenen Schreibvorgänge: 40 Buchungen vorhanden', count($par));
check(accByName($st, 'Privatkonto')['balance'] === 110000 - 750 - 4000, 'Saldo nach parallelen Buchungen korrekt', accByName($st, 'Privatkonto')['balance']);
foreach ($par as $t) {
    $owner->act('tx.delete', ['id' => $t['id']]);
}

// ------------------------------------------------------------------ THB
section('THB-Ferienbudget und Umrechnung');
$thId = $th['id'];
$r = $owner->act('tx.save', ['type' => 'expense', 'currency' => 'THB', 'originalAmount' => '1000', 'rate' => '', 'date' => $today, 'accountId' => $privat, 'categoryId' => $rest, 'budgetId' => $thId]);
check($r['status'] === 400 && str_contains($r['json']['error'], 'Wechselkurs'), 'Ohne Kurs: Kurs wird abgefragt, nicht erfunden', $r['json']['error'] ?? null);
$st = state($owner);
check(empty((array)$st['settings']['lastRates']), 'Noch kein Kurs gespeichert');
$r = $owner->act('tx.save', ['type' => 'expense', 'currency' => 'THB', 'originalAmount' => "1'000", 'rate' => '38.5', 'date' => $today, 'accountId' => $privat, 'categoryId' => $rest, 'budgetId' => $thId, 'description' => 'Pad Thai']);
check($r['status'] === 200, 'THB-Ausgabe erfasst', $r['json']['error'] ?? null);
$st = $r['json']['state'];
$pad = array_values(array_filter($st['transactions'], fn($t) => $t['description'] === 'Pad Thai'))[0];
check($pad['amount'] === 2597 && $pad['fx']['original'] === 100000 && $pad['fx']['currency'] === 'THB' && $pad['fx']['rate'] === '38.5', 'THB 1000 / 38.5 = CHF 25.97 (Original, Währung, Kurs gespeichert)', $pad);
$thb = array_values(array_filter($st['budgets'], fn($b) => $b['id'] === $thId))[0];
check($thb['spent'] === 2597 && $thb['remaining'] === 31500 - 2597, 'Thailand-Restbudget 289.03', $thb['remaining']);
check(accByName($st, 'Privatkonto')['balance'] === 110000 - 750 - 2597, 'Ausgabe reduziert Konto (Budget erzeugt keine 2. Buchung)');
check($st['settings']['lastRates']['THB'] === '38.5', 'Letzter Kurs wird vorbelegt');
$r = $owner->act('tx.save', ['type' => 'expense', 'currency' => 'THB', 'originalAmount' => '1', 'rate' => '8', 'date' => $today, 'accountId' => $privat, 'categoryId' => $rest, 'budgetId' => $thId, 'description' => 'Rundung']);
$rd = array_values(array_filter($r['json']['state']['transactions'], fn($t) => $t['description'] === 'Rundung'))[0];
check($rd['amount'] === 13, 'Kaufmännische Rundung: THB 1.00 / 8 = 0.125 → CHF 0.13', $rd['amount']);
$owner->act('tx.delete', ['id' => $rd['id']]);
$r = $owner->act('rate.set', ['currency' => 'THB', 'rate' => '40']);
$st = $r['json']['state'];
$pad2 = array_values(array_filter($st['transactions'], fn($t) => $t['id'] === $pad['id']))[0];
check($pad2['amount'] === 2597 && $st['settings']['lastRates']['THB'] === '40', 'Kursänderung verändert alte Buchung nicht');

// ------------------------------------------------------------------ Budgets
section('Monatsbudgets');
$r = $owner->act('budget.save', ['kind' => 'monthly', 'amount' => '400', 'categoryId' => $rest]);
check($r['status'] === 200, 'Monatsbudget Restaurant erstellt');
$r = $owner->act('budget.save', ['kind' => 'monthly', 'amount' => '100', 'categoryId' => $rest]);
check($r['status'] === 409, 'Kein zweites Monatsbudget für dieselbe Kategorie');
$st = state($owner);
$rb = array_values(array_filter($st['budgets'], fn($b) => $b['kind'] === 'monthly'))[0];
check($rb['spent'] === 750 && $rb['remaining'] === 40000 - 750, 'Kaffee zählt, Thailand-Ausgabe (anderes Budget) nicht doppelt', $rb['spent']);
$lastMonth = date('Y-m', strtotime('first day of last month'));
$owner->act('tx.save', ['type' => 'expense', 'amount' => '20', 'date' => $lastMonth . '-15', 'accountId' => $privat, 'categoryId' => $rest, 'description' => 'Vormonat']);
$st = state($owner);
$rb = array_values(array_filter($st['budgets'], fn($b) => $b['kind'] === 'monthly'))[0];
check($rb['spent'] === 750, 'Vormonat zählt nicht im aktuellen Monat');
$stPrev = state($owner, $lastMonth);
check($stPrev['summary']['expense'] === 2000 && $stPrev['month'] === $lastMonth, 'Monatswechsel liefert Vormonatswerte');
$r = $owner->act('budget.delete', ['id' => $rb['id']]);
check($r['status'] === 200 && count(array_filter($r['json']['state']['transactions'], fn($t) => $t['description'] === 'Kaffee')) === 1, 'Budget gelöscht, Buchungen bleiben');

// ------------------------------------------------------------------ Rechnungen
section('Wiederkehrende Rechnungen');
$start = date('Y-m-d', strtotime('-100 days'));
$r = $owner->act('bill.save', ['name' => 'Handy-Abo', 'amount' => '29.90', 'categoryId' => $abo, 'startDate' => $start, 'interval' => 'monthly', 'accountId' => $privat]);
check($r['status'] === 200, 'Monatliche Rechnung mit Startdatum vor 100 Tagen');
$billId = $r['json']['id'];
$st = $r['json']['state'];
$occ = array_values(array_filter($st['occurrences'], fn($o) => $o['billId'] === $billId));
$dates = array_column($occ, 'dueDate');
check(count($dates) === count(array_unique($dates)), 'Keine doppelten Fälligkeiten');
$past = array_filter($dates, fn($d) => $d < $today);
check(count($past) >= 3, 'Verpasste Fälligkeiten nachträglich erzeugt (' . count($past) . ')');
check(count(array_filter($dates, fn($d) => $d >= $today)) >= 1, 'Nächste Fälligkeit vorhanden');
check($st['summary']['openBills']['overdueCount'] >= 3, 'Überfällige werden gezählt');
check(accByName($st, 'Privatkonto')['balance'] === 110000 - 750 - 2597 - 2000, 'Keine automatische Zahlung unterstellt');
// 10 parallele state-Aufrufe → keine Duplikate
$mh = curl_multi_init();
$hs = [];
for ($i = 0; $i < 10; $i++) {
    $ch = curl_init($BASE . 'api.php?a=state');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '']);
    foreach ($owner->cookies() as $line) {
        curl_setopt($ch, CURLOPT_COOKIELIST, $line);
    }
    curl_multi_add_handle($mh, $ch);
    $hs[] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); } while ($running);
$st = state($owner);
$occ2 = array_values(array_filter($st['occurrences'], fn($o) => $o['billId'] === $billId));
check(count($occ2) === count($occ), 'Parallele Aufrufe erzeugen keine zusätzlichen Fälligkeiten', [count($occ), count($occ2)]);

section('Doppelte Zahlungsklicks');
$first = $occ[0];
$mh = curl_multi_init();
$hs = [];
for ($i = 0; $i < 6; $i++) {
    $ch = curl_init($BASE . 'api.php?a=occ.pay');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEFILE => '',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $owner->csrf],
        CURLOPT_POSTFIELDS => json_encode(['id' => $first['id'], 'date' => $today, 'accountId' => $privat, 'amount' => '29.90', 'requestId' => 'pay-' . $i . bin2hex(random_bytes(4))])]);
    foreach ($owner->cookies() as $line) {
        curl_setopt($ch, CURLOPT_COOKIELIST, $line);
    }
    curl_multi_add_handle($mh, $ch);
    $hs[] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); } while ($running);
$codes = array_count_values(array_map(fn($ch) => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $hs));
check(($codes[200] ?? 0) === 1 && ($codes[409] ?? 0) === 5, '6 gleichzeitige Klicks: 1× bezahlt, 5× abgelehnt', $codes);
$st = state($owner);
$linked = array_filter($st['transactions'], fn($t) => ($t['link']['occurrenceId'] ?? null) === $first['id']);
check(count($linked) === 1, 'Genau eine verknüpfte Ausgabe');
$tx = array_values($linked)[0];
check($tx['type'] === 'expense' && $tx['amount'] === 2990 && $tx['categoryId'] === $abo, 'Ausgabe mit Betrag/Kategorie der Rechnung');
$o = array_values(array_filter($st['occurrences'], fn($x) => $x['id'] === $first['id']))[0];
check($o['status'] === 'paid' && $o['txId'] === $tx['id'] && $o['accountId'] === $privat, 'Fälligkeit bezahlt und verknüpft');

// Bearbeitung der Ausgabe synchronisiert die Rechnung
$r = $owner->act('tx.save', ['id' => $tx['id'], 'type' => 'expense', 'amount' => '31', 'date' => $today, 'accountId' => $spar, 'categoryId' => $abo, 'description' => 'Handy-Abo']);
$o = array_values(array_filter($r['json']['state']['occurrences'], fn($x) => $x['id'] === $first['id']))[0];
check($o['paidAmount'] === 3100 && $o['accountId'] === $spar, 'Buchungsänderung wird in Rechnung übernommen');
$r = $owner->act('tx.save', ['id' => $tx['id'], 'type' => 'income', 'amount' => '31', 'date' => $today, 'accountId' => $spar, 'categoryId' => $lohn]);
check($r['status'] === 400, 'Bezahlte Rechnung kann nicht zur Einnahme werden');
// Rücknahme
$r = $owner->act('occ.unpay', ['id' => $first['id']]);
$st = $r['json']['state'];
$o = array_values(array_filter($st['occurrences'], fn($x) => $x['id'] === $first['id']))[0];
check($o['status'] === 'open' && $o['txId'] === null && !array_filter($st['transactions'], fn($t) => $t['id'] === $tx['id']), 'Rücknahme: Ausgabe gelöscht, Rechnung offen');
// Löschen der Buchung öffnet die Rechnung
$r = $owner->act('occ.pay', ['id' => $first['id'], 'date' => $today, 'accountId' => $privat]);
$tid = array_values(array_filter($r['json']['state']['occurrences'], fn($x) => $x['id'] === $first['id']))[0]['txId'];
$r = $owner->act('tx.delete', ['id' => $tid]);
$o = array_values(array_filter($r['json']['state']['occurrences'], fn($x) => $x['id'] === $first['id']))[0];
check($o['status'] === 'open', 'Löschen der Ausgabe setzt Rechnung auf offen');
// Einmalige Rechnung in der Zukunft
$r = $owner->act('bill.save', ['name' => 'Zahnarzt', 'amount' => '180', 'categoryId' => catByName($st, 'Gesundheit')['id'], 'startDate' => date('Y-m-d', strtotime('+90 days')), 'interval' => 'once']);
$oc = array_filter($r['json']['state']['occurrences'], fn($o) => $o['billId'] === $r['json']['id']);
check(count($oc) === 1, 'Einmalige Rechnung: genau eine Fälligkeit');
// Rechnung beenden
$r = $owner->act('bill.active', ['id' => $billId, 'active' => false]);
$open = array_filter($r['json']['state']['occurrences'], fn($o) => $o['billId'] === $billId && $o['status'] === 'open' && $o['dueDate'] >= $today);
check(count($open) === 0, 'Beendete Rechnung: keine künftigen Fälligkeiten');
$owner->act('bill.active', ['id' => $billId, 'active' => true]);

// ------------------------------------------------------------------ Sparziele
section('Sparziele');
$before = state($owner)['summary']['totalBalance'];
$r = $owner->act('goal.save', ['name' => 'Velo', 'target' => '1200', 'initial' => '100']);
$gid = $r['json']['id'];
$r = $owner->act('goal.allocate', ['goalId' => $gid, 'amount' => '50', 'mode' => 'add', 'date' => $today]);
$g = array_values(array_filter($r['json']['state']['goals'], fn($g) => $g['id'] === $gid))[0];
check($g['saved'] === 15000 && $g['remaining'] === 105000, 'Fortschritt 150 von 1200');
check($r['json']['state']['summary']['totalBalance'] === $before, 'Sparzuweisung verändert keinen Kontostand');
$r = $owner->act('goal.allocate', ['goalId' => $gid, 'amount' => '500', 'mode' => 'remove', 'date' => $today]);
check($r['status'] === 400, 'Nicht mehr entnehmen als zugewiesen');

// ------------------------------------------------------------------ Schulden
section('Schulden mit Teilrückzahlungen');
$st = state($owner);
$balP = accByName($st, 'Privatkonto')['balance'];
$inc = $st['summary']['income'];
$exp = $st['summary']['expense'];
$r = $owner->act('debt.save', ['direction' => 'i_owe', 'person' => 'Anna', 'amount' => '300', 'date' => $today, 'originAccountId' => $privat, 'note' => 'Darlehen']);
check($r['status'] === 200, 'Darlehen von Anna erfasst (auf Privatkonto erhalten)');
$did = $r['json']['id'];
$st = $r['json']['state'];
check(accByName($st, 'Privatkonto')['balance'] === $balP + 30000, 'Konto +300 (Darlehen erhalten)');
check($st['summary']['income'] === $inc, 'Darlehen zählt nicht als Einnahme');
$r = $owner->act('debt.pay', ['debtId' => $did, 'amount' => '100', 'date' => $today, 'accountId' => $privat]);
$st = $r['json']['state'];
$d = array_values(array_filter($st['debts'], fn($x) => $x['id'] === $did))[0];
check($d['paid'] === 10000 && $d['remaining'] === 20000, 'Teilrückzahlung 100 → Rest 200');
check(accByName($st, 'Privatkonto')['balance'] === $balP + 20000 && $st['summary']['expense'] === $exp, 'Rückzahlung reduziert Konto, aber keine Ausgabe');
$r = $owner->act('debt.pay', ['debtId' => $did, 'amount' => '250', 'date' => $today, 'accountId' => $privat]);
check($r['status'] === 400, 'Überzahlung abgelehnt');
$r = $owner->act('tx.delete', ['id' => array_values(array_filter($st['transactions'], fn($t) => ($t['link']['debtId'] ?? null) === $did))[0]['id']]);
check($r['status'] === 409, 'Darlehensbuchung nur im Schuldenbereich änderbar');
$r = $owner->act('debt.pay', ['debtId' => $did, 'amount' => '200', 'date' => $today, 'accountId' => null, 'note' => 'verrechnet']);
$st = $r['json']['state'];
$d = array_values(array_filter($st['debts'], fn($x) => $x['id'] === $did))[0];
check($d['remaining'] === 0 && count($d['payments']) === 2, 'Rest ohne Kontobewegung beglichen, Verlauf mit 2 Zahlungen');
check(accByName($st, 'Privatkonto')['balance'] === $balP + 20000, 'Zahlung ohne Konto verändert keinen Saldo');
$r = $owner->act('debt.save', ['direction' => 'owed_to_me', 'person' => 'Luca', 'amount' => '80', 'date' => $today, 'originAccountId' => $spar]);
$lid = $r['json']['id'];
$st = $r['json']['state'];
check(accByName($st, 'E-Jugendsparkonto')['balance'] === 60000 - 8000, 'Ausgeliehen an Luca: Sparkonto −80 (keine Ausgabe)');
$r = $owner->act('debt.pay', ['debtId' => $lid, 'amount' => '30', 'date' => $today, 'accountId' => $spar]);
$st = $r['json']['state'];
check(accByName($st, 'E-Jugendsparkonto')['balance'] === 60000 - 5000 && $st['summary']['income'] === $inc, 'Rückzahlung von Luca +30, keine Einnahme');
check($st['summary']['debts']['owedToMe'] === 5000, 'Mir geschuldet: 50');
$r = $owner->act('debt.save', ['id' => $did, 'person' => 'Anna', 'amount' => '250', 'date' => $today, 'originAccountId' => $privat]);
check($r['status'] === 400, 'Betrag kleiner als Rückzahlungen abgelehnt');
$r = $owner->act('debt.delete', ['id' => $lid]);
$st = $r['json']['state'];
check(accByName($st, 'E-Jugendsparkonto')['balance'] === 60000 && !array_filter($st['transactions'], fn($t) => ($t['link']['debtId'] ?? null) === $lid), 'Schuld gelöscht inkl. Kontobewegungen');

// ------------------------------------------------------------------ Datentrennung
section('Datentrennung zwischen Benutzern');
$r = $owner->post('admin.registration', ['open' => true]);
check($r['json']['registrationOpen'] === true, 'Hauptaccount öffnet Registrierung');
$u2 = new Client($BASE);
$u2->status();
$r = $u2->post('auth.register', ['name' => 'Gast', 'username' => 'gast', 'password' => 'gast-passwort-1']);
check($r['status'] === 200 && $r['json']['user']['role'] === 'user', 'Zweiter Benutzer registriert');
$r = $u2->post('admin.registration', ['open' => true]);
check($r['status'] === 403, 'Normaler Benutzer darf Registrierung nicht ändern');
$owner->post('admin.registration', ['open' => false]);
$s2 = state($u2);
check(count($s2['accounts']) === 0 && count($s2['transactions']) === 0 && count($s2['budgets']) === 0, 'Gast sieht keine Daten des Hauptaccounts');
$ownerSt = state($owner);
$someTx = $ownerSt['transactions'][0]['id'];
$r = $u2->act('tx.delete', ['id' => $someTx]);
check($r['status'] === 404, 'Gast kann fremde Buchung nicht löschen');
$r = $u2->act('debt.pay', ['debtId' => $did, 'amount' => '1', 'date' => $today]);
check($r['status'] === 404, 'Gast kann fremde Schuld nicht ändern');
$r = $u2->act('occ.pay', ['id' => $first['id'], 'date' => $today, 'accountId' => $privat]);
check($r['status'] === 404, 'Gast kann fremde Rechnung nicht bezahlen');
check(count(state($owner)['transactions']) === count($ownerSt['transactions']), 'Daten des Hauptaccounts unverändert');
$x = $u2->get('backup.download');
check(!str_contains((string)$x['raw'], 'Pad Thai'), 'Backup des Gasts enthält keine fremden Daten');

// ------------------------------------------------------------------ Login / Angemeldet bleiben
section('Anmeldung, Angemeldet bleiben, Logout');
$rem = $owner->cookie('CASHREM');
$c3 = new Client($BASE, array_values(array_filter($owner->cookies(), fn($l) => str_contains($l, "\tCASHREM\t"))));
$s = $c3->status();
check(($s['json']['user']['username'] ?? null) === 'tobias', 'Automatisch angemeldet nur mit Token-Cookie (ohne Session)');
$rotated = $c3->cookie('CASHREM');
check($rotated !== null && $rotated !== $rem, 'Token wurde rotiert');
$c4 = new Client($BASE);
$c4->status();
for ($i = 0; $i < 5; $i++) {
    $r = $c4->post('auth.login', ['username' => 'tobias', 'password' => 'falsch-' . $i]);
}
check($r['status'] === 401, 'Falsches Passwort abgelehnt');
$r = $c4->post('auth.login', ['username' => 'tobias', 'password' => 'sehr-geheim-123']);
check($r['status'] === 429, 'Nach 5 Fehlversuchen gesperrt (auch mit richtigem Passwort)', $r['status']);
$r = $c3->post('auth.logout');
check($r['json']['user'] === null, 'Logout');
$c5 = new Client($BASE, ["127.0.0.1\tFALSE\t/cash/\tFALSE\t0\tCASHREM\t" . $rotated]);
$s = $c5->status();
check($s['json']['user'] === null, 'Logout hat das Token widerrufen');
// Gestohlenes Token: alter Prüfwert nach Ablauf der Gnadenfrist → alles widerrufen (hier: Selektor mit falschem Prüfwert)
$sel = explode(':', (string)$owner->cookie('CASHREM'))[0];
$c6 = new Client($BASE, ["127.0.0.1\tFALSE\t/cash/\tFALSE\t0\tCASHREM\t" . $sel . ':' . str_repeat('0', 64)]);
$s = $c6->status();
check($s['json']['user'] === null, 'Gefälschter Prüfwert wird abgelehnt');
$s = (new Client($BASE, ["127.0.0.1\tFALSE\t/cash/\tFALSE\t0\tCASHREM\t" . $owner->cookie('CASHREM')]))->status();
check($s['json']['user'] === null, 'Bei Missbrauchsverdacht werden alle Tokens des Benutzers widerrufen');
$s = $owner->status();
check(($s['json']['user']['username'] ?? null) === 'tobias', 'Aktive Session bleibt bestehen');

section('Profil und Passwort');
$r = $owner->post('profile.save', ['name' => 'Tobias R.', 'username' => 'gast', 'email' => '']);
check($r['status'] === 409, 'Benutzername eines anderen Accounts nicht übernehmbar');
$r = $owner->post('profile.save', ['name' => 'Tobias R.', 'username' => 'tobias', 'email' => 'tobias@example.ch']);
check($r['status'] === 200 && $r['json']['user']['name'] === 'Tobias R.', 'Profil gespeichert');
$r = $owner->post('password.change', ['current' => 'falsch', 'password' => 'neues-passwort-1']);
check($r['status'] === 403, 'Passwortwechsel braucht aktuelles Passwort');
$r = $owner->post('password.change', ['current' => 'sehr-geheim-123', 'password' => 'neues-passwort-1']);
check($r['status'] === 200, 'Passwort geändert');
$c7 = new Client($BASE);
$c7->status();
$r = $c7->post('auth.login', ['username' => 'gast', 'password' => 'gast-passwort-1', 'remember' => false]);
check($r['status'] === 200 && $c7->cookie('CASHREM') === null, 'Login ohne «Angemeldet bleiben» setzt kein Token');

// ------------------------------------------------------------------ Backup
section('Backup, Wiederherstellung, CSV');
$x = $owner->get('backup.download');
$backup = json_decode((string)$x['raw'], true);
check(($backup['format'] ?? '') === 'cash-backup' && count($backup['data']['transactions']) > 0, 'JSON-Backup heruntergeladen');
$bad = $backup;
$bad['data']['transactions'][0]['amount'] = '12.50';
$r = $owner->post('backup.restore', ['backup' => $bad]);
check($r['status'] === 400, 'Ungültiges Backup (Betrag als Text) abgelehnt', $r['json']['error'] ?? null);
$bad = $backup;
$bad['data']['transactions'][0]['accountId'] = 'gibtsnicht';
$r = $owner->post('backup.restore', ['backup' => $bad]);
check($r['status'] === 400, 'Ungültiges Backup (unbekanntes Konto) abgelehnt');
$r = $owner->post('backup.restore', ['backup' => ['format' => 'x']]);
check($r['status'] === 400, 'Fremdformat abgelehnt');
$countBefore = count(state($owner)['transactions']);
$owner->act('tx.save', ['type' => 'expense', 'amount' => '1', 'date' => $today, 'accountId' => $privat, 'categoryId' => $food, 'description' => 'nach-backup']);
$r = $owner->post('backup.restore', ['backup' => $backup]);
check($r['status'] === 200 && count($r['json']['state']['transactions']) === $countBefore, 'Backup wiederhergestellt', $r['json']['error'] ?? null);
if ($DATA !== '') {
    $files = glob($DATA . '/backups/*/*vor-wiederherstellung*.json.php');
    check(count($files) >= 1, 'Vor der Wiederherstellung wurde eine Sicherung angelegt');
    $raw = file_get_contents($files[0]);
    check(str_starts_with($raw, '<?php http_response_code(404); exit; ?>'), 'Sicherungsdateien beginnen mit PHP-Sperre');
    $perm = substr(sprintf('%o', fileperms(glob($DATA . '/users/*/finance.json.php')[0])), -3);
    check($perm === '600', 'Datendatei hat Rechte 600', $perm);
}
$x = $owner->get('export.csv');
check(str_starts_with((string)$x['raw'], "\xEF\xBB\xBF\"Datum\";\"Typ\""), 'CSV mit BOM und Kopfzeile');
check(str_contains((string)$x['raw'], ';"THB";"38.5"'), 'CSV enthält Originalwährung und Kurs');

// ------------------------------------------------------------------ HTTP-Schutz
section('Direkter Dateizugriff');
$ch = curl_init($BASE . 'app/bootstrap.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$body = curl_exec($ch);
check(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 404 && $body === '', 'app/bootstrap.php liefert 404 ohne Inhalt');
$ch = curl_init($BASE . 'data/index.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_exec($ch);
check(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 404, 'data/ liefert 404');
$ch = curl_init($BASE);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$resp = (string)curl_exec($ch);
check(str_contains($resp, 'Content-Security-Policy:') && str_contains($resp, 'X-Frame-Options: DENY'), 'Sicherheits-Header gesetzt');
$ch = curl_init($BASE . 'api.php?a=auth.status');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$resp = (string)curl_exec($ch);
check((bool)preg_match('/Set-Cookie: CASHSID=[^\r\n]*path=\/cash\/[^\r\n]*HttpOnly[^\r\n]*SameSite=Lax/i', $resp), 'Session-Cookie: Pfad /cash/, HttpOnly, SameSite', null);

// ------------------------------------------------------------------ Frei für Freizeit
section('Frei für Freizeit, wiederkehrende Ausgaben, Sparrate');
$st = state($owner);
$F = fn($st) => $st['summary']['free'];
$f0 = $F($st);
check(accByName($st, 'Privatkonto')['available'] === true && accByName($st, 'E-Jugendsparkonto')['available'] === false, 'Bestehende Konten migriert: Privat zählt, Sparkonto nicht');
check($f0['free'] === $f0['available'] - $f0['fixedOpen'] - $f0['savingsDue'], 'Frei = Alltagskonten − offene Fixkosten − Sparrate', $f0);
check($f0['available'] === accByName($st, 'Privatkonto')['balance'], 'Nur Alltagskonten eingerechnet');

$r = $owner->act('tx.save', ['type' => 'expense', 'amount' => '40', 'date' => $today, 'accountId' => $privat, 'categoryId' => $abo, 'description' => 'Netflix', 'recurring' => true, 'interval' => 'monthly']);
check($r['status'] === 200, 'Wiederkehrende Ausgabe erfasst', $r['json']['error'] ?? null);
$st = $r['json']['state'];
$nb = array_values(array_filter($st['bills'], fn($b) => $b['name'] === 'Netflix'))[0] ?? null;
check($nb && $nb['interval'] === 'monthly' && $nb['amount'] === 4000 && $nb['active'], 'Fixkosten «Netflix» automatisch angelegt');
$nocc = array_values(array_filter($st['occurrences'], fn($o) => $o['billId'] === $nb['id']));
$ntx = array_values(array_filter($st['transactions'], fn($t) => $t['description'] === 'Netflix'));
check(count($ntx) === 1 && ($ntx[0]['link']['kind'] ?? '') === 'bill', 'Genau eine Ausgabe, mit Fixkosten verknüpft');
check(count(array_filter($nocc, fn($o) => $o['dueDate'] === $today && $o['status'] === 'paid')) === 1, 'Heutiger Termin gilt als bezahlt');
check(!array_filter($nocc, fn($o) => $o['status'] === 'open' && $o['dueDate'] <= date('Y-m-t')), 'Kein weiterer offener Termin in diesem Monat');
check(count(array_filter($nocc, fn($o) => $o['dueDate'] === date('Y-m-d', strtotime($today . ' +1 month')) || substr($o['dueDate'], 0, 7) === date('Y-m', strtotime('first day of next month')))) <= 1, 'Nächster Monat höchstens einmal geplant');
check($F($st)['free'] === $f0['free'] - 4000, 'Frei sinkt um die Ausgabe', [$f0['free'], $F($st)['free']]);
check($F($st)['fixedPerMonth'] >= 4000, 'Fixkosten pro Monat enthalten Netflix');

$past = date('Y-m-d', strtotime('-70 days'));
$r = $owner->act('tx.save', ['type' => 'expense', 'amount' => '15', 'date' => $past, 'accountId' => $privat, 'categoryId' => $abo, 'description' => 'Spotify', 'recurring' => true]);
$st = $r['json']['state'];
$sb = array_values(array_filter($st['bills'], fn($b) => $b['name'] === 'Spotify'))[0];
$late = array_filter($st['occurrences'], fn($o) => $o['billId'] === $sb['id'] && $o['status'] === 'open' && $o['dueDate'] < $today);
check(count($late) === 0, 'Nachträglich erfasst: keine erfundenen überfälligen Termine');

// Fixkosten fällig heute: zählen bis bezahlt
$before = $F($st)['free'];
$r = $owner->act('bill.save', ['name' => 'Miete Parkplatz', 'amount' => '100', 'categoryId' => $abo, 'startDate' => $today, 'interval' => 'monthly', 'accountId' => $privat]);
$st = $r['json']['state'];
check($F($st)['free'] === $before - 10000 && $F($st)['fixedOpen'] >= 10000, 'Offene Fixkosten werden abgezogen');
$po = array_values(array_filter($st['occurrences'], fn($o) => $o['billId'] === $r['json']['id'] && $o['dueDate'] === $today))[0];
$r = $owner->act('occ.pay', ['id' => $po['id'], 'date' => $today, 'accountId' => $privat]);
check($F($r['json']['state'])['free'] === $before - 10000, 'Bezahlen ändert «frei» nicht mehr (nur Konto ↓, offen ↓)');

// Sparrate
$st = $r['json']['state'];
$before = $F($st)['free'];
$r = $owner->act('goal.save', ['name' => 'Ferien', 'target' => '1000', 'monthly' => '100']);
$fg = $r['json']['id'];
$st = $r['json']['state'];
check($F($st)['savingsDue'] === $F(state($owner))['savingsDue'] && $F($st)['free'] === $before - 10000, 'Sparrate 100 wird vom freien Geld reserviert');
$spBal = accByName($st, 'E-Jugendsparkonto')['balance'];
$r = $owner->act('goal.allocate', ['goalId' => $fg, 'mode' => 'add', 'amount' => '60', 'date' => $today, 'fromAccountId' => $privat, 'toAccountId' => $spar]);
$st = $r['json']['state'];
$g = array_values(array_filter($st['goals'], fn($x) => $x['id'] === $fg))[0];
check($g['saved'] === 6000 && $g['savedThisMonth'] === 6000 && $g['dueThisMonth'] === 4000, '60 gespart → noch 40 diesen Monat');
check(accByName($st, 'E-Jugendsparkonto')['balance'] === $spBal + 6000, 'Umbuchung aufs Sparkonto erfasst');
check($F($st)['free'] === $before - 10000, '«Gespart» verändert das freie Geld nicht (schon reserviert)');
check($st['summary']['expense'] === state($owner)['summary']['expense'], 'Sparen zählt nicht als Ausgabe');
$gtx = array_values(array_filter($st['transactions'], fn($t) => ($t['link']['kind'] ?? '') === 'goal'))[0];
$r = $owner->act('tx.save', ['id' => $gtx['id'], 'type' => 'transfer', 'amount' => '70', 'date' => $today, 'accountId' => $privat, 'toAccountId' => $spar]);
$g = array_values(array_filter($r['json']['state']['goals'], fn($x) => $x['id'] === $fg))[0];
check($g['saved'] === 7000 && $g['dueThisMonth'] === 3000, 'Geänderte Spar-Umbuchung wird im Ziel übernommen');
$r = $owner->act('tx.delete', ['id' => $gtx['id']]);
$st = $r['json']['state'];
$g = array_values(array_filter($st['goals'], fn($x) => $x['id'] === $fg))[0];
check($g['saved'] === 0 && $g['dueThisMonth'] === 10000 && accByName($st, 'E-Jugendsparkonto')['balance'] === $spBal, 'Gelöschte Spar-Umbuchung entfernt auch die Zuweisung');
$r = $owner->act('goal.save', ['id' => $fg, 'name' => 'Ferien', 'target' => '1000', 'monthly' => '950']);
$g = array_values(array_filter($r['json']['state']['goals'], fn($x) => $x['id'] === $fg))[0];
check($g['dueThisMonth'] === 95000, 'Sparrate änderbar');
$r = $owner->act('goal.save', ['id' => $fg, 'name' => 'Ferien', 'target' => '50', 'monthly' => '950']);
$g = array_values(array_filter($r['json']['state']['goals'], fn($x) => $x['id'] === $fg))[0];
check($g['dueThisMonth'] === 5000, 'Nie mehr reservieren als bis zum Ziel fehlt');

// Alltagskonto umschalten
$st = $r['json']['state'];
$a0 = $F($st)['available'];
$r = $owner->act('account.save', ['id' => $spar, 'name' => 'E-Jugendsparkonto', 'type' => 'savings', 'opening' => '500', 'available' => true]);
$st = $r['json']['state'];
check($F($st)['available'] === $a0 + accByName($st, 'E-Jugendsparkonto')['balance'], 'Konto als Alltagskonto einrechnen');
$owner->act('account.save', ['id' => $spar, 'name' => 'E-Jugendsparkonto', 'type' => 'savings', 'opening' => '500', 'available' => false]);

// Backup mit neuen Feldern
$x = $owner->get('backup.download');
$r = $owner->post('backup.restore', ['backup' => json_decode((string)$x['raw'], true)]);
check($r['status'] === 200, 'Backup mit Fixkosten, Sparraten und Spar-Umbuchungen gültig', $r['json']['error'] ?? null);

section('Kontostand ändern');
$st = state($owner);
$n0 = count($st['transactions']);
$inc0 = $st['summary']['income'];
$exp0 = $st['summary']['expense'];
$sparBal = accByName($st, 'E-Jugendsparkonto')['balance'];
$r = $owner->act('account.setBalance', ['id' => $privat, 'balance' => "2'345.65"]);
check($r['status'] === 200, 'Kontostand gesetzt', $r['json']['error'] ?? null);
$st = $r['json']['state'];
check(accByName($st, 'Privatkonto')['balance'] === 234565, 'Privatkonto zeigt genau 2’345.65');
check(count($st['transactions']) === $n0 && $st['summary']['income'] === $inc0 && $st['summary']['expense'] === $exp0, 'Keine Buchung, keine Einnahme/Ausgabe');
check(accByName($st, 'E-Jugendsparkonto')['balance'] === $sparBal, 'Andere Konten unverändert');
check($st['summary']['free']['available'] === 234565, '«Frei» rechnet mit dem neuen Stand');
$r = $owner->act('tx.save', ['type' => 'expense', 'amount' => '5', 'date' => $today, 'accountId' => $privat, 'categoryId' => $food, 'description' => 'nach Korrektur']);
check(accByName($r['json']['state'], 'Privatkonto')['balance'] === 234065, 'Neue Buchungen rechnen ab dem korrigierten Stand');
$r = $owner->act('account.setBalance', ['id' => $privat, 'balance' => '-12.30']);
check(accByName($r['json']['state'], 'Privatkonto')['balance'] === -1230, 'Negativer Kontostand möglich');
$r = $owner->act('account.setBalance', ['id' => $privat, 'balance' => 'abc']);
check($r['status'] === 400, 'Ungültiger Betrag abgelehnt');
$r = $u2->act('account.setBalance', ['id' => $privat, 'balance' => '0']);
check($r['status'] === 404, 'Fremdes Konto nicht änderbar');
$x = $owner->get('backup.download');
$r = $owner->post('backup.restore', ['backup' => json_decode((string)$x['raw'], true)]);
check($r['status'] === 200 && accByName($r['json']['state'], 'Privatkonto')['balance'] === -1230, 'Backup enthält den korrigierten Stand');

echo "\n" . $passes . ' bestanden, ' . $fails . " fehlgeschlagen\n";
exit($fails ? 1 : 0);
