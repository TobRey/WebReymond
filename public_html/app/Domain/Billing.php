<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\Db;

/**
 * Was schuldet mir wer, und wann setzt sich das zurück?
 *
 * Der Kern der Unternehmensverwaltung, und die einzige Stelle mit
 * echter Rechnerei. Ein Kunde hat mehrere Kostenposten – Aufbau,
 * Domain, Wartung – und jeder hat seinen eigenen Rhythmus. Der Aufbau
 * wird einmal bezahlt, die Domain jährlich, die Wartung monatlich.
 *
 * Der entscheidende Begriff ist die **Periode**. Eine monatliche
 * Position wird je Monat abgehakt ("2026-09"), eine jährliche je Jahr
 * ("2026"), eine einmalige genau einmal (""). Damit setzt sich nichts
 * "zurück" – es gibt schlicht für jede Periode einen eigenen Eintrag.
 * Das ist wichtig, denn ein Zurücksetzen würde die Vergangenheit
 * löschen, und dann liesse sich nicht mehr sagen, ob der Kunde im Mai
 * bezahlt hat.
 *
 * Beträge stehen überall in Rappen als ganze Zahl. Fränkli als
 * Fliesskommazahl zu führen, rächt sich beim Aufsummieren.
 */
final class Billing
{
    /** Wie oft eine Position anfällt. */
    public const INTERVALS = [
        'einmalig' => 'Einmalig',
        'monatlich' => 'Jeden Monat',
        'jaehrlich' => 'Jedes Jahr',
    ];

    /** Wofür. Frei erweiterbar – die Liste ist nur die Vorauswahl. */
    public const KINDS = [
        'aufbau' => 'Aufbau',
        'wartung' => 'Wartung',
        'domain' => 'Domain',
        'hosting' => 'Hosting',
        'weiteres' => 'Weiteres',
    ];

    /**
     * Die Periode, die für ein Datum gerade läuft.
     *
     * Monatlich: "2026-09". Jährlich: "2026". Einmalig: "" – die gibt es
     * nur ein einziges Mal, unabhängig vom Kalender.
     */
    public static function period(string $interval, ?string $day = null): string
    {
        $day = $day !== null && $day !== '' ? $day : date('Y-m-d');
        $zeit = strtotime($day) ?: time();

        return match ($interval) {
            'monatlich' => date('Y-m', $zeit),
            'jaehrlich' => date('Y', $zeit),
            default => '',
        };
    }

    /**
     * Läuft dieser Posten an diesem Tag überhaupt?
     *
     * Ein Posten mit Enddatum in der Vergangenheit fällt nicht mehr an,
     * einer mit Beginn in der Zukunft noch nicht. Ohne diese Prüfung
     * stünde ein gekündigter Wartungsvertrag ewig als offen da.
     */
    public static function dueOn(array $charge, ?string $day = null): bool
    {
        if (empty($charge['active'])) {
            return false;
        }

        $day = $day !== null && $day !== '' ? $day : date('Y-m-d');

        $von = (string) ($charge['starts_on'] ?? '');
        $bis = (string) ($charge['ends_on'] ?? '');

        if ($von !== '' && $day < $von) {
            return false;
        }

        if ($bis !== '' && $day > $bis) {
            return false;
        }

        return true;
    }

    /**
     * Ist dieser Posten für die laufende Periode bezahlt?
     */
    public static function isPaid(int $chargeId, string $interval, ?string $day = null): bool
    {
        $periode = self::period($interval, $day);

        return (bool) Db::value(
            'SELECT 1 FROM payments WHERE charge_id = :c AND period = :p LIMIT 1',
            ['c' => $chargeId, 'p' => $periode]
        );
    }

    /**
     * Der Stand eines Kunden: jede Position mit Betrag und Häkchen.
     *
     * @return array{
     *     posten: array<int, array<string, mixed>>,
     *     offen_rappen: int,
     *     bezahlt_rappen: int,
     *     monatlich_rappen: int,
     *     jaehrlich_rappen: int
     * }
     */
    public static function statusOf(int $customerId, ?string $day = null): array
    {
        $charges = Db::all(
            'SELECT * FROM charges WHERE customer_id = :c ORDER BY active DESC, kind ASC, id ASC',
            ['c' => $customerId]
        );

        $posten = [];
        $offen = 0;
        $bezahlt = 0;
        $monatlich = 0;
        $jaehrlich = 0;

        foreach ($charges as $charge) {
            $interval = (string) $charge['interval'];
            $betrag = (int) $charge['amount_rappen'];
            $faellig = self::dueOn($charge, $day);
            $periode = self::period($interval, $day);

            $zahlung = Db::first(
                'SELECT * FROM payments WHERE charge_id = :c AND period = :p ORDER BY id DESC LIMIT 1',
                ['c' => (int) $charge['id'], 'p' => $periode]
            );

            $istBezahlt = $zahlung !== null;

            if ($faellig) {
                $istBezahlt ? $bezahlt += $betrag : $offen += $betrag;
            }

            if ($interval === 'monatlich' && !empty($charge['active'])) {
                $monatlich += $betrag;
            }

            if ($interval === 'jaehrlich' && !empty($charge['active'])) {
                $jaehrlich += $betrag;
            }

            $posten[] = $charge + [
                'faellig' => $faellig,
                'periode' => $periode,
                'bezahlt' => $istBezahlt,
                'bezahlt_am' => (string) ($zahlung['paid_on'] ?? ''),
                'zahlung_id' => (int) ($zahlung['id'] ?? 0),
            ];
        }

        return [
            'posten' => $posten,
            'offen_rappen' => $offen,
            'bezahlt_rappen' => $bezahlt,
            'monatlich_rappen' => $monatlich,
            'jaehrlich_rappen' => $jaehrlich,
        ];
    }

