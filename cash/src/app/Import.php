<?php
declare(strict_types=1);

if (!defined('CASH_ENTRY')) {
    http_response_code(404);
    exit;
}

/**
 * Kontoauszug-Import (PostFinance CSV sowie ISO 20022 camt.053 / camt.052 / camt.054).
 *
 * Ablauf: Datei hochladen → Vorschau (nichts wird gespeichert ausser der Vorschau selbst)
 * → Benutzer bestätigt → Buchungen werden atomar übernommen. Jeder Import ist rückgängig machbar.
 *
 * Duplikate: Pro Konto werden Import-Markierungen (Datum + Betrag mit Vorzeichen) gezählt.
 * Enthält eine Datei k gleiche Zeilen und es gibt bereits m Markierungen, gelten die ersten m
 * als Duplikate – so funktionieren überlappende Zeiträume und auch CSV + camt gemischt.
 *
 * Es werden keine Bankzugangsdaten benötigt oder gespeichert; die Originaldatei wird nach
 * der Verarbeitung verworfen und nie geloggt.
 */
final class Import
{
    public const MAX_BYTES = 5_000_000;
    public const MAX_ROWS = 5000;
    public const MATCH_DAYS = 3;
    public const BILL_DAYS = 10;
    public const PREVIEW_TTL = 7200;

    private static function err(string $msg, int $status = 400): never
    {
        throw new ApiError($msg, $status);
    }

    public static function previewFile(string $uid): string
    {
        Finance::file($uid);   // prüft die Benutzer-ID
        return cash_path('users/' . $uid . '/import-preview.json.php');
    }

    // ================================================================ Hilfen

