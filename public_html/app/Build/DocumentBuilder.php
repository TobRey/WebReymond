<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Config, Db, Pdf, Settings};
use WebAtze\Domain\Letterhead;

/**
 * Offerten und Rechnungen.
 *
 * Beide sehen fast gleich aus – der Unterschied ist, was oben steht und
 * ob unten eine Zahlungsfrist folgt. Deshalb eine Klasse für beides,
 * statt zweier, die auseinanderlaufen.
 *
 * Beträge stehen überall in Rappen, als ganze Zahl. Fliesskomma und
 * Geld gehören nicht zusammen: 0.1 + 0.2 ist dort nicht 0.3, und bei
 * einer Rechnung merkt man das erst, wenn sich der Kunde meldet.
 *
 * Nicht dabei ist der Einzahlungsschein (die QR-Rechnung). Der braucht
 * einen QR-Code nach Schweizer Norm, und den halbherzig zu erzeugen
 * wäre schlimmer als keinen: Eine Rechnung, deren Code die Bank nicht
 * liest, kostet den Kunden Zeit. IBAN und Verwendungszweck stehen im
 * Text – damit lässt sich jede Zahlung auslösen.
 */
final class DocumentBuilder
{
    public const KINDS = ['offer' => 'Offerte', 'invoice' => 'Rechnung'];

    public const STATUS = [
        'draft' => 'Entwurf',
        'sent' => 'Verschickt',
        'paid' => 'Bezahlt',
        'cancelled' => 'Storniert',
    ];

    private const MARGIN = 56.0;

    /**
     * Ein Dokument anlegen.
     *
     * @param array{kind:string, project_id:?int, title:string,
     *     recipient:array<string,string>, items:array<int, array<string,mixed>>,
     *     intro:string, outro:string, vat_percent:int, due_days:int} $data
     */
    public static function create(array $data): int
    {
        $kind = isset(self::KINDS[$data['kind'] ?? '']) ? (string) $data['kind'] : 'offer';
        $items = self::cleanItems((array) ($data['items'] ?? []));
        $vat = max(0, min(30, (int) ($data['vat_percent'] ?? 0)));

        $dueDays = max(0, min(180, (int) ($data['due_days'] ?? 30)));

        $id = Db::insert('documents', [
            'kind' => $kind,
            'project_id' => ($data['project_id'] ?? null) ?: null,
            'customer_id' => ($data['customer_id'] ?? null) ?: null,
            'number' => self::nextNumber($kind),
            'title' => mb_substr(trim((string) ($data['title'] ?? '')), 0, 190),
            'recipient' => json_encode(self::cleanRecipient((array) ($data['recipient'] ?? [])), JSON_UNESCAPED_UNICODE),
            'items' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'intro' => mb_substr(trim((string) ($data['intro'] ?? '')), 0, 4000),
            'outro' => mb_substr(trim((string) ($data['outro'] ?? '')), 0, 4000),
            'total_rappen' => self::total($items, $vat),
            'vat_percent' => $vat,
            'currency' => 'CHF',
            'status' => 'draft',
            'issued_on' => date('Y-m-d'),
            'due_on' => $kind === 'invoice' ? date('Y-m-d', strtotime('+' . $dueDays . ' days')) : '',
            'created_at' => Db::now(),
            'updated_at' => Db::now(),
        ]);

        self::render($id);

        return $id;
    }

