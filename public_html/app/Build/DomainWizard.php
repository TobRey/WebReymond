<?php

declare(strict_types=1);

namespace WebAtze\Build;

use WebAtze\Core\{Db, Http, Logger};

/**
 * Begleitet den Umzug einer Domain – Schritt für Schritt.
 *
 * Warum kein vollautomatischer Transfer? Dafür bräuchte es ein
 * Händlerkonto bei einem Registrar samt Guthaben und Verantwortung für
 * fremde Domains. Der Assistent hier macht stattdessen das, was in der
 * Praxis fehlt: Er sagt genau, wo beim jeweiligen Anbieter welcher
 * Knopf sitzt – und prüft danach selbst nach, ob es funktioniert hat.
 *
 * Zwei Wege:
 *   point     Die Domain bleibt beim Anbieter, es wird nur umgezeigt.
 *             Schnell, umkehrbar, für die meisten Fälle richtig.
 *   transfer  Die Domain wechselt den Anbieter. Dauert bis zu sieben
 *             Tage und braucht einen Auth-Code.
 */
final class DomainWizard
{
    /** Die Schritte für "nur umzeigen". */
    private static function pointSteps(array $registrar, string $targetIp): array
    {
        return [
            [
                'key' => 'ip',
                'title' => 'Serveradresse bereitlegen',
                'text' => $targetIp !== ''
                    ? 'Die Website liegt auf der Adresse <code>' . e($targetIp) . '</code>. '
                      . 'Diese Zahl wird gleich gebraucht.'
                    : 'Zuerst muss feststehen, auf welchem Server die Website liegt. '
                      . 'Die IP-Adresse steht im Kundenbereich des Hosting-Anbieters, '
                      . 'meist unter „Serverdaten" oder in der Willkommens-E-Mail.',
                'check' => 'target',
            ],
            [
                'key' => 'dns',
                'title' => 'DNS-Eintrag ändern',
                'text' => $registrar['dns'] ?? '',
                'detail' => 'Gesucht ist ein Eintrag vom <strong>Typ A</strong> mit dem Namen '
                    . '<code>@</code> (manchmal auch leer oder der Domainname selbst). '
                    . 'Dort die Serveradresse eintragen. '
                    . 'Zusätzlich sollte ein Eintrag für <code>www</code> bestehen – entweder '
                    . 'ebenfalls Typ A mit derselben Adresse oder Typ CNAME auf die Domain.',
                'check' => 'dns',
            ],
            [
                'key' => 'wait',
                'title' => 'Warten',
                'text' => 'Die Änderung braucht Zeit, bis sie überall angekommen ist – '
                    . 'meist zwischen zehn Minuten und vier Stunden, in seltenen Fällen bis zu '
                    . 'einem Tag. In dieser Zeit sehen manche Besucher schon die neue Seite '
                    . 'und andere noch die alte. Das ist normal und geht vorbei.',
                'check' => 'dns',
            ],
            [
                'key' => 'ssl',
                'title' => 'Verschlüsselung einrichten',
                'text' => 'Sobald die Domain auf den neuen Server zeigt, im Kundenbereich des '
                    . 'Hosting-Anbieters das kostenlose SSL-Zertifikat (Let\'s Encrypt) aktivieren. '
                    . 'Bei den meisten Anbietern reicht ein Klick; oft passiert es sogar von selbst.',
                'check' => 'https',
            ],
        ];
    }

