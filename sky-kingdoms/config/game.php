<?php
/**
 * Sky Kingdoms – zentrale Spielkonfiguration
 * -----------------------------------------
 * Diese Datei darf gefahrlos bearbeitet werden. Werte, die der Administrator im
 * Adminbereich ändert, liegen zusätzlich in storage/data/meta/settings.json und
 * überschreiben die Werte von hier.
 *
 * WICHTIG: Der Spielname wird ausschliesslich hier (bzw. im Adminbereich) gesetzt.
 */

declare(strict_types=1);

defined('SK_ROOT') || exit('Direkter Zugriff nicht erlaubt.');

return [
    // ------------------------------------------------------------------
    // Identität
    // ------------------------------------------------------------------
    'name'             => 'Sky Kingdoms',
    'tagline'          => 'Baue dein Königreich über den Wolken',
    'version'          => '1.0.0',
    'locale'           => 'de',
    'timezone'         => 'Europe/Zurich',

    // Farben der Oberfläche (werden als CSS-Variablen ausgegeben)
    'theme'            => [
        'primary'   => '#3f8cff',
        'secondary' => '#ffb43f',
        'accent'    => '#7ee3a6',
        'danger'    => '#ff5c6c',
        'sky_top'   => '#5ec6ff',
        'sky_bottom'=> '#b9e9ff',
    ],

    // Optionales eigenes Logo (Pfad relativ zum Projektordner, z. B. 'assets/img/logo-eigen.svg').
    // Leer lassen = mitgeliefertes Logo verwenden.
    'logo'             => '',

    // ------------------------------------------------------------------
    // Konten & Sicherheit
    // ------------------------------------------------------------------
    'registration_open'    => true,
    'password_min_length'  => 10,
    'username_min_length'  => 3,
    'username_max_length'  => 20,
    'session_lifetime'     => 7200,          // Sekunden Inaktivität bis zum Abmelden
    'remember_lifetime'    => 60 * 60 * 24 * 30,
    'reset_lifetime'       => 3600,

    // Rate-Limits: [Versuche, Zeitfenster in Sekunden]
    'rate_limits'      => [
        'login'    => [8,  900],
        'register' => [5,  3600],
        'reset'    => [5,  3600],
        'api'      => [240, 60],
        'attack'   => [30, 3600],
    ],

    // ------------------------------------------------------------------
    // Spielwelt
    // ------------------------------------------------------------------
    'max_offline_seconds'  => 86400,   // maximal nachberechnete Abwesenheit (24 h)
    'min_tick_seconds'     => 1,       // kürzester Abstand zwischen zwei Berechnungen
    'max_sim_segments'     => 240,     // Obergrenze der Ereignissegmente je Berechnung
    'ranking_ttl'          => 600,     // Sekunden, bis die Ranglisten-Momentaufnahme neu gebaut wird
    'ranking_page_size'    => 25,
    'newbie_protection'    => 259200,  // 3 Tage Schutz für neue Königreiche
    'shield_after_loss'    => 10800,   // 3 Stunden Schutz nach einem verlorenen Angriff
    'attacks_per_defender' => 3,       // maximale Angriffe pro Tag gegen dasselbe Königreich
    'matchmaking_spread'   => 0.4,     // ±40 % Punktedifferenz bei der Gegnersuche
    'max_reports'          => 50,      // gespeicherte Kampfberichte je Spieler

    // ------------------------------------------------------------------
    // E-Mail (wird im Adminbereich gepflegt; hier stehen nur die Vorgaben)
    // ------------------------------------------------------------------
    'mail' => [
        'enabled'    => false,          // false = Administrator setzt Passwörter manuell zurück
        'transport'  => 'mail',         // 'mail' oder 'smtp'
        'from'       => '',
        'from_name'  => 'Sky Kingdoms',
        'smtp_host'  => '',
        'smtp_port'  => 587,
        'smtp_user'  => '',
        'smtp_pass'  => '',
        'smtp_secure'=> 'tls',          // 'tls', 'ssl' oder ''
    ],

    // ------------------------------------------------------------------
    // Betrieb
    // ------------------------------------------------------------------
    'maintenance'      => false,
    'maintenance_note' => 'Das Königreich wird gerade erweitert. Bitte in Kürze erneut vorbeischauen.',
    'debug'            => false,       // true zeigt ausführliche Fehler – auf dem Server aus lassen!
];
