<?php
declare(strict_types=1);

/**
 * Backups (Download, automatische Tagessicherung, validierte Wiederherstellung) und CSV-Export.
 */
final class Backup
{
    public const FORMAT = 'cash-backup';
    private const KEEP_AUTO = 30;
    private const KEEP_MANUAL = 20;

    private static function dir(string $uid): string
    {
        $dir = cash_path('backups/' . $uid);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
            cash_protect_dir($dir);
        }
        return $dir;
    }

    /** Legt eine Kopie der aktuellen Daten an. */
    public static function snapshot(string $uid, string $reason): ?string
    {
        $src = Finance::file($uid);
        if (!is_file($src)) {
            return null;
        }
        $data = Store::read($src);
        $name = date('Ymd-His') . '-' . preg_replace('/[^a-z\-]/', '', $reason) . '-' . bin2hex(random_bytes(3)) . '.json.php';
        $file = self::dir($uid) . '/' . $name;
        Store::replace($file, $data);
        @unlink($file . '.lock');
        self::prune($uid);
        return $name;
    }

    /** Einmal pro Tag vor der ersten Änderung eine Sicherung anlegen. */
    public static function dailySnapshot(string $uid): void
    {
        $dir = self::dir($uid);
        if (glob($dir . '/' . date('Ymd') . '-*-auto-*.json.php')) {
            return;
        }
        self::snapshot($uid, 'auto');
    }

    private static function prune(string $uid): void
    {
        $dir = self::dir($uid);
        foreach (['auto' => self::KEEP_AUTO, 'vor-wiederherstellung' => self::KEEP_MANUAL] as $kind => $keep) {
            $files = glob($dir . '/*-' . $kind . '-*.json.php') ?: [];
            sort($files);
            while (count($files) > $keep) {
                @unlink(array_shift($files));
            }
        }
    }

    public static function list(string $uid): array
    {
        $files = glob(self::dir($uid) . '/*.json.php') ?: [];
        rsort($files);
        $out = [];
        foreach (array_slice($files, 0, 50) as $f) {
            $b = basename($f);
            if (preg_match('/^(\d{8})-(\d{6})-([a-z\-]+)-/', $b, $m)) {
                $out[] = [
                    'date' => DateTimeImmutable::createFromFormat('Ymd His', $m[1] . ' ' . $m[2])->format('c'),
                    'kind' => $m[3] === 'auto' ? 'Automatische Tagessicherung' : 'Vor Wiederherstellung',
                    'size' => filesize($f),
                ];
            }
        }
        return $out;
    }

    public static function export(string $uid, array $user): array
    {
        $data = Store::read(Finance::file($uid), Finance::blank());
        Finance::normalize($data);
        unset($data['requests']);
        return [
            'format' => self::FORMAT,
            'version' => Finance::VERSION,
            'app' => 'CASH ' . CASH_VERSION,
            'exportedAt' => date('c'),
            'user' => ['name' => $user['name'], 'username' => $user['username']],
            'data' => $data,
        ];
    }

    /** Validiert eine Sicherung vollständig. Gibt die bereinigten Daten zurück oder wirft ApiError. */
    public static function validate(mixed $payload): array
    {
        if (!is_array($payload) || ($payload['format'] ?? null) !== self::FORMAT || !is_array($payload['data'] ?? null)) {
            throw new ApiError('Das ist keine gültige CASH-Sicherung.');
        }
        if ((int)($payload['version'] ?? 0) > Finance::VERSION) {
            throw new ApiError('Die Sicherung stammt aus einer neueren CASH-Version.');
        }
        $d = $payload['data'];
        $fail = function (string $where) {
            throw new ApiError('Sicherung ungültig: ' . $where . '.');
        };
        $isId = fn($v) => is_string($v) && preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $v);
        $isStr = fn($v, int $max) => is_string($v) && mb_strlen($v) <= $max;
        $isAmt = fn($v, bool $neg = false) => is_int($v) && abs($v) <= Money::MAX && ($neg || $v >= 0);
        $isDate = fn($v) => Finance::isDate($v);
        $optDate = fn($v) => $v === null || Finance::isDate($v);
        $list = function ($v, string $name) use ($fail) {
            if (!is_array($v) || !array_is_list($v)) {
                $fail($name . ' fehlt');
            }
            return $v;
        };
        $ids = function (array $items, string $name) use ($isId, $fail) {
            $set = [];
            foreach ($items as $k => $it) {
                if (!is_array($it) || !$isId($it['id'] ?? null) || isset($set[$it['id']])) {
                    $fail($name . ' #' . ($k + 1) . ' (ID)');
                }
                $set[$it['id']] = $it;
            }
            return $set;
        };
        foreach (['accounts', 'categories', 'transactions', 'budgets', 'bills', 'occurrences', 'goals', 'debts'] as $k) {
            $d[$k] = $list($d[$k] ?? null, $k);
        }
        $acc = $ids($d['accounts'], 'Konto');
        foreach ($d['accounts'] as $a) {
            if (!$isStr($a['name'] ?? null, 60) || $a['name'] === '' || !in_array($a['type'] ?? '', Finance::ACCOUNT_TYPES, true)
                || !$isAmt($a['opening'] ?? null, true) || !is_bool($a['archived'] ?? null) || !$optDate($a['openingDate'] ?? null)) {
                $fail('Konto «' . (is_string($a['name'] ?? null) ? $a['name'] : '?') . '»');
            }
        }
        $cat = $ids($d['categories'], 'Kategorie');
        foreach ($d['categories'] as $c) {
            if (!$isStr($c['name'] ?? null, 40) || !in_array($c['type'] ?? '', ['income', 'expense'], true)
                || !is_bool($c['archived'] ?? null) || !in_array($c['icon'] ?? '', Finance::ICONS, true)) {
                $fail('Kategorie');
            }
        }
        $bud = $ids($d['budgets'], 'Budget');
        foreach ($d['budgets'] as $b) {
            $ok = in_array($b['kind'] ?? '', ['monthly', 'custom'], true) && $isStr($b['name'] ?? null, 60)
                && $isAmt($b['amount'] ?? null) && is_bool($b['archived'] ?? null)
                && $optDate($b['startDate'] ?? null) && $optDate($b['endDate'] ?? null)
                && (($b['inputCurrency'] ?? null) === null || in_array($b['inputCurrency'], Finance::CURRENCIES, true))
                && $isStr($b['note'] ?? '', 200);
            if ($ok && $b['kind'] === 'monthly') {
                $ok = isset($cat[$b['categoryId'] ?? '']) && $cat[$b['categoryId']]['type'] === 'expense';
            }
            if (!$ok) {
                $fail('Budget «' . (is_string($b['name'] ?? null) ? $b['name'] : '?') . '»');
            }
        }
        $bills = $ids($d['bills'], 'Rechnung');
        foreach ($d['bills'] as $b) {
            if (!$isStr($b['name'] ?? null, 80) || !$isAmt($b['amount'] ?? null) || !isset($cat[$b['categoryId'] ?? ''])
                || !$isDate($b['startDate'] ?? null) || !in_array($b['interval'] ?? '', Finance::INTERVALS, true)
                || !$optDate($b['endDate'] ?? null) || !is_bool($b['active'] ?? null) || !$optDate($b['generatedUntil'] ?? null)
                || (($b['accountId'] ?? null) !== null && !isset($acc[$b['accountId']]))) {
                $fail('Rechnung «' . (is_string($b['name'] ?? null) ? $b['name'] : '?') . '»');
            }
        }
        $tx = $ids($d['transactions'], 'Buchung');
        $occ = $ids($d['occurrences'], 'Fälligkeit');
        $debts = $ids($d['debts'], 'Schuld');
        foreach ($d['transactions'] as $n => $t) {
            $where = 'Buchung #' . ($n + 1);
            $type = $t['type'] ?? '';
            if (!in_array($type, Finance::TX_TYPES, true) || !$isDate($t['date'] ?? null) || !$isAmt($t['amount'] ?? null)
                || $t['amount'] === 0 || !isset($acc[$t['accountId'] ?? '']) || !$isStr($t['description'] ?? '', 200)) {
                $fail($where);
            }
            if ($type === 'transfer' && (!isset($acc[$t['toAccountId'] ?? '']) || $t['toAccountId'] === $t['accountId'])) {
                $fail($where . ' (Umbuchung)');
            }
            if (in_array($type, ['income', 'expense'], true)
                && (!isset($cat[$t['categoryId'] ?? '']) || $cat[$t['categoryId']]['type'] !== $type)) {
                $fail($where . ' (Kategorie)');
            }
            if (($t['budgetId'] ?? null) !== null && ($type !== 'expense' || !isset($bud[$t['budgetId']]))) {
                $fail($where . ' (Budget)');
            }
            $fx = $t['fx'] ?? null;
            if ($fx !== null && (!is_array($fx) || !in_array($fx['currency'] ?? '', Finance::CURRENCIES, true)
                    || !$isAmt($fx['original'] ?? null) || !is_int($fx['rateMicro'] ?? null) || $fx['rateMicro'] <= 0 || !is_string($fx['rate'] ?? null))) {
                $fail($where . ' (Währung)');
            }
            $link = $t['link'] ?? null;
            if ($link !== null) {
                $kind = $link['kind'] ?? '';
                if ($kind === 'bill') {
                    if ($type !== 'expense' || !isset($bills[$link['billId'] ?? '']) || !isset($occ[$link['occurrenceId'] ?? ''])) {
                        $fail($where . ' (Rechnung)');
                    }
                } elseif ($kind === 'debt') {
                    if (!in_array($type, ['debt_in', 'debt_out'], true) || !isset($debts[$link['debtId'] ?? ''])) {
                        $fail($where . ' (Schuld)');
                    }
                } else {
                    $fail($where . ' (Verknüpfung)');
                }
            } elseif (in_array($type, ['debt_in', 'debt_out'], true)) {
                $fail($where . ' (Schuld ohne Verknüpfung)');
            }
        }
        $seenOcc = [];
        foreach ($d['occurrences'] as $o) {
            $key = ($o['billId'] ?? '') . '|' . ($o['dueDate'] ?? '');
            if (!isset($bills[$o['billId'] ?? '']) || !$isDate($o['dueDate'] ?? null) || !$isAmt($o['amount'] ?? null)
                || !in_array($o['status'] ?? '', ['open', 'paid', 'skipped'], true) || isset($seenOcc[$key])) {
                $fail('Fälligkeit');
            }
            $seenOcc[$key] = true;
            if ($o['status'] === 'paid') {
                $t = $tx[$o['txId'] ?? ''] ?? null;
                if (!$t || ($t['link']['occurrenceId'] ?? null) !== $o['id']) {
                    $fail('Bezahlte Fälligkeit ohne Buchung');
                }
            }
        }
        $ids($d['goals'], 'Sparziel');
        foreach ($d['goals'] as $g) {
            if (!$isStr($g['name'] ?? null, 60) || !$isAmt($g['target'] ?? null) || !$optDate($g['targetDate'] ?? null)
                || !is_array($g['allocations'] ?? null) || !array_is_list($g['allocations'])) {
                $fail('Sparziel');
            }
            $ids($g['allocations'], 'Sparzuweisung');
            $sum = 0;
            foreach ($g['allocations'] as $al) {
                if (!$isAmt($al['amount'] ?? null, true) || !$isDate($al['date'] ?? null) || !$isStr($al['note'] ?? '', 120)) {
                    $fail('Sparzuweisung');
                }
                $sum += $al['amount'];
            }
            if ($sum < 0) {
                $fail('Sparziel mit negativem Betrag');
            }
        }
        foreach ($d['debts'] as $x) {
            if (!in_array($x['direction'] ?? '', ['i_owe', 'owed_to_me'], true) || !$isStr($x['person'] ?? null, 60)
                || !$isAmt($x['amount'] ?? null) || !$isDate($x['date'] ?? null) || !$optDate($x['dueDate'] ?? null)
                || !is_array($x['payments'] ?? null) || !array_is_list($x['payments'])
                || (($x['originAccountId'] ?? null) !== null && !isset($acc[$x['originAccountId']]))) {
                $fail('Schuld');
            }
            $ids($x['payments'], 'Teilzahlung');
            $paid = 0;
            foreach ($x['payments'] as $p) {
                if (!$isAmt($p['amount'] ?? null) || !$isDate($p['date'] ?? null)
                    || (($p['accountId'] ?? null) !== null && !isset($acc[$p['accountId']]))
                    || (($p['txId'] ?? null) !== null && !isset($tx[$p['txId']]))) {
                    $fail('Teilzahlung');
                }
                $paid += $p['amount'];
            }
            if ($paid > $x['amount']) {
                $fail('Schuld mit Überzahlung');
            }
        }
        $settings = is_array($d['settings'] ?? null) ? $d['settings'] : [];
        $rates = [];
        foreach ((array)($settings['lastRates'] ?? []) as $cur => $r) {
            if (in_array($cur, Finance::CURRENCIES, true) && is_string($r)) {
                try {
                    $rates[$cur] = Money::parseRate($r)[1];
                } catch (InvalidArgumentException) {
                }
            }
        }
        $last = $settings['lastAccountId'] ?? null;
        $d['settings'] = ['lastAccountId' => is_string($last) && isset($acc[$last]) ? $last : null, 'lastRates' => $rates];
        $d['requests'] = [];
        $d['version'] = Finance::VERSION;
        return $d;
    }

    public static function restore(string $uid, mixed $payload): void
    {
        $data = self::validate($payload);
        self::snapshot($uid, 'vor-wiederherstellung');
        Store::mutate(Finance::file($uid), function (array &$d) use ($data) {
            $d = $data;
        }, Finance::blank());
    }

    private static function csvCell(string $v): string
    {
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $v = "'" . $v;   // Schutz vor Formel-Injektion in Tabellenprogrammen
        }
        return '"' . str_replace('"', '""', $v) . '"';
    }

    public static function csv(array $d, ?string $month): string
    {
        $acc = array_column($d['accounts'], 'name', 'id');
        $cat = array_column($d['categories'], 'name', 'id');
        $bud = array_column($d['budgets'], 'name', 'id');
        $types = ['income' => 'Einnahme', 'expense' => 'Ausgabe', 'transfer' => 'Umbuchung',
            'debt_in' => 'Darlehen/Rückzahlung erhalten', 'debt_out' => 'Darlehen/Rückzahlung bezahlt'];
        $tx = $d['transactions'];
        usort($tx, fn($a, $b) => [$a['date'], $a['created']] <=> [$b['date'], $b['created']]);
        $rows = [['Datum', 'Typ', 'Konto', 'Zielkonto', 'Kategorie', 'Budget', 'Beschreibung', 'Betrag CHF', 'Wirkung auf Konto CHF', 'Originalbetrag', 'Währung', 'Kurs (pro CHF)']];
        foreach ($tx as $t) {
            if ($month !== null && !str_starts_with($t['date'], $month)) {
                continue;
            }
            $sign = in_array($t['type'], ['income', 'debt_in'], true) ? 1 : -1;
            $rows[] = [
                (new DateTimeImmutable($t['date']))->format('d.m.Y'),
                $types[$t['type']] ?? $t['type'],
                $acc[$t['accountId']] ?? '',
                $t['toAccountId'] ? ($acc[$t['toAccountId']] ?? '') : '',
                $t['categoryId'] ? ($cat[$t['categoryId']] ?? '') : '',
                $t['budgetId'] ? ($bud[$t['budgetId']] ?? '') : '',
                $t['description'],
                ['num', Money::plain($t['amount'])],
                ['num', $t['type'] === 'transfer' ? '0.00' : Money::plain($sign * $t['amount'])],
                $t['fx'] ? ['num', Money::plain($t['fx']['original'])] : '',
                $t['fx']['currency'] ?? '',
                $t['fx']['rate'] ?? '',
            ];
        }
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $r) {
            $cells = array_map(fn($c) => is_array($c) ? $c[1] : self::csvCell((string)$c), $r);
            $out .= implode(';', $cells) . "\r\n";
        }
        return $out;
    }
}