    /** Die Schritte für den echten Umzug. */
    private static function transferSteps(array $registrar): array
    {
        return [
            [
                'key' => 'lock',
                'title' => 'Transfersperre aufheben',
                'text' => $registrar['lock'] ?? '',
                'detail' => 'Fast jede Domain ist gegen versehentliche Umzüge gesperrt. '
                    . 'Ohne dieses Aufheben schlägt der Umzug ohne Erklärung fehl.',
                'check' => 'manual',
            ],
            [
                'key' => 'authcode',
                'title' => 'Auth-Code anfordern',
                'text' => $registrar['authcode'] ?? '',
                'detail' => 'Der Auth-Code (auch EPP-Code) ist der Schlüssel zur Domain. '
                    . 'Er kommt oft per E-Mail an die im Register hinterlegte Adresse und '
                    . 'ist meist nur wenige Tage gültig. '
                    . '<strong>Wichtig:</strong> Diesen Code niemals per unverschlüsselter '
                    . 'E-Mail weitergeben – wer ihn hat, kann die Domain übernehmen.',
                'check' => 'manual',
            ],
            [
                'key' => 'whois',
                'title' => 'Kontaktangaben prüfen',
                'text' => 'Die im Register hinterlegte E-Mail-Adresse muss erreichbar sein – '
                    . 'dorthin geht die Bestätigungsanfrage für den Umzug. '
                    . 'Ist sie veraltet, zuerst dort ändern.',
                'check' => 'manual',
            ],
            [
                'key' => 'order',
                'title' => 'Umzug beim neuen Anbieter beauftragen',
                'text' => 'Beim neuen Anbieter „Domain umziehen" wählen, den Domainnamen und '
                    . 'den Auth-Code eingeben. Danach kommt eine Bestätigungsmail.',
                'check' => 'manual',
            ],
            [
                'key' => 'confirm',
                'title' => 'Bestätigen und warten',
                'text' => 'Den Verweis in der Bestätigungsmail anklicken. Danach dauert der '
                    . 'Umzug je nach Endung bis zu sieben Tage. '
                    . 'Die Website bleibt in dieser Zeit erreichbar – solange die alten '
                    . 'DNS-Einträge bestehen bleiben.',
                'check' => 'whois',
            ],
            [
                'key' => 'dns',
                'title' => 'DNS-Eintrag setzen',
                'text' => 'Nach dem Umzug beim neuen Anbieter den A-Eintrag auf die '
                    . 'Serveradresse setzen.',
                'check' => 'dns',
            ],
            [
                'key' => 'ssl',
                'title' => 'Verschlüsselung einrichten',
                'text' => 'Zum Schluss das kostenlose SSL-Zertifikat aktivieren.',
                'check' => 'https',
            ],
        ];
    }

    /** Die Schritte für ein Projekt zusammenstellen. */
    public static function steps(array $transfer): array
    {
        $providers = require APP_DIR . '/Support/providers.php';
        $registrar = $providers['registrar'][(string) $transfer['registrar']]
            ?? $providers['registrar']['other'];

        $steps = (string) $transfer['mode'] === 'transfer'
            ? self::transferSteps($registrar)
            : self::pointSteps($registrar, (string) ($transfer['target_ip'] ?? ''));

        $checks = json_decode((string) ($transfer['checks'] ?? '{}'), true) ?: [];
        $current = (int) $transfer['current_step'];

        foreach ($steps as $index => $step) {
            $steps[$index]['index'] = $index;
            $steps[$index]['done'] = $index < $current;
            $steps[$index]['active'] = $index === $current;
            // Nach 'check' nachschlagen, nicht nach 'key': jeder Schritt sagt
            // selbst, welche Prüfung ihn bestätigt. Schritte mit 'manual'
            // lassen sich nicht automatisch nachsehen und bleiben ohne Ergebnis.
            $steps[$index]['result'] = $checks[(string) ($step['check'] ?? '')] ?? null;
        }

        return $steps;
    }

