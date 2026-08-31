<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\Db;

/**
 * Der Fragebogen für den Kunden.
 *
 * Bisher lief es so: Du rufst an, notierst mit, tippst später ab. Was
 * dabei verlorengeht, merkt man erst beim Bauen – die Öffnungszeiten,
 * die genaue Adresse, wie die Firma eigentlich heisst.
 *
 * Also bekommt der Kunde einen Verweis und füllt selbst aus. Kein
 * Konto, keine Anmeldung: Wer den Verweis hat, darf schreiben. Der
 * Verweis läuft nach 30 Tagen ab, und er zeigt auf nichts als dieses
 * eine Formular.
 *
 * Die Antworten füllen danach das Formular unter /create vor. Ändern
 * kannst du alles – der Kunde beschreibt seine Firma, nicht seine
 * Website.
 */
final class Questionnaire
{
    /** So lange gilt ein Verweis. */
    private const DAYS = 30;

    /**
     * Die Fragen.
     *
     * Bewusst wenige, und in der Reihenfolge, in der ein Mensch von
     * seiner Firma erzählt. Ein Formular mit vierzig Feldern füllt
     * niemand aus; eines mit zwölf schon.
     *
     * @return array<int, array{name:string, label:string, type:string,
     *     hint?:string, required?:bool, options?:array<string,string>}>
     */
    public static function fields(): array
    {
        return [
            [
                'name' => 'company_name',
                'label' => 'Wie heisst Ihre Firma?',
                'type' => 'text',
                'required' => true,
                'hint' => 'Genau so, wie es auf der Website stehen soll.',
            ],
            [
                'name' => 'slogan',
                'label' => 'Haben Sie einen Slogan?',
                'type' => 'text',
                'hint' => 'Ein Satz, der sagt, wofür Sie stehen. Wenn nicht, lassen Sie es leer.',
            ],
            [
                'name' => 'industry',
                'label' => 'In welcher Branche sind Sie?',
                'type' => 'text',
                'required' => true,
                'hint' => 'Zum Beispiel: Schreinerei, Zahnarztpraxis, Coiffeursalon.',
            ],
            [
                'name' => 'description',
                'label' => 'Was machen Sie genau?',
                'type' => 'textarea',
                'required' => true,
                'hint' => 'Erzählen Sie es, als würden Sie es jemandem am Telefon erklären. '
                    . 'Je mehr hier steht, desto besser wird der Text auf der Website.',
            ],
            [
                'name' => 'audience',
                'label' => 'Wer sind Ihre Kunden?',
                'type' => 'textarea',
                'hint' => 'Privatpersonen, Firmen, eine bestimmte Region?',
            ],
            [
                'name' => 'services',
                'label' => 'Was bieten Sie an?',
                'type' => 'textarea',
                'hint' => 'Eine Liste genügt – eine Leistung pro Zeile.',
            ],
            [
                'name' => 'tone',
                'label' => 'Wie sollen wir Ihre Besucher ansprechen?',
                'type' => 'choice',
                'options' => [
                    'sie' => 'Höflich, mit "Sie"',
                    'du' => 'Persönlich, mit "du"',
                    'neutral' => 'Sachlich, ohne direkte Anrede',
                ],
            ],
            [
                'name' => 'style',
                'label' => 'Wie soll die Website wirken?',
                'type' => 'choice',
                'options' => [
                    'clean' => 'Klar und aufgeräumt',
                    'bold' => 'Kräftig und selbstbewusst',
                    'elegant' => 'Edel und zurückhaltend',
                    'warm' => 'Warm und einladend',
                    'technical' => 'Technisch und präzise',
                    'playful' => 'Verspielt und lebendig',
                    'natural' => 'Natürlich und erdig',
                    'luxury' => 'Hochwertig und exklusiv',
                ],
            ],
            [
                'name' => 'old_url',
                'label' => 'Haben Sie schon eine Website?',
                'type' => 'text',
                'hint' => 'Die Adresse genügt. Wir sehen sie uns an – gebaut wird trotzdem neu.',
            ],
            [
                'name' => 'contact_email',
                'label' => 'An welche E-Mail-Adresse sollen Anfragen gehen?',
                'type' => 'email',
                'required' => true,
            ],
            [
                'name' => 'contact_phone',
                'label' => 'Telefonnummer',
                'type' => 'text',
            ],
            [
                'name' => 'contact_address',
                'label' => 'Adresse',
                'type' => 'textarea',
                'hint' => 'Strasse, Postleitzahl, Ort. Sie gehört ins Impressum – ohne sie '
                    . 'ist die Website rechtlich unvollständig.',
            ],
            [
                'name' => 'opening_hours',
                'label' => 'Öffnungszeiten',
                'type' => 'textarea',
                'hint' => 'Wenn Sie keine haben, lassen Sie es leer.',
            ],
            [
                'name' => 'social',
                'label' => 'Sind Sie in sozialen Netzwerken?',
                'type' => 'textarea',
                'hint' => 'Eine Adresse pro Zeile.',
            ],
            [
                'name' => 'wishes',
                'label' => 'Gibt es sonst noch etwas, das wir wissen sollten?',
                'type' => 'textarea',
                'hint' => 'Farben, Vorbilder, Dinge, die Ihnen wichtig sind – oder was Sie '
                    . 'auf keinen Fall wollen.',
            ],
        ];
    }

