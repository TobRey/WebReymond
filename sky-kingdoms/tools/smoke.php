<?php
/**
 * Durchlauftest gegen einen echten Webserver.
 *
 *   php tools/smoke.php [basis-adresse]
 *
 * Ohne Angabe wird ein eigener PHP-Server gestartet und in einem Unterordner
 * UND im Wurzelverzeichnis getestet. Der Test führt die Installation durch,
 * registriert einen Spieler und spielt die wichtigsten Aktionen durch.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

final class Smoke
{
    private string $cookies;
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function __construct(private string $base)
    {
        $this->cookies = tempnam(sys_get_temp_dir(), 'sk-smoke-');
    }

    public function __destruct()
    {
        @unlink($this->cookies);
    }

    public function check(bool $condition, string $message, string $detail = ''): bool
    {
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✓\033[0m " . $message . "\n";

            return true;
        }
        $this->failed++;
        $this->failures[] = $message . ($detail !== '' ? ' – ' . $detail : '');
        echo "  \033[31m✗ " . $message . ($detail !== '' ? ' – ' . $detail : '') . "\033[0m\n";

        return false;
    }

    /** @return array{status:int,body:string} */
    public function request(string $path, ?array $post = null, array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $this->cookies,
            CURLOPT_COOKIEFILE     => $this->cookies,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'body' => 'curl: ' . $error];
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    public function json(string $path, ?array $post = null): array
    {
        $headers = [];
        if ($post !== null) {
            $post['_token'] = $this->token ?? '';
            $headers[] = 'X-SK-CSRF: ' . ($this->token ?? '');
        }
        $response = $this->request($path, $post, $headers);
        $data     = json_decode($response['body'], true);

        return is_array($data) ? $data + ['_status' => $response['status']] : ['_status' => $response['status'], '_raw' => substr($response['body'], 0, 400)];
    }

    public ?string $token = null;

    /** CSRF-Token aus einem Formular lesen. */
    public function tokenFrom(string $html): string
    {
        if (preg_match('/name="_token" value="([a-f0-9]{64})"/', $html, $m)) {
            return $m[1];
        }

        return '';
    }

    public function summary(): int
    {
        echo "\n" . str_repeat('─', 60) . "\n";
        if ($this->failed === 0) {
            echo "\033[32mDurchlauftest bestanden: {$this->passed} Prüfungen.\033[0m\n";

            return 0;
        }
        echo "\033[31m{$this->failed} von " . ($this->passed + $this->failed) . " Prüfungen fehlgeschlagen:\033[0m\n";
        foreach ($this->failures as $failure) {
            echo '  • ' . $failure . "\n";
        }

        return 1;
    }
}

