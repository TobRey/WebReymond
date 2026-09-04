<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use WebAtze\Core\{Http, Validator};

/**
 * Der Auftrag: alles, was im /create-Formular eingetragen wurde.
 *
 * Diese Klasse ist die einzige Stelle, an der festgelegt ist, welche
 * Angaben es gibt und was erlaubt ist. Formular, Prüfung, Speicherung
 * und der Auftrag an die KI beziehen sich alle darauf.
 */
final class Brief
{
    // Auswahlmöglichkeiten – auch die Grundlage der Prüfung im Formular.

    public const STYLES = [
        'clean' => 'Klar und aufgeräumt',
        'bold' => 'Kräftig und selbstbewusst',
        'elegant' => 'Edel und zurückhaltend',
        'warm' => 'Warm und einladend',
        'technical' => 'Technisch und präzise',
        'playful' => 'Verspielt und lebendig',
        'natural' => 'Natürlich und erdig',
        'luxury' => 'Hochwertig und exklusiv',
    ];

    public const TONES = [
        'du' => 'Persönlich, mit "du"',
        'sie' => 'Höflich, mit "Sie"',
        'neutral' => 'Sachlich, ohne direkte Anrede',
    ];

    public const SCOPES = [
        'onepager' => 'Eine Seite (alles untereinander)',
        'small' => 'Klein: 3–4 Seiten',
        'medium' => 'Mittel: 5–7 Seiten',
        'large' => 'Gross: 8 und mehr Seiten',
    ];

    public const COLOR_MODES = [
        'manual' => 'Farben selbst festlegen',
        'from_old' => 'Von der alten Website übernehmen',
        'auto' => 'Passend zur Branche vorschlagen lassen',
    ];

    public const LOCALES = [
        'de' => 'Nur Deutsch',
        'en' => 'Nur Englisch',
        'de,en' => 'Deutsch und Englisch',
        'de,fr' => 'Deutsch und Französisch',
        'de,en,fr' => 'Deutsch, Englisch und Französisch',
    ];

    public const PROTOCOLS = ['ftp' => 'FTP', 'ftps' => 'FTP mit Verschlüsselung', 'sftp' => 'SFTP (empfohlen)'];

    /** Felder, deren Inhalt niemals in ein Protokoll oder zur KI gelangt. */
    public const SECRET_FIELDS = ['admin_password', 'ftp_password'];

