<?php
declare(strict_types=1);

/**
 * Lokaler Fake-Spotify-Server für Browsertests (NUR Entwicklung):
 *   php -S 127.0.0.1:8090 tests/e2e/fake-spotify-server.php
 * Simuliert accounts.spotify.com (/authorize, /api/token) und api.spotify.com/v1 (/v1/...).
 * Zustand liegt in einer Datei, damit er über Requests hinweg erhalten bleibt.
 */
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require dirname(__DIR__) . '/support/TestCase.php';
require dirname(__DIR__) . '/support/FakeHttp.php';

use SwipeGroove\Tests\FakeHttp;

$stateFile = sys_get_temp_dir() . '/sg-fake-spotify.state';
$lock = fopen($stateFile . '.lock', 'c');
flock($lock, LOCK_EX);
$fake = is_file($stateFile) ? unserialize((string) file_get_contents($stateFile), ['allowed_classes' => [FakeHttp::class]]) : null;
if (!$fake instanceof FakeHttp) {
    $fake = new FakeHttp();
    // Beispiel-Bibliothek des Testnutzers (nur lokal!)
    $fake->addPlaylist('pltest0000000000000001', 'spotify_owner', 'Sommer Vibes', [1, 2, 3, 4, 5, 6, 2, 7]);
    $fake->addPlaylist('pltest0000000000000002', 'spotify_owner', 'Workout', [10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 1, 2, 3, 4, 5, 6, 7, 8, 9]);
    $fake->addPlaylist('pltest0000000000000003', 'spotify_owner', 'Leere Liste', []);
    $fake->addPlaylist('pltest0000000000000004', 'spotify_owner', 'Noch eine leere', []);
    $fake->addPlaylist('pltest0000000000000005', 'spotify_user2', 'Fremde Liste', [20, 21]);
    $fake->playlists['pltest0000000000000005']['followers'] = ['spotify_owner'];
    $fake->addPlaylist('pltest0000000000000006', 'spotify_user2', 'Alice Favoriten', [30, 31, 32]);
    $fake->addPlaylist('pltest0000000000000007', 'spotify_user3', 'Bob Beats', [40, 41]);
    for ($i = 8; $i <= 60; $i++) {
        $fake->addPlaylist('pltest' . str_pad((string) $i, 16, '0', STR_PAD_LEFT), 'spotify_owner', 'Playlist ' . $i, $i % 5 === 0 ? [] : [($i % 50) + 1, (($i * 3) % 50) + 1]);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = (string) parse_url($uri, PHP_URL_PATH);
$body = file_get_contents('php://input') ?: null;
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))))] = $v;
    }
}

if ($path === '/authorize') {
    // Autorisierungsseite: Nutzer wählen (nur lokal), dann Redirect mit Code
    $user = $_GET['as'] ?? null;
    if ($user && isset($fake->users[$user])) {
        $code = $fake->issueCode($user);
        $fake->grantScope = (string) ($_GET['scope'] ?? $fake->grantScope);
        $redirect = $_GET['redirect_uri'] . '?code=' . rawurlencode($code) . '&state=' . rawurlencode($_GET['state'] ?? '');
        file_put_contents($stateFile, serialize($fake));
        header('Location: ' . $redirect, true, 302);
        exit;
    }
    if (isset($_GET['deny'])) {
        header('Location: ' . $_GET['redirect_uri'] . '?error=access_denied&state=' . rawurlencode($_GET['state'] ?? ''), true, 302);
        exit;
    }
    $q = http_build_query($_GET);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Fake Spotify Login</title></head><body style="font-family:sans-serif;background:#191414;color:#fff;padding:2rem">';
    echo '<h1>Fake Spotify (lokaler Test)</h1><p>Scopes: ' . htmlspecialchars($_GET['scope'] ?? '') . '</p>';
    foreach ($fake->users as $id => $u) {
        echo '<p><a id="login-' . htmlspecialchars($id) . '" style="color:#1db954" href="/authorize?' . $q . '&as=' . rawurlencode($id) . '">Anmelden als ' . htmlspecialchars($u['display_name']) . ' (' . htmlspecialchars($id) . ')</a></p>';
    }
    echo '<p><a id="deny" style="color:#f55" href="/authorize?' . $q . '&deny=1">Ablehnen</a></p></body></html>';
    exit;
}

$target = match (true) {
    $path === '/api/token' => 'https://accounts.spotify.com/api/token',
    str_starts_with($path, '/v1/') => 'https://api.spotify.com' . $path . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''),
    $path === '/__reset' => '__reset',
    $path === '/__state' => '__state',
    default => null,
};
if ($target === '__reset') {
    @unlink($stateFile);
    echo 'reset';
    exit;
}
if ($target === '__state') {
    header('Content-Type: application/json');
    echo json_encode(['playlists' => array_map(fn($p) => ['name' => $p['name'], 'items' => $p['items'], 'owner' => $p['owner']], $fake->playlists), 'unfollowed' => $fake->libraryUnfollowed, 'plays' => $fake->plays]);
    exit;
}
if ($target === null) {
    http_response_code(404);
    echo 'unknown';
    exit;
}
$resp = $fake->request($method, $target, $headers, $body);
file_put_contents($stateFile, serialize($fake));
http_response_code($resp->status);
foreach ($resp->headers as $k => $v) {
    header($k . ': ' . $v);
}
header('Content-Type: application/json');
echo $resp->body;
