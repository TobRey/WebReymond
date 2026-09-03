<?php

declare(strict_types=1);

namespace WebAtze\Domain;

use SensitiveParameter;
use WebAtze\Core\{Audit, Crypto, Db, RateLimit, Session};

/**
 * Der Tresor: alle Zugänge der Kunden an einem Ort.
 *
 * Was hier geschützt wird, ist nicht das eigene Konto, sondern fremdes
 * Eigentum – die Server-Passwörter von Leuten, die WebAtze vertraut
 * haben. Entsprechend streng ist der Aufbau:
 *
 * 1. Jedes Passwort liegt mit libsodium verschlüsselt in der Datenbank.
 *    Der Schlüssel steht in app/config.php, also NICHT in der Datenbank.
 *    Eine gestohlene Datenbanksicherung allein ist damit wertlos.
 *
 * 2. Ein Passwort steht nie in einer HTML-Seite. Auch nicht als
 *    Sternchenfeld – wer «Quelltext anzeigen» drückt, sähe es sonst.
 *    Es wird einzeln geholt, auf ausdrücklichen Knopfdruck.
 *
 * 3. Vor dem ersten Ansehen muss das Anmeldepasswort erneut eingegeben
 *    werden. Der Tresor bleibt danach fünfzehn Minuten offen. Wer den
 *    Rechner offen stehen lässt, verliert dadurch nicht gleich alles.
 *
 * 4. Falsche Versuche werden gezählt und gebremst.
 *
 * 5. Jeder Blick steht im Protokoll: wer, wann, auf welchen Zugang.
 *
 * Was der Tresor NICHT kann: ein vergessenes Passwort wiederherstellen,
 * wenn crypto_key in der config.php ausgetauscht wurde. Das ist keine
 * Lücke, sondern der Sinn der Sache.
 */
final class Vault
{
    /** So lange bleibt der Tresor nach dem Aufschliessen offen. */
    public const OPEN_SECONDS = 900;

    private const SESSION_KEY = 'vault_open_until';

    /** Wofür der Zugang gilt – hilft beim Sortieren, sonst nichts. */
    public const KINDS = [
        'ftp' => 'FTP / SFTP',
        'cpanel' => 'Hosting-Verwaltung',
        'domain' => 'Domain-Verwaltung',
        'backend' => 'Website-Backend',
        'datenbank' => 'Datenbank',
        'mail' => 'E-Mail-Konto',
        'social' => 'Soziale Netzwerke',
        'weiteres' => 'Weiteres',
    ];

    // ------------------------------------------------------------------
    // Auf- und zuschliessen
    // ------------------------------------------------------------------

    public static function isOpen(): bool
    {
        return (int) Session::get(self::SESSION_KEY, 0) > time();
    }

    public static function openSecondsLeft(): int
    {
        return max(0, (int) Session::get(self::SESSION_KEY, 0) - time());
    }