    /**
     * Ein Häkchen setzen: Diese Position ist für diese Periode bezahlt.
     *
     * @return int Nummer der Zahlung
     */
    public static function markPaid(int $chargeId, ?string $day = null, string $method = '', string $note = ''): int
    {
        $charge = Db::first('SELECT * FROM charges WHERE id = :id', ['id' => $chargeId]);

        if ($charge === null) {
            return 0;
        }

        $tag = $day !== null && $day !== '' ? $day : date('Y-m-d');
        $periode = self::period((string) $charge['interval'], $tag);

        // Zweimal dasselbe Häkchen ist kein Fehler, aber auch kein
        // zweiter Eintrag – sonst stimmt die Buchhaltung nicht mehr.
        $da = Db::first(
            'SELECT id FROM payments WHERE charge_id = :c AND period = :p',
            ['c' => $chargeId, 'p' => $periode]
        );

        if ($da !== null) {
            return (int) $da['id'];
        }

        return (int) Db::insert('payments', [
            'customer_id' => (int) $charge['customer_id'],
            'charge_id' => $chargeId,
            'period' => $periode,
            'label' => (string) $charge['label'],
            'amount_rappen' => (int) $charge['amount_rappen'],
            'paid_on' => $tag,
            'method' => mb_substr($method, 0, 40),
            'note' => mb_substr($note, 0, 255),
            'created_at' => Db::now(),
        ]);
    }

    /**
     * Das Häkchen wieder wegnehmen.
     *
     * Nur für die laufende Periode – wer einen Irrtum von vor drei
     * Monaten korrigieren will, tut das in der Historie. So kann ein
     * Fehlklick nicht unbemerkt die Vergangenheit verändern.
     */
    public static function markUnpaid(int $chargeId, ?string $day = null): bool
    {
        $charge = Db::first('SELECT * FROM charges WHERE id = :id', ['id' => $chargeId]);

        if ($charge === null) {
            return false;
        }

        $periode = self::period((string) $charge['interval'], $day);

        return Db::delete(
            'payments',
            'charge_id = :c AND period = :p',
            ['c' => $chargeId, 'p' => $periode]
        ) > 0;
    }

    /**
     * Einnahmen und Ausgaben eines Zeitraums.
     *
     * @return array{einnahmen:int, ausgaben:int, gewinn:int,
     *     nach_art:array<string,int>, nach_kategorie:array<string,int>}
     */
    public static function books(string $von, string $bis): array
    {
        $einnahmen = (int) (Db::value(
            'SELECT COALESCE(SUM(amount_rappen), 0) FROM payments WHERE paid_on >= :v AND paid_on <= :b',
            ['v' => $von, 'b' => $bis]
        ) ?? 0);

        $ausgaben = (int) (Db::value(
            'SELECT COALESCE(SUM(amount_rappen), 0) FROM expenses WHERE spent_on >= :v AND spent_on <= :b',
            ['v' => $von, 'b' => $bis]
        ) ?? 0);

        $nachArt = [];

        foreach (Db::all(
            'SELECT c.kind, COALESCE(SUM(p.amount_rappen), 0) AS summe
             FROM payments p LEFT JOIN charges c ON c.id = p.charge_id
             WHERE p.paid_on >= :v AND p.paid_on <= :b
             GROUP BY c.kind',
            ['v' => $von, 'b' => $bis]
        ) as $zeile) {
            $nachArt[(string) ($zeile['kind'] ?? 'weiteres')] = (int) $zeile['summe'];
        }

        $nachKategorie = [];

        foreach (Db::all(
            'SELECT category, COALESCE(SUM(amount_rappen), 0) AS summe FROM expenses
             WHERE spent_on >= :v AND spent_on <= :b GROUP BY category',
            ['v' => $von, 'b' => $bis]
        ) as $zeile) {
            $nachKategorie[(string) $zeile['category']] = (int) $zeile['summe'];
        }

        arsort($nachArt);
        arsort($nachKategorie);

        return [
            'einnahmen' => $einnahmen,
            'ausgaben' => $ausgaben,
            'gewinn' => $einnahmen - $ausgaben,
            'nach_art' => $nachArt,
            'nach_kategorie' => $nachKategorie,
        ];
    }

    /**
     * Alles, was gerade offen ist – über alle Kunden.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function openItems(?string $day = null): array
    {
        $offen = [];

        $kunden = Db::all("SELECT id, name FROM customers WHERE status = 'aktiv' ORDER BY name");

        foreach ($kunden as $kunde) {
            $stand = self::statusOf((int) $kunde['id'], $day);

            foreach ($stand['posten'] as $posten) {
                if ($posten['faellig'] && !$posten['bezahlt']) {
                    $offen[] = [
                        'kunde_id' => (int) $kunde['id'],
                        'kunde' => (string) $kunde['name'],
                        'label' => (string) $posten['label'],
                        'kind' => (string) $posten['kind'],
                        'interval' => (string) $posten['interval'],
                        'periode' => (string) $posten['periode'],
                        'amount_rappen' => (int) $posten['amount_rappen'],
                        'charge_id' => (int) $posten['id'],
                    ];
                }
            }
        }

        return $offen;
    }

    /** Rappen als Betrag zum Lesen: 125000 wird zu "1'250.00". */
    public static function money(int $rappen): string
    {
        return number_format($rappen / 100, 2, '.', "'");
    }

    /** Der umgekehrte Weg: "1'250.50" wird zu 125050. */
    public static function toRappen(string $eingabe): int
    {
        $sauber = str_replace(["'", ' ', ','], ['', '', '.'], trim($eingabe));

        return (int) round(((float) $sauber) * 100);
    }
}
