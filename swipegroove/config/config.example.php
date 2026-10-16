<?php
// Beispielkonfiguration – die echte config.php wird vom Installer erzeugt.
declare(strict_types=1);

return [
    'app' => [
        'base_url' => 'https://swipe-groove.com',   // oder https://example.com/groove (ohne Slash am Ende)
        'key' => 'BASE64-SCHLUESSEL-AUS-DEM-INSTALLER',
        'debug' => false,
        'cron_key' => 'ZUFALLSWERT',
    ],
    'db' => ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => '', 'socket' => ''],
    'spotify' => ['client_id' => '', 'client_secret' => ''],
    'images' => ['provider' => 'openai', 'openai_api_key' => ''],
    'stripe' => ['mode' => 'test', 'secret_key' => '', 'publishable_key' => '', 'webhook_secret' => '', 'price_id' => ''],
];