    /**
     * Einen neuen Fragebogen anlegen.
     *
     * @return array{id:int, token:string}
     */
    public static function create(string $company, string $email, string $note = ''): array
    {
        $token = random_token(24);

        $id = Db::insert('questionnaires', [
            'token' => $token,
            'company' => mb_substr(trim($company), 0, 190),
            'email' => mb_substr(trim($email), 0, 190),
            'note' => mb_substr(trim($note), 0, 490),
            'status' => 'open',
            'expires_at' => date('Y-m-d H:i:s', time() + self::DAYS * 86400),
            'created_at' => Db::now(),
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /** Den Fragebogen zu einem Verweis holen – oder null. */
    public static function byToken(string $token): ?array
    {
        if (strlen($token) < 20 || strlen($token) > 100) {
            return null;
        }

        $row = Db::first('SELECT * FROM questionnaires WHERE token = :t', ['t' => $token]);

        if ($row === null) {
            return null;
        }

        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        $row['answers'] = json_decode((string) $row['answers'], true) ?: [];

        return $row;
    }

    /**
     * Antworten prüfen und ablegen.
     *
     * @return array{ok:bool, errors:array<string, string>}
     */
    public static function store(array $row, array $input): array
    {
        $answers = [];
        $errors = [];

        foreach (self::fields() as $field) {
            $name = $field['name'];
            $value = trim((string) ($input[$name] ?? ''));

            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';
            $value = mb_substr($value, 0, ($field['type'] ?? 'text') === 'textarea' ? 4000 : 300);

            if (($field['type'] ?? '') === 'choice' && $value !== ''
                && !isset(($field['options'] ?? [])[$value])) {
                $value = '';
            }

            if (($field['type'] ?? '') === 'email' && $value !== ''
                && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$name] = 'Diese E-Mail-Adresse sieht nicht richtig aus.';
            }

            if (!empty($field['required']) && $value === '') {
                $errors[$name] = 'Das brauchen wir.';
            }

            $answers[$name] = $value;
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        Db::update('questionnaires', [
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'company' => mb_substr($answers['company_name'] ?: (string) $row['company'], 0, 190),
            'email' => mb_substr($answers['contact_email'] ?: (string) $row['email'], 0, 190),
            'status' => 'submitted',
            'submitted_at' => Db::now(),
        ], 'id = :id', ['id' => (int) $row['id']]);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Aus den Antworten die Vorbelegung für /create machen.
     *
     * Nicht eins zu eins: Der Kunde beantwortet Fragen über seine Firma,
     * das Formular fragt nach einer Website. Was nicht passt, wird
     * zusammengefasst statt weggeworfen.
     *
     * @return array<string, string>
     */
    public static function toBrief(array $answers): array
    {
        $extra = [];

        foreach ([
            'services' => 'Angebot',
            'social' => 'Soziale Netzwerke',
            'wishes' => 'Wünsche des Kunden',
        ] as $key => $label) {
            $value = trim((string) ($answers[$key] ?? ''));

            if ($value !== '') {
                $extra[] = $label . ":\n" . $value;
            }
        }

        return [
            'company_name' => (string) ($answers['company_name'] ?? ''),
            'slogan' => (string) ($answers['slogan'] ?? ''),
            'industry' => (string) ($answers['industry'] ?? ''),
            'description' => (string) ($answers['description'] ?? ''),
            'audience' => (string) ($answers['audience'] ?? ''),
            'old_url' => (string) ($answers['old_url'] ?? ''),
            'tone' => (string) ($answers['tone'] ?? 'sie'),
            'style' => (string) ($answers['style'] ?? 'clean'),
            'contact_email' => (string) ($answers['contact_email'] ?? ''),
            'contact_phone' => (string) ($answers['contact_phone'] ?? ''),
            'contact_address' => (string) ($answers['contact_address'] ?? ''),
            'opening_hours' => (string) ($answers['opening_hours'] ?? ''),
            'extra_notes' => implode("\n\n", $extra),
        ];
    }

    /** Abgelaufene Fragebögen wegräumen. */
    public static function purgeExpired(): int
    {
        return Db::delete(
            'questionnaires',
            "expires_at < :now AND status = 'open'",
            ['now' => Db::now()]
        );
    }
}