    /**
     * Prüft die Eingaben und gibt entweder die bereinigten Werte
     * oder die Fehlermeldungen zurück.
     *
     * @return array{ok:bool, data:array, errors:array<string,string>}
     */
    public static function validate(array $input): array
    {
        $data = self::normalise($input);

        $v = Validator::make($data)
            ->required('company_name', 'Firmenname')
            ->maxLength('company_name', 120, 'Firmenname')
            ->maxLength('slogan', 160, 'Slogan')
            ->required('industry', 'Branche')
            ->maxLength('industry', 120, 'Branche')
            ->required('description', 'Was macht die Firma')
            ->minLength('description', 25, 'Was macht die Firma')
            ->maxLength('description', 4000, 'Was macht die Firma')
            ->maxLength('design_notes', 4000, 'Designwünsche')
            ->maxLength('extra_notes', 8000, 'Zusätzliche Informationen')
            ->in('style', array_keys(self::STYLES), 'Stil')
            ->in('tone', array_keys(self::TONES), 'Ansprache')
            ->in('scope', array_keys(self::SCOPES), 'Umfang')
            ->in('color_mode', array_keys(self::COLOR_MODES), 'Farbwahl')
            ->in('locales', array_keys(self::LOCALES), 'Sprachen')
            ->url('old_url', 'Adresse der alten Website')
            ->domain('domain', 'Domain')
            ->email('contact_email', 'Kontakt-E-Mail', false)
            ->email('report_email', 'E-Mail für den Wochenbericht', false)
            ->maxLength('contact_phone', 60, 'Telefon')
            ->maxLength('contact_address', 300, 'Adresse')
            ->maxLength('opening_hours', 600, 'Öffnungszeiten')
            ->maxLength('audience', 600, 'Zielgruppe');

        // Farben nur prüfen, wenn sie auch selbst gewählt werden.
        if ($data['color_mode'] === 'manual') {
            $v->hexColor('color_primary', 'Hauptfarbe', true)
              ->hexColor('color_secondary', 'Zweitfarbe', true)
              ->hexColor('color_accent', 'Akzentfarbe', true);
        }

        // Zeigt die alte Adresse ins eigene Netz, wird sie später ohnehin
        // abgewiesen. Das gleich hier zu sagen, ist ehrlicher, als jemanden
        // erst einen Auftrag anstossen und dann ins Leere laufen zu lassen.
        if ($data['old_url'] !== '') {
            $grund = Http::assertPublicUrl($data['old_url']);
            if ($grund !== null) {
                $v->custom('old_url', false, $grund);
            }
        }

        // Farben von der alten Website setzen voraus, dass es eine gibt.
        if ($data['color_mode'] === 'from_old' && $data['old_url'] === '') {
            $v->custom(
                'old_url',
                false,
                'Für "Farben von der alten Website" wird deren Adresse gebraucht.'
            );
        }

        // Backend nur mit Zugangsdaten.
        if ($data['wants_admin']) {
            $v->required('admin_username', 'Admin-Benutzername')
              ->minLength('admin_username', 3, 'Admin-Benutzername')
              ->maxLength('admin_username', 64, 'Admin-Benutzername')
              ->custom(
                  'admin_username',
                  (bool) preg_match('/^[a-zA-Z0-9._-]+$/', $data['admin_username']),
                  'Admin-Benutzername darf nur Buchstaben, Zahlen, Punkt, Strich und Unterstrich enthalten.'
              )
              ->password('admin_password', 'Admin-Passwort', 10);
        }

        // Ein Wochenbericht ohne Zahlen wäre ein leeres Blatt.
        if ($data['report_email'] !== '' && !$data['wants_stats']) {
            $v->custom(
                'wants_stats',
                false,
                'Für den Wochenbericht muss die Besucherzählung eingeschaltet sein.'
            );
        }

        // FTP nur, wenn überhaupt etwas eingetragen wurde.
        if ($data['ftp_host'] !== '' || $data['ftp_username'] !== '') {
            $v->required('ftp_host', 'FTP-Server')
              ->maxLength('ftp_host', 190, 'FTP-Server')
              ->required('ftp_username', 'FTP-Benutzername')
              ->maxLength('ftp_username', 190, 'FTP-Benutzername')
              ->required('ftp_password', 'FTP-Passwort')
              ->in('ftp_protocol', array_keys(self::PROTOCOLS), 'Übertragungsart')
              ->custom(
                  'ftp_port',
                  $data['ftp_port'] >= 1 && $data['ftp_port'] <= 65535,
                  'FTP-Port muss zwischen 1 und 65535 liegen.'
              )
              ->custom(
                  'ftp_path',
                  str_starts_with($data['ftp_path'], '/') && !str_contains($data['ftp_path'], '..'),
                  'Das Zielverzeichnis muss mit / beginnen und darf kein ".." enthalten.'
              );
        }

        return ['ok' => $v->passes(), 'data' => $data, 'errors' => $v->errors()];
    }