    /**
     * Nachsehen, ob die Domain schon dort ankommt, wo sie soll.
     *
     * Das ist der eigentliche Nutzen des Assistenten: Statt zu raten, ob
     * die Änderung durchgekommen ist, wird nachgeschaut.
     */
    public static function check(array $transfer): array
    {
        $domain = (string) $transfer['domain'];
        $expectedIp = trim((string) ($transfer['target_ip'] ?? ''));

        $result = [
            'checked_at' => date('c'),
            'domain' => $domain,
        ];

        // --- Wohin zeigt die Domain gerade? ---------------------------
        $addresses = Http::resolveHost($domain);
        $result['dns'] = [
            'ok' => $addresses !== [],
            'addresses' => $addresses,
            'message' => $addresses === []
                ? 'Die Domain löst noch auf keine Adresse auf. Entweder ist die Änderung '
                  . 'noch nicht durchgekommen, oder es fehlt noch ein Eintrag.'
                : 'Die Domain zeigt auf: ' . implode(', ', $addresses),
        ];

        if ($expectedIp !== '' && $addresses !== []) {
            $matches = in_array($expectedIp, $addresses, true);
            $result['dns']['ok'] = $matches;
            $result['dns']['message'] = $matches
                ? 'Die Domain zeigt auf den richtigen Server (' . $expectedIp . ').'
                : 'Die Domain zeigt auf ' . implode(', ', $addresses)
                  . ' – erwartet wäre ' . $expectedIp . '. '
                  . 'Entweder ist die Änderung noch unterwegs, oder der Eintrag stimmt nicht.';
        }

        // --- www ------------------------------------------------------
        $wwwAddresses = Http::resolveHost('www.' . $domain);
        $result['www'] = [
            'ok' => $wwwAddresses !== [],
            'addresses' => $wwwAddresses,
            'message' => $wwwAddresses === []
                ? 'Für www gibt es noch keinen Eintrag. Besucher, die www eintippen, '
                  . 'landen im Nichts – deshalb sollte er gesetzt werden.'
                : 'www zeigt auf: ' . implode(', ', $wwwAddresses),
        ];

        // --- Ist die Website erreichbar? ------------------------------
        $https = Http::get('https://' . $domain . '/', 10);
        $result['https'] = [
            'ok' => $https['ok'],
            'status' => $https['status'],
            'message' => $https['ok']
                ? 'Die Website ist über https erreichbar.'
                : ($https['error'] !== ''
                    ? 'Über https noch nicht erreichbar: ' . $https['error']
                    : 'Über https noch nicht erreichbar (Status ' . $https['status'] . ').'),
        ];

        if (!$https['ok']) {
            $http = Http::get('http://' . $domain . '/', 10);
            $result['http'] = [
                'ok' => $http['ok'],
                'message' => $http['ok']
                    ? 'Über http erreichbar, aber noch ohne Verschlüsselung. '
                      . 'Jetzt fehlt nur noch das SSL-Zertifikat.'
                    : 'Auch über http noch nicht erreichbar.',
            ];
        }

        // --- Nameserver ----------------------------------------------
        $ns = @dns_get_record($domain, DNS_NS);
        $nameservers = [];
        foreach (is_array($ns) ? $ns : [] as $record) {
            if (isset($record['target'])) {
                $nameservers[] = (string) $record['target'];
            }
        }
        $result['whois'] = [
            'ok' => $nameservers !== [],
            'nameservers' => $nameservers,
            'message' => $nameservers === []
                ? 'Es sind keine Nameserver auffindbar.'
                : 'Zuständige Nameserver: ' . implode(', ', $nameservers),
        ];

        $result['target'] = [
            'ok' => $expectedIp !== '',
            'message' => $expectedIp !== ''
                ? 'Zieladresse hinterlegt: ' . $expectedIp
                : 'Es ist noch keine Zieladresse eingetragen.',
        ];

        return $result;
    }

    /** Prüfergebnis speichern und den Fortschritt anpassen. */
    public static function runCheck(int $transferId): array
    {
        $transfer = Db::first('SELECT * FROM domain_transfers WHERE id = :id', ['id' => $transferId]);
        if ($transfer === null) {
            return ['ok' => false, 'error' => 'Es ist kein Domainumzug hinterlegt.'];
        }

        $result = self::check($transfer);
        $steps = self::steps($transfer);
        $current = (int) $transfer['current_step'];

        // Alle Schritte durchgehen, deren Prüfung schon erfüllt ist.
        while ($current < count($steps)) {
            $check = (string) ($steps[$current]['check'] ?? 'manual');

            if ($check === 'manual') {
                break;
            }
            if (($result[$check]['ok'] ?? false) !== true) {
                break;
            }
            $current++;
        }

        Db::update('domain_transfers', [
            'checks' => $result,
            'current_step' => $current,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $transferId]);

        return ['ok' => true, 'result' => $result, 'step' => $current, 'total' => count($steps)];
    }

    /**
     * Einen Schritt von Hand weiterschalten oder zurücknehmen.
     *
     * Nötig, weil sich die ersten Schritte eines echten Umzugs nicht von
     * aussen nachsehen lassen: Ob die Transfersperre offen ist und der
     * Auth-Code angekommen, weiss nur, wer im Kundenbereich nachschaut.
     * Ohne diesen Weg bliebe der Assistent für immer beim ersten Schritt.
     */
    public static function advance(int $transferId, bool $forward = true): array
    {
        $transfer = Db::first('SELECT * FROM domain_transfers WHERE id = :id', ['id' => $transferId]);
        if ($transfer === null) {
            return ['ok' => false, 'error' => 'Es ist kein Domainumzug hinterlegt.'];
        }

        $total = count(self::steps($transfer));
        $current = (int) $transfer['current_step'] + ($forward ? 1 : -1);
        $current = max(0, min($current, $total));

        Db::update('domain_transfers', [
            'current_step' => $current,
            'updated_at' => Db::now(),
        ], 'id = :id', ['id' => $transferId]);

        return ['ok' => true, 'step' => $current, 'total' => $total, 'done' => $current >= $total];
    }
}