    /**
     * Mit dem eigenen Anmeldepasswort aufschliessen.
     *
     * @return array{ok:bool, meldung:string}
     */
    public static function unlock(#[SensitiveParameter] string $passwort, string $ip): array
    {
        $eimer = 'vault:' . $ip;

        if (RateLimit::tooMany($eimer, 5)) {
            return [
                'ok' => false,
                'meldung' => 'Zu viele Versuche. Bitte in '
                    . max(1, (int) ceil(RateLimit::retryAfter($eimer) / 60)) . ' Minuten nochmal.',
            ];
        }

        $benutzer = Session::user();

        if ($benutzer === null) {
            return ['ok' => false, 'meldung' => 'Nicht angemeldet.'];
        }

        // Session::user() gibt den Passwort-Abdruck bewusst nicht mit -
        // er hat in einer Sitzung nichts verloren. Hier wird er einmal
        // gezielt geholt und sonst nirgends.
        $abdruck = (string) Db::value(
            'SELECT password_hash FROM users WHERE id = :id',
            ['id' => (int) $benutzer['id']],
            ''
        );

        RateLimit::hit($eimer, 5, 900);

        if ($passwort === '' || $abdruck === '' || !Crypto::checkPassword($passwort, $abdruck)) {
            Audit::log('vault.unlock.failed');

            return ['ok' => false, 'meldung' => 'Das Passwort stimmt nicht.'];
        }

        RateLimit::clear($eimer);
        Session::put(self::SESSION_KEY, time() + self::OPEN_SECONDS);
        Audit::log('vault.unlock');

        return ['ok' => true, 'meldung' => 'Der Tresor ist für 15 Minuten offen.'];
    }

    public static function lock(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    // ------------------------------------------------------------------
    // Lesen
    // ------------------------------------------------------------------

    /**
     * Alle Einträge – ohne die Passwörter.
     *
     * Diese Liste geht in die HTML-Seite. Deshalb steht das Geheimnis
     * nicht darin, nicht einmal verschlüsselt.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listAll(string $suche = ''): array
    {
        $sql = 'SELECT s.id, s.customer_id, s.label, s.kind, s.username, s.url, s.note,
                       s.opened_at, s.created_at, s.updated_at,
                       (CASE WHEN s.secret_enc IS NULL OR s.secret_enc = :leer THEN 0 ELSE 1 END) AS hat_geheimnis,
                       c.name AS kunde
                FROM secrets s
                LEFT JOIN customers c ON c.id = s.customer_id';

        $werte = ['leer' => ''];
        $suche = trim($suche);

        if ($suche !== '') {
            $sql .= ' WHERE LOWER(s.label) LIKE :suche OR LOWER(s.username) LIKE :suche
                      OR LOWER(s.url) LIKE :suche OR LOWER(c.name) LIKE :suche';
            $werte['suche'] = '%' . mb_strtolower($suche) . '%';
        }

        $sql .= ' ORDER BY c.name ASC, s.label ASC';

        return Db::all($sql, $werte);
    }

    public static function find(int $id): ?array
    {
        return Db::first(
            'SELECT id, customer_id, label, kind, username, url, note, created_at, updated_at
             FROM secrets WHERE id = :id',
            ['id' => $id]
        );
    }

    public static function count(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM secrets', [], 0);
    }

    /**
     * Das Passwort selbst – der einzige Weg dorthin.
     *
     * @return array{ok:bool, meldung:string, geheimnis:string}
     */
    public static function reveal(int $id): array
    {
        if (!self::isOpen()) {
            return ['ok' => false, 'meldung' => 'Der Tresor ist zu.', 'geheimnis' => ''];
        }

        $zeile = Db::first('SELECT label, secret_enc FROM secrets WHERE id = :id', ['id' => $id]);

        if ($zeile === null) {
            return ['ok' => false, 'meldung' => 'Diesen Eintrag gibt es nicht.', 'geheimnis' => ''];
        }

        $roh = (string) ($zeile['secret_enc'] ?? '');

        if ($roh === '') {
            return ['ok' => false, 'meldung' => 'Zu diesem Eintrag ist kein Passwort hinterlegt.', 'geheimnis' => ''];
        }

        $grund = Crypto::status();

        if ($grund !== '') {
            return ['ok' => false, 'meldung' => $grund, 'geheimnis' => ''];
        }

        $klar = Crypto::decrypt($roh);

        if ($klar === null) {
            return [
                'ok' => false,
                'meldung' => 'Das Passwort lässt sich nicht mehr entschlüsseln. '
                    . 'Das passiert, wenn crypto_key in der config.php gewechselt wurde.',
                'geheimnis' => '',
            ];
        }

        Db::update('secrets', ['opened_at' => Db::now()], 'id = :id', ['id' => $id]);
        Audit::log('vault.reveal', (string) $zeile['label'], ['id' => $id]);

        return ['ok' => true, 'meldung' => '', 'geheimnis' => $klar];
    }

    // ------------------------------------------------------------------
    // Schreiben
    // ------------------------------------------------------------------

    /**
     * Anlegen oder ändern.
     *
     * Bleibt das Passwortfeld beim Ändern leer, bleibt das bisherige
     * stehen. Sonst würde jedes Korrigieren eines Tippfehlers im Namen
     * das Passwort löschen.
     *
     * @param array<string, mixed> $daten
     * @return array{ok:bool, meldung:string, id:int}
     */
    public static function save(array $daten, ?int $id = null): array
    {
        $label = mb_substr(trim((string) ($daten['label'] ?? '')), 0, 191);

        if ($label === '') {
            return ['ok' => false, 'meldung' => 'Ein Name fehlt – sonst weiss später niemand, wofür das gilt.', 'id' => 0];
        }

        $satz = [
            'customer_id' => ((int) ($daten['customer_id'] ?? 0)) ?: null,
            'label' => $label,
            'kind' => isset(self::KINDS[(string) ($daten['kind'] ?? '')]) ? (string) $daten['kind'] : 'weiteres',
            'username' => mb_substr(trim((string) ($daten['username'] ?? '')), 0, 191),
            'url' => mb_substr(trim((string) ($daten['url'] ?? '')), 0, 255),
            'note' => mb_substr(trim((string) ($daten['note'] ?? '')), 0, 2000),
            'updated_at' => Db::now(),
        ];

        $geheimnis = (string) ($daten['secret'] ?? '');

        if ($geheimnis !== '') {
            // Erst fragen, ob verschlüsseln überhaupt geht. Sonst fliegt
            // hier eine Ausnahme, und der Tresor antwortet mit einer
            // Fehlerseite, die nicht sagt, woran es liegt.
            $grund = Crypto::status();

            if ($grund !== '') {
                return [
                    'ok' => false,
                    'meldung' => 'Das Passwort lässt sich nicht verschlüsseln, deshalb wurde '
                        . 'nichts gespeichert. ' . $grund,
                    'id' => 0,
                ];
            }

            $satz['secret_enc'] = Crypto::encrypt($geheimnis);
            sodium_memzero($geheimnis);
        }

        if ($id !== null) {
            Db::update('secrets', $satz, 'id = :id', ['id' => $id]);
            Audit::log('vault.update', $label, ['id' => $id]);

            return ['ok' => true, 'meldung' => 'Gespeichert.', 'id' => $id];
        }

        $satz['created_at'] = Db::now();
        $neu = Db::insert('secrets', $satz);
        Audit::log('vault.create', $label, ['id' => $neu]);

        return ['ok' => true, 'meldung' => 'Der Zugang liegt im Tresor.', 'id' => $neu];
    }

    public static function remove(int $id): bool
    {
        $zeile = self::find($id);

        if ($zeile === null) {
            return false;
        }

        Audit::log('vault.delete', (string) $zeile['label'], ['id' => $id]);

        return Db::delete('secrets', 'id = :id', ['id' => $id]) > 0;
    }

    /**
     * Wie viel verschlüsseltes Material insgesamt herumliegt.
     *
     * Die Zahl entscheidet, ob ein neuer Schlüssel gefahrlos ist: Ein
     * Schlüsseltausch macht alles Bisherige unlesbar. Bei null ist
     * nichts zu verlieren.
     */
    public static function encryptedCount(): int
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM secrets WHERE secret_enc IS NOT NULL AND secret_enc <> :leer',
            ['leer' => ''],
            0
        ) + (int) Db::value(
            'SELECT COUNT(*) FROM deploy_targets WHERE secret IS NOT NULL AND secret <> :leer',
            ['leer' => ''],
            0
        );
    }

