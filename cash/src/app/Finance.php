<?php
declare(strict_types=1);

/**
 * Finanzlogik pro Benutzer. Alle Beträge in Rappen (int).
 *
 * Buchungstypen und Wirkung auf Kontostände:
 *   income    +  (zählt als Einnahme)
 *   expense   −  (zählt als Ausgabe)
 *   transfer  −  vom Konto / + aufs Zielkonto (keine Einnahme/Ausgabe)
 *   debt_in   +  Darlehen erhalten / Rückzahlung erhalten (keine Einnahme)
 *   debt_out  −  Darlehen gegeben / Rückzahlung geleistet (keine Ausgabe)
 * Anfangssalden sind Kontoeigenschaften, keine Buchungen.
 */
final class Finance
{
    public const VERSION = 1;
    public const CURRENCIES = ['THB', 'EUR', 'USD', 'GBP'];
    public const ACCOUNT_TYPES = ['checking', 'savings', 'cash', 'card', 'other'];
    public const INTERVALS = ['once', 'weekly', 'biweekly', 'monthly', 'quarterly', 'semiannual', 'yearly'];
    public const TX_TYPES = ['income', 'expense', 'transfer', 'debt_in', 'debt_out'];
    public const ICONS = ['cart', 'coffee', 'home', 'bus', 'car', 'heart', 'shield', 'ticket', 'plane', 'bag', 'play',
        'phone', 'book', 'gift', 'file', 'dots', 'briefcase', 'coins', 'rotate', 'tag', 'percent', 'bolt', 'paw',
        'dumbbell', 'shirt', 'music', 'wallet', 'piggy', 'bank', 'card', 'sparkles', 'users'];
    public const BILL_HORIZON_DAYS = 35;

