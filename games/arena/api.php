<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/Defaults.php';
require_once __DIR__ . '/lib/Store.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Validate.php';
require_once __DIR__ . '/lib/Accounts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** @param array<string, mixed> $payload */
function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['ok' => false, 'error' => $message], $status);
}

/** @return array<string, mixed> */
function input(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    $data = is_array($decoded) ? $decoded : $_POST;
    return $data;
}

function requireAdmin(): void
{
    if (!Auth::isAdmin()) {
        fail('Nicht angemeldet.', 401);
    }
    $token = (string) (input()['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    if (!isset($_SESSION['arena_csrf']) || !hash_equals((string) $_SESSION['arena_csrf'], $token)) {
        fail('Ungültiges Sicherheitstoken.', 403);
    }
}

function csrf(): string
{
    Auth::start();
    if (empty($_SESSION['arena_csrf'])) {
        $_SESSION['arena_csrf'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['arena_csrf'];
}

/** Prueft, ob ein Asset noch irgendwo verwendet wird. @param array<string,mixed> $data */
function assetUsage(array $data, string $path): array
{
    $used = [];
    foreach ($data['weapons'] as $w) {
        if (($w['sprite'] ?? '') === $path) {
            $used[] = 'Waffe: ' . $w['name'];
        }
    }
    foreach ($data['enemies'] as $e) {
        if (($e['sprite'] ?? '') === $path) {
            $used[] = 'Gegner: ' . $e['name'];
        }
    }
    foreach ($data['maps'] as $m) {
        if (($m['image'] ?? '') === $path) {
            $used[] = 'Map: ' . $m['name'];
        }
    }
    foreach (['spriteFront', 'spriteBack', 'spriteSide', 'spriteDust'] as $k) {
        if (($data['player'][$k] ?? '') === $path) {
            $used[] = 'Spieler-Sprite';
        }
    }
    return $used;
}

$store = new Store();
$action = (string) ($_GET['action'] ?? input()['action'] ?? '');
Auth::start();

switch ($action) {
    /* ------------------------------------------------------------- öffentlich */
    case 'content': {
        respond(['ok' => true, 'content' => $store->read()]);
    }

    case 'session': {
        respond(['ok' => true, 'admin' => Auth::isAdmin(), 'csrf' => Auth::isAdmin() ? csrf() : null]);
    }

    case 'login': {
        $code = (string) (input()['code'] ?? '');
        $result = Auth::login($code);
        if (!$result['ok']) {
            usleep(300000); // bremst Rateversuche zusätzlich aus
            fail($result['error'] ?? 'Login fehlgeschlagen.', 401);
        }
        respond(['ok' => true, 'csrf' => csrf()]);
    }

    case 'logout': {
        Auth::logout();
        respond(['ok' => true]);
    }

    /* --------------------------------------------------------- Spielerkonten */
    case 'account': {
        $account = Accounts::current();
        respond([
            'ok' => true,
            'account' => $account ? Accounts::publicView($account) : null,
        ]);
    }

    case 'account.register':
    case 'account.login': {
        $name = (string) (input()['name'] ?? '');
        $password = (string) (input()['password'] ?? '');
        $result = $action === 'account.register'
            ? Accounts::register($name, $password)
            : Accounts::login($name, $password);
        if (!$result['ok']) {
            usleep(250000);
            fail($result['error'] ?? 'Anmeldung fehlgeschlagen.', 401);
        }
        respond(['ok' => true, 'account' => $result['account']]);
    }

    case 'account.logout': {
        Accounts::logout();
        respond(['ok' => true]);
    }

    case 'account.run': {
        // Ergebnis eines Durchlaufs: Erfahrung, Bestwerte, Bestenliste.
        $account = Accounts::submitRun(is_array(input()['result'] ?? null) ? input()['result'] : []);
        respond(['ok' => true, 'account' => $account]);
    }

    case 'account.unlock': {
        $id = (string) (input()['id'] ?? '');
        $content = $store->read();
        $character = null;
        foreach ($content['characters'] as $entry) {
            if (($entry['id'] ?? '') === $id) {
                $character = $entry;
                break;
            }
        }
        if ($character === null) {
            fail('Unbekannter Charakter.', 404);
        }
        if (!empty($character['starter'])) {
            fail('Dieser Charakter ist von Anfang an frei.');
        }
        // Preis kommt aus den Serverdaten, nicht aus der Anfrage.
        $result = Accounts::unlock($id, (int) $character['unlockCost']);
        if (!$result['ok']) {
            fail($result['error'] ?? 'Freischalten nicht möglich.', 400);
        }
        respond(['ok' => true, 'account' => $result['account']]);
    }

    case 'leaderboard': {
        $limit = (int) (input()['limit'] ?? $_GET['limit'] ?? 25);
        respond(['ok' => true, 'entries' => Accounts::leaderboard($limit)]);
    }

    /* -------------------------------------------------------------------- Admin */
    case 'put': {
        requireAdmin();
        $section = (string) (input()['section'] ?? '');
        $item = input()['item'] ?? null;
        if (!in_array($section, ['weapons', 'enemies', 'upgrades', 'maps', 'characters'], true) || !is_array($item)) {
            fail('Ungültiger Abschnitt.');
        }
        $clean = match ($section) {
            'weapons' => Validate::weapon($item),
            'enemies' => Validate::enemy($item),
            'upgrades' => Validate::upgrade($item),
            'maps' => Validate::map($item),
            'characters' => Validate::character($item),
        };
        if ($section === 'maps' && $clean['image'] === '') {
            fail('Eine Map braucht ein Bild.');
        }
        if ($section === 'enemies' && $clean['sprite'] === '') {
            fail('Ein Gegner braucht ein Sprite.');
        }

        $data = $store->mutate(function (array $data) use ($section, $clean): array {
            $found = false;
            foreach ($data[$section] as $i => $existing) {
                if (($existing['id'] ?? '') === $clean['id']) {
                    $data[$section][$i] = $clean;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $data[$section][] = $clean;
            }
            return $data;
        });
        respond(['ok' => true, 'item' => $clean, 'content' => $data]);
    }

    case 'delete': {
        requireAdmin();
        $section = (string) (input()['section'] ?? '');
        $id = (string) (input()['id'] ?? '');
        if (!in_array($section, ['weapons', 'enemies', 'upgrades', 'maps', 'characters'], true) || $id === '') {
            fail('Ungültiger Abschnitt.');
        }
        $data = $store->mutate(function (array $data) use ($section, $id): array {
            $data[$section] = array_values(array_filter(
                $data[$section],
                static fn(array $item): bool => ($item['id'] ?? '') !== $id
            ));
            return $data;
        });
        respond(['ok' => true, 'content' => $data]);
    }

    case 'settings': {
        requireAdmin();
        $player = input()['player'] ?? null;
        $balance = input()['balance'] ?? null;
        $audio = input()['audio'] ?? null;
        $data = $store->mutate(function (array $data) use ($player, $balance, $audio): array {
            if (is_array($player)) {
                $data['player'] = Validate::player($player);
            }
            if (is_array($balance)) {
                $data['balance'] = Validate::balance($balance);
            }
            if (is_array($audio)) {
                $data['audio'] = Validate::audio($audio);
            }
            return $data;
        });
        respond(['ok' => true, 'content' => $data]);
    }

    case 'usage': {
        requireAdmin();
        $path = (string) (input()['path'] ?? '');
        respond(['ok' => true, 'usedBy' => assetUsage($store->read(), $path)]);
    }

    case 'upload': {
        requireAdmin();
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            fail('Keine Datei empfangen.');
        }
        $file = $_FILES['file'];
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code !== UPLOAD_ERR_OK) {
            // Die häufigste Ursache ist ein zu niedriges Serverlimit - das
            // steht sonst nirgends und kostet unnötig Suchzeit.
            $limit = ini_get('upload_max_filesize');
            fail(match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    'Die Datei ist größer als das Serverlimit (' . $limit . '). '
                    . 'In .user.ini bzw. .htaccess steht bereits 24M - falls dein Hoster das ignoriert, '
                    . 'bitte upload_max_filesize und post_max_size dort erhöhen.',
                UPLOAD_ERR_PARTIAL => 'Die Datei wurde nur teilweise übertragen. Bitte erneut versuchen.',
                UPLOAD_ERR_NO_FILE => 'Es wurde keine Datei ausgewählt.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                    'Der Server kann die Datei nicht ablegen (Schreibrechte prüfen).',
                default => 'Upload fehlgeschlagen (Code ' . $code . ').',
            });
        }
        if ($file['size'] > 24 * 1024 * 1024) {
            fail('Datei ist größer als 24 MB.');
        }
        $wantsAudio = ($_POST['kind'] ?? '') === 'audio';
        $width = 0;
        $height = 0;

        if ($wantsAudio) {
            // Audio wird über die Dateisignatur geprueft, nicht über die Endung.
            $head = (string) @file_get_contents($file['tmp_name'], false, null, 0, 16);
            $ext = null;
            if (str_starts_with($head, 'ID3') || (strlen($head) > 1 && (ord($head[0]) === 0xFF) && (ord($head[1]) & 0xE0) === 0xE0)) {
                $ext = 'mp3';
            } elseif (str_starts_with($head, 'OggS')) {
                $ext = 'ogg';
            } elseif (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') {
                $ext = 'wav';
            } elseif (substr($head, 4, 4) === 'ftyp') {
                $ext = 'm4a';
            }
            if ($ext === null) {
                fail('Nur MP3, OGG, WAV oder M4A.');
            }
        } else {
            $info = @getimagesize($file['tmp_name']);
            if ($info === false) {
                fail('Das ist kein gueltiges Bild.');
            }
            $ext = match ($info['mime']) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => null,
            };
            if ($ext === null) {
                fail('Nur PNG, GIF, JPG oder WEBP.');
            }
            $width = (int) $info[0];
            $height = (int) $info[1];
        }
        $slug = Validate::id((string) ($_POST['name'] ?? ''), 'asset');
        $name = $slug . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dir = __DIR__ . '/assets/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
            fail('Datei konnte nicht gespeichert werden.', 500);
        }
        @chmod($dir . '/' . $name, 0644);
        respond([
            'ok' => true,
            'path' => 'assets/uploads/' . $name,
            'width' => $width,
            'height' => $height,
            'kind' => $wantsAudio ? 'audio' : 'image',
        ]);
    }

    case 'reset': {
        requireAdmin();
        $section = (string) (input()['section'] ?? 'all');
        $data = $store->mutate(function (array $data) use ($section): array {
            $fresh = Defaults::content();
            if ($section === 'all') {
                return $fresh;
            }
            if (isset($fresh[$section])) {
                $data[$section] = $fresh[$section];
            }
            return $data;
        });
        respond(['ok' => true, 'content' => $data]);
    }

    default:
        fail('Unbekannte Aktion.', 404);
}
