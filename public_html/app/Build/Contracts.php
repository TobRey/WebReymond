<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Db, Logger};
use WebAtze\Domain\Websites;

/**
 * Wartungsverträge.
 *
 * Eine Website ist nicht fertig, wenn sie steht. PHP bekommt neue
 * Versionen, Inhalte veralten, jemand will eine Seite dazu. Ein Vertrag
 * macht daraus etwas Planbares – für den Kunden und für dich.
 *
 * Was hier passiert, ist bewusst wenig: Der Vertrag merkt sich, wann
 * die nächste Rechnung fällig ist, und legt sie an, wenn es soweit ist.
 * Sie geht dabei nicht automatisch hinaus – sie liegt als Entwurf
 * bereit. Eine Rechnung, die ohne Ansehen verschickt wird, ist
 * irgendwann die falsche.
 */
final class Contracts
{
    public const PLANS = [
        'basis' => 'Basis',
        'plus' => 'Plus',
        'rundum' => 'Rundum',
    ];

    /** Was in welchem Umfang enthalten ist – Text, keine Zahlen. */
    public const INCLUDES = [
        'basis' => [
            'Sicherung jeden Tag, 14 Tage rückwärts abrufbar',
            'Erreichbarkeit im Blick',
            'Kleine Textänderungen',
        ],
        'plus' => [
            'Alles aus Basis',
            'Bilder tauschen und Seiten ergänzen',
            'Wochenbericht zu den Besucherzahlen',
            'Antwort auf Fragen innert zwei Werktagen',
        ],
        'rundum' => [
            'Alles aus Plus',
            'Jährlich ein Durchgang: Texte, Bilder, Gestaltung',
            'Neue Abschnitte und Unterseiten nach Bedarf',
            'Antwort am selben Werktag',
        ],
    ];

    /**
     * Einen Vertrag anlegen.
     *
     * @param array{project_id:int, plan:string, price_rappen:int,
     *     interval_months:int, started_on:string, note:string} $data
     */
    public static function create(array $data): int
    {
        $plan = isset(self::PLANS[$data['plan'] ?? '']) ? (string) $data['plan'] : 'basis';
        $interval = max(1, min(24, (int) ($data['interval_months'] ?? 12)));

        $start = self::day((string) ($data['started_on'] ?? '')) ?: date('Y-m-d');

        return Db::insert('contracts', [
            'project_id' => (int) $data['project_id'],
            // Damit der Vertrag auf der Seite seines Kunden auftaucht
            // und nicht nur in der Gesamtliste. Ohne das hier war die
            // Spalte da und blieb bei jedem neuen Vertrag leer.
            'customer_id' => Websites::kundeVon((int) $data['project_id']) ?: null,
            'plan' => $plan,
            'price_rappen' => max(0, min(99_999_900, (int) ($data['price_rappen'] ?? 0))),
            'interval_months' => $interval,
            'started_on' => $start,
            'next_invoice_on' => date('Y-m-d', strtotime($start . ' +' . $interval . ' months')),
            'cancelled_on' => '',
            'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 2000),
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);
    }

    public static function cancel(int $id): void
    {
        Db::update('contracts', [
            'cancelled_on' => date('Y-m-d'),
            'next_invoice_on' => '',
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $id]);
    }

    /** @return array<int, array<string, mixed>> */
    public static function listAll(): array
    {
        $rows = Db::all(
            'SELECT c.*, p.name AS project_name, p.slug AS project_slug, p.domain, p.brief
             FROM contracts c LEFT JOIN projects p ON p.id = c.project_id
             ORDER BY c.cancelled_on ASC, c.next_invoice_on ASC, c.id DESC LIMIT 300'
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['due'] = self::isDue($row);
            $rows[$index]['active'] = (string) $row['cancelled_on'] === '';
        }

        return $rows;
    }

    public static function isDue(array $row): bool
    {
        $next = (string) $row['next_invoice_on'];

        return (string) $row['cancelled_on'] === ''
            && $next !== ''
            && strtotime($next) <= strtotime('today');
    }

    /**
     * Fällige Verträge abrechnen.
     *
     * Läuft aus der Pflege. Erzeugt Rechnungsentwürfe und schiebt das
     * nächste Fälligkeitsdatum weiter. Verschickt wird nichts: Das
     * bleibt eine Entscheidung, die ein Mensch trifft.
     *
     * @return int Anzahl angelegter Entwürfe
     */
    public static function billDue(): int
    {
        $due = Db::all(
            // Zwei Platzhalter fuer denselben leeren Text: MySQL
            // erlaubt einen benannten Platzhalter nur einmal je Abfrage.
            "SELECT c.*, p.name AS project_name, p.brief FROM contracts c
             LEFT JOIN projects p ON p.id = c.project_id
             WHERE c.cancelled_on = :empty1 AND c.next_invoice_on <> :empty2
               AND c.next_invoice_on <= :today
             ORDER BY c.id ASC LIMIT 10",
            ['empty1' => '', 'empty2' => '', 'today' => date('Y-m-d')]
        );

        $made = 0;

        foreach ($due as $contract) {
            $brief = json_decode((string) ($contract['brief'] ?? '{}'), true) ?: [];

            $months = (int) $contract['interval_months'];

            $id = DocumentBuilder::create([
                'kind' => 'invoice',
                'project_id' => (int) $contract['project_id'],
                'title' => 'Wartung ' . (string) ($contract['project_name'] ?? ''),
                'recipient' => [
                    'name' => (string) ($contract['project_name'] ?? ''),
                    'street' => self::firstLine((string) ($brief['contact_address'] ?? '')),
                    'city' => self::secondLine((string) ($brief['contact_address'] ?? '')),
                    'email' => (string) ($brief['contact_email'] ?? ''),
                ],
                'items' => [[
                    'label' => 'Wartung "' . (self::PLANS[(string) $contract['plan']] ?? '') . '" für '
                        . $months . ' ' . ($months === 1 ? 'Monat' : 'Monate') . ', ab '
                        . date('d.m.Y', strtotime((string) $contract['next_invoice_on'])),
                    'quantity' => 1,
                    'price_rappen' => (int) $contract['price_rappen'],
                ]],
                'intro' => 'Guten Tag' . "\n\n"
                    . 'Nachstehend die Rechnung für die Wartung Ihrer Website.',
                'outro' => 'Besten Dank für Ihr Vertrauen.',
                'vat_percent' => 0,
                'due_days' => 30,
            ]);

            if ($id > 0) {
                $made++;
            }

            Db::update('contracts', [
                'next_invoice_on' => date(
                    'Y-m-d',
                    strtotime((string) $contract['next_invoice_on'] . ' +' . $months . ' months')
                ),
                'updated_at' => Db::now(),
            ], 'id = :id', ['id' => (int) $contract['id']]);
        }

        if ($made > 0) {
            Logger::info('Wartungsrechnungen angelegt', ['anzahl' => $made]);
        }

        return $made;
    }

    // ------------------------------------------------------------ Kleinkram

    private static function day(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private static function firstLine(string $text): string
    {
        return trim(explode("\n", str_replace("\r\n", "\n", $text))[0] ?? '');
    }

    private static function secondLine(string $text): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $text));

        return trim(implode(', ', array_map('trim', array_slice($lines, 1))));
    }
}