/** Kompletter Durchlauf gegen eine Basisadresse. */
function run(string $base, string $label): int
{
    echo "\n\033[1m» Durchlauftest: {$label} ({$base})\033[0m\n";
    $s = new Smoke($base);

    // ---------------------------------------------------------------
    // 1. Installation
    // ---------------------------------------------------------------
    $page = $s->request('/install/');
    $s->check($page['status'] === 200, 'Installer erreichbar', 'HTTP ' . $page['status']);
    $s->check(str_contains($page['body'], 'Alles bereit'), 'Systemprüfung bestanden');
    $s->token = $s->tokenFrom($page['body']);

    $page = $s->request('/install/?step=2');
    $s->token = $s->tokenFrom($page['body']);
    $page = $s->request('/install/?step=2', [
        '_token'   => $s->token,
        'name'     => 'Sky Kingdoms',
        'timezone' => 'Europe/Zurich',
        'offline'  => '24',
        'open'     => '1',
    ]);
    $s->check(str_contains($page['body'], 'Administratorkonto'), 'Einstellungen gespeichert');

    $s->token = $s->tokenFrom($page['body']);
    $page = $s->request('/install/?step=3', [
        '_token'           => $s->token,
        'username'         => 'Testadmin',
        'email'            => 'admin@example.test',
        'password'         => 'HimmelBurg#2026',
        'password_confirm' => 'HimmelBurg#2026',
    ]);
    $s->check(str_contains($page['body'], 'Alles bereit?'), 'Adminkonto angenommen');

    $s->token = $s->tokenFrom($page['body']);
    $page = $s->request('/install/?step=4', ['_token' => $s->token]);
    $s->check(str_contains($page['body'], 'Geschafft'), 'Installation abgeschlossen');

    $page = $s->request('/install/');
    $s->check(str_contains($page['body'], 'Bereits installiert'), 'Installer sperrt sich selbst');

    // Datenschutz: Spielstände dürfen auch ohne .htaccess nichts preisgeben.
    // Der eingebaute PHP-Server kennt keine .htaccess – genau deshalb trägt jede
    // Datendatei zusätzlich einen exit-Wächter.
    $leak = $s->request('/storage/data/meta/install.json.php');
    $s->check(!str_contains($leak['body'], 'installed_at') && !str_contains($leak['body'], '"php"'),
        'Spielstände geben auch ohne .htaccess nichts preis', 'Antwort: ' . substr(trim($leak['body']), 0, 60));

    $leakLog = $s->request('/storage/logs/app-' . date('Y-m-d') . '.log.php');
    $s->check(!str_contains($leakLog['body'], 'FEHLER') && !str_contains($leakLog['body'], 'INFO'),
        'Protokolle geben nichts preis');

    // ---------------------------------------------------------------
    // 2. Registrierung und Anmeldung
    // ---------------------------------------------------------------
    $page = $s->request('/?p=register');
    $s->token = $s->tokenFrom($page['body']);
    $s->check(str_contains($page['body'], 'Königreich gründen'), 'Registrierungsformular erreichbar');
    $s->check(!str_contains($page['body'], 'Als Gast spielen'), 'Kein Gastmodus vorhanden');

    $page = $s->request('/?p=register', [
        '_token'           => $s->token,
        'username'         => 'Testspieler',
        'email'            => 'spieler@example.test',
        'kingdom'          => 'Wolkenfeste',
        'password'         => 'WolkenFeste#77',
        'password_confirm' => 'WolkenFeste#77',
        'rules'            => '1',
    ]);
    $s->check(str_contains($page['body'], 'sk-game') || str_contains($page['body'], 'SK_BOOT'), 'Registrierung führt direkt ins Spiel');

    // Doppelter Name muss scheitern
    $s2 = new Smoke($base);
    $reg = $s2->request('/?p=register');
    $s2->token = $s2->tokenFrom($reg['body']);
    $dupe = $s2->request('/?p=register', [
        '_token' => $s2->token, 'username' => 'Testspieler', 'email' => 'anders@example.test',
        'password' => 'WolkenFeste#77', 'password_confirm' => 'WolkenFeste#77', 'rules' => '1',
    ]);
    $s->check(str_contains($dupe['body'], 'schon vergeben'), 'Doppelter Spielername wird abgelehnt');

    $dupe2 = $s2->request('/?p=register', [
        '_token' => $s2->token, 'username' => 'Anders', 'email' => 'spieler@example.test',
        'password' => 'WolkenFeste#77', 'password_confirm' => 'WolkenFeste#77', 'rules' => '1',
    ]);
    $s->check(str_contains($dupe2['body'], 'bereits ein Konto'), 'Doppelte E-Mail wird abgelehnt');

    $weak = $s2->request('/?p=register', [
        '_token' => $s2->token, 'username' => 'Schwach', 'email' => 'schwach@example.test',
        'password' => 'passwort', 'password_confirm' => 'passwort', 'rules' => '1',
    ]);
    $s->check(str_contains($weak['body'], 'Zeichen lang') || str_contains($weak['body'], 'zu bekannt'),
        'Schwaches Passwort wird abgelehnt');

    // ---------------------------------------------------------------
    // 3. API ohne Anmeldung
    // ---------------------------------------------------------------
    $anon = new Smoke($base);
    $denied = $anon->json('/api/?a=state');
    $s->check(($denied['_status'] ?? 0) === 401, 'API verweigert Zugriff ohne Anmeldung', 'HTTP ' . ($denied['_status'] ?? '?'));

    // ---------------------------------------------------------------
    // 4. Spielzustand
    // ---------------------------------------------------------------
    $page = $s->request('/?p=game');
    if (preg_match('/"csrf":"([a-f0-9]{64})"/', $page['body'], $m)) {
        $s->token = $m[1];
    }
    $s->check($s->token !== '', 'CSRF-Token im Spiel vorhanden');

    $state = $s->json('/api/?a=state');
    $s->check(($state['ok'] ?? false) === true, 'Spielzustand abrufbar');
    $world = (array) ($state['world'] ?? []);
    $s->check(count((array) ($world['islands'] ?? [])) === 4, 'Vier Startinseln vorhanden');
    $s->check(count((array) ($world['routes'] ?? [])) === 3, 'Drei Startrouten vorhanden');
    $s->check(($world['store']['wood'] ?? 0) > 0, 'Startrohstoffe im Lager');

    // Gebäude finden
    $lumber = null;
    $castle = null;
    $resourceIsland = null;
    foreach ((array) ($world['buildings'] ?? []) as $id => $building) {
        if ($building['type'] === 'lumberjack') { $lumber = $id; $resourceIsland = $building['island']; }
        if ($building['type'] === 'castle') { $castle = $id; }
    }
    $s->check($lumber !== null && $castle !== null, 'Startgebäude gefunden');

    // ---------------------------------------------------------------
    // 5. Bauen und Transport
    // ---------------------------------------------------------------
    $build = $s->json('/api/?a=build', ['island' => $resourceIsland, 'type' => 'lumberjack', 'x' => '2', 'y' => '5']);
    $s->check(($build['ok'] ?? false) === true, 'Neues Gebäude errichtet', (string) ($build['error'] ?? ''));

    $collide = $s->json('/api/?a=build', ['island' => $resourceIsland, 'type' => 'lumberjack', 'x' => '2', 'y' => '5']);
    $s->check(($collide['ok'] ?? true) === false, 'Besetztes Feld wird abgelehnt');

    $offgrid = $s->json('/api/?a=build', ['island' => $resourceIsland, 'type' => 'lumberjack', 'x' => '99', 'y' => '99']);
    $s->check(($offgrid['ok'] ?? true) === false, 'Feld ausserhalb der Insel wird abgelehnt');

    $wrongIsland = $s->json('/api/?a=build', ['island' => $resourceIsland, 'type' => 'castle', 'x' => '3', 'y' => '3']);
    $s->check(($wrongIsland['ok'] ?? true) === false, 'Falscher Inseltyp wird abgelehnt');

    if (($build['ok'] ?? false) && isset($build['building'])) {
        $route = $s->json('/api/?a=route_create', [
            'src' => 'b:' . $build['building'], 'dst' => 'store', 'resource' => 'wood', 'mode' => 'foot',
        ]);
        $s->check(($route['ok'] ?? false) === true, 'Neue Transportroute angelegt', (string) ($route['error'] ?? ''));

        if (($route['ok'] ?? false) && isset($route['route'])) {
            $carrier = $s->json('/api/?a=route_carrier_add', ['id' => $route['route'], 'count' => '1']);
            $s->check(($carrier['ok'] ?? false) === true, 'Träger hinzugefügt', (string) ($carrier['error'] ?? ''));

            $rates = $s->json('/api/?a=rates');
            $s->check(isset($rates['rates']['routes'][$route['route']]), 'Route erscheint in der Transportübersicht');
        }
    }

    $badResource = $s->json('/api/?a=route_create', ['src' => 'b:' . $lumber, 'dst' => 'store', 'resource' => 'gold']);
    $s->check(($badResource['ok'] ?? true) === false, 'Route für nicht erzeugte Ware wird abgelehnt');

    // ---------------------------------------------------------------
    // 6. Verbesserungen: +1, +10, MAX
    // ---------------------------------------------------------------
    $preview = $s->json('/api/?a=preview&type=building&id=' . $lumber);
    $s->check(isset($preview['preview']['steps']['1']['cost']), 'Vorschau liefert Kosten für +1');
    $s->check(isset($preview['preview']['steps']['max']), 'Vorschau liefert MAX');
    $s->check(($preview['preview']['benefits'][0]['percent'] ?? 0) > 0, 'Vorschau zeigt prozentualen Vorteil');

    $up1 = $s->json('/api/?a=upgrade', ['id' => $lumber, 'steps' => '1']);
    $s->check(($up1['ok'] ?? false) && ($up1['level'] ?? 0) === 2, 'Verbesserung +1 sofort wirksam');

    $preview10 = $s->json('/api/?a=preview&type=building&id=' . $lumber);
    $afford10  = (bool) ($preview10['preview']['steps']['10']['afford'] ?? false);
    $levelNow  = (int) ($preview10['preview']['level'] ?? 0);
    $up10 = $s->json('/api/?a=upgrade', ['id' => $lumber, 'steps' => '10']);
    if ($afford10) {
        $s->check(($up10['ok'] ?? false) && ($up10['level'] ?? 0) === $levelNow + 10,
            'Verbesserung +10 sofort wirksam', (string) ($up10['error'] ?? ''));
    } else {
        $s->check(($up10['ok'] ?? true) === false,
            'Nicht bezahlbares +10 wird korrekt abgelehnt (passend zur Vorschau)');
    }

    $upMax = $s->json('/api/?a=upgrade', ['id' => $lumber, 'steps' => 'max']);
    $s->check(($upMax['ok'] ?? false) || str_contains((string) ($upMax['error'] ?? ''), 'fehlen'),
        'MAX-Verbesserung liefert ein sinnvolles Ergebnis');

    $broke = $s->json('/api/?a=upgrade', ['id' => $lumber, 'steps' => '100']);
    $s->check(($broke['ok'] ?? true) === false, 'Zu teure Verbesserung wird abgelehnt');
    $s->check(!empty($broke['missing']), 'Fehlende Rohstoffe werden benannt');

    // Manipulation: fremde Gebäudekennung
    $foreign = $s->json('/api/?a=upgrade', ['id' => 'b99999', 'steps' => '1']);
    $s->check(($foreign['ok'] ?? true) === false, 'Unbekanntes Gebäude wird abgelehnt');

    // Manipulation: negative Schritte
    $negative = $s->json('/api/?a=upgrade', ['id' => $lumber, 'steps' => '-50']);
    $s->check(($negative['ok'] ?? false) === true && ($negative['steps'] ?? 0) >= 1 || ($negative['ok'] ?? true) === false,
        'Negative Schrittzahl wird nicht ausgenutzt');

    // CSRF fehlt
    $noCsrf = $s->request('/api/?a=upgrade', ['id' => $lumber, 'steps' => '1']);
    $s->check($noCsrf['status'] === 419, 'Ohne CSRF-Token wird abgelehnt', 'HTTP ' . $noCsrf['status']);

    // GET statt POST
    $getOnly = $s->json('/api/?a=upgrade&id=' . $lumber . '&steps=1');
    $s->check(($getOnly['_status'] ?? 0) === 405 || ($getOnly['ok'] ?? true) === false, 'Verändernde Aktion nur per POST');

    // ---------------------------------------------------------------
    // 7. Forschung, Lager, Rangliste
    // ---------------------------------------------------------------
    $research = $s->json('/api/?a=research_list');
    $s->check(!empty($research['research']), 'Forschungsliste abrufbar');

    $ranking = $s->json('/api/?a=ranking');
    $s->check(!empty($ranking['ranking']['entries']), 'Rangliste enthält Einträge');

    $quests = $s->json('/api/?a=quests');
    $s->check(!empty($quests['quests']), 'Aufgabenliste abrufbar');

    // ---------------------------------------------------------------
    // 8. Angriffssystem
    // ---------------------------------------------------------------
    $targets = $s->json('/api/?a=attack_targets');
    $s->check(($targets['ok'] ?? false) === true, 'Gegnersuche antwortet');

    $selfAttack = $s->json('/api/?a=attack_start', ['target' => 'selbst', 'mission' => 'loot_storage', 'squad' => '{}']);
    $s->check(($selfAttack['ok'] ?? true) === false, 'Angriff ohne gültiges Ziel wird abgelehnt');

    // ---------------------------------------------------------------
    // 9. Abmelden
    // ---------------------------------------------------------------
    $page = $s->request('/?p=konto');
    $s->token = $s->tokenFrom($page['body']);
    $out = $s->request('/?p=logout', ['_token' => $s->token]);
    $s->check(str_contains($out['body'], 'Anmelden'), 'Abmeldung funktioniert');

    $after = $s->json('/api/?a=state');
    $s->check(($after['_status'] ?? 0) === 401, 'Nach der Abmeldung kein API-Zugriff mehr');

    return $s->summary();
}