    public static function compactIban(mixed $v): string
    {
        return is_string($v) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v) ?? '') : '';
    }

    public static function validIban(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }
        $r = substr($iban, 4) . substr($iban, 0, 4);
        $num = '';
        foreach (str_split($r) as $ch) {
            $num .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
        }
        $mod = 0;
        foreach (str_split($num, 7) as $part) {
            $mod = (int)(($mod . $part)) % 97;
        }
        return $mod === 1;
    }

    private static function ch(string $date): string
    {
        return substr($date, 8, 2) . '.' . substr($date, 5, 2) . '.' . substr($date, 0, 4);
    }

    public static function maskIban(string $iban): string
    {
        return strlen($iban) > 8 ? substr($iban, 0, 4) . ' … ' . substr($iban, -4) : $iban;
    }

    /** Betrag aus Bankdateien: "1'234.50", "-23.80", "1.234,50", "1,234.50" → Rappen (mit Vorzeichen). */
    public static function amount(string $v): ?int
    {
        $s = trim(str_replace(["\u{2019}", "'", ' ', "\u{00A0}", "\u{202F}", 'CHF'], '', $v));
        if ($s === '' || $s === '-') {
            return null;
        }
        $neg = false;
        if (str_starts_with($s, '-') || str_starts_with($s, "\u{2212}")) {
            $neg = true;
            $s = ltrim($s, "-\u{2212}");
        } elseif (str_ends_with($s, '-')) {
            $neg = true;
            $s = rtrim($s, '-');
        } elseif (str_starts_with($s, '+')) {
            $s = substr($s, 1);
        }
        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $dec = $lastDot > $lastComma ? '.' : ',';
            $s = str_replace($dec === '.' ? ',' : '.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($lastComma !== false) {
            $s = preg_match('/,\d{1,2}$/', $s) ? str_replace(',', '.', $s) : str_replace(',', '', $s);
        }
        if (!preg_match('/^(\d{1,12})(?:\.(\d{1,2}))?$/', $s, $m)) {
            return null;
        }
        $c = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        if ($c > Money::MAX) {
            return null;
        }
        return $neg ? -$c : $c;
    }

    public static function date(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{2,4})$/', $v, $m)) {
            [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            if ($y < 100) {
                $y += 2000;
            }
        } else {
            return null;
        }
        $s = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        return Finance::isDate($s) ? $s : null;
    }

    /** Lesbarer Text aus PostFinance-Avisierungstexten (Kartennummern entfernt). */
    public static function cleanText(string $t): string
    {
        $t = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t) ?? '';
        $t = preg_replace('/\s+/u', ' ', trim($t)) ?? '';
        $cash = (bool)preg_match('/^BARGELDBEZUG/i', $t);
        $t = preg_replace('/^(KAUF\/DIENSTLEISTUNG|KAUF\/ONLINE[- ]?SHOPPING|KAUF ONLINE|ONLINE[- ]?SHOPPING|BARGELDBEZUG|KAUF)\s+VOM\s+\d{1,2}\.\d{1,2}\.\d{2,4}\s*/i', '', $t) ?? $t;
        $t = preg_replace('/KARTEN?\s*NR\.?\s*[X\d*]+/i', '', $t) ?? $t;
        $t = preg_replace('/\b\d{12,}\b/', '…', $t) ?? $t;
        $t = preg_replace('/^(GUTSCHRIFT|LASTSCHRIFT)\s+(AUFTRAGGEBER|VON|AN)?:?\s*/i', '', $t) ?? $t;
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t, " ;,-");
        if ($t !== '' && mb_strtoupper($t) === $t && preg_match('/\p{L}{3}/u', $t)) {
            $t = mb_convert_case(mb_strtolower($t), MB_CASE_TITLE);   // GROSSBUCHSTABEN → Normal
        }
        if ($cash) {
            $t = 'Bargeldbezug' . ($t !== '' ? ' ' . $t : '');
        }
        return mb_substr($t, 0, 200);
    }

    public static function merchantKey(string $text): string
    {
        $t = mb_strtoupper($text);
        $t = preg_replace('/[^\p{L} ]+/u', ' ', $t) ?? '';
        $words = array_values(array_filter(explode(' ', $t), fn($w) => mb_strlen($w) >= 2));
        return implode(' ', array_slice($words, 0, 2));
    }

    // ================================================================ Parser

    /** Erkennt das Format und liefert ['format','groups'=>[iban => ['iban','closing','rows']]] */
    public static function parse(string $raw): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            self::err('Die Datei ist zu gross (max. 5 MB).', 413);
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (str_starts_with($raw, "PK\x03\x04")) {
            self::err('ZIP-Dateien bitte zuerst entpacken und die XML- oder CSV-Datei hochladen.');
        }
        $head = ltrim(substr($raw, 0, 600));
        if (str_starts_with($head, '<')) {
            return self::parseCamt($raw);
        }
        return self::parseCsv($raw);
    }

    public static function parseCamt(string $xml): array
    {
        // Schutz vor XXE / Entity-Expansion: keine DTDs zulassen.
        if (str_contains($xml, "\0") || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            self::err('Die XML-Datei enthält nicht erlaubte Definitionen oder Zeichen (bitte UTF-8).');
        }
        if (substr_count($xml, '<Ntry') + substr_count($xml, ':Ntry') > self::MAX_ROWS) {
            self::err('Die Datei enthält zu viele Buchungen (max. ' . self::MAX_ROWS . ').');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            self::err('Die XML-Datei konnte nicht gelesen werden.');
        }
        if ($doc->doctype !== null) {   // zweite Linie: keine DTD, egal in welcher Kodierung
            self::err('Die XML-Datei enthält nicht erlaubte Definitionen.');
        }
        $xp = new DOMXPath($doc);
        $q = fn(string $path, ?DOMNode $ctx = null) => $xp->query($path, $ctx);
        $first = function (string $path, DOMNode $ctx) use ($q): string {
            $n = $q($path, $ctx);
            return $n && $n->length ? trim((string)$n->item(0)->textContent) : '';
        };
        $l = fn(string $name) => "*[local-name()='" . $name . "']";
        $stmts = $q('//' . $l('BkToCstmrStmt') . '/' . $l('Stmt') . ' | //' . $l('BkToCstmrAcctRpt') . '/' . $l('Rpt') . ' | //' . $l('BkToCstmrDbtCdtNtfctn') . '/' . $l('Ntfctn'));
        if (!$stmts || $stmts->length === 0) {
            self::err('Keine Kontoauszugsdaten (camt.053/052/054) gefunden.');
        }
        $groups = [];
        $total = 0;
        foreach ($stmts as $st) {
            $iban = self::compactIban($first($l('Acct') . '/' . $l('Id') . '/' . $l('IBAN'), $st));
            $ccy = $first($l('Acct') . '/' . $l('Ccy'), $st);
            if ($ccy !== '' && $ccy !== 'CHF') {
                self::err('Nur CHF-Konten werden unterstützt (Datei: ' . preg_replace('/[^A-Z]/', '', $ccy) . ').');
            }
            $g = $groups[$iban] ?? ['iban' => $iban, 'closing' => null, 'rows' => []];
            foreach ($q($l('Bal'), $st) as $bal) {
                $code = $first($l('Tp') . '/' . $l('CdOrPrtry') . '/' . $l('Cd'), $bal);
                if (!in_array($code, ['CLBD', 'ITBD'], true)) {
                    continue;
                }
                $amt = self::amount($first($l('Amt'), $bal));
                $date = self::date($first($l('Dt') . '/' . $l('Dt'), $bal) ?: $first($l('Dt') . '/' . $l('DtTm'), $bal));
                if ($amt === null || $date === null) {
                    continue;
                }
                if ($first($l('CdtDbtInd'), $bal) === 'DBIT') {
                    $amt = -$amt;
                }
                if ($g['closing'] === null || $date > $g['closing']['date'] || ($code === 'CLBD' && $date === $g['closing']['date'])) {
                    $g['closing'] = ['amount' => $amt, 'date' => $date];
                }
            }
            foreach ($q($l('Ntry'), $st) as $n) {
                $sts = $first($l('Sts'), $n);
                if ($sts !== '' && !str_starts_with($sts, 'BOOK')) {
                    continue;   // nur gebuchte Einträge (keine vorgemerkten)
                }
                $amtNode = $q($l('Amt'), $n);
                $ccyN = $amtNode && $amtNode->length ? $amtNode->item(0)->attributes?->getNamedItem('Ccy')?->nodeValue : null;
                if ($ccyN !== null && $ccyN !== 'CHF') {
                    continue;
                }
                $amt = self::amount($first($l('Amt'), $n));
                $date = self::date($first($l('BookgDt') . '/' . $l('Dt'), $n) ?: $first($l('BookgDt') . '/' . $l('DtTm'), $n) ?: $first($l('ValDt') . '/' . $l('Dt'), $n));
                if ($amt === null || $amt === 0 || $date === null) {
                    continue;
                }
                $credit = $first($l('CdtDbtInd'), $n) === 'CRDT';
                $info = $first($l('AddtlNtryInf'), $n);
                $tx = $q($l('NtryDtls') . '/' . $l('TxDtls'), $n);
                $name = '';
                $ustrd = '';
                $counter = '';
                if ($tx && $tx->length) {
                    $t0 = $tx->item(0);
                    $party = $credit ? 'Dbtr' : 'Cdtr';
                    $name = $first($l('RltdPties') . '/' . $l($party) . '/' . $l('Nm'), $t0)
                        ?: $first($l('RltdPties') . '/' . $l($party) . '/' . $l('Pty') . '/' . $l('Nm'), $t0);
                    $counter = self::compactIban($first($l('RltdPties') . '/' . $l($party . 'Acct') . '/' . $l('Id') . '/' . $l('IBAN'), $t0));
                    $ustrd = $first($l('RmtInf') . '/' . $l('Ustrd'), $t0);
                    if ($info === '') {
                        $info = $first($l('AddtlTxInf'), $t0);
                    }
                }
                $generic = $info === '' || preg_match('/^(GIRO|GUTSCHRIFT|LASTSCHRIFT|E-FINANCE|ÜBERWEISUNG|UEBERWEISUNG|DAUERAUFTRAG|ESR|QR|ZAHLUNG|SAMMELAUFTRAG)/iu', $info);
                $text = ($name !== '' && $generic) ? $name . ($ustrd !== '' ? ' · ' . $ustrd : '') : ($info !== '' ? $info : $name);
                $g['rows'][] = [
                    'date' => $date,
                    'amount' => $credit ? $amt : -$amt,
                    'text' => mb_substr($text, 0, 300),
                    'ref' => mb_substr($first($l('AcctSvcrRef'), $n) ?: $first($l('NtryRef'), $n), 0, 80),
                    'counterIban' => $counter,
                ];
                if (++$total > self::MAX_ROWS) {
                    self::err('Die Datei enthält zu viele Buchungen (max. ' . self::MAX_ROWS . ').');
                }
            }
            $groups[$iban] = $g;
        }
        return ['format' => 'camt', 'groups' => $groups];
    }

    private static function norm(string $h): string
    {
        $h = mb_strtolower(trim($h));
        return strtr($h, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'é' => 'e', 'è' => 'e', 'à' => 'a']);
    }

    private static function cell(string $v): string
    {
        $v = trim($v);
        if (preg_match('/^="(.*)"$/s', $v, $m)) {   // Excel-Schreibweise ="2024-01-31"
            $v = $m[1];
        }
        return trim($v);
    }

    public static function parseCsv(string $raw): array
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = explode("\n", $raw);
        if (count($lines) > self::MAX_ROWS + 50) {
            self::err('Die Datei enthält zu viele Zeilen (max. ' . self::MAX_ROWS . ').');
        }
        $sample = implode("\n", array_slice($lines, 0, 40));
        $counts = [';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t")];
        arsort($counts);
        $sep = array_key_first($counts);
        if ($counts[';'] > 0 && $counts[';'] * 2 >= $counts[$sep]) {
            $sep = ';';
        }

        $iban = '';
        $currency = 'CHF';
        $header = null;
        $map = [];
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                if ($header !== null && $rows) {
                    break;   // Ende der Tabelle (danach folgen Hinweise)
                }
                continue;
            }
            $cells = array_map([self::class, 'cell'], str_getcsv($line, $sep, '"', ''));
            if ($header === null) {
                $joined = self::norm(implode(' ', $cells));
                if (preg_match('/\b(iban|konto)\b/', self::norm($cells[0] ?? '')) && isset($cells[1])) {
                    $cand = self::compactIban($cells[1]);
                    if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $cand)) {
                        $iban = $cand;
                    }
                }
                if (preg_match('/^waehrung/', self::norm($cells[0] ?? '')) && isset($cells[1])) {
                    $currency = strtoupper(trim($cells[1]));
                }
                if (preg_match('/datum|date/', $joined) && preg_match('/gutschrift|lastschrift|belastung|betrag|amount|credit|debit/', $joined)) {
                    $header = $cells;
                    foreach ($cells as $i => $h) {
                        $n = self::norm($h);
                        if (preg_match('/(gutschrift|lastschrift|betrag|saldo|belastung).*\b([a-z]{3})\s*$/', $n, $cm) && $cm[2] !== 'chf') {
                            self::err('Nur CHF-Konten werden unterstützt.');
                        }
                        $set = function (string $key) use (&$map, $i) { if (!isset($map[$key])) { $map[$key] = $i; } };
                        if (str_contains($n, 'buchungsdatum') || $n === 'datum' || $n === 'date' || str_starts_with($n, 'datum')) {
                            $set('date');
                        } elseif (str_contains($n, 'valuta') || str_contains($n, 'value')) {
                            $set('valuta');
                        } elseif (preg_match('/avisierungstext|buchungstext|beschreibung|^text|details|mitteilung|description/', $n)) {
                            $set('text');
                        } elseif (str_contains($n, 'gutschrift') || str_contains($n, 'credit')) {
                            $set('credit');
                        } elseif (str_contains($n, 'lastschrift') || str_contains($n, 'belastung') || str_contains($n, 'debit')) {
                            $set('debit');
                        } elseif (str_contains($n, 'saldo') || str_contains($n, 'kontostand') || str_contains($n, 'balance')) {
                            $set('balance');
                        } elseif (str_contains($n, 'betrag') || str_contains($n, 'amount')) {
                            $set('amount');
                        } elseif (str_contains($n, 'bewegungstyp') || $n === 'typ') {
                            $set('kind');
                        }
                    }
                    if (!isset($map['date']) && isset($map['valuta'])) {
                        $map['date'] = $map['valuta'];
                    }
                    if (!isset($map['date']) || (!isset($map['amount']) && !isset($map['credit']) && !isset($map['debit']))) {
                        self::err('Die CSV-Spalten wurden nicht erkannt (Datum und Betrag nötig).');
                    }
                }
                continue;
            }
            $date = self::date($cells[$map['date']] ?? '');
            if ($date === null) {
                continue;   // Zwischenzeilen oder Fussnoten
            }
            if (isset($map['amount'])) {
                $amt = self::amount($cells[$map['amount']] ?? '');
            } else {
                $cr = self::amount($cells[$map['credit']] ?? '') ?? 0;
                $db = self::amount($cells[$map['debit'] ?? -1] ?? '') ?? 0;
                $amt = abs($cr) - abs($db);
            }
            if ($amt === null || $amt === 0) {
                continue;
            }
            $text = $cells[$map['text'] ?? -1] ?? '';
            if ($text === '' && isset($map['kind'])) {
                $text = $cells[$map['kind']] ?? '';
            }
            $rows[] = [
                'date' => $date,
                'amount' => $amt,
                'text' => mb_substr($text, 0, 300),
                'ref' => '',
                'counterIban' => '',
                'balance' => isset($map['balance']) ? self::amount($cells[$map['balance']] ?? '') : null,
            ];
            if (count($rows) > self::MAX_ROWS) {
                self::err('Die Datei enthält zu viele Buchungen (max. ' . self::MAX_ROWS . ').');
            }
        }
        if ($header === null) {
            self::err('Format nicht erkannt. Unterstützt: PostFinance-CSV und camt.053/052/054 (XML).');
        }
        if ($currency !== '' && $currency !== 'CHF' && preg_match('/^[A-Z]{3}$/', $currency)) {
            self::err('Nur CHF-Konten werden unterstützt.');
        }
        // Schlusssaldo: Saldo der zeitlich letzten Buchung (Reihenfolge der Datei beachten).
        $closing = null;
        $withBal = array_values(array_filter($rows, fn($r) => $r['balance'] !== null));
        if ($withBal) {
            $desc = $withBal[0]['date'] > end($withBal)['date'];
            $maxDate = max(array_column($withBal, 'date'));
            $cands = array_values(array_filter($withBal, fn($r) => $r['date'] === $maxDate));
            $pick = $desc ? $cands[0] : end($cands);
            $closing = ['amount' => $pick['balance'], 'date' => $maxDate];
        }
        foreach ($rows as &$r) {
            unset($r['balance']);
        }
        unset($r);
        return ['format' => 'csv', 'groups' => [$iban => ['iban' => $iban, 'closing' => $closing, 'rows' => $rows]]];
    }

    // ================================================================ Kategorien

    private const RULES_EXPENSE = [
        'Lebensmittel' => ['MIGROS', 'MIGROLINO', 'COOP', 'DENNER', 'ALDI', 'LIDL', 'VOLG', 'SPAR ', 'LANDI', 'AVEC', 'PRIMO', 'FARMY', 'VOI '],
        'Restaurant & Café' => ['RESTAURANT', 'CAFE', 'CAFÉ', 'MCDONALD', 'BURGER KING', 'STARBUCKS', 'SUBWAY', 'PIZZ', 'KEBAB', 'SUSHI', 'TAKE AWAY', 'BAECKEREI', 'BÄCKEREI', 'BAKERY', 'KONDITOREI', 'JUST EAT', 'UBER EATS', 'SMOOD'],
        'Mobilität' => ['SBB', 'CFF', 'ZVV', 'BLS', 'POSTAUTO', 'BERNMOBIL', 'VBZ', 'BVB', 'TPG', 'TL ', 'UBER', 'TAXI', 'TANKSTELLE', 'AVIA', 'SHELL', 'AGROLA', 'SOCAR', 'MIGROL', 'ENI ', 'PARKING', 'PARKHAUS', 'PUBLIBIKE', 'LIME'],
        'Handy & Internet' => ['SWISSCOM', 'SALT', 'SUNRISE', 'YALLO', 'WINGO', 'M-BUDGET MOBILE', 'DIGITAL REPUBLIC', 'INIT7', 'GOMO', 'LYCAMOBILE'],
        'Abos & Medien' => ['NETFLIX', 'SPOTIFY', 'DISNEY', 'APPLE.COM', 'ITUNES', 'YOUTUBE', 'AMAZON PRIME', 'AUDIBLE', 'PLAYSTATION', 'XBOX', 'STEAM', 'NINTENDO', 'DAZN', 'BLUE+', 'TELETOP', 'ZATTOO', 'CHATGPT', 'OPENAI'],
        'Shopping & Kleider' => ['ZALANDO', 'H&M', 'H & M', 'ZARA', 'GALAXUS', 'DIGITEC', 'AMAZON', 'MANOR', 'GLOBUS', 'INTERDISCOUNT', 'MEDIA MARKT', 'MEDIAMARKT', 'IKEA', 'DECATHLON', 'ALIEXPRESS', 'TEMU', 'SHEIN', 'BRACK', 'OCHSNER', 'C&A', 'TOMMY', 'NIKE', 'ADIDAS', 'SNIPES', 'JUMBO', 'BAUHAUS', 'HORNBACH', 'TOPCC', 'FOOTLOCKER'],
        'Gesundheit' => ['APOTHEKE', 'PHARMACIE', 'AMAVITA', 'SUN STORE', 'SUNSTORE', 'DROGERIE', 'ARZT', 'PRAXIS', 'ZAHNARZT', 'SPITAL', 'DENTAL', 'BENU'],
        'Versicherungen' => ['VERSICHERUNG', 'CSS ', 'HELSANA', 'SWICA', 'SANITAS', 'VISANA', 'CONCORDIA', 'ASSURA', 'GROUPE MUTUEL', 'KPT', 'ÖKK', 'OEKK', 'MOBILIAR', 'AXA', 'ALLIANZ', 'GENERALI', 'HELVETIA', 'BALOISE'],
        'Freizeit' => ['KINO', 'CINEMA', 'PATHE', 'KITAG', 'TICKETCORNER', 'TICKETMASTER', 'EVENTIM', 'FITNESS', 'HALLENBAD', 'BADI', 'MUSEUM', 'ZOO ', 'EUROPA-PARK', 'BOWLING', 'CLUB', 'FESTIVAL', 'KLETTER', 'SKI'],
        'Ferien & Reisen' => ['HOTEL', 'AIRBNB', 'BOOKING.COM', 'EASYJET', 'SWISS INTERNATIONAL', 'EDELWEISS', 'RYANAIR', 'LUFTHANSA', 'FLUGHAFEN', 'HOSTEL', 'TRIVAGO', 'EXPEDIA'],
        'Wohnen' => ['MIETE', 'EWZ', 'IWB', 'BKW', 'GROUPE E', 'STROM', 'NEBENKOSTEN'],
        'Steuern & Gebühren' => ['STEUER', 'GEMEINDE', 'SERAFE', 'GEBÜHR', 'GEBUEHR', 'KONTOFÜHRUNG', 'KONTOFUEHRUNG', 'SPESEN', 'PREIS FÜR', 'PREIS FUER'],
        'Bildung' => ['SCHULE', 'KURS', 'UNIVERSIT', 'HOCHSCHULE', 'ORELL', 'THALIA', 'EX LIBRIS', 'BUCHHANDLUNG'],
        'Geschenke' => ['GESCHENK'],
    ];
    private const RULES_INCOME = [
        'Lohn' => ['LOHN', 'SALÄR', 'SALAER', 'SALAIRE', 'GEHALT', 'SALARY'],
        'Taschengeld' => ['TASCHENGELD'],
        'Rückerstattung' => ['RÜCKERSTATTUNG', 'RUECKERSTATTUNG', 'REFUND', 'ERSTATTUNG', 'RETOURE', 'GUTSCHRIFT RÜCK'],
        'Zinsen' => ['ZINS'],
    ];

    private static function categoryByName(array $d, string $type, string $name): ?string
    {
        foreach ($d['categories'] as $c) {
            if ($c['type'] === $type && !$c['archived'] && mb_strtolower($c['name']) === mb_strtolower($name)) {
                return $c['id'];
            }
        }
        return null;
    }

    public static function suggestCategory(array $d, string $type, string $original, string $clean): string
    {
        $key = self::merchantKey($clean);
        $learned = $d['settings']['importRules'][$key] ?? null;
        if ($learned !== null) {
            $i = Finance::find($d['categories'], $learned);
            if ($i !== null && $d['categories'][$i]['type'] === $type && !$d['categories'][$i]['archived']) {
                return $learned;
            }
        }
        $hay = ' ' . mb_strtoupper($original . ' ' . $clean) . ' ';
        foreach ($type === 'income' ? self::RULES_INCOME : self::RULES_EXPENSE as $name => $words) {
            foreach ($words as $w) {
                if (str_contains($hay, $w)) {
                    $id = self::categoryByName($d, $type, $name);
                    if ($id !== null) {
                        return $id;
                    }
                }
            }
        }
        $fallback = self::categoryByName($d, $type, $type === 'income' ? 'Sonstige Einnahmen' : 'Sonstiges');
        if ($fallback !== null) {
            return $fallback;
        }
        foreach ($d['categories'] as $c) {
            if ($c['type'] === $type && !$c['archived']) {
                return $c['id'];
            }
        }
        self::err('Keine passende Kategorie vorhanden.');
    }

    // ================================================================ Vorschau

    /** Wirkung einer Buchung auf ein Konto (Rappen mit Vorzeichen) oder null. */
    private static function effect(array $t, string $acc): ?int
    {
        return match (true) {
            $t['accountId'] === $acc && in_array($t['type'], ['income', 'debt_in'], true) => $t['amount'],
            $t['accountId'] === $acc && in_array($t['type'], ['expense', 'debt_out', 'transfer'], true) => -$t['amount'],
            $t['type'] === 'transfer' && ($t['toAccountId'] ?? null) === $acc => $t['amount'],
            default => null,
        };
    }

    private static function hasMarker(array $t, string $acc): bool
    {
        foreach ($t['imports'] ?? [] as $m) {
            if ($m['account'] === $acc) {
                return true;
            }
        }
        return false;
    }

    private static function dayDiff(string $a, string $b): int
    {
        return (int)abs((strtotime($a) - strtotime($b)) / 86400);
    }

    /** Bewertet alle Zeilen gegen den aktuellen Datenstand. */
    public static function evaluate(array $d, string $accId, array $rows): array
    {
        $ai = Finance::find($d['accounts'], $accId);
        if ($ai === null) {
            self::err('Bitte ein Konto wählen.', 404);
        }
        $acc = $d['accounts'][$ai];
        // Bereits importierte (Datum|Betrag) auf diesem Konto zählen.
        $seen = [];
        foreach ($d['transactions'] as $t) {
            foreach ($t['imports'] ?? [] as $m) {
                if ($m['account'] === $accId) {
                    $k = $m['date'] . '|' . $m['amount'];
                    $seen[$k] = ($seen[$k] ?? 0) + 1;
                }
            }
        }
        $ibans = [];
        foreach ($d['accounts'] as $a) {
            if (!empty($a['iban']) && $a['id'] !== $accId && !$a['archived']) {
                $ibans[$a['iban']] = $a['id'];
            }
        }
        $usedTx = [];
        $usedOcc = [];
        $out = [];
        foreach ($rows as $i => $r) {
            $clean = self::cleanText($r['text']) ?: ($r['amount'] > 0 ? 'Gutschrift' : 'Belastung');
            $k = $r['date'] . '|' . $r['amount'];
            $row = ['i' => $i, 'date' => $r['date'], 'amount' => $r['amount'], 'text' => $clean, 'status' => 'new',
                'action' => 'import', 'type' => $r['amount'] > 0 ? 'income' : 'expense', 'categoryId' => null,
                'transferAccountId' => null, 'matchTxId' => null, 'matchLabel' => null, 'billOccId' => null, 'billLabel' => null];
            if (($seen[$k] ?? 0) > 0) {
                $seen[$k]--;
                $row['status'] = 'duplicate';
                $row['action'] = 'skip';
                $out[] = $row;
                continue;
            }
            if (!empty($acc['openingDate']) && $r['date'] < $acc['openingDate']) {
                $row['status'] = 'before';
                $row['action'] = 'skip';
            }
            // Bereits manuell erfasst?
            $best = null;
            foreach ($d['transactions'] as $t) {
                if (isset($usedTx[$t['id']]) || self::hasMarker($t, $accId) || self::effect($t, $accId) !== $r['amount']) {
                    continue;
                }
                $dd = self::dayDiff($t['date'], $r['date']);
                if ($dd <= self::MATCH_DAYS && ($best === null || $dd < $best[0])) {
                    $best = [$dd, $t];
                }
            }
            if ($best !== null && $row['status'] !== 'before') {
                $t = $best[1];
                $usedTx[$t['id']] = true;
                $row['status'] = $t['type'] === 'transfer' ? 'transfer' : 'match';
                $row['action'] = 'link';
                $row['matchTxId'] = $t['id'];
                $label = $t['description'] !== '' ? $t['description'] : ($t['type'] === 'transfer' ? 'Umbuchung' : 'Buchung');
                $row['matchLabel'] = mb_substr($label, 0, 80) . ' · ' . self::ch($t['date']);
                $out[] = $row;
                continue;
            }
            // Umbuchung zwischen eigenen Konten?
            $other = $r['counterIban'] !== '' ? ($ibans[$r['counterIban']] ?? null) : null;
            if ($other === null) {
                foreach ($ibans as $ib => $id) {
                    if (str_contains(self::compactIban($r['text']), $ib)) {
                        $other = $id;
                        break;
                    }
                }
            }
            if ($other !== null) {
                $row['type'] = 'transfer';
                $row['transferAccountId'] = $other;
            } else {
                $row['categoryId'] = self::suggestCategory($d, $row['type'], $r['text'], $clean);
                // Offene Fixkosten mit gleichem Betrag?
                if ($r['amount'] < 0 && $row['status'] !== 'before') {
                    $bestO = null;
                    foreach ($d['occurrences'] as $o) {
                        if ($o['status'] !== 'open' || isset($usedOcc[$o['id']]) || $o['amount'] !== -$r['amount']) {
                            continue;
                        }
                        $dd = self::dayDiff($o['dueDate'], $r['date']);
                        if ($dd <= self::BILL_DAYS && ($bestO === null || $dd < $bestO[0])) {
                            $bestO = [$dd, $o];
                        }
                    }
                    if ($bestO !== null) {
                        $o = $bestO[1];
                        $usedOcc[$o['id']] = true;
                        $bi = Finance::find($d['bills'], $o['billId']);
                        $row['billOccId'] = $o['id'];
                        $row['billLabel'] = ($bi !== null ? $d['bills'][$bi]['name'] : 'Fixkosten') . ' · fällig ' . self::ch($o['dueDate']);
                        if ($bi !== null) {
                            $row['categoryId'] = $d['bills'][$bi]['categoryId'];
                        }
                    }
                }
            }
            $out[] = $row;
        }
        return $out;
    }

    public static function balanceAt(array $d, string $accId, string $date): int
    {
        $i = Finance::find($d['accounts'], $accId);
        $bal = $i === null ? 0 : $d['accounts'][$i]['opening'];
        foreach ($d['transactions'] as $t) {
            if ($t['date'] <= $date) {
                $bal += self::effect($t, $accId) ?? 0;
            }
        }
        return $bal;
    }

    /** Wählt die Kontogruppe aus der Datei passend zum Konto. */
    private static function pickGroup(array $d, array $parsed, ?string $accId): array
    {
        $groups = $parsed['groups'];
        $acc = $accId !== null ? (Finance::find($d['accounts'], $accId)) : null;
        $accIban = $acc !== null ? ($d['accounts'][$acc]['iban'] ?? '') : '';
        if ($accIban !== '' && isset($groups[$accIban])) {
            return $groups[$accIban];
        }
        $withData = array_values(array_filter($groups, fn($g) => $g['rows'] || $g['closing']));
        if (count($withData) === 1) {
            return $withData[0];
        }
        if (!$withData) {
            self::err('Die Datei enthält keine gebuchten Bewegungen.');
        }
        self::err('Die Datei enthält mehrere Konten (' . implode(', ', array_map(fn($g) => self::maskIban($g['iban']), $withData))
            . '). Bitte beim Konto in CASH die passende IBAN hinterlegen.');
    }

    public static function buildPreview(array $d, string $raw, ?string $accId, string $filename): array
    {
        Finance::normalize($d);
        $parsed = self::parse($raw);
        // Konto anhand der IBAN erkennen, falls keines gewählt wurde.
        if ($accId === null) {
            foreach ($parsed['groups'] as $g) {
                foreach ($d['accounts'] as $a) {
                    if (!$a['archived'] && !empty($a['iban']) && $a['iban'] === $g['iban']) {
                        $accId = $a['id'];
                        break 2;
                    }
                }
            }
        }
        if ($accId !== null && Finance::find($d['accounts'], $accId) === null) {
            self::err('Das Konto wurde nicht gefunden.', 404);
        }
        $group = self::pickGroup($d, $parsed, $accId);
        if ($accId === null) {
            return ['needAccount' => true, 'iban' => self::maskIban($group['iban']), 'format' => $parsed['format']];
        }
        $ai = Finance::find($d['accounts'], $accId);
        if ($ai === null) {
            self::err('Das Konto wurde nicht gefunden.', 404);
        }
        if ($d['accounts'][$ai]['archived']) {
            self::err('Bitte ein aktives Konto wählen.');
        }
        $accIban = $d['accounts'][$ai]['iban'] ?? '';
        if ($accIban !== '' && $group['iban'] !== '' && $accIban !== $group['iban']) {
            self::err('Die IBAN der Datei (' . self::maskIban($group['iban']) . ') passt nicht zu «' . $d['accounts'][$ai]['name'] . '».');
        }
        $rows = $group['rows'];
        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']));
        $eval = self::evaluate($d, $accId, $rows);
        $dates = array_column($rows, 'date');
        $closing = $group['closing'];
        $balanceInfo = null;
        if ($closing !== null) {
            $balanceInfo = ['date' => $closing['date'], 'bank' => $closing['amount']];
        }
        return [
            'id' => bin2hex(random_bytes(12)),
            'created' => time(),
            'accountId' => $accId,
            'format' => $parsed['format'],
            'iban' => $group['iban'],
            'filename' => mb_substr(preg_replace('/[^\p{L}\p{N} ._\-]/u', '', $filename) ?? '', 0, 80),
            'from' => $dates ? min($dates) : null,
            'to' => $dates ? max($dates) : null,
            'closing' => $closing,
            'balanceInfo' => $balanceInfo,
            'rows' => $rows,
            'eval' => $eval,
        ];
    }

    /** Für die Ausgabe an den Browser (ohne Rohdaten). */
    public static function publicPreview(array $p, array $d): array
    {
        $counts = array_count_values(array_column($p['eval'], 'status'));
        return [
            'id' => $p['id'], 'accountId' => $p['accountId'], 'format' => $p['format'], 'iban' => self::maskIban($p['iban']),
            'from' => $p['from'], 'to' => $p['to'], 'closing' => $p['closing'], 'counts' => $counts, 'rows' => $p['eval'],
        ];
    }

    // ================================================================ Übernehmen

    public static function commit(array &$d, array $p, array $decisions, bool $syncBalance, string $today): array
    {
        Finance::normalize($d);
        $accId = $p['accountId'];
        $ai = Finance::find($d['accounts'], $accId);
        if ($ai === null) {
            self::err('Das Konto existiert nicht mehr.', 409);
        }
        $eval = self::evaluate($d, $accId, $p['rows']);   // gegen den aktuellen Stand neu prüfen
        $byI = [];
        foreach ($decisions as $dec) {
            if (is_array($dec) && isset($dec['i']) && is_int($dec['i'])) {
                $byI[$dec['i']] = $dec;
            }
        }
        $batch = cash_new_id();
        $created = [];
        $linked = [];
        $paidOcc = [];
        $skipped = 0;
        $now = date('c');
        foreach ($eval as $row) {
            $raw = $p['rows'][$row['i']];
            $dec = $byI[$row['i']] ?? ['action' => $row['action']];
            $action = in_array($dec['action'] ?? '', ['import', 'link', 'skip'], true) ? $dec['action'] : $row['action'];
            if ($row['status'] === 'duplicate' || $action === 'skip') {
                $skipped++;
                continue;
            }
            $marker = ['account' => $accId, 'batch' => $batch, 'date' => $raw['date'], 'amount' => $raw['amount'],
                'ref' => $raw['ref'] ?? '', 'text' => mb_substr($raw['text'], 0, 300)];
            if ($action === 'link') {
                $ti = $row['matchTxId'] !== null ? Finance::find($d['transactions'], $row['matchTxId']) : null;
                if ($ti === null) {
                    $skipped++;
                    continue;
                }
                $d['transactions'][$ti]['imports'][] = $marker;
                $linked[] = $row['matchTxId'];
                continue;
            }
            $type = in_array($dec['type'] ?? '', ['income', 'expense', 'transfer'], true) ? $dec['type'] : $row['type'];
            if ($type !== 'transfer' && ($type === 'income') !== ($raw['amount'] > 0)) {
                $type = $raw['amount'] > 0 ? 'income' : 'expense';   // Richtung folgt immer der Bank
            }
            $amount = abs($raw['amount']);
            $tx = [
                'id' => cash_new_id(), 'type' => $type, 'date' => $raw['date'], 'amount' => $amount,
                'accountId' => $accId, 'toAccountId' => null, 'categoryId' => null, 'budgetId' => null,
                'description' => $row['text'], 'fx' => null, 'link' => null, 'imports' => [$marker],
                'created' => $now, 'updated' => $now,
            ];
            if ($type === 'transfer') {
                $other = is_string($dec['transferAccountId'] ?? null) ? $dec['transferAccountId'] : $row['transferAccountId'];
                $oi = $other !== null ? Finance::find($d['accounts'], $other) : null;
                if ($oi === null || $other === $accId || $d['accounts'][$oi]['archived']) {
                    self::err('Zeile vom ' . self::ch($raw['date']) . ': bitte das Gegenkonto der Umbuchung wählen.');
                }
                if ($raw['amount'] < 0) {
                    $tx['toAccountId'] = $other;
                } else {
                    $tx['accountId'] = $other;
                    $tx['toAccountId'] = $accId;
                }
            } else {
                $cat = is_string($dec['categoryId'] ?? null) ? $dec['categoryId'] : $row['categoryId'];
                $ci = $cat !== null ? Finance::find($d['categories'], $cat) : null;
                if ($ci === null || $d['categories'][$ci]['type'] !== $type) {
                    $cat = self::suggestCategory($d, $type, $raw['text'], $row['text']);
                }
                $tx['categoryId'] = $cat;
                // Nur bewusst geänderte Kategorien lernen (sonst überschreibt eine zweite,
                // unveränderte Zeile desselben Händlers die Korrektur).
                $key = self::merchantKey($row['text']);
                if ($key !== '' && $cat !== $row['categoryId']) {
                    $d['settings']['importRules'][$key] = $cat;
                }
                $payBill = ($dec['payBill'] ?? true) && $type === 'expense' && $row['billOccId'] !== null;
                if ($payBill) {
                    $oi = Finance::find($d['occurrences'], $row['billOccId']);
                    if ($oi !== null && $d['occurrences'][$oi]['status'] === 'open') {
                        $o = &$d['occurrences'][$oi];
                        $o['status'] = 'paid';
                        $o['paidDate'] = $raw['date'];
                        $o['paidAmount'] = $amount;
                        $o['accountId'] = $accId;
                        $o['txId'] = $tx['id'];
                        $tx['link'] = ['kind' => 'bill', 'billId' => $o['billId'], 'occurrenceId' => $o['id']];
                        $paidOcc[] = $o['id'];
                        unset($o);
                    }
                }
            }
            $d['transactions'][] = $tx;
            $created[] = $tx['id'];
        }
        if (count($d['settings']['importRules']) > 400) {
            $d['settings']['importRules'] = array_slice($d['settings']['importRules'], -400, null, true);
        }
        // IBAN beim Konto merken.
        if (empty($d['accounts'][$ai]['iban']) && $p['iban'] !== '' && self::validIban($p['iban'])) {
            $d['accounts'][$ai]['iban'] = $p['iban'];
        }
        // Kontostand mit dem Schlusssaldo der Bank abgleichen.
        $diff = 0;
        if ($syncBalance && $p['closing'] !== null) {
            $diff = $p['closing']['amount'] - self::balanceAt($d, $accId, $p['closing']['date']);
            $d['accounts'][$ai]['opening'] += $diff;
        }
        $d['imports'][] = [
            'id' => $batch, 'created' => $now, 'accountId' => $accId, 'format' => $p['format'],
            'filename' => $p['filename'], 'from' => $p['from'], 'to' => $p['to'],
            'createdTx' => $created, 'linkedTx' => $linked, 'paidOcc' => $paidOcc,
            'skipped' => $skipped, 'balanceDiff' => $diff, 'closing' => $p['closing'],
        ];
        $d['imports'] = array_slice($d['imports'], -50);
        $msg = count($created) . ' Buchung' . (count($created) === 1 ? '' : 'en') . ' importiert';
        if ($linked) {
            $msg .= ', ' . count($linked) . ' bereits erfasste verknüpft';
        }
        if ($skipped) {
            $msg .= ', ' . $skipped . ' übersprungen';
        }
        if ($syncBalance && $p['closing'] !== null) {
            $msg .= '. Kontostand per ' . self::ch($p['closing']['date']) . ' = CHF ' . Money::format($p['closing']['amount']);
        }
        return ['message' => $msg . '.', 'batchId' => $batch];
    }

    public static function undo(array &$d, string $batchId): array
    {
        $bi = Finance::find($d['imports'], $batchId);
        if ($bi === null) {
            self::err('Dieser Import wurde nicht gefunden.', 404);
        }
        $b = $d['imports'][$bi];
        $createdSet = array_flip($b['createdTx']);
        foreach ($d['occurrences'] as &$o) {
            if ($o['status'] === 'paid' && $o['txId'] !== null && isset($createdSet[$o['txId']])) {
                $o['status'] = 'open';
                $o['paidDate'] = null;
                $o['paidAmount'] = null;
                $o['accountId'] = null;
                $o['txId'] = null;
            }
        }
        unset($o);
        $removed = 0;
        $d['transactions'] = array_values(array_filter($d['transactions'], function ($t) use ($createdSet, &$removed) {
            if (isset($createdSet[$t['id']])) {
                $removed++;
                return false;
            }
            return true;
        }));
        foreach ($d['transactions'] as &$t) {
            if (!empty($t['imports'])) {
                $t['imports'] = array_values(array_filter($t['imports'], fn($m) => $m['batch'] !== $batchId));
            }
        }
        unset($t);
        $ai = Finance::find($d['accounts'], $b['accountId']);
        if ($ai !== null) {
            $d['accounts'][$ai]['opening'] -= (int)$b['balanceDiff'];
        }
        array_splice($d['imports'], $bi, 1);
        return ['message' => 'Import rückgängig gemacht (' . $removed . ' Buchungen entfernt).'];
    }
}