    public static function file(string $uid): string
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $uid)) {
            throw new RuntimeException('Ungültige Benutzer-ID.');
        }
        return cash_path('users/' . $uid . '/finance.json.php');
    }

    public static function blank(): array
    {
        return [
            'version' => self::VERSION,
            'settings' => ['lastAccountId' => null, 'lastRates' => []],
            'accounts' => [],
            'categories' => self::defaultCategories(),
            'transactions' => [],
            'budgets' => [],
            'bills' => [],
            'occurrences' => [],
            'goals' => [],
            'debts' => [],
            'requests' => [],
        ];
    }

    public static function initUser(string $uid): void
    {
        Store::mutate(self::file($uid), function (array &$d) {
            self::normalize($d);
        }, self::blank());
    }

    public static function defaultCategories(): array
    {
        $exp = [
            ['Lebensmittel', 'cart'], ['Restaurant & Café', 'coffee'], ['Wohnen', 'home'], ['Mobilität', 'bus'],
            ['Gesundheit', 'heart'], ['Versicherungen', 'shield'], ['Freizeit', 'ticket'], ['Ferien & Reisen', 'plane'],
            ['Shopping & Kleider', 'bag'], ['Abos & Medien', 'play'], ['Handy & Internet', 'phone'], ['Bildung', 'book'],
            ['Geschenke', 'gift'], ['Steuern & Gebühren', 'file'], ['Sonstiges', 'dots'],
        ];
        $inc = [
            ['Lohn', 'briefcase'], ['Taschengeld', 'coins'], ['Geschenk erhalten', 'gift'], ['Rückerstattung', 'rotate'],
            ['Verkauf', 'tag'], ['Zinsen', 'percent'], ['Sonstige Einnahmen', 'dots'],
        ];
        $out = [];
        foreach ($exp as [$n, $i]) {
            $out[] = ['id' => cash_new_id(), 'name' => $n, 'type' => 'expense', 'icon' => $i, 'archived' => false];
        }
        foreach ($inc as [$n, $i]) {
            $out[] = ['id' => cash_new_id(), 'name' => $n, 'type' => 'income', 'icon' => $i, 'archived' => false];
        }
        return $out;
    }

    public static function normalize(array &$d): void
    {
        $blank = self::blank();
        foreach ($blank as $k => $v) {
            if ($k === 'version') {
                continue;
            }
            if (!isset($d[$k]) || !is_array($d[$k])) {
                $d[$k] = $v;
            }
        }
        $d['settings']['lastRates'] ??= [];
        $d['settings']['lastAccountId'] ??= null;
        $d['version'] = self::VERSION;
    }

    // ================================================================ Helfer

    private static function err(string $msg, int $status = 400): never
    {
        throw new ApiError($msg, $status);
    }

    private static function money(mixed $v, string $label, bool $allowZero = false, bool $allowNegative = false): int
    {
        try {
            return Money::parse($v, $allowZero, $allowNegative);
        } catch (InvalidArgumentException $e) {
            self::err($label . ': ' . $e->getMessage());
        }
    }

    private static function text(mixed $v, string $label, int $max, bool $required): string
    {
        if ($v === null) {
            $v = '';
        }
        if (!is_string($v)) {
            self::err($label . ': ungültige Eingabe.');
        }
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');
        if ($required && $v === '') {
            self::err($label . ' fehlt.');
        }
        if (mb_strlen($v) > $max) {
            self::err($label . ': höchstens ' . $max . ' Zeichen.');
        }
        return $v;
    }

    public static function isDate(mixed $v): bool
    {
        return is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)
            && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[1] >= 1970 && (int)$m[1] <= 2200;
    }

    private static function date(mixed $v, string $label, bool $required = true): ?string
    {
        if (($v === null || $v === '') && !$required) {
            return null;
        }
        if (!self::isDate($v)) {
            self::err($label . ': bitte ein gültiges Datum wählen.');
        }
        return $v;
    }

    private static function idOf(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    public static function find(array $list, ?string $id): ?int
    {
        if ($id === null) {
            return null;
        }
        foreach ($list as $i => $item) {
            if (($item['id'] ?? null) === $id) {
                return $i;
            }
        }
        return null;
    }

    private static function mustFind(array $list, ?string $id, string $what): int
    {
        $i = self::find($list, $id);
        if ($i === null) {
            self::err($what . ' wurde nicht gefunden.', 404);
        }
        return $i;
    }

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    public static function addMonths(string $anchor, int $months): string
    {
        [$y, $m, $day] = array_map('intval', explode('-', $anchor));
        $total = $y * 12 + ($m - 1) + $months;
        $ny = intdiv($total, 12);
        $nm = $total % 12 + 1;
        $dim = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $ny, $nm)))->format('t');
        return sprintf('%04d-%02d-%02d', $ny, $nm, min($day, $dim));
    }

    private static function account(array $d, ?string $id, string $label, ?string $allowArchived = null): string
    {
        $i = self::find($d['accounts'], $id);
        if ($i === null) {
            self::err($label . ': bitte ein Konto wählen.');
        }
        if ($d['accounts'][$i]['archived'] && $id !== $allowArchived) {
            self::err($label . ': dieses Konto ist archiviert.');
        }
        return $id;
    }

    private static function category(array $d, ?string $id, string $type, ?string $allowArchived = null): string
    {
        $i = self::find($d['categories'], $id);
        if ($i === null) {
            self::err('Bitte eine Kategorie wählen.');
        }
        $c = $d['categories'][$i];
        if ($c['type'] !== $type) {
            self::err('Die Kategorie passt nicht zum Buchungstyp.');
        }
        if ($c['archived'] && $id !== $allowArchived) {
            self::err('Diese Kategorie ist archiviert.');
        }
        return $id;
    }

    // ============================================================ Dispatcher

    /** Führt eine Änderung aus. Gibt optional eine Meldung bzw. Daten zurück. */
    public static function apply(string $action, array $in, array &$d, string $today): array
    {
        self::normalize($d);
        $res = match ($action) {
            'onboarding.complete' => self::onboarding($d, $in, $today),
            'account.save' => self::accountSave($d, $in, $today),
            'account.archive' => self::accountArchive($d, $in),
            'account.delete' => self::accountDelete($d, $in),
            'category.save' => self::categorySave($d, $in),
            'category.archive' => self::categoryArchive($d, $in),
            'category.delete' => self::categoryDelete($d, $in),
            'tx.save' => self::txSave($d, $in, $today),
            'tx.delete' => self::txDelete($d, $in),
            'rate.set' => self::rateSet($d, $in),
            'budget.save' => self::budgetSave($d, $in, $today),
            'budget.archive' => self::budgetArchive($d, $in),
            'budget.delete' => self::budgetDelete($d, $in),
            'bill.save' => self::billSave($d, $in, $today),
            'bill.active' => self::billActive($d, $in, $today),
            'bill.delete' => self::billDelete($d, $in),
            'occ.pay' => self::occPay($d, $in, $today),
            'occ.unpay' => self::occUnpay($d, $in),
            'occ.skip' => self::occSkip($d, $in, true),
            'occ.unskip' => self::occSkip($d, $in, false),
            'goal.save' => self::goalSave($d, $in, $today),
            'goal.delete' => self::goalDelete($d, $in),
            'goal.allocate' => self::goalAllocate($d, $in, $today),
            'goal.allocDelete' => self::goalAllocDelete($d, $in),
            'debt.save' => self::debtSave($d, $in, $today),
            'debt.delete' => self::debtDelete($d, $in),
            'debt.pay' => self::debtPay($d, $in, $today),
            'debt.payDelete' => self::debtPayDelete($d, $in),
            default => self::err('Unbekannte Aktion.', 404),
        };
        self::generateBills($d, $today);
        return is_array($res) ? $res : [];
    }

    // ============================================================ Einrichtung

    private static function onboarding(array &$d, array $in, string $today): array
    {
        if (count($d['accounts']) > 0) {
            self::err('Die Einrichtung wurde bereits abgeschlossen.', 409);
        }
        $accounts = $in['accounts'] ?? null;
        if (!is_array($accounts) || count($accounts) < 1 || count($accounts) > 10) {
            self::err('Bitte mindestens ein Konto angeben.');
        }
        $new = [];
        foreach ($accounts as $a) {
            if (!is_array($a)) {
                self::err('Ungültige Kontoangaben.');
            }
            $name = self::text($a['name'] ?? '', 'Kontoname', 60, true);
            if (!isset($a['opening']) || $a['opening'] === '' || $a['opening'] === null) {
                self::err('Bitte für «' . $name . '» den aktuellen Kontostand eingeben (0 ist erlaubt).');
            }
            $opening = self::money($a['opening'], 'Anfangssaldo ' . $name, true, true);
            $type = in_array($a['type'] ?? '', self::ACCOUNT_TYPES, true) ? $a['type'] : 'checking';
            $new[] = self::newAccount($name, $type, $opening, $today);
        }
        $d['accounts'] = $new;
        $d['settings']['lastAccountId'] = $new[0]['id'];

        $th = $in['thailand'] ?? null;
        if (is_array($th) && !empty($th['enabled'])) {
            $amount = self::money($th['amount'] ?? '315.00', 'Thailand-Budget');
            $d['budgets'][] = [
                'id' => cash_new_id(),
                'kind' => 'custom',
                'name' => self::text($th['name'] ?? 'Ferien Thailand', 'Budgetname', 60, true),
                'amount' => $amount,
                'categoryId' => null,
                'startDate' => $today,
                'endDate' => null,
                'inputCurrency' => 'THB',
                'note' => 'Verbleibendes Restbudget ab Einrichtung – kein Kontostand und nicht das Gesamtbudget der Reise.',
                'archived' => false,
                'created' => date('c'),
            ];
        }
        return ['message' => 'Einrichtung abgeschlossen.'];
    }

    private static function newAccount(string $name, string $type, int $opening, string $today): array
    {
        return [
            'id' => cash_new_id(),
            'name' => $name,
            'type' => $type,
            'opening' => $opening,
            'openingDate' => $today,
            'archived' => false,
            'created' => date('c'),
        ];
    }

    // ================================================================ Konten

    private static function accountSave(array &$d, array $in, string $today): array
    {
        $name = self::text($in['name'] ?? '', 'Kontoname', 60, true);
        $type = in_array($in['type'] ?? '', self::ACCOUNT_TYPES, true) ? $in['type'] : 'checking';
        $opening = self::money($in['opening'] ?? '0', 'Anfangssaldo', true, true);
        foreach ($d['accounts'] as $a) {
            if (mb_strtolower($a['name']) === mb_strtolower($name) && $a['id'] !== ($in['id'] ?? null)) {
                self::err('Ein Konto mit diesem Namen existiert bereits.', 409);
            }
        }
        $id = self::idOf($in['id'] ?? null);
        if ($id === null) {
            $a = self::newAccount($name, $type, $opening, $today);
            $d['accounts'][] = $a;
            return ['message' => 'Konto angelegt.', 'id' => $a['id']];
        }
        $i = self::mustFind($d['accounts'], $id, 'Das Konto');
        $d['accounts'][$i]['name'] = $name;
        $d['accounts'][$i]['type'] = $type;
        $d['accounts'][$i]['opening'] = $opening;
        return ['message' => 'Konto gespeichert.'];
    }

    private static function accountArchive(array &$d, array $in): array
    {
        $i = self::mustFind($d['accounts'], self::idOf($in['id'] ?? null), 'Das Konto');
        $archive = !empty($in['archived']);
        if ($archive) {
            $active = array_filter($d['accounts'], fn($a) => !$a['archived']);
            if (count($active) <= 1) {
                self::err('Mindestens ein aktives Konto wird benötigt.');
            }
        }
        $d['accounts'][$i]['archived'] = $archive;
        if ($archive && $d['settings']['lastAccountId'] === $d['accounts'][$i]['id']) {
            $d['settings']['lastAccountId'] = null;
        }
        return ['message' => $archive ? 'Konto archiviert.' : 'Konto wiederhergestellt.'];
    }

    private static function accountDelete(array &$d, array $in): array
    {
        $id = self::idOf($in['id'] ?? null);
        $i = self::mustFind($d['accounts'], $id, 'Das Konto');
        foreach ($d['transactions'] as $t) {
            if ($t['accountId'] === $id || ($t['toAccountId'] ?? null) === $id) {
                self::err('Das Konto hat Buchungen und kann nur archiviert werden.', 409);
            }
        }
        foreach ($d['bills'] as $b) {
            if (($b['accountId'] ?? null) === $id) {
                self::err('Das Konto wird von einer Rechnung verwendet. Bitte zuerst dort ändern.', 409);
            }
        }
        foreach ($d['debts'] as $b) {
            if (($b['originAccountId'] ?? null) === $id) {
                self::err('Das Konto wird von einer Schuld verwendet.', 409);
            }
        }
        $active = array_filter($d['accounts'], fn($a) => !$a['archived'] && $a['id'] !== $id);
        if (count($active) < 1) {
            self::err('Mindestens ein aktives Konto wird benötigt.');
        }
        array_splice($d['accounts'], $i, 1);
        if ($d['settings']['lastAccountId'] === $id) {
            $d['settings']['lastAccountId'] = null;
        }
        return ['message' => 'Konto gelöscht.'];
    }

    // ============================================================ Kategorien

    private static function categorySave(array &$d, array $in): array
    {
        $name = self::text($in['name'] ?? '', 'Kategoriename', 40, true);
        $icon = in_array($in['icon'] ?? '', self::ICONS, true) ? $in['icon'] : 'dots';
        $id = self::idOf($in['id'] ?? null);
        if ($id === null) {
            $type = ($in['type'] ?? '') === 'income' ? 'income' : 'expense';
        } else {
            $type = $d['categories'][self::mustFind($d['categories'], $id, 'Die Kategorie')]['type'];
        }
        foreach ($d['categories'] as $c) {
            if ($c['type'] === $type && $c['id'] !== $id && mb_strtolower($c['name']) === mb_strtolower($name)) {
                self::err('Diese Kategorie existiert bereits.', 409);
            }
        }
        if ($id === null) {
            $c = ['id' => cash_new_id(), 'name' => $name, 'type' => $type, 'icon' => $icon, 'archived' => false];
            $d['categories'][] = $c;
            return ['message' => 'Kategorie angelegt.', 'id' => $c['id']];
        }
        $i = self::mustFind($d['categories'], $id, 'Die Kategorie');
        $d['categories'][$i]['name'] = $name;
        $d['categories'][$i]['icon'] = $icon;
        return ['message' => 'Kategorie gespeichert.'];
    }

    private static function categoryArchive(array &$d, array $in): array
    {
        $i = self::mustFind($d['categories'], self::idOf($in['id'] ?? null), 'Die Kategorie');
        $d['categories'][$i]['archived'] = !empty($in['archived']);
        return ['message' => !empty($in['archived']) ? 'Kategorie ausgeblendet.' : 'Kategorie wieder aktiv.'];
    }

    private static function categoryDelete(array &$d, array $in): array
    {
        $id = self::idOf($in['id'] ?? null);
        $i = self::mustFind($d['categories'], $id, 'Die Kategorie');
        foreach ($d['transactions'] as $t) {
            if (($t['categoryId'] ?? null) === $id) {
                self::err('Die Kategorie wird von Buchungen verwendet – bitte ausblenden statt löschen.', 409);
            }
        }
        foreach (array_merge($d['budgets'], $d['bills']) as $x) {
            if (($x['categoryId'] ?? null) === $id) {
                self::err('Die Kategorie wird von einem Budget oder einer Rechnung verwendet.', 409);
            }
        }
        array_splice($d['categories'], $i, 1);
        return ['message' => 'Kategorie gelöscht.'];
    }

    // ============================================================== Buchungen

    private static function txSave(array &$d, array $in, string $today): array
    {
        $id = self::idOf($in['id'] ?? null);
        $old = null;
        $idx = null;
        if ($id !== null) {
            $idx = self::mustFind($d['transactions'], $id, 'Die Buchung');
            $old = $d['transactions'][$idx];
            $kind = $old['link']['kind'] ?? null;
            if ($kind === 'debt') {
                self::err('Diese Buchung gehört zu einer Schuld. Bitte im Bereich «Schulden» bearbeiten.', 409);
            }
        }
        $type = $in['type'] ?? '';
        if (!in_array($type, ['income', 'expense', 'transfer'], true)) {
            self::err('Ungültiger Buchungstyp.');
        }
        if ($old && ($old['link']['kind'] ?? null) === 'bill' && $type !== 'expense') {
            self::err('Eine bezahlte Rechnung bleibt eine Ausgabe.');
        }
        $date = self::date($in['date'] ?? null, 'Datum');
        $accountId = self::account($d, self::idOf($in['accountId'] ?? null), $type === 'transfer' ? 'Von Konto' : 'Konto', $old['accountId'] ?? null);
        $toAccountId = null;
        $categoryId = null;
        $budgetId = null;
        if ($type === 'transfer') {
            $toAccountId = self::account($d, self::idOf($in['toAccountId'] ?? null), 'Auf Konto', $old['toAccountId'] ?? null);
            if ($toAccountId === $accountId) {
                self::err('Bitte zwei verschiedene Konten wählen.');
            }
        } else {
            $categoryId = self::category($d, self::idOf($in['categoryId'] ?? null), $type, $old['categoryId'] ?? null);
        }
        if ($type === 'expense') {
            $budgetId = self::idOf($in['budgetId'] ?? null);
            if ($budgetId !== null) {
                $bi = self::find($d['budgets'], $budgetId);
                if ($bi === null) {
                    self::err('Das Budget wurde nicht gefunden.');
                }
                if ($d['budgets'][$bi]['archived'] && $budgetId !== ($old['budgetId'] ?? null)) {
                    self::err('Dieses Budget ist archiviert.');
                }
            }
        }
        $description = self::text($in['description'] ?? '', 'Beschreibung', 200, false);

        // Betrag – optional in Fremdwährung mit manuellem Kurs.
        $fx = null;
        $currency = is_string($in['currency'] ?? null) ? strtoupper($in['currency']) : 'CHF';
        if ($currency !== 'CHF' && $type !== 'transfer') {
            if (!in_array($currency, self::CURRENCIES, true)) {
                self::err('Diese Währung wird nicht unterstützt.');
            }
            $original = self::money($in['originalAmount'] ?? '', 'Betrag in ' . $currency);
            if (($in['rate'] ?? '') === '' || ($in['rate'] ?? null) === null) {
                self::err('Bitte den Wechselkurs «' . $currency . ' pro CHF» eingeben.');
            }
            try {
                [$rateMicro, $rateStr] = Money::parseRate($in['rate']);
            } catch (InvalidArgumentException $e) {
                self::err($e->getMessage());
            }
            $amount = Money::fxToChf($original, $rateMicro);
            if ($amount <= 0) {
                self::err('Der umgerechnete Betrag ist zu klein.');
            }
            $fx = ['currency' => $currency, 'original' => $original, 'rate' => $rateStr, 'rateMicro' => $rateMicro];
            $d['settings']['lastRates'][$currency] = $rateStr;
        } else {
            $amount = self::money($in['amount'] ?? '', 'Betrag');
        }

        $tx = [
            'id' => $id ?? cash_new_id(),
            'type' => $type,
            'date' => $date,
            'amount' => $amount,
            'accountId' => $accountId,
            'toAccountId' => $toAccountId,
            'categoryId' => $categoryId,
            'budgetId' => $budgetId,
            'description' => $description,
            'fx' => $fx,
            'link' => $old['link'] ?? null,
            'created' => $old['created'] ?? date('c'),
            'updated' => date('c'),
        ];
        if ($idx === null) {
            $d['transactions'][] = $tx;
        } else {
            $d['transactions'][$idx] = $tx;
        }
        // Verknüpfte Rechnung konsistent halten.
        if (($tx['link']['kind'] ?? null) === 'bill') {
            $oi = self::find($d['occurrences'], $tx['link']['occurrenceId']);
            if ($oi !== null) {
                $d['occurrences'][$oi]['paidDate'] = $date;
                $d['occurrences'][$oi]['accountId'] = $accountId;
                $d['occurrences'][$oi]['paidAmount'] = $amount;
            }
        }
        $d['settings']['lastAccountId'] = $accountId;
        return ['message' => $id ? 'Buchung gespeichert.' : 'Buchung erfasst.', 'id' => $tx['id']];
    }

    private static function txDelete(array &$d, array $in): array
    {
        $i = self::mustFind($d['transactions'], self::idOf($in['id'] ?? null), 'Die Buchung');
        $t = $d['transactions'][$i];
        $kind = $t['link']['kind'] ?? null;
        if ($kind === 'debt') {
            self::err('Diese Buchung gehört zu einer Schuld. Bitte im Bereich «Schulden» löschen.', 409);
        }
        if ($kind === 'bill') {
            $oi = self::find($d['occurrences'], $t['link']['occurrenceId']);
            if ($oi !== null) {
                self::reopenOccurrence($d['occurrences'][$oi]);
            }
        }
        array_splice($d['transactions'], $i, 1);
        return ['message' => $kind === 'bill' ? 'Buchung gelöscht – Rechnung ist wieder offen.' : 'Buchung gelöscht.'];
    }

    private static function rateSet(array &$d, array $in): array
    {
        $cur = is_string($in['currency'] ?? null) ? strtoupper($in['currency']) : '';
        if (!in_array($cur, self::CURRENCIES, true)) {
            self::err('Diese Währung wird nicht unterstützt.');
        }
        try {
            [, $rateStr] = Money::parseRate($in['rate'] ?? '');
        } catch (InvalidArgumentException $e) {
            self::err($e->getMessage());
        }
        $d['settings']['lastRates'][$cur] = $rateStr;
        return ['message' => 'Kurs gespeichert. Bestehende Buchungen bleiben unverändert.'];
    }

    // ================================================================ Budgets

    private static function budgetSave(array &$d, array $in, string $today): array
    {
        $id = self::idOf($in['id'] ?? null);
        $kind = ($in['kind'] ?? '') === 'monthly' ? 'monthly' : 'custom';
        if ($id !== null) {
            $kind = $d['budgets'][self::mustFind($d['budgets'], $id, 'Das Budget')]['kind'];
        }
        $amount = self::money($in['amount'] ?? '', 'Budgetbetrag');
        $note = self::text($in['note'] ?? '', 'Notiz', 200, false);
        $categoryId = null;
        $startDate = null;
        $endDate = null;
        $inputCurrency = null;
        if ($kind === 'monthly') {
            $categoryId = self::category($d, self::idOf($in['categoryId'] ?? null), 'expense');
            foreach ($d['budgets'] as $b) {
                if ($b['kind'] === 'monthly' && !$b['archived'] && $b['categoryId'] === $categoryId && $b['id'] !== $id) {
                    self::err('Für diese Kategorie gibt es bereits ein Monatsbudget.', 409);
                }
            }
            $cat = $d['categories'][self::find($d['categories'], $categoryId)];
            $name = self::text(($in['name'] ?? '') !== '' ? $in['name'] : $cat['name'], 'Name', 60, true);
        } else {
            $name = self::text($in['name'] ?? '', 'Budgetname', 60, true);
            $startDate = self::date($in['startDate'] ?? null, 'Von', false);
            $endDate = self::date($in['endDate'] ?? null, 'Bis', false);
            if ($startDate && $endDate && $endDate < $startDate) {
                self::err('Das Enddatum liegt vor dem Startdatum.');
            }
            $cur = is_string($in['inputCurrency'] ?? null) ? strtoupper($in['inputCurrency']) : '';
            if ($cur !== '' && $cur !== 'CHF') {
                if (!in_array($cur, self::CURRENCIES, true)) {
                    self::err('Diese Währung wird nicht unterstützt.');
                }
                $inputCurrency = $cur;
            }
        }
        if ($id === null) {
            $b = [
                'id' => cash_new_id(), 'kind' => $kind, 'name' => $name, 'amount' => $amount,
                'categoryId' => $categoryId, 'startDate' => $startDate, 'endDate' => $endDate,
                'inputCurrency' => $inputCurrency, 'note' => $note, 'archived' => false,
                'startMonth' => $kind === 'monthly' ? substr($today, 0, 7) : null,
                'created' => date('c'),
            ];
            if ($kind === 'monthly' && is_string($in['startMonth'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $in['startMonth'])) {
                $b['startMonth'] = $in['startMonth'];
            }
            $d['budgets'][] = $b;
            return ['message' => 'Budget erstellt.', 'id' => $b['id']];
        }
        $i = self::mustFind($d['budgets'], $id, 'Das Budget');
        $d['budgets'][$i] = array_merge($d['budgets'][$i], [
            'name' => $name, 'amount' => $amount, 'categoryId' => $categoryId, 'startDate' => $startDate,
            'endDate' => $endDate, 'inputCurrency' => $inputCurrency, 'note' => $note,
        ]);
        return ['message' => 'Budget gespeichert.'];
    }

    private static function budgetArchive(array &$d, array $in): array
    {
        $i = self::mustFind($d['budgets'], self::idOf($in['id'] ?? null), 'Das Budget');
        $arch = !empty($in['archived']);
        if (!$arch && $d['budgets'][$i]['kind'] === 'monthly') {
            foreach ($d['budgets'] as $b) {
                if ($b['kind'] === 'monthly' && !$b['archived'] && $b['categoryId'] === $d['budgets'][$i]['categoryId'] && $b['id'] !== $d['budgets'][$i]['id']) {
                    self::err('Für diese Kategorie gibt es bereits ein aktives Monatsbudget.', 409);
                }
            }
        }
        $d['budgets'][$i]['archived'] = $arch;
        return ['message' => $arch ? 'Budget archiviert.' : 'Budget wieder aktiv.'];
    }

    private static function budgetDelete(array &$d, array $in): array
    {
        $id = self::idOf($in['id'] ?? null);
        $i = self::mustFind($d['budgets'], $id, 'Das Budget');
        foreach ($d['transactions'] as &$t) {
            if (($t['budgetId'] ?? null) === $id) {
                $t['budgetId'] = null;   // Buchungen bleiben bestehen
            }
        }
        unset($t);
        array_splice($d['budgets'], $i, 1);
        return ['message' => 'Budget gelöscht. Zugeordnete Ausgaben bleiben erhalten.'];
    }

    // ============================================================= Rechnungen

    public static function nthDate(array $bill, int $n): string
    {
        $a = $bill['startDate'];
        return match ($bill['interval']) {
            'weekly' => self::addDays($a, 7 * $n),
            'biweekly' => self::addDays($a, 14 * $n),
            'monthly' => self::addMonths($a, $n),
            'quarterly' => self::addMonths($a, 3 * $n),
            'semiannual' => self::addMonths($a, 6 * $n),
            'yearly' => self::addMonths($a, 12 * $n),
            default => $a,
        };
    }

    /**
     * Erzeugt fehlende Fälligkeiten (idempotent, ohne Duplikate) bis heute + Horizont,
     * mindestens aber die nächste kommende Fälligkeit. Läuft immer unter exklusiver Sperre.
     */
    public static function generateBills(array &$d, string $today): int
    {
        $horizon = self::addDays($today, self::BILL_HORIZON_DAYS);
        $created = 0;
        foreach ($d['bills'] as &$b) {
            if (!$b['active']) {
                continue;
            }
            $existing = [];
            $hasUpcoming = false;
            foreach ($d['occurrences'] as $o) {
                if ($o['billId'] === $b['id']) {
                    $existing[$o['dueDate']] = true;
                    if ($o['dueDate'] >= $today) {
                        $hasUpcoming = true;
                    }
                }
            }
            $after = $b['generatedUntil'] ?? null;
            $maxDate = $after;
            for ($n = 0; $n < 3000; $n++) {
                $date = self::nthDate($b, $n);
                if ($b['interval'] === 'once' && $n > 0) {
                    break;
                }
                if (!empty($b['endDate']) && $date > $b['endDate']) {
                    break;
                }
                if ($date > $horizon && $hasUpcoming) {
                    break;
                }
                if ($after !== null && $date <= $after) {
                    continue;
                }
                if (!isset($existing[$date])) {
                    $d['occurrences'][] = [
                        'id' => cash_new_id(),
                        'billId' => $b['id'],
                        'dueDate' => $date,
                        'amount' => $b['amount'],
                        'status' => 'open',
                        'paidDate' => null,
                        'paidAmount' => null,
                        'accountId' => null,
                        'txId' => null,
                    ];
                    $existing[$date] = true;
                    $created++;
                }
                if ($date >= $today) {
                    $hasUpcoming = true;
                }
                if ($maxDate === null || $date > $maxDate) {
                    $maxDate = $date;
                }
                if ($date > $horizon) {
                    break;
                }
            }
            $b['generatedUntil'] = $maxDate;
        }
        unset($b);
        return $created;
    }

    private static function billSave(array &$d, array $in, string $today): array
    {
        $id = self::idOf($in['id'] ?? null);
        $name = self::text($in['name'] ?? '', 'Name', 80, true);
        $amount = self::money($in['amount'] ?? '', 'Betrag');
        $old = null;
        if ($id !== null) {
            $old = $d['bills'][self::mustFind($d['bills'], $id, 'Die Rechnung')];
        }
        $categoryId = self::category($d, self::idOf($in['categoryId'] ?? null), 'expense', $old['categoryId'] ?? null);
        $startDate = self::date($in['startDate'] ?? null, 'Fälligkeit');
        $interval = $in['interval'] ?? 'once';
        if (!in_array($interval, self::INTERVALS, true)) {
            self::err('Ungültiges Wiederholungsintervall.');
        }
        $endDate = $interval === 'once' ? null : self::date($in['endDate'] ?? null, 'Endet am', false);
        if ($endDate !== null && $endDate < $startDate) {
            self::err('«Endet am» liegt vor der ersten Fälligkeit.');
        }
        $accountId = self::idOf($in['accountId'] ?? null);
        if ($accountId !== null) {
            self::account($d, $accountId, 'Konto', $old['accountId'] ?? null);
        }
        $note = self::text($in['note'] ?? '', 'Notiz', 200, false);

        if ($old === null) {
            $b = [
                'id' => cash_new_id(), 'name' => $name, 'amount' => $amount, 'categoryId' => $categoryId,
                'startDate' => $startDate, 'interval' => $interval, 'endDate' => $endDate,
                'accountId' => $accountId, 'note' => $note, 'active' => true, 'generatedUntil' => null,
                'created' => date('c'),
            ];
            $d['bills'][] = $b;
            return ['message' => 'Rechnung erfasst.', 'id' => $b['id']];
        }
        $i = self::find($d['bills'], $id);
        $scheduleChanged = $old['startDate'] !== $startDate || $old['interval'] !== $interval || ($old['endDate'] ?? null) !== $endDate;
        $d['bills'][$i] = array_merge($old, [
            'name' => $name, 'amount' => $amount, 'categoryId' => $categoryId, 'startDate' => $startDate,
            'interval' => $interval, 'endDate' => $endDate, 'accountId' => $accountId, 'note' => $note,
        ]);
        // Offene Fälligkeiten übernehmen den neuen Betrag.
        foreach ($d['occurrences'] as &$o) {
            if ($o['billId'] === $id && $o['status'] === 'open') {
                $o['amount'] = $amount;
            }
        }
        unset($o);
        if ($scheduleChanged) {
            // Offene Fälligkeiten nach altem Plan entfernen; bezahlte/erledigte bleiben.
            $d['occurrences'] = array_values(array_filter($d['occurrences'], fn($o) => !($o['billId'] === $id && $o['status'] === 'open')));
            $last = null;
            foreach ($d['occurrences'] as $o) {
                if ($o['billId'] === $id && ($last === null || $o['dueDate'] > $last)) {
                    $last = $o['dueDate'];
                }
            }
            $d['bills'][$i]['generatedUntil'] = $last;
        }
        return ['message' => 'Rechnung gespeichert.'];
    }

    private static function billActive(array &$d, array $in, string $today): array
    {
        $id = self::idOf($in['id'] ?? null);
        $i = self::mustFind($d['bills'], $id, 'Die Rechnung');
        $active = !empty($in['active']);
        $d['bills'][$i]['active'] = $active;
        if (!$active) {
            $d['occurrences'] = array_values(array_filter($d['occurrences'],
                fn($o) => !($o['billId'] === $id && $o['status'] === 'open' && $o['dueDate'] >= $today)));
            return ['message' => 'Rechnung beendet. Es werden keine neuen Fälligkeiten erzeugt.'];
        }
        $last = null;
        foreach ($d['occurrences'] as $o) {
            if ($o['billId'] === $id && ($last === null || $o['dueDate'] > $last)) {
                $last = $o['dueDate'];
            }
        }
        // Beim Reaktivieren keine verpassten Fälligkeiten der Pause nachträglich erzeugen.
        $d['bills'][$i]['generatedUntil'] = max((string)$last, self::addDays($today, -1));
        return ['message' => 'Rechnung wieder aktiv.'];
    }

    private static function billDelete(array &$d, array $in): array
    {
        $id = self::idOf($in['id'] ?? null);
        $i = self::mustFind($d['bills'], $id, 'Die Rechnung');
        $name = $d['bills'][$i]['name'];
        foreach ($d['transactions'] as &$t) {
            if (($t['link']['kind'] ?? null) === 'bill' && $t['link']['billId'] === $id) {
                $t['link'] = null;   // bezahlte Ausgaben bleiben als normale Ausgaben bestehen
                if ($t['description'] === '') {
                    $t['description'] = $name;
                }
            }
        }
        unset($t);
        $d['occurrences'] = array_values(array_filter($d['occurrences'], fn($o) => $o['billId'] !== $id));
        array_splice($d['bills'], $i, 1);
        return ['message' => 'Rechnung gelöscht. Bereits bezahlte Ausgaben bleiben erhalten.'];
    }

    private static function reopenOccurrence(array &$o): void
    {
        $o['status'] = 'open';
        $o['paidDate'] = null;
        $o['paidAmount'] = null;
        $o['accountId'] = null;
        $o['txId'] = null;
    }

    private static function occPay(array &$d, array $in, string $today): array
    {
        $oi = self::mustFind($d['occurrences'], self::idOf($in['id'] ?? null), 'Die Fälligkeit');
        $o = $d['occurrences'][$oi];
        if ($o['status'] === 'paid') {
            self::err('Diese Rechnung ist bereits als bezahlt markiert.', 409);
        }
        if ($o['status'] !== 'open') {
            self::err('Diese Fälligkeit ist nicht offen.', 409);
        }
        $bi = self::mustFind($d['bills'], $o['billId'], 'Die Rechnung');
        $bill = $d['bills'][$bi];
        $date = self::date($in['date'] ?? null, 'Zahlungsdatum');
        $accountId = self::account($d, self::idOf($in['accountId'] ?? null), 'Konto');
        $amount = isset($in['amount']) && $in['amount'] !== '' ? self::money($in['amount'], 'Betrag') : $o['amount'];
        $txId = cash_new_id();
        $d['transactions'][] = [
            'id' => $txId,
            'type' => 'expense',
            'date' => $date,
            'amount' => $amount,
            'accountId' => $accountId,
            'toAccountId' => null,
            'categoryId' => $bill['categoryId'],
            'budgetId' => null,
            'description' => $bill['name'],
            'fx' => null,
            'link' => ['kind' => 'bill', 'billId' => $bill['id'], 'occurrenceId' => $o['id']],
            'created' => date('c'),
            'updated' => date('c'),
        ];
        $d['occurrences'][$oi]['status'] = 'paid';
        $d['occurrences'][$oi]['paidDate'] = $date;
        $d['occurrences'][$oi]['paidAmount'] = $amount;
        $d['occurrences'][$oi]['accountId'] = $accountId;
        $d['occurrences'][$oi]['txId'] = $txId;
        $d['settings']['lastAccountId'] = $accountId;
        return ['message' => 'Als bezahlt markiert – Ausgabe erfasst.'];
    }

    private static function occUnpay(array &$d, array $in): array
    {
        $oi = self::mustFind($d['occurrences'], self::idOf($in['id'] ?? null), 'Die Fälligkeit');
        $o = $d['occurrences'][$oi];
        if ($o['status'] !== 'paid') {
            self::err('Diese Fälligkeit ist nicht als bezahlt markiert.', 409);
        }
        $ti = self::find($d['transactions'], $o['txId']);
        if ($ti !== null) {
            array_splice($d['transactions'], $ti, 1);
        }
        self::reopenOccurrence($d['occurrences'][$oi]);
        return ['message' => 'Zahlung zurückgenommen – Ausgabe gelöscht.'];
    }

    private static function occSkip(array &$d, array $in, bool $skip): array
    {
        $oi = self::mustFind($d['occurrences'], self::idOf($in['id'] ?? null), 'Die Fälligkeit');
        $o = $d['occurrences'][$oi];
        if ($skip && $o['status'] !== 'open') {
            self::err('Nur offene Fälligkeiten können übersprungen werden.', 409);
        }
        if (!$skip && $o['status'] !== 'skipped') {
            self::err('Diese Fälligkeit wurde nicht übersprungen.', 409);
        }
        $d['occurrences'][$oi]['status'] = $skip ? 'skipped' : 'open';
        return ['message' => $skip ? 'Fälligkeit übersprungen (ohne Zahlung).' : 'Fälligkeit wieder offen.'];
    }

    // ============================================================= Sparziele

    private static function goalSave(array &$d, array $in, string $today): array
    {
        $id = self::idOf($in['id'] ?? null);
        $name = self::text($in['name'] ?? '', 'Name', 60, true);
        $target = self::money($in['target'] ?? '', 'Zielbetrag');
        $targetDate = self::date($in['targetDate'] ?? null, 'Zieldatum', false);
        if ($id === null) {
            $g = ['id' => cash_new_id(), 'name' => $name, 'target' => $target, 'targetDate' => $targetDate,
                'allocations' => [], 'archived' => false, 'created' => date('c')];
            $initial = $in['initial'] ?? '';
            if ($initial !== '' && $initial !== null) {
                $amt = self::money($initial, 'Bereits zugewiesen', true);
                if ($amt > 0) {
                    $g['allocations'][] = ['id' => cash_new_id(), 'date' => $today, 'amount' => $amt, 'note' => 'Startbetrag'];
                }
            }
            $d['goals'][] = $g;
            return ['message' => 'Sparziel erstellt.', 'id' => $g['id']];
        }
        $i = self::mustFind($d['goals'], $id, 'Das Sparziel');
        $d['goals'][$i]['name'] = $name;
        $d['goals'][$i]['target'] = $target;
        $d['goals'][$i]['targetDate'] = $targetDate;
        if (isset($in['archived'])) {
            $d['goals'][$i]['archived'] = (bool)$in['archived'];
        }
        return ['message' => 'Sparziel gespeichert.'];
    }

    private static function goalDelete(array &$d, array $in): array
    {
        $i = self::mustFind($d['goals'], self::idOf($in['id'] ?? null), 'Das Sparziel');
        array_splice($d['goals'], $i, 1);
        return ['message' => 'Sparziel gelöscht (Kontostände unverändert).'];
    }

    private static function goalAllocate(array &$d, array $in, string $today): array
    {
        $i = self::mustFind($d['goals'], self::idOf($in['goalId'] ?? null), 'Das Sparziel');
        $amount = self::money($in['amount'] ?? '', 'Betrag');
        if (($in['mode'] ?? 'add') === 'remove') {
            $amount = -$amount;
        }
        $saved = array_sum(array_column($d['goals'][$i]['allocations'], 'amount'));
        if ($saved + $amount < 0) {
            self::err('Es kann nicht mehr entnommen werden als zugewiesen ist (CHF ' . Money::format($saved) . ').');
        }
        $d['goals'][$i]['allocations'][] = [
            'id' => cash_new_id(),
            'date' => self::date($in['date'] ?? $today, 'Datum'),
            'amount' => $amount,
            'note' => self::text($in['note'] ?? '', 'Notiz', 120, false),
        ];
        return ['message' => $amount > 0 ? 'Betrag zugewiesen.' : 'Betrag entnommen.'];
    }

    private static function goalAllocDelete(array &$d, array $in): array
    {
        $i = self::mustFind($d['goals'], self::idOf($in['goalId'] ?? null), 'Das Sparziel');
        $ai = self::mustFind($d['goals'][$i]['allocations'], self::idOf($in['allocId'] ?? null), 'Die Zuweisung');
        $rest = $d['goals'][$i]['allocations'];
        array_splice($rest, $ai, 1);
        if (array_sum(array_column($rest, 'amount')) < 0) {
            self::err('Danach wäre der Sparbetrag negativ.');
        }
        $d['goals'][$i]['allocations'] = $rest;
        return ['message' => 'Zuweisung entfernt.'];
    }

    // ================================================================ Schulden

    private static function debtFlows(string $direction): array
    {
        // [Typ der Ursprungsbuchung, Typ der Rückzahlungsbuchung]
        return $direction === 'i_owe' ? ['debt_in', 'debt_out'] : ['debt_out', 'debt_in'];
    }

    private static function debtText(array $debt, bool $origin): string
    {
        $p = $debt['person'];
        if ($debt['direction'] === 'i_owe') {
            return $origin ? 'Darlehen von ' . $p : 'Rückzahlung an ' . $p;
        }
        return $origin ? 'Darlehen an ' . $p : 'Rückzahlung von ' . $p;
    }

    private static function debtPaid(array $debt): int
    {
        return array_sum(array_column($debt['payments'], 'amount'));
    }

    private static function debtSave(array &$d, array $in, string $today): array
    {
        $id = self::idOf($in['id'] ?? null);
        $old = null;
        $i = null;
        if ($id !== null) {
            $i = self::mustFind($d['debts'], $id, 'Die Schuld');
            $old = $d['debts'][$i];
        }
        $direction = $old['direction'] ?? (($in['direction'] ?? '') === 'owed_to_me' ? 'owed_to_me' : 'i_owe');
        $person = self::text($in['person'] ?? '', 'Person', 60, true);
        $amount = self::money($in['amount'] ?? '', 'Betrag');
        $date = self::date($in['date'] ?? null, 'Datum');
        $dueDate = self::date($in['dueDate'] ?? null, 'Fällig bis', false);
        $note = self::text($in['note'] ?? '', 'Notiz', 300, false);
        $originAccountId = self::idOf($in['originAccountId'] ?? null);
        if ($originAccountId !== null) {
            self::account($d, $originAccountId, 'Konto', $old['originAccountId'] ?? null);
        }
        if ($old !== null && $amount < self::debtPaid($old)) {
            self::err('Der Betrag ist kleiner als die bereits erfassten Rückzahlungen.');
        }
        $debt = array_merge($old ?? ['id' => cash_new_id(), 'payments' => [], 'originTxId' => null, 'created' => date('c')], [
            'direction' => $direction, 'person' => $person, 'amount' => $amount, 'date' => $date,
            'dueDate' => $dueDate, 'note' => $note, 'originAccountId' => $originAccountId,
        ]);
        [$originType] = self::debtFlows($direction);

        // Ursprungsbuchung (nur wenn tatsächlich Geld über ein Konto geflossen ist).
        $ti = self::find($d['transactions'], $debt['originTxId']);
        if ($originAccountId === null) {
            if ($ti !== null) {
                array_splice($d['transactions'], $ti, 1);
            }
            $debt['originTxId'] = null;
        } else {
            $tx = [
                'id' => $ti !== null ? $debt['originTxId'] : cash_new_id(),
                'type' => $originType, 'date' => $date, 'amount' => $amount, 'accountId' => $originAccountId,
                'toAccountId' => null, 'categoryId' => null, 'budgetId' => null,
                'description' => self::debtText($debt, true), 'fx' => null,
                'link' => ['kind' => 'debt', 'debtId' => $debt['id'], 'paymentId' => null],
                'created' => $ti !== null ? $d['transactions'][$ti]['created'] : date('c'), 'updated' => date('c'),
            ];
            if ($ti !== null) {
                $d['transactions'][$ti] = $tx;
            } else {
                $d['transactions'][] = $tx;
            }
            $debt['originTxId'] = $tx['id'];
        }
        // Texte der Rückzahlungsbuchungen aktuell halten (z. B. nach Namensänderung).
        foreach ($debt['payments'] as $p) {
            $pi = self::find($d['transactions'], $p['txId'] ?? null);
            if ($pi !== null) {
                $d['transactions'][$pi]['description'] = self::debtText($debt, false);
            }
        }
        if ($i === null) {
            $d['debts'][] = $debt;
        } else {
            $d['debts'][$i] = $debt;
        }
        return ['message' => $old ? 'Schuld gespeichert.' : 'Schuld erfasst.', 'id' => $debt['id']];
    }

    private static function debtDelete(array &$d, array $in): array
    {
        $id = self::idOf($in['id'] ?? null);
        $i = self::mustFind($d['debts'], $id, 'Die Schuld');
        $d['transactions'] = array_values(array_filter($d['transactions'],
            fn($t) => !(($t['link']['kind'] ?? null) === 'debt' && $t['link']['debtId'] === $id)));
        array_splice($d['debts'], $i, 1);
        return ['message' => 'Schuld und verknüpfte Kontobewegungen gelöscht.'];
    }

    private static function debtPay(array &$d, array $in, string $today): array
    {
        $i = self::mustFind($d['debts'], self::idOf($in['debtId'] ?? null), 'Die Schuld');
        $debt = $d['debts'][$i];
        $amount = self::money($in['amount'] ?? '', 'Betrag');
        $remaining = $debt['amount'] - self::debtPaid($debt);
        if ($remaining <= 0) {
            self::err('Diese Schuld ist bereits vollständig beglichen.', 409);
        }
        if ($amount > $remaining) {
            self::err('Der Betrag ist grösser als der Restbetrag (CHF ' . Money::format($remaining) . ').');
        }
        $date = self::date($in['date'] ?? null, 'Datum');
        $accountId = self::idOf($in['accountId'] ?? null);
        if ($accountId !== null) {
            self::account($d, $accountId, 'Konto');
        }
        $note = self::text($in['note'] ?? '', 'Notiz', 120, false);
        $pid = cash_new_id();
        $txId = null;
        if ($accountId !== null) {
            [, $payType] = self::debtFlows($debt['direction']);
            $txId = cash_new_id();
            $d['transactions'][] = [
                'id' => $txId, 'type' => $payType, 'date' => $date, 'amount' => $amount, 'accountId' => $accountId,
                'toAccountId' => null, 'categoryId' => null, 'budgetId' => null,
                'description' => self::debtText($debt, false), 'fx' => null,
                'link' => ['kind' => 'debt', 'debtId' => $debt['id'], 'paymentId' => $pid],
                'created' => date('c'), 'updated' => date('c'),
            ];
            $d['settings']['lastAccountId'] = $accountId;
        }
        $d['debts'][$i]['payments'][] = ['id' => $pid, 'date' => $date, 'amount' => $amount, 'accountId' => $accountId, 'txId' => $txId, 'note' => $note];
        $left = $remaining - $amount;
        return ['message' => $left === 0 ? 'Zahlung erfasst – vollständig beglichen.' : 'Teilzahlung erfasst. Rest: CHF ' . Money::format($left) . '.'];
    }

    private static function debtPayDelete(array &$d, array $in): array
    {
        $i = self::mustFind($d['debts'], self::idOf($in['debtId'] ?? null), 'Die Schuld');
        $pi = self::mustFind($d['debts'][$i]['payments'], self::idOf($in['paymentId'] ?? null), 'Die Zahlung');
        $p = $d['debts'][$i]['payments'][$pi];
        $ti = self::find($d['transactions'], $p['txId'] ?? null);
        if ($ti !== null) {
            array_splice($d['transactions'], $ti, 1);
        }
        array_splice($d['debts'][$i]['payments'], $pi, 1);
        return ['message' => 'Zahlung gelöscht.'];
    }

    // ============================================================== Auswertung

    public static function balances(array $d): array
    {
        $bal = [];
        foreach ($d['accounts'] as $a) {
            $bal[$a['id']] = $a['opening'];
        }
        foreach ($d['transactions'] as $t) {
            $amt = $t['amount'];
            $acc = $t['accountId'];
            switch ($t['type']) {
                case 'income':
                case 'debt_in':
                    $bal[$acc] = ($bal[$acc] ?? 0) + $amt;
                    break;
                case 'expense':
                case 'debt_out':
                    $bal[$acc] = ($bal[$acc] ?? 0) - $amt;
                    break;
                case 'transfer':
                    $bal[$acc] = ($bal[$acc] ?? 0) - $amt;
                    $bal[$t['toAccountId']] = ($bal[$t['toAccountId']] ?? 0) + $amt;
                    break;
            }
        }
        return $bal;
    }

    public static function monthBounds(string $month): array
    {
        $start = $month . '-01';
        $end = (new DateTimeImmutable($start))->format('Y-m-t');
        return [$start, $end];
    }

    public static function budgetSpent(array $d, array $b, string $month): array
    {
        [$ms, $me] = self::monthBounds($month);
        $spent = 0;
        $spentMonth = 0;
        foreach ($d['transactions'] as $t) {
            if ($t['type'] !== 'expense') {
                continue;
            }
            $inMonth = $t['date'] >= $ms && $t['date'] <= $me;
            if ($b['kind'] === 'monthly') {
                $assigned = ($t['budgetId'] ?? null) === $b['id'];
                $byCategory = ($t['budgetId'] ?? null) === null && $t['categoryId'] === $b['categoryId'];
                if ($inMonth && ($assigned || $byCategory)) {
                    $spent += $t['amount'];
                }
            } elseif (($t['budgetId'] ?? null) === $b['id']) {
                $spent += $t['amount'];
                if ($inMonth) {
                    $spentMonth += $t['amount'];
                }
            }
        }
        if ($b['kind'] === 'monthly') {
            $spentMonth = $spent;
        }
        return [$spent, $spentMonth];
    }

    public static function state(array $d, string $month, string $today): array
    {
        self::normalize($d);
        [$ms, $me] = self::monthBounds($month);
        $balances = self::balances($d);

        $accounts = [];
        $total = 0;
        foreach ($d['accounts'] as $a) {
            $a['balance'] = $balances[$a['id']] ?? 0;
            if (!$a['archived']) {
                $total += $a['balance'];
            }
            $accounts[] = $a;
        }

        // Monatswerte und Diagramm (kumuliert pro Tag).
        $days = (int)substr($me, 8, 2);
        $inc = array_fill(0, $days, 0);
        $exp = array_fill(0, $days, 0);
        $income = 0;
        $expense = 0;
        foreach ($d['transactions'] as $t) {
            if ($t['date'] < $ms || $t['date'] > $me) {
                continue;
            }
            $day = (int)substr($t['date'], 8, 2) - 1;
            if ($t['type'] === 'income') {
                $income += $t['amount'];
                $inc[$day] += $t['amount'];
            } elseif ($t['type'] === 'expense') {
                $expense += $t['amount'];
                $exp[$day] += $t['amount'];
            }
        }
        $cumI = [];
        $cumE = [];
        $ri = 0;
        $re = 0;
        for ($k = 0; $k < $days; $k++) {
            $ri += $inc[$k];
            $re += $exp[$k];
            $cumI[] = $ri;
            $cumE[] = $re;
        }
        $lastDay = $days;
        if ($today >= $ms && $today <= $me) {
            $lastDay = (int)substr($today, 8, 2);
        } elseif ($today < $ms) {
            $lastDay = 0;
        }

        // Budgets
        $budgets = [];
        foreach ($d['budgets'] as $b) {
            [$spent, $spentMonth] = self::budgetSpent($d, $b, $month);
            $b['spent'] = $spent;
            $b['spentMonth'] = $spentMonth;
            $b['remaining'] = $b['amount'] - $spent;
            if ($b['kind'] === 'monthly') {
                $b['activeInMonth'] = !$b['archived'] && ($b['startMonth'] ?? '0000-00') <= $month;
            } else {
                $b['activeInMonth'] = !$b['archived']
                    && ($b['startDate'] === null || $b['startDate'] <= $me)
                    && ($b['endDate'] === null || $b['endDate'] >= $ms);
            }
            $budgets[] = $b;
        }

        // Rechnungen
        $billNames = [];
        foreach ($d['bills'] as $b) {
            $billNames[$b['id']] = $b['name'];
        }
        $openCount = 0;
        $openSum = 0;
        $overdueCount = 0;
        $overdueSum = 0;
        $nextDue = [];
        foreach ($d['occurrences'] as $o) {
            if ($o['status'] !== 'open') {
                continue;
            }
            if (!isset($nextDue[$o['billId']]) || $o['dueDate'] < $nextDue[$o['billId']]) {
                $nextDue[$o['billId']] = $o['dueDate'];
            }
            if ($o['dueDate'] < $today) {
                $overdueCount++;
                $overdueSum += $o['amount'];
            }
            if ($o['dueDate'] <= $me) {
                $openCount++;
                $openSum += $o['amount'];
            }
        }
        $bills = [];
        foreach ($d['bills'] as $b) {
            $b['nextDue'] = $nextDue[$b['id']] ?? null;
            $bills[] = $b;
        }

        // Sparziele
        $goals = [];
        foreach ($d['goals'] as $g) {
            $g['saved'] = array_sum(array_column($g['allocations'], 'amount'));
            $g['remaining'] = max(0, $g['target'] - $g['saved']);
            $goals[] = $g;
        }

        // Schulden
        $debts = [];
        $iOwe = 0;
        $owedToMe = 0;
        foreach ($d['debts'] as $x) {
            $x['paid'] = self::debtPaid($x);
            $x['remaining'] = $x['amount'] - $x['paid'];
            if ($x['direction'] === 'i_owe') {
                $iOwe += $x['remaining'];
            } else {
                $owedToMe += $x['remaining'];
            }
            $debts[] = $x;
        }

        $tx = $d['transactions'];
        usort($tx, fn($a, $b) => [$b['date'], $b['created']] <=> [$a['date'], $a['created']]);

        return [
            'today' => $today,
            'month' => $month,
            'settings' => [
                'lastAccountId' => $d['settings']['lastAccountId'],
                'lastRates' => (object)$d['settings']['lastRates'],
                'currencies' => self::CURRENCIES,
            ],
            'accounts' => $accounts,
            'categories' => $d['categories'],
            'transactions' => $tx,
            'budgets' => $budgets,
            'bills' => $bills,
            'occurrences' => $d['occurrences'],
            'goals' => $goals,
            'debts' => $debts,
            'summary' => [
                'totalBalance' => $total,
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
                'openBills' => ['count' => $openCount, 'sum' => $openSum, 'overdueCount' => $overdueCount, 'overdueSum' => $overdueSum],
                'debts' => ['iOwe' => $iOwe, 'owedToMe' => $owedToMe],
                'chart' => ['days' => $days, 'lastDay' => $lastDay, 'income' => $cumI, 'expense' => $cumE],
            ],
        ];
    }
}