// =====================================================================
// Server starten und beide Varianten testen
// =====================================================================

$given = $argv[1] ?? '';
if ($given !== '') {
    exit(run(rtrim($given, '/'), 'vorgegebene Adresse'));
}

$port  = 8200 + random_int(0, 300);
$total = 0;

foreach ([['', 'Wurzelverzeichnis'], ['/spiele/himmelreich', 'Unterordner']] as [$prefix, $label]) {
    $serveRoot = sys_get_temp_dir() . '/sk-smoke-' . bin2hex(random_bytes(4));
    $target    = $serveRoot . $prefix;
    mkdir($target, 0777, true);

    // Projekt kopieren (ohne Spielstände)
    $source = dirname(__DIR__);
    exec('cp -r ' . escapeshellarg($source) . '/. ' . escapeshellarg($target));
    exec('rm -f ' . escapeshellarg($target) . '/config/config.php');
    exec('rm -rf ' . escapeshellarg($target) . '/storage/data/users ' . escapeshellarg($target) . '/storage/data/index '
        . escapeshellarg($target) . '/storage/data/meta ' . escapeshellarg($target) . '/storage/data/limits');

    $port++;
    $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $process = proc_open('php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($serveRoot), $descriptors, $pipes);

    // Warten, bis der Server antwortet
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($socket !== false) {
            fclose($socket);
            break;
        }
        usleep(100000);
    }

    $total += run('http://127.0.0.1:' . $port . $prefix, $label);

    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    exec('rm -rf ' . escapeshellarg($serveRoot));
}

exit($total === 0 ? 0 : 1);
