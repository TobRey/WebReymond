<?php
/**
 * Unit-Tests ohne Webserver: php tests/unit-test.php
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/cash-unit-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
putenv('CASH_DATA_DIR=' . $tmp);
const CASH_ENTRY = true;
require __DIR__ . '/../src/app/bootstrap.php';

$fails = 0;
$passes = 0;
function check(bool $c, string $l, mixed $i = null): void
{
    global $fails, $passes;
    if ($c) { $passes++; echo "  ✓ $l\n"; } else { $fails++; echo "  ✗ $l" . ($i !== null ? ' → ' . json_encode($i) : '') . "\n"; }
}
function throws(callable $f): bool { try { $f(); return false; } catch (Throwable) { return true; } }

echo "== Geld ==\n";
check(Money::parse("1'234.50") === 123450, "1'234.50");
check(Money::parse("1\u{2019}234,5") === 123450, '1’234,5');
check(Money::parse('0.05') === 5, '0.05');
check(Money::parse('12') === 1200, '12');
check(throws(fn() => Money::parse('1.234')), '3 Nachkommastellen abgelehnt');
check(throws(fn() => Money::parse('abc')), 'Text abgelehnt');
check(throws(fn() => Money::parse(12.5)), 'Float abgelehnt (keine Fliesskommazahlen)');
check(Money::parse('-12.30', false, true) === -1230, 'Negativ erlaubt wenn gewünscht');
check(Money::format(123456789) === "1\u{2019}234\u{2019}567.89", 'Schweizer Format', Money::format(123456789));
check(Money::format(-5) === '-0.05', 'Negativ formatiert');
check(Money::parseRate('38,50') === [38500000, '38.5'], 'Kurs 38,50');
check(throws(fn() => Money::parseRate('0')), 'Kurs 0 abgelehnt');
check(Money::fxToChf(100000, 38500000) === 2597, 'THB 1000 @ 38.5 = 25.97');
check(Money::fxToChf(100, 8000000) === 13, '0.125 → 0.13 (half up)');
check(Money::fxToChf(100, 8100000) === 12, '0.1234 → 0.12');
check(Money::fxToChf(12345678, 35123456) === 351494, 'THB 123456.78 @ 35.123456 = 3514.938… → 3514.94', Money::fxToChf(12345678, 35123456));

echo "== Datumslogik ==\n";
check(Finance::addMonths('2026-01-31', 1) === '2026-02-28', '31. Jan + 1 Monat = 28. Feb');
check(Finance::addMonths('2026-01-31', 2) === '2026-03-31', 'Kein Drift: 31. Jan + 2 Monate = 31. März');
check(Finance::addMonths('2024-02-29', 12) === '2025-02-28', 'Schaltjahr');
check(Finance::addMonths('2026-11-15', 3) === '2027-02-15', 'Jahreswechsel');

echo "== Wiederkehrende Rechnungen (lange nicht geöffnet) ==\n";
$d = Finance::blank();
$cat = $d['categories'][0]['id'];
$d['accounts'][] = ['id' => 'a1', 'name' => 'Privat', 'type' => 'checking', 'opening' => 0, 'openingDate' => '2025-01-01', 'archived' => false, 'created' => ''];
Finance::apply('bill.save', ['name' => 'Miete', 'amount' => '1200', 'categoryId' => $cat, 'startDate' => '2025-01-31', 'interval' => 'monthly'], $d, '2025-01-10');
$cnt = fn($d) => count($d['occurrences']);
check($cnt($d) === 1 && $d['occurrences'][0]['dueDate'] === '2025-01-31', 'Erste Fälligkeit erzeugt');
// 14 Monate nicht geöffnet
Finance::generateBills($d, '2026-03-20');
$dates = array_column($d['occurrences'], 'dueDate');
check(count($dates) === count(array_unique($dates)), 'Keine Duplikate nach langer Pause');
check(in_array('2025-02-28', $dates, true) && in_array('2026-02-28', $dates, true) && in_array('2026-03-31', $dates, true), 'Alle Monate inkl. Monatsende nachgeholt');
$n1 = $cnt($d);
Finance::generateBills($d, '2026-03-20');
Finance::generateBills($d, '2026-03-21');
check($cnt($d) === $n1, 'Wiederholter Aufruf erzeugt nichts doppelt', [$n1, $cnt($d)]);
check(max($dates) <= '2026-04-30', 'Nur bis Horizont erzeugt', max($dates));
// Zahlung + Planänderung: bezahlte bleiben, offene werden neu geplant
$oid = $d['occurrences'][0]['id'];
Finance::apply('occ.pay', ['id' => $oid, 'date' => '2025-01-31', 'accountId' => 'a1'], $d, '2026-03-20');
$billId = $d['bills'][0]['id'];
Finance::apply('bill.save', ['id' => $billId, 'name' => 'Miete', 'amount' => '1250', 'categoryId' => $cat, 'startDate' => '2026-04-01', 'interval' => 'monthly'], $d, '2026-03-20');
$open = array_filter($d['occurrences'], fn($o) => $o['status'] === 'open');
$paid = array_filter($d['occurrences'], fn($o) => $o['status'] === 'paid');
check(count($paid) === 1 && count($d['transactions']) === 1, 'Bezahlte Fälligkeit und Buchung bleiben');
check(min(array_column($open, 'dueDate')) === '2026-04-01' && array_values($open)[0]['amount'] === 125000, 'Offene Fälligkeiten nach neuem Plan', array_column($open, 'dueDate'));
// Wöchentlich mit Enddatum
Finance::apply('bill.save', ['name' => 'Kurs', 'amount' => '20', 'categoryId' => $cat, 'startDate' => '2026-01-05', 'interval' => 'weekly', 'endDate' => '2026-02-02'], $d, '2026-06-01');
$k = array_filter($d['occurrences'], fn($o) => $o['billId'] === end($d['bills'])['id']);
check(count($k) === 5, 'Wöchentlich mit Enddatum: 5 Fälligkeiten', count($k));
// Jährlich weit in der Zukunft: mind. die nächste
Finance::apply('bill.save', ['name' => 'Vignette', 'amount' => '40', 'categoryId' => $cat, 'startDate' => '2026-12-01', 'interval' => 'yearly'], $d, '2026-06-01');
$v = array_filter($d['occurrences'], fn($o) => $o['billId'] === end($d['bills'])['id']);
check(count($v) === 1 && array_values($v)[0]['dueDate'] === '2026-12-01', 'Jährlich: nächste Fälligkeit auch ausserhalb des Horizonts');

echo "== Dateisperren: parallele Prozesse ==\n";
$file = $tmp . '/counter.json.php';
$procs = 12;
$per = 40;
$pids = [];
for ($p = 0; $p < $procs; $p++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        for ($i = 0; $i < $per; $i++) {
            Store::mutate($file, function (array &$x) use ($p, $i) { $x['n'] = ($x['n'] ?? 0) + 1; $x['log'][] = "$p-$i"; }, ['n' => 0, 'log' => []]);
        }
        exit(0);
    }
    $pids[] = $pid;
}
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}
$x = Store::read($file);
check($x['n'] === $procs * $per && count($x['log']) === $procs * $per, "Keine verlorenen Updates ({$x['n']} von " . ($procs * $per) . ')');
check(count(glob($tmp . '/counter.json.php.tmp-*')) === 0, 'Keine temporären Dateireste');
check(str_starts_with(file_get_contents($file), Store::GUARD), 'Datei beginnt mit PHP-Sperrzeile');

echo "== Backup-Validierung ==\n";
$exp = ['format' => 'cash-backup', 'version' => 1, 'data' => $d];
check(is_array(Backup::validate($exp)), 'Gültige Daten akzeptiert');
$bad = $exp; $bad['data']['occurrences'][] = $bad['data']['occurrences'][0];
check(throws(fn() => Backup::validate($bad)), 'Doppelte Fälligkeit abgelehnt');
$bad = $exp; $bad['data']['transactions'][0]['link']['occurrenceId'] = 'x';
check(throws(fn() => Backup::validate($bad)), 'Kaputte Rechnungsverknüpfung abgelehnt');
$bad = $exp; $bad['version'] = 99;
check(throws(fn() => Backup::validate($bad)), 'Neuere Version abgelehnt');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$passes bestanden, $fails fehlgeschlagen\n";
exit($fails ? 1 : 0);
