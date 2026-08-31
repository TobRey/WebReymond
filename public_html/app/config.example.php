<?php

/**
 * WebAtze – Konfiguration (VORLAGE)
 * =================================
 *
 * Diese Datei ist nur die Vorlage und gehört ins Repository.
 * Die echte Datei heisst config.php, liegt daneben und wird NIEMALS committet.
 *
 * Sie wird normalerweise von install.php automatisch erzeugt. Von Hand:
 *   cp config.example.php config.php   und die Werte unten eintragen.
 */

declare(strict_types=1);

return [

    // ------------------------------------------------------------------
    // Grundeinstellungen
    // ------------------------------------------------------------------

    // Vollständige Adresse der Website, ohne Schrägstrich am Ende.
    // Beispiel: https://webatze.ch
    'app_url' => 'http://localhost:8080',

    // 'production' auf dem Server, 'development' beim Entwickeln.
    // In 'production' werden Fehlermeldungen niemals im Browser angezeigt.
    'env' => 'production',

    // Standardsprache: 'de' oder 'en'
    'default_locale' => 'de',

    // ------------------------------------------------------------------
    // Datenbank
    // ------------------------------------------------------------------
    //
    // ACHTUNG cPanel: Datenbankname und -benutzer bekommen dort meist ein
    // Kürzel vorangestellt, z.B. "abc123_db_webatze". Trage genau den Namen
    // ein, der in cPanel unter "MySQL-Datenbanken" steht.

    'db' => [
        'driver'   => 'mysql',        // 'mysql' (Server) oder 'sqlite' (lokaler Test)
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'db_webatze',
        'username' => 'usr_webatze',
        'password' => '',
        'charset'  => 'utf8mb4',

        // Nur für driver = 'sqlite': Pfad zur Datei.
        //
        // install.php hängt an den Namen eine Zufallsfolge – das ist
        // Absicht. Die Datei liegt im Web-Verzeichnis und wird durch
        // storage/.htaccess gesperrt; auf die allein ist aber kein
        // Verlass (bei nginx greift sie gar nicht). Anders als eine
        // PHP-Datei kann eine Datenbankdatei keinen Wächter tragen:
        // Sie muss mit "SQLite format 3" beginnen. Bleibt der Name.
        //
        // Auf einem gemieteten Hosting gehört ohnehin MySQL hierhin.
        // SQLite ist für den Probelauf auf dem eigenen Rechner.
        'path'     => __DIR__ . '/../storage/webatze.sqlite',
    ],

    // ------------------------------------------------------------------
    // Sicherheit
    // ------------------------------------------------------------------

    // Zufälliger Schlüssel, mindestens 32 Zeichen. Signiert Sitzungen.
    // Erzeugen: php -r "echo bin2hex(random_bytes(32));"
    'app_key' => '',

    // Schlüssel zum Verschlüsseln gespeicherter FTP-Zugangsdaten (getrennt!).
    // Erzeugen: php -r "echo bin2hex(random_bytes(32));"
    // WICHTIG: Wird dieser Schlüssel getauscht, sind alle gespeicherten
    // FTP-Zugangsdaten unlesbar und müssen neu eingegeben werden.
    'crypto_key' => '',

    // Adresse des versteckten Bereichs. Standard: 'create'
    // Wer möchte, trägt hier etwas Unauffälliges ein, z.B. 'werkstatt-7f2a'.
    // Der Bereich ist dann nur unter /werkstatt-7f2a erreichbar.
    'create_path' => 'create',

    // Anmeldung sperren nach so vielen Fehlversuchen ...
    'login_max_attempts' => 5,
    // ... für so viele Sekunden.
    'login_lockout_seconds' => 900,

    // ------------------------------------------------------------------
    // Claude (Anthropic)
    // ------------------------------------------------------------------

    'anthropic' => [
        // Schlüssel von console.anthropic.com. Ohne ihn läuft der Generator
        // im Übungsmodus und erzeugt Beispielinhalte statt echter Texte.
        'api_key' => '',

        // Nur nötig, wenn der Schlüssel einer Person gehört statt einem
        // Arbeitsbereich ("identity-linked"). Dann weiss die Schnittstelle
        // nicht, für welchen Bereich sie abrechnen soll, und weist jede
        // Anfrage ab. Die Kennung steht in der Adresszeile der Konsole:
        // platform.claude.com/workspaces/wrkspc_01…
        //
        // Hier leer lassen ist der Normalfall: Gibt es genau einen
        // Arbeitsbereich, trägt die Anwendung ihn selbst ein (im
        // Adminbereich unter Einstellungen sichtbar). Was hier steht, hat
        // Vorrang vor dem, was dort eingetragen wurde.
        'workspace_id' => '',

        // Modell für Struktur- und Designentscheidungen (anspruchsvoll).
        'model_plan' => 'claude-opus-5',

        // Modell für grössere Textmengen (schnell und günstiger).
        'model_content' => 'claude-sonnet-5',

        // Abbruch einer einzelnen Anfrage nach so vielen Sekunden.
        'timeout' => 120,
    ],

    // ------------------------------------------------------------------
    // E-Mail
    // ------------------------------------------------------------------

    'mail' => [
        // Wohin Kontaktanfragen gehen.
        'to' => 'tobias.reymond05@gmail.com',

        // Absender. Muss zu deiner Domain gehören, sonst landet die Mail
        // im Spam. Beispiel: noreply@webatze.ch
        'from' => 'noreply@localhost',
        'from_name' => 'WebAtze',
    ],

    // ------------------------------------------------------------------
    // Vorschau und Aufräumen
    // ------------------------------------------------------------------

    // Wie lange eine Vorschau unter /vorschau erreichbar bleibt (Stunden).
    'preview_ttl_hours' => 24,

    // Wie viele Sekunden der Worker pro Durchlauf höchstens arbeitet.
    // Muss unter dem PHP-Zeitlimit des Hosters liegen. 50 ist sicher.
    'worker_budget_seconds' => 50,
];