    /**
     * Bringt die Rohwerte des Formulars in eine feste Form.
     * Fehlende Felder bekommen einen sinnvollen Standardwert.
     */
    public static function normalise(array $input): array
    {
        $get = static fn (string $key, string $default = ''): string
            => trim((string) ($input[$key] ?? $default));

        $bool = static fn (string $key): bool
            => in_array((string) ($input[$key] ?? ''), ['1', 'on', 'true', 'ja'], true);

        $oldUrl = $get('old_url');
        if ($oldUrl !== '' && !preg_match('#^https?://#i', $oldUrl)) {
            // Ein vergessenes https:// ist der häufigste Tippfehler – wir ergänzen es.
            $oldUrl = 'https://' . $oldUrl;
        }

        $ftpProtocol = $get('ftp_protocol', 'sftp');
        $defaultPort = $ftpProtocol === 'sftp' ? 22 : 21;

        return [
            // Grunddaten
            'company_name' => $get('company_name'),
            'slogan' => $get('slogan'),
            'industry' => $get('industry'),
            'description' => self::multiline($input['description'] ?? '', 4000),
            'audience' => self::multiline($input['audience'] ?? '', 600),

            // Bestehende Website
            'old_url' => $oldUrl,
            'take_texts' => $bool('take_texts'),
            'take_images' => $bool('take_images'),

            // Gestaltung
            'style' => $get('style', 'clean'),
            'tone' => $get('tone', 'sie'),
            'design_notes' => self::multiline($input['design_notes'] ?? '', 4000),
            'color_mode' => $get('color_mode', 'auto'),
            'color_primary' => self::colour($get('color_primary')),
            'color_secondary' => self::colour($get('color_secondary')),
            'color_accent' => self::colour($get('color_accent')),

            // Umfang
            'scope' => $get('scope', 'small'),
            'locales' => $get('locales', 'de'),
            'wanted_pages' => self::multiline($input['wanted_pages'] ?? '', 800),

            // Kontaktangaben für die neue Website
            'contact_email' => $get('contact_email'),
            'contact_phone' => $get('contact_phone'),
            'contact_address' => self::multiline($input['contact_address'] ?? '', 300),
            'opening_hours' => self::multiline($input['opening_hours'] ?? '', 600),
            'social_instagram' => $get('social_instagram'),
            'social_facebook' => $get('social_facebook'),
            'social_linkedin' => $get('social_linkedin'),

            // Kundenbackend
            'wants_admin' => $bool('wants_admin'),
            'admin_username' => $get('admin_username'),
            'admin_password' => (string) ($input['admin_password'] ?? ''),

            // Die Zählung ist wirklich eine Entscheidung: Wer sie nicht
            // will, bekommt keine einzige Zeile davon.
            'wants_stats' => $bool('wants_stats'),
            'report_email' => $get('report_email'),

            // Anleitung, Supportbereich und Zählung sind Entscheidungen -
            // aber solche, die im Auftrag dann VOLLSTÄNDIG ausformuliert
            // werden. Der Unterschied zu früher liegt nicht im Haken,
            // sondern darin, was hinter ihm passiert: Wer ihn setzt,
            // bekommt Adresse, Schlüssel und Zugangscode fertig
            // eingetragen und muss danach nichts mehr einrichten.
            'wants_support' => $bool('wants_support'),
            'wants_docs' => $bool('wants_docs'),

            // Domain und Veröffentlichung
            'domain' => $get('domain') === '' ? '' : Validator::normaliseDomain($get('domain')),
            'domain_mode' => in_array($get('domain_mode', 'none'), ['none', 'point', 'transfer'], true)
                ? $get('domain_mode', 'none')
                : 'none',
            'registrar' => $get('registrar', 'other'),

            'hosting_provider' => $get('hosting_provider', 'other'),
            'ftp_protocol' => in_array($ftpProtocol, array_keys(self::PROTOCOLS), true) ? $ftpProtocol : 'sftp',
            'ftp_host' => $get('ftp_host'),
            'ftp_port' => (int) ($input['ftp_port'] ?? 0) > 0 ? (int) $input['ftp_port'] : $defaultPort,
            'ftp_username' => $get('ftp_username'),
            'ftp_password' => (string) ($input['ftp_password'] ?? ''),
            'ftp_path' => $get('ftp_path', '/public_html'),

            // Freitext für alles Weitere
            'extra_notes' => self::multiline($input['extra_notes'] ?? '', 8000),
        ];
    }

