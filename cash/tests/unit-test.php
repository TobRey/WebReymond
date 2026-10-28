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

echo "== Migration bestehender Daten (v1.0) ==\n";
$old = Finance::blank();
$old['accounts'] = [
    ['id' => 'p1', 'name' => 'Privatkonto', 'type' => 'checking', 'opening' => 100000, 'openingDate' => '2026-10-04', 'archived' => false, 'created' => ''],
    ['id' => 's1', 'name' => 'E-Jugendsparkonto', 'type' => 'savings', 'opening' => 50000, 'openingDate' => '2026-10-04', 'archived' => false, 'created' => ''],
];
$old['goals'] = [['id' => 'g1', 'name' => 'Velo', 'target' => 90000, 'targetDate' => null, 'allocations' => [], 'archived' => false, 'created' => '']];
$st = Finance::state($old, '2026-10', '2026-10-24');
check($st['accounts'][0]['available'] === true && $st['accounts'][1]['available'] === false, 'Privatkonto zählt, Sparkonto nicht');
check($st['goals'][0]['monthly'] === 0 && $st['goals'][0]['dueThisMonth'] === 0, 'Bestehendes Sparziel ohne Sparrate');
check($st['summary']['free']['free'] === 100000 && $st['summary']['free']['daysLeft'] === 8, 'Frei = 1000.00, 8 Tage übrig');
check(is_array(Backup::validate(['format' => 'cash-backup', 'version' => 1, 'data' => $old])), 'Alte Sicherung bleibt gültig');

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

