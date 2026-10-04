<?php
/*
 * ============================================================
 *  Gleiche Hirnzelle – Konfiguration
 * ============================================================
 *  Nur diese Datei musst du anpassen.
 *
 *  Wichtig: Diese Datei ist per .htaccess vor Zugriffen aus dem
 *  Browser geschützt und gibt beim direkten Aufruf ohnehin nichts
 *  aus. Der API-Key verlässt niemals den Server.
 * ============================================================
 */

return [

    // Dein Anthropic-API-Key (beginnt mit "sk-ant-").
    // Erstellen unter: https://console.anthropic.com/settings/keys
    // Alternativ leer lassen und auf dem Server die Umgebungsvariable
    // ANTHROPIC_API_KEY setzen.
    'anthropic_api_key' => '',

    // Claude-Modell für Fragen, Bewertungen und Duo-Titel.
    'model' => 'claude-opus-5-5',

    // Denktiefe: 'low' ist schnell und reicht für ein Partyspiel.
    // Mögliche Werte: low, medium, high
    'effort' => 'low',

    // Maximale Wartezeit pro Claude-Anfrage in Sekunden. Danach greift
    // automatisch der Ersatz (Fragen-Pool bzw. Buchstabenähnlichkeit).
    'api_timeout' => 25,

    // Lehnt Claude eine Anfrage ab, darf die API automatisch ein
    // Ersatzmodell verwenden (empfohlen).
    'use_fallback_models' => true,

    // Ordner für Räume und Spielstände. null = Ordner "data" im Spiel.
    // Noch sicherer: ein Ordner ausserhalb von public_html, z. B.
    // '/home/DEINBENUTZER/gleiche-hirnzelle-data'
    'data_dir' => null,

    // Nach wie vielen Stunden ohne Aktivität ein Raum gelöscht wird.
    'room_lifetime_hours' => 48,

    // Wie viele neue Räume eine IP-Adresse pro Stunde erstellen darf.
    'max_rooms_per_hour' => 30,
];