    /**
     * Entfernt die Geheimnisse, bevor der Auftrag gespeichert oder an die
     * KI geschickt wird. Passwörter werden getrennt und verschlüsselt
     * bzw. als Hash abgelegt.
     */
    public static function withoutSecrets(array $data): array
    {
        foreach (self::SECRET_FIELDS as $field) {
            unset($data[$field]);
        }
        return $data;
    }

    /**
     * Beschreibt in Worten, was gewünscht ist – das geht so an die KI.
     * Enthält bewusst keine Zugangsdaten.
     */
    public static function toPromptText(array $data): string
    {
        $lines = [];
        $add = static function (string $label, string $value) use (&$lines): void {
            if (trim($value) !== '') {
                $lines[] = $label . ': ' . trim($value);
            }
        };

        $add('Firmenname', $data['company_name'] ?? '');
        $add('Slogan', $data['slogan'] ?? '');
        $add('Branche', $data['industry'] ?? '');
        $add('Was die Firma macht', $data['description'] ?? '');
        $add('Zielgruppe', $data['audience'] ?? '');
        $add('Gewünschter Stil', self::STYLES[$data['style'] ?? ''] ?? '');
        $add('Ansprache', self::TONES[$data['tone'] ?? ''] ?? '');
        $add('Designwünsche', $data['design_notes'] ?? '');
        $add('Umfang', self::SCOPES[$data['scope'] ?? ''] ?? '');
        $add('Sprachen', self::LOCALES[$data['locales'] ?? ''] ?? '');
        $add('Gewünschte Seiten', $data['wanted_pages'] ?? '');
        $add('E-Mail', $data['contact_email'] ?? '');
        $add('Telefon', $data['contact_phone'] ?? '');
        $add('Adresse', $data['contact_address'] ?? '');
        $add('Öffnungszeiten', $data['opening_hours'] ?? '');
        $add('Instagram', $data['social_instagram'] ?? '');
        $add('Facebook', $data['social_facebook'] ?? '');
        $add('LinkedIn', $data['social_linkedin'] ?? '');
        $add('Domain', $data['domain'] ?? '');
        $add('Zusätzliche Wünsche', $data['extra_notes'] ?? '');

        if (($data['color_mode'] ?? '') === 'manual') {
            $add('Farbwünsche', sprintf(
                'Hauptfarbe %s, Zweitfarbe %s, Akzent %s',
                $data['color_primary'] ?? '',
                $data['color_secondary'] ?? '',
                $data['color_accent'] ?? ''
            ));
        }

        return implode("\n", $lines);
    }

    /** Braucht dieses Projekt eine Datenbank? Nur wenn ausdrücklich gewünscht. */
    public static function needsDatabase(array $data): bool
    {
        $text = mb_strtolower(($data['extra_notes'] ?? '') . ' ' . ($data['description'] ?? ''));

        // Bewusst eng gefasst: eine Datenbank entsteht nur, wenn wirklich
        // etwas gespeichert werden muss. Alles andere bleibt eine
        // statische Website – schneller, sicherer, günstiger.
        $needles = [
            'buchung', 'buchungssystem', 'termin', 'reservation', 'reservierung',
            'shop', 'warenkorb', 'bestellung', 'produkte verwalten',
            'mitglieder', 'login für kunden', 'kundenkonto', 'anmeldung für',
            'verwaltungssystem', 'datenbank', 'kalender', 'anfragen speichern',
        ];

        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function multiline(mixed $value, int $max): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '';
        return mb_substr(trim($text), 0, $max);
    }

    /** Farbwert vereinheitlichen: '2b1b9e' und '#2B1B9E' werden gleich behandelt. */
    private static function colour(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!str_starts_with($value, '#')) {
            $value = '#' . $value;
        }
        $value = strtolower($value);

        // Kurzform #abc auf #aabbcc erweitern
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m)) {
            $value = '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : $value;
    }
}