    /**
     * Ein neues Passwort vorschlagen.
     *
     * Ohne die Zeichen, die auf Papier oder am Telefon Ärger machen:
     * l und 1, O und 0, I. Ein Passwort, das nach dem Diktieren nicht
     * passt, wird durch ein kürzeres ersetzt – und das ist schlechter
     * als vier Zeichen weniger Auswahl.
     */
    public static function suggest(int $laenge = 20): string
    {
        $zeichen = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!?%+-=@#';
        $laenge = max(12, min(64, $laenge));
        $wort = '';

        for ($i = 0; $i < $laenge; $i++) {
            $wort .= $zeichen[random_int(0, strlen($zeichen) - 1)];
        }

        return $wort;
    }

    /**
     * Die FTP-Zugänge, die schon in deploy_targets stehen, in den Tresor holen.
     *
     * Sie liegen dort bereits verschlüsselt, aber verstreut je Projekt.
     * Wer alle Passwörter an einem Ort sucht, soll sie dort auch finden.
     *
     * @return int wie viele übernommen wurden
     */
    public static function adoptDeployTargets(): int
    {
        if (!Crypto::isReady()) {
            return 0;
        }

        $uebernommen = 0;

        $ziele = Db::all(
            'SELECT d.*, p.name AS projekt FROM deploy_targets d
             LEFT JOIN projects p ON p.id = d.project_id'
        );

        foreach ($ziele as $ziel) {
            $name = trim((string) ($ziel['projekt'] ?? '')) ?: (string) $ziel['host'];
            $label = $name . ' – FTP';

            if (Db::value('SELECT id FROM secrets WHERE label = :l LIMIT 1', ['l' => $label])) {
                continue;
            }

            $klar = Crypto::decrypt((string) ($ziel['secret'] ?? ''));

            if ($klar === null) {
                continue;
            }

            self::save([
                'label' => $label,
                'kind' => 'ftp',
                'username' => (string) $ziel['username'],
                'url' => (string) $ziel['host'],
                'secret' => $klar,
                'note' => 'Automatisch aus den Hochlade-Einstellungen übernommen.',
            ]);

            $uebernommen++;
        }

        return $uebernommen;
    }
}