    /**
     * Das PDF schreiben und beim Dokument vermerken.
     *
     * @return string Pfad zur Datei, oder ''
     */
    public static function render(int $id): string
    {
        $doc = self::find($id);

        if ($doc === null) {
            return '';
        }

        $pdf = new Pdf(self::KINDS[$doc['kind']] . ' ' . $doc['number']);

        $y = self::MARGIN;
        $right = Pdf::WIDTH - self::MARGIN;

        // --- Kopf --------------------------------------------------------
        //
        // Gibt es einen hinterlegten Briefkopf - Logo und Absenderzeilen,
        // etwa aus einer hochgeladenen Word-Vorlage -, wird der genommen.
        // Sonst der Absender aus den Einstellungen wie bisher.
        $briefkopf = Letterhead::current();
        $sender = self::sender();

        if ($briefkopf['logo'] !== '') {
            $hoehe = $pdf->image($briefkopf['logo'], self::MARGIN, $y, (float) $briefkopf['logo_breite']);
            $y += $hoehe + 14;
        } else {
            $pdf->text(self::MARGIN, $y, (string) $sender['name'], 17, true);
            $y += 22;
        }

        $zeilen = $briefkopf['zeilen'] !== [] ? $briefkopf['zeilen'] : $sender['lines'];

        foreach (array_slice($zeilen, 0, 6) as $line) {
            $pdf->text(self::MARGIN, $y, $line, 9, false, [0.42, 0.42, 0.48]);
            $y += 12;
        }

        // --- Empfänger ---------------------------------------------------
        // Mindestens dort, wo das Sichtfenster im Couvert liegt - aber
        // nie im Briefkopf, wenn der hoch ausfällt.
        $y = max(150.0, $y + 24);
        $recipient = (array) $doc['recipient'];

        foreach (['name', 'attn', 'street', 'city', 'country'] as $key) {
            $value = trim((string) ($recipient[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            $pdf->text(self::MARGIN, $y, $value, 11, $key === 'name');
            $y += 15;
        }

        // --- Titel und Datum ---------------------------------------------
        // Wie beim Empfaenger: die gewohnte Hoehe, aber niemals in den
        // Block darueber hinein. Mit einem hohen Briefkopf ruecken beide
        // gemeinsam nach unten.
        $y = max(260.0, $y + 30);

        $pdf->text(self::MARGIN, $y, self::KINDS[$doc['kind']] . ' ' . $doc['number'], 20, true);
        $pdf->textRight($right, $y + 4, self::date((string) $doc['issued_on']), 10, false, [0.42, 0.42, 0.48]);
        $y += 26;

        if ((string) $doc['title'] !== '') {
            $pdf->text(self::MARGIN, $y, (string) $doc['title'], 12, false, [0.30, 0.30, 0.36]);
            $y += 20;
        }

        $y += 8;

        // --- Anrede ------------------------------------------------------
        if ((string) $doc['intro'] !== '') {
            $y = $pdf->paragraph(self::MARGIN, $y, $right - self::MARGIN, (string) $doc['intro'], 10);
            $y += 14;
        }

        // --- Positionen --------------------------------------------------
        $pdf->rect(self::MARGIN, $y - 4, $right - self::MARGIN, 20, [0.95, 0.95, 0.97]);
        $pdf->text(self::MARGIN + 6, $y + 9, 'Leistung', 9, true);
        $pdf->textRight($right - 200, $y + 9, 'Menge', 9, true);
        $pdf->textRight($right - 105, $y + 9, 'Einzelpreis', 9, true);
        $pdf->textRight($right - 6, $y + 9, 'Betrag', 9, true);
        $y += 26;

        $net = 0;

        foreach ((array) $doc['items'] as $item) {
            $quantity = (float) ($item['quantity'] ?? 1);
            $price = (int) ($item['price_rappen'] ?? 0);
            $sum = (int) round($quantity * $price);
            $net += $sum;

            // Eine neue Seite, bevor die Zeile in den Fuss läuft.
            if ($y > Pdf::HEIGHT - 200) {
                $pdf->addPage();
                $y = self::MARGIN;
            }

            $end = $pdf->paragraph(self::MARGIN + 6, $y, $right - self::MARGIN - 220, (string) ($item['label'] ?? ''), 10);

            $pdf->textRight($right - 200, $y, self::quantity($quantity), 10);
            $pdf->textRight($right - 105, $y, self::money($price), 10);
            $pdf->textRight($right - 6, $y, self::money($sum), 10);

            $y = max($end, $y + 16) + 4;
            $pdf->line(self::MARGIN, $y - 2, $right, $y - 2, 0.4, [0.90, 0.90, 0.93]);
        }

        // --- Summen ------------------------------------------------------
        $y += 10;
        $vat = (int) round($net * (int) $doc['vat_percent'] / 100);

        if ((int) $doc['vat_percent'] > 0) {
            $pdf->textRight($right - 105, $y, 'Zwischensumme', 10);
            $pdf->textRight($right - 6, $y, self::money($net), 10);
            $y += 16;

            $pdf->textRight($right - 105, $y, 'Mehrwertsteuer ' . (int) $doc['vat_percent'] . ' %', 10);
            $pdf->textRight($right - 6, $y, self::money($vat), 10);
            $y += 16;
        }

        $pdf->line($right - 230, $y - 4, $right, $y - 4, 0.8, [0.20, 0.20, 0.26]);
        $y += 8;

        $pdf->textRight($right - 105, $y, 'Total ' . (string) $doc['currency'], 12, true);
        $pdf->textRight($right - 6, $y, self::money($net + $vat), 12, true);
        $y += 26;

        if ((int) $doc['vat_percent'] === 0) {
            $pdf->text(self::MARGIN, $y, 'Nicht mehrwertsteuerpflichtig.', 9, false, [0.42, 0.42, 0.48]);
            $y += 16;
        }

        // --- Zahlung -----------------------------------------------------
        if ($doc['kind'] === 'invoice') {
            $y += 8;

            $due = (string) $doc['due_on'];

            $pdf->text(
                self::MARGIN,
                $y,
                $due !== ''
                    ? 'Zahlbar bis ' . self::date($due) . '.'
                    : 'Zahlbar innert 30 Tagen.',
                10,
                true
            );
            $y += 18;

            $iban = trim((string) Settings::get('iban', ''));

            if ($iban !== '') {
                $pdf->text(self::MARGIN, $y, 'IBAN ' . $iban, 10);
                $y += 14;
                $pdf->text(self::MARGIN, $y, 'Verwendungszweck: ' . (string) $doc['number'], 10);
                $y += 14;
            }
        }

        // --- Schluss -----------------------------------------------------
        if ((string) $doc['outro'] !== '') {
            $y += 10;
            $y = $pdf->paragraph(self::MARGIN, $y, $right - self::MARGIN, (string) $doc['outro'], 10);
        }

        // --- Fuss --------------------------------------------------------
        $foot = Pdf::HEIGHT - 46;
        $pdf->line(self::MARGIN, $foot - 12, $right, $foot - 12, 0.4, [0.88, 0.88, 0.91]);
        $pdf->textCenter(
            Pdf::WIDTH / 2,
            $foot,
            implode('  ·  ', array_filter([$sender['name'], $sender['email'], $sender['phone']])),
            8,
            false,
            [0.52, 0.52, 0.58]
        );

        // --- Ablegen -----------------------------------------------------
        $dir = STORAGE_DIR . '/documents';
        ensure_dir($dir);

        $path = $dir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $doc['number']) . '.pdf';

        write_file_atomic($path, $pdf->output());

        Db::update('documents', ['file_path' => $path, 'updated_at' => Db::now()], 'id = :id', ['id' => $id]);

        return $path;
    }

    // ---------------------------------------------------------------- Lesen

    public static function find(int $id): ?array
    {
        $row = Db::first('SELECT * FROM documents WHERE id = :id', ['id' => $id]);

        if ($row === null) {
            return null;
        }

        $row['recipient'] = json_decode((string) $row['recipient'], true) ?: [];
        $row['items'] = json_decode((string) $row['items'], true) ?: [];

        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    public static function listAll(string $kind = '', string $status = ''): array
    {
        $where = [];
        $params = [];

        if (isset(self::KINDS[$kind])) {
            $where[] = 'd.kind = :k';
            $params['k'] = $kind;
        }

        if (isset(self::STATUS[$status])) {
            $where[] = 'd.status = :s';
            $params['s'] = $status;
        }

        $rows = Db::all(
            'SELECT d.*, p.name AS project_name FROM documents d
             LEFT JOIN projects p ON p.id = d.project_id'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY d.id DESC LIMIT 300',
            $params
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['items'] = json_decode((string) $row['items'], true) ?: [];
            $rows[$index]['recipient'] = json_decode((string) $row['recipient'], true) ?: [];
            $rows[$index]['overdue'] = self::isOverdue($row);
        }

        return $rows;
    }

    /** Ist die Rechnung überfällig? */
    public static function isOverdue(array $row): bool
    {
        return (string) $row['kind'] === 'invoice'
            && (string) $row['status'] === 'sent'
            && (string) $row['due_on'] !== ''
            && strtotime((string) $row['due_on']) < strtotime('today');
    }

    /** @return array{offen:int, ueberfaellig:int, bezahlt:int, offen_rappen:int} */
    public static function summary(): array
    {
        $out = ['offen' => 0, 'ueberfaellig' => 0, 'bezahlt' => 0, 'offen_rappen' => 0];

        foreach (Db::all("SELECT * FROM documents WHERE kind = 'invoice'") as $row) {
            $status = (string) $row['status'];

            if ($status === 'paid') {
                $out['bezahlt']++;
                continue;
            }

            if ($status === 'cancelled') {
                continue;
            }

            $out['offen']++;
            $out['offen_rappen'] += (int) $row['total_rappen'];

            if (self::isOverdue($row)) {
                $out['ueberfaellig']++;
            }
        }

        return $out;
    }

    // -------------------------------------------------------------- Ändern

    public static function setStatus(int $id, string $status): bool
    {
        if (!isset(self::STATUS[$status])) {
            return false;
        }

        Db::update('documents', [
            'status' => $status,
            'paid_on' => $status === 'paid' ? date('Y-m-d') : '',
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $id]);

        return true;
    }

    /** Aus einer Offerte eine Rechnung machen. */
    public static function toInvoice(int $offerId): ?int
    {
        $offer = self::find($offerId);

        if ($offer === null || (string) $offer['kind'] !== 'offer') {
            return null;
        }

        return self::create([
            'kind' => 'invoice',
            'project_id' => $offer['project_id'] !== null ? (int) $offer['project_id'] : null,
            'title' => (string) $offer['title'],
            'recipient' => (array) $offer['recipient'],
            'items' => (array) $offer['items'],
            'intro' => 'Besten Dank für den Auftrag. Nachstehend die vereinbarten Leistungen.',
            'outro' => (string) $offer['outro'],
            'vat_percent' => (int) $offer['vat_percent'],
            'due_days' => 30,
        ]);
    }

    // ------------------------------------------------------------ Kleinkram

    /** Fortlaufende Nummer, je Art und Jahr. */
    public static function nextNumber(string $kind): string
    {
        $prefix = ($kind === 'invoice' ? 'R' : 'O') . '-' . date('Y') . '-';

        $last = Db::first(
            'SELECT number FROM documents WHERE kind = :k AND number LIKE :p
             ORDER BY id DESC LIMIT 1',
            ['k' => $kind, 'p' => $prefix . '%']
        );

        $next = 1;

        if ($last !== null && preg_match('/(\d+)$/', (string) $last['number'], $m) === 1) {
            $next = (int) $m[1] + 1;
        }

        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /** @return array<int, array{label:string, quantity:float, price_rappen:int}> */
    private static function cleanItems(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $label = trim((string) ($item['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $out[] = [
                'label' => mb_substr($label, 0, 500),
                'quantity' => max(0.0, min(9999.0, (float) ($item['quantity'] ?? 1))),
                'price_rappen' => max(-99_999_900, min(99_999_900, (int) ($item['price_rappen'] ?? 0))),
            ];
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function cleanRecipient(array $recipient): array
    {
        $out = [];

        foreach (['name', 'attn', 'street', 'city', 'country', 'email'] as $key) {
            $out[$key] = mb_substr(trim((string) ($recipient[$key] ?? '')), 0, 190);
        }

        return $out;
    }

    public static function total(array $items, int $vatPercent): int
    {
        $net = 0;

        foreach ($items as $item) {
            $net += (int) round((float) ($item['quantity'] ?? 1) * (int) ($item['price_rappen'] ?? 0));
        }

        return $net + (int) round($net * $vatPercent / 100);
    }

    /** 123456 Rappen -> "1'234.56" */
    public static function money(int $rappen): string
    {
        return number_format($rappen / 100, 2, '.', "'");
    }

    /** 1.0 -> "1", 2.5 -> "2.5" */
    private static function quantity(float $value): string
    {
        return $value == (int) $value
            ? (string) (int) $value
            : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function date(string $day): string
    {
        $time = strtotime($day);

        return $time === false ? $day : date('d.m.Y', $time);
    }

    /**
     * Der Absender.
     *
     * Kommt aus den Einstellungen – denselben, aus denen auch das
     * Impressum entsteht. Zwei Stellen für dieselbe Adresse wären eine
     * zu viel: Eine davon wäre irgendwann veraltet.
     *
     * @return array{name:string, email:string, phone:string, lines:array<int,string>}
     */
    private static function sender(): array
    {
        $lines = array_values(array_filter([
            trim((string) Settings::get('owner_name', '')),
            trim((string) Settings::get('street', '')),
            trim((string) Settings::get('zip_city', '')),
            trim((string) Settings::get('country', '')),
            trim((string) Settings::get('email', '')),
            trim((string) Settings::get('uid', '')) !== ''
                ? 'MWST-Nr. ' . trim((string) Settings::get('uid', ''))
                : '',
        ]));

        return [
            'name' => (string) (Settings::get('company_name', '') ?: Config::get('mail.from_name', 'WebAtze')),
            'email' => (string) Settings::get('email', ''),
            'phone' => (string) Settings::get('phone', ''),
            'lines' => $lines,
        ];
    }
}
