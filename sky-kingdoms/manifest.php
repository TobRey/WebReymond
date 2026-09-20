<?php
/**
 * Web-App-Manifest – dynamisch, damit Spielname, Farben und Pfade automatisch
 * zur Installation passen (das Spiel darf in jedem Unterordner liegen).
 */

declare(strict_types=1);

define('SK_ENTRY_DEPTH', 0);
require __DIR__ . '/app/bootstrap.php';

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Url;

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$name  = (string) App::config('name', 'Sky Kingdoms');
$theme = (array) App::config('theme', []);

echo json_encode([
    'name'             => $name,
    'short_name'       => mb_substr($name, 0, 12),
    'description'      => (string) App::config('tagline', ''),
    'lang'             => 'de',
    'dir'              => 'ltr',
    'start_url'        => Url::to('?p=game'),
    'scope'            => Url::to(''),
    'id'               => Url::to(''),
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#0b1424',
    'theme_color'      => (string) ($theme['sky_top'] ?? '#5ec6ff'),
    'categories'       => ['games', 'strategy'],
    'icons'            => [
        ['src' => Url::asset('icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => Url::asset('icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => Url::asset('icons/icon-maskable.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ['src' => Url::asset('img/logo.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
    ],
    'shortcuts' => [
        ['name' => 'Königreich', 'url' => Url::to('?p=game')],
        ['name' => 'Rangliste',  'url' => Url::to('?p=rangliste')],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