echo "== Import: Parser ==\n";
$fx = __DIR__ . '/fixtures/';
$csv = Import::parse(file_get_contents($fx . 'postfinance-klassisch.csv'));
$g = $csv['groups']['CH7609000000123456789'] ?? null;
check($csv['format'] === 'csv' && $g !== null, 'Klassische PostFinance-CSV (Windows-1252) erkannt, IBAN aus Kopfzeilen');
check(count($g['rows']) === 7, '7 Buchungen gelesen, Fusszeilen ignoriert', count($g['rows']));
check($g['closing'] === ['amount' => 252070, 'date' => '2026-10-27'], 'Schlusssaldo = jüngste Zeile (2520.70)', $g['closing']);
$byText = fn($rows, $needle) => array_values(array_filter($rows, fn($r) => str_contains($r['text'], $needle)));
check($byText($g['rows'], 'NETFLIX')[0]['amount'] === -2000 && $byText($g['rows'], 'LOHN')[0]['amount'] === 250000, 'Lastschrift negativ, Gutschrift positiv');
check(Import::cleanText($byText($g['rows'], 'MIGROS')[0]['text']) === 'Migros M Zürich', 'Avisierungstext bereinigt (ohne Kartennummer)', Import::cleanText($byText($g['rows'], 'MIGROS')[0]['text']));
check(!str_contains(Import::cleanText('KAUF/DIENSTLEISTUNG VOM 01.10.2026 KARTEN NR. XXXX1234 SBB'), '1234'), 'Kartennummer entfernt');
$v = Import::parse(file_get_contents($fx . 'postfinance-variante.csv'));
$vg = $v['groups']['CH7609000000123456789'] ?? null;
check($vg && count($vg['rows']) === 2 && $vg['rows'][0]['amount'] === -12900 && $vg['rows'][1]['amount'] === 3000 && $vg['rows'][0]['date'] === '2026-10-28', 'CSV-Variante (UTF-8, ="…") gelesen');
$x = Import::parse(file_get_contents($fx . 'camt053.xml'));
$xg = $x['groups']['CH7609000000123456789'] ?? null;
check($x['format'] === 'camt' && count($xg['rows']) === 7, 'camt.053: 7 gebuchte Einträge, vorgemerkter ignoriert', count($xg['rows'] ?? []));
check($xg['closing'] === ['amount' => 249870, 'date' => '2026-10-31'], 'camt.053: Schlusssaldo CLBD');
check($byText($xg['rows'], 'Anna')[0]['text'] === 'Anna Muster · Pizza Freitag', 'Gegenpartei statt generischem Text');
check(throws(fn() => Import::parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><Document>&e;</Document>')), 'XXE/DTD wird abgelehnt');
check(throws(fn() => Import::parse("Datum;Text\n01.01.2026;x\n")), 'CSV ohne Betragsspalte abgelehnt');
check(throws(fn() => Import::parse(str_replace('Ccy>CHF', 'Ccy>EUR', file_get_contents($fx . 'camt053.xml')))), 'Nicht-CHF-Konto abgelehnt');
foreach (["1'234.50" => 123450, '1.234,50' => 123450, '-23.80' => -2380, '12,5' => 1250, '23.80-' => -2380, '1,234.50' => 123450] as $in => $exp) {
    check(Import::amount($in) === $exp, "Betrag «{$in}»");
}
check(Import::validIban('CH7609000000123456789') && !Import::validIban('CH7709000000123456789'), 'IBAN-Prüfziffer');

echo "== Import: Abgleich, Duplikate, Fixkosten, Kontostand ==\n";
$d = Finance::blank();
$d['accounts'] = [
    ['id' => 'pf', 'name' => 'Privatkonto', 'type' => 'checking', 'available' => true, 'iban' => 'CH7609000000123456789', 'opening' => 30000, 'openingDate' => '2026-10-01', 'archived' => false, 'created' => ''],
    ['id' => 'sp', 'name' => 'Sparkonto', 'type' => 'savings', 'available' => false, 'iban' => 'CH2809000000987654321', 'opening' => 0, 'openingDate' => '2026-10-01', 'archived' => false, 'created' => ''],
];
$catId = fn($n) => array_values(array_filter($d['categories'], fn($c) => $c['name'] === $n))[0]['id'];
Finance::apply('tx.save', ['type' => 'expense', 'amount' => '45.30', 'date' => '2026-10-26', 'accountId' => 'pf', 'categoryId' => $catId('Lebensmittel'), 'description' => 'Migros von Hand'], $d, '2026-10-28');
Finance::apply('bill.save', ['name' => 'Netflix', 'amount' => '20', 'categoryId' => $catId('Abos & Medien'), 'startDate' => '2026-10-15', 'interval' => 'monthly'], $d, '2026-10-28');
$p = Import::buildPreview($d, file_get_contents($fx . 'postfinance-klassisch.csv'), null, 'auszug.csv');
check(($p['accountId'] ?? null) === 'pf', 'Konto automatisch per IBAN erkannt');
$ev = fn($p, $needle) => array_values(array_filter($p['eval'], fn($r) => str_contains(mb_strtolower($r['text']), $needle)));
check($ev($p, 'migros')[0]['status'] === 'match', 'Von Hand erfasste Migros-Ausgabe erkannt (1 Tag Abweichung)');
check($ev($p, 'netflix')[0]['billOccId'] !== null && $ev($p, 'netflix')[0]['categoryId'] === $catId('Abos & Medien'), 'Netflix passt zu offenen Fixkosten');
check($ev($p, 'sparkonto')[0]['type'] === 'transfer' && $ev($p, 'sparkonto')[0]['transferAccountId'] === 'sp', 'Überweisung aufs eigene Sparkonto = Umbuchung');
check($ev($p, 'lohn')[0]['categoryId'] === $catId('Lohn') && $ev($p, 'coop')[0]['categoryId'] === $catId('Lebensmittel') && $ev($p, 'kontoführung')[0]['categoryId'] === $catId('Steuern & Gebühren'), 'Kategorien vorgeschlagen (Lohn, Lebensmittel, Gebühren)');
// Eine Coop-Zeile auf «Freizeit» ändern → wird gelernt
$coop = $ev($p, 'coop')[0];
$res = Import::commit($d, $p, [['i' => $coop['i'], 'action' => 'import', 'type' => 'expense', 'categoryId' => $catId('Freizeit')]], true, '2026-10-28');
check(count($d['transactions']) === 1 + 6, '6 neue Buchungen + 1 verknüpfte (keine Doppelung)', count($d['transactions']));
$migros = array_values(array_filter($d['transactions'], fn($t) => $t['description'] === 'Migros von Hand'))[0];
check(count($migros['imports']) === 1, 'Manuelle Buchung als importiert markiert');
$occ = array_values(array_filter($d['occurrences'], fn($o) => $o['dueDate'] === '2026-10-15'))[0];
check($occ['status'] === 'paid' && $occ['paidAmount'] === 2000, 'Netflix-Fixkosten automatisch als bezahlt markiert');
check(Import::balanceAt($d, 'pf', '2026-10-27') === 252070, 'Kontostand per 27.10. = Bank (2520.70)', Import::balanceAt($d, 'pf', '2026-10-27'));
check(Finance::balances($d)['sp'] === 20000, 'Sparkonto +200 durch Umbuchung');
$st = Finance::state($d, '2026-10', '2026-10-28');
check($st['summary']['income'] === 250000 && $st['summary']['expense'] === 4530 + 2000 + 450 + 450 + 500, 'Einnahmen/Ausgaben korrekt, Umbuchung nicht gezählt', [$st['summary']['income'], $st['summary']['expense']]);
check(($d['settings']['importRules'][Import::merchantKey(Import::cleanText($coop['text']))] ?? null) === $catId('Freizeit'), 'Geänderte Kategorie gelernt');
// camt.053 mit überlappendem Zeitraum
$p2 = Import::buildPreview($d, file_get_contents($fx . 'camt053.xml'), 'pf', 'camt.xml');
$counts = array_count_values(array_column($p2['eval'], 'status'));
check(($counts['duplicate'] ?? 0) === 5 && ($counts['new'] ?? 0) === 2, 'Überlappung CSV ↔ camt: 5 Duplikate, 2 neu (gleiche Coop-Beträge am selben Tag korrekt gezählt)', $counts);
$sbb = $ev($p2, 'sbb')[0];
check($sbb['categoryId'] === $catId('Mobilität'), 'SBB → Mobilität');
$r2 = Import::commit($d, $p2, [], true, '2026-10-31');
check(Finance::balances($d)['pf'] === 249870, 'Kontostand nach camt = Bank-Schlusssaldo 2498.70', Finance::balances($d)['pf']);
$p3 = Import::buildPreview($d, file_get_contents($fx . 'camt053.xml'), 'pf', 'camt.xml');
check(count(array_filter($p3['eval'], fn($r) => $r['status'] !== 'duplicate')) === 0, 'Gleiche Datei nochmals: alles Duplikate');
$n = count($d['transactions']);
Import::undo($d, $r2['batchId']);
check(count($d['transactions']) === $n - 2 && Import::balanceAt($d, 'pf', '2026-10-27') === 252070, 'Import rückgängig: Buchungen und Kontostand-Abgleich zurückgenommen');
$p4 = Import::buildPreview($d, file_get_contents($fx . 'camt053.xml'), 'pf', 'camt.xml');
$c4 = array_count_values(array_column($p4['eval'], 'status'));
check(($c4['new'] ?? 0) === 2, 'Nach Rückgängig erneut importierbar');
// Zeilen vor Kontoeinrichtung
$d2 = $d;
$d2['accounts'][0]['openingDate'] = '2026-10-21';
$d2['transactions'] = [];
$d2['occurrences'] = [];
$p5 = Import::buildPreview($d2, file_get_contents($fx . 'postfinance-klassisch.csv'), 'pf', 'a.csv');
check($ev($p5, 'netflix')[0]['status'] === 'before' && $ev($p5, 'netflix')[0]['action'] === 'skip', 'Buchungen vor der Kontoeinrichtung standardmässig übersprungen (schon im Anfangssaldo)');
check(throws(fn() => Import::buildPreview($d, file_get_contents($fx . 'camt053.xml'), 'sp', 'x.xml')), 'IBAN passt nicht zum gewählten Konto → Fehler');
check(is_array(Backup::validate(['format' => 'cash-backup', 'version' => 1, 'data' => $d])), 'Backup mit Import-Daten gültig');

exec('rm -rf ' . escapeshellarg($tmp));
echo "\n$passes bestanden, $fails fehlgeschlagen\n";
exit($fails ? 1 : 0);
