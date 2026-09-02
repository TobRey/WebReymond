<?php

/**
 * Die einmalige Einrichtung.
 *
 * Im Browser aufrufen, Formular ausfüllen, fertig. Danach löscht sich
 * diese Datei selbst – was nicht mehr da ist, kann auch niemand mehr
 * aufrufen.
 *
 * Sie ist bewusst eigenständig: kein Router, kein Autoloader, keine
 * Konfiguration. Sie muss auch dann laufen, wenn noch gar nichts
 * eingerichtet ist.
 */

declare(strict_types=1);

const MIN_PHP = 80100;

$appDir = __DIR__ . '/app';
$configFile = $appDir . '/config.php';
$storage = __DIR__ . '/storage';

$errors = [];
$done = false;

// ------------------------------------------------------------------
// Ist schon eingerichtet?
// ------------------------------------------------------------------

if (is_file($configFile)) {
    // Zweimal einrichten hiesse: Zugangsdaten überschreiben. Wer das
    // wirklich will, löscht app/config.php von Hand.
    render_page(
        'Schon eingerichtet',
        '<p>Diese Website ist bereits eingerichtet. <code>app/config.php</code> ist vorhanden.</p>'
        . '<p>Diese Datei sollte jetzt gelöscht werden – sie wird nicht mehr gebraucht.</p>'
        . '<p><a class="btn" href="/">Zur Website</a></p>'
    );
}

// ------------------------------------------------------------------
// Was der Server mitbringt
// ------------------------------------------------------------------

/** @return array<int, array{label:string, ok:bool, hint:string}> */
function requirements(): array
{
    $checks = [];

    $checks[] = [
        'label' => 'PHP ' . PHP_VERSION,
        'ok' => PHP_VERSION_ID >= MIN_PHP,
        'hint' => 'Mindestens PHP 8.1. In cPanel unter „MultiPHP Manager" umstellbar.',
    ];

    foreach ([
        'pdo_mysql' => 'Verbindung zur Datenbank',
        'zip' => 'Pakete schnüren',
        'gd' => 'Bilder verarbeiten',
        'curl' => 'Anfragen ins Netz',
        'mbstring' => 'Umlaute und Sonderzeichen',
        'sodium' => 'Zugangsdaten verschlüsseln',
        'json' => 'Datenaustausch',
    ] as $ext => $wofür) {
        $checks[] = [
            'label' => 'Erweiterung ' . $ext,
            'ok' => extension_loaded($ext),
            'hint' => $wofür . ($ext === 'sodium' ? ' (ohne sie werden FTP-Daten nicht gespeichert)' : ''),
        ];
    }

    $checks[] = [
        'label' => 'Schreibrechte app/',
        'ok' => is_writable(__DIR__ . '/app'),
        'hint' => 'Hier entsteht config.php.',
    ];

    $checks[] = [
        'label' => 'Schreibrechte storage/',
        'ok' => is_writable(__DIR__ . '/storage'),
        'hint' => 'Hier liegen Projekte, Pakete und Vorschauen.',
    ];

    return $checks;
}

$requirements = requirements();
$blocking = false;

foreach ($requirements as $check) {
    // sodium ist nicht überall vorhanden und nur für FTP nötig.
    if (!$check['ok'] && !str_contains($check['label'], 'sodium')) {
        $blocking = true;
    }
}

// ------------------------------------------------------------------
// Das Formular
// ------------------------------------------------------------------

$values = [
    'app_url' => guess_url(),
    'db_host' => 'localhost',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'admin_user' => 'webatze',
    'admin_pass' => '',
    'admin_pass2' => '',
    'api_key' => '',
    'create_path' => 'create',
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach (array_keys($values) as $key) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $errors = validate($values);

    if ($errors === []) {
        $errors = install($values, $appDir, $storage);

        if ($errors === []) {
            $done = true;
        }
    }
}

/** @return string[] */
function validate(array $v): array
{
    $errors = [];

    if (!preg_match('#^https?://[^/\s]+$#i', $v['app_url'])) {
        $errors[] = 'Die Adresse muss mit https:// beginnen und ohne Schrägstrich enden, '
            . 'zum Beispiel https://webatze.ch';
    }
    if ($v['db_host'] !== 'sqlite' && ($v['db_name'] === '' || $v['db_user'] === '')) {
        $errors[] = 'Datenbankname und Benutzername werden gebraucht. In cPanel steht dem '
            . 'Namen meist ein Kürzel voran – der vollständige Name gehört hier hinein.';
    }
    if (mb_strlen($v['admin_user']) < 3) {
        $errors[] = 'Der Benutzername braucht mindestens drei Zeichen.';
    }
    if (mb_strlen($v['admin_pass']) < 10) {
        $errors[] = 'Das Passwort braucht mindestens zehn Zeichen.';
    }
    if ($v['admin_pass'] !== $v['admin_pass2']) {
        $errors[] = 'Die beiden Passwörter stimmen nicht überein.';
    }
    if ($v['api_key'] !== '' && !str_starts_with($v['api_key'], 'sk-ant-')) {
        $errors[] = 'Ein Anthropic-Schlüssel beginnt mit "sk-ant-". Leer lassen ist auch möglich – '
            . 'dann läuft der Generator im Übungsmodus.';
    }
    if (!preg_match('/^[a-z0-9_-]{2,40}$/i', $v['create_path'])) {
        $errors[] = 'Die versteckte Adresse darf nur Buchstaben, Ziffern, Bindestrich '
            . 'und Unterstrich enthalten.';
    }

    return $errors;
}

/** @return string[] Fehler, leer bei Erfolg */
function install(array $v, string $appDir, string $storage): array
{
    // Wer als Server "sqlite" einträgt, bekommt eine Datei statt eines
    // Datenbankservers. Auf cPanel gehört MySQL hierhin; für einen
    // Probelauf auf dem eigenen Rechner ist SQLite bequemer, und die
    // Anwendung kennt beides ohnehin.
    $sqlite = $v['db_host'] === 'sqlite';

    // --- Verbindung prüfen, bevor irgendetwas geschrieben wird -------
    if (!$sqlite) {
        try {
            new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $v['db_host'], $v['db_name']),
                $v['db_user'],
                $v['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            return [
                'Die Datenbank antwortet nicht: ' . $e->getMessage()
                . ' — In cPanel unter „MySQL-Datenbanken" nachsehen, ob Datenbank und Benutzer '
                . 'existieren und der Benutzer der Datenbank zugewiesen ist.',
            ];
        }
    }

    // --- config.php schreiben ---------------------------------------
    $config = [
        'app_url' => rtrim($v['app_url'], '/'),
        'env' => 'production',
        // Die Namen der Felder stehen so in Db::connect() und in
        // config.example.php. Sie hier anders zu nennen, ergäbe eine
        // Konfiguration, die sich schreiben, aber nicht lesen lässt.
        'db' => $sqlite
            // Der Pfad wird hier aufgelöst und fest eingetragen: Er gilt
            // für diesen Server und ändert sich nicht mehr.
            //
            // Der Zufall im Dateinamen ist Absicht. Die Datei liegt im
            // Web-Verzeichnis; gesperrt wird sie durch storage/.htaccess.
            // Auf .htaccess allein ist aber kein Verlass – bei nginx
            // greift sie gar nicht. Anders als eine PHP-Datei kann eine
            // Datenbankdatei keinen Wächter tragen: Sie muss mit
            // "SQLite format 3" beginnen. Bleibt der Name. Wer ihn nicht
            // kennt, kann die Datei nicht abrufen, und Verzeichnisse
            // werden nicht aufgelistet (Options -Indexes).
            ? [
                'driver' => 'sqlite',
                'database' => 'webatze',
                'path' => __DIR__ . '/storage/db-' . bin2hex(random_bytes(8)) . '.sqlite',
            ]
            : [
                'driver' => 'mysql',
                'host' => $v['db_host'],
                'port' => 3306,
                'database' => $v['db_name'],
                'username' => $v['db_user'],
                'password' => $v['db_pass'],
                'charset' => 'utf8mb4',
            ],
        'app_key' => bin2hex(random_bytes(32)),
        'crypto_key' => bin2hex(random_bytes(32)),
        'create_path' => strtolower($v['create_path']),
        'login_max_attempts' => 5,
        'login_lockout_seconds' => 900,
        'anthropic' => [
            'api_key' => $v['api_key'],
            // Leer ist der Normalfall. Gehört der Schlüssel einer Person
            // statt einem Arbeitsbereich, trägt die Anwendung die Kennung
            // beim ersten Aufruf selbst ein – sichtbar im Adminbereich
            // unter Einstellungen.
            'workspace_id' => '',
            'model_plan' => 'claude-opus-5',
            'model_content' => 'claude-sonnet-5',
            'timeout' => 300,
        ],
        'mail' => [
            'from' => 'website@' . preg_replace('#^https?://(www\.)?#', '', $v['app_url']),
            'to' => '',
        ],
        'preview_ttl_hours' => 24,
        'worker_budget_seconds' => 50,
    ];

    $php = "<?php\n\n"
        . "// Die Konfiguration dieser Installation.\n"
        . "//\n"
        . "// Diese Datei enthält Zugangsdaten und gehört nirgendwo hin ausser\n"
        . "// auf diesen Server. Sie steht nicht im Repository.\n\n"
        . 'return ' . var_export($config, true) . ";\n";

    if (@file_put_contents($appDir . '/config.php', $php) === false) {
        return ['app/config.php liess sich nicht schreiben. Fehlen die Schreibrechte?'];
    }

    @chmod($appDir . '/config.php', 0640);

    // --- Tabellen und Konto -----------------------------------------
    try {
        require_once $appDir . '/bootstrap.php';

        // ensureCurrent() statt migrate(): Es legt zusätzlich den Merker
        // an, an dem spätere Aufrufe erkennen, dass die Tabellen zur
        // eingespielten Fassung passen.
        \WebAtze\Core\Schema::ensureCurrent();

        $exists = \WebAtze\Core\Db::first(
            'SELECT id FROM users WHERE username = :u',
            ['u' => mb_strtolower($v['admin_user'])]
        );

        if ($exists === null) {
            \WebAtze\Core\Db::insert('users', [
                'username' => mb_strtolower($v['admin_user']),
                'display_name' => $v['admin_user'],
                'password_hash' => \WebAtze\Core\Crypto::hashPassword($v['admin_pass']),
                'role' => 'owner',
                'created_at' => \WebAtze\Core\Db::now(),
            ]);
        }
    } catch (\Throwable $e) {
        @unlink($appDir . '/config.php');
        return ['Die Einrichtung ist gescheitert: ' . $e->getMessage()];
    }

    // --- Ordner ------------------------------------------------------
    foreach (['projects', 'zips', 'previews', 'uploads', 'logs', 'tmp'] as $dir) {
        @mkdir($storage . '/' . $dir, 0755, true);
    }

    // --- Die Designstudien -------------------------------------------
    seed_showcase($storage);

    return [];
}

/**
 * Die Designstudien in die Referenzen eintragen.
 *
 * Damit steht die Referenzseite vom ersten Tag an nicht leer. Es sind
 * ausdrücklich Studien und keine Kundenarbeiten – jede Karte sagt das
 * auch. Wer sie nicht will, entfernt sie im Adminbereich mit einem Klick.
 *
 * Eingetragen wird nur, wenn noch keine Referenz existiert. Ein zweiter
 * Durchlauf soll nichts doppelt anlegen und nichts überschreiben.
 */
function seed_showcase(string $storage): void
{
    $seedFile = dirname($storage) . '/app/Support/showcase-seed.php';

    if (!is_file($seedFile)) {
        return;
    }

    try {
        $vorhanden = (int) \WebAtze\Core\Db::value('SELECT COUNT(*) FROM showcase', [], 0);
        if ($vorhanden > 0) {
            return;
        }

        foreach ((array) require $seedFile as $eintrag) {
            if (!is_array($eintrag) || ($eintrag['slug'] ?? '') === '') {
                continue;
            }

            // Nur eintragen, wenn die zugehörige Seite auch wirklich da
            // ist – eine Referenz mit leerem Rahmen wäre schlechter als
            // gar keine.
            if (!is_file($storage . '/showcase/' . $eintrag['slug'] . '/index.html')) {
                continue;
            }

            \WebAtze\Core\Db::insert('showcase', array_merge($eintrag, [
                'project_id' => null,
                'published' => 1,
                'created_at' => \WebAtze\Core\Db::now(),
                'updated_at' => \WebAtze\Core\Db::now(),
            ]));
        }
    } catch (\Throwable) {
        // Ohne Studien läuft alles weiter. Die Einrichtung daran
        // scheitern zu lassen, wäre nicht verhältnismässig.
    }
}

/** Die eigene Adresse erraten – als Vorschlag, nicht als Festlegung. */
function guess_url(): string
{
    $secure = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443';

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'beispiel.ch');

    return ($secure ? 'https://' : 'http://') . $host;
}

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ------------------------------------------------------------------
// Ausgabe
// ------------------------------------------------------------------

if ($done) {
    $cron = sprintf(
        '* * * * * %s %s/worker.php >/dev/null 2>&1',
        PHP_BINARY !== '' ? PHP_BINARY : '/usr/local/bin/php',
        __DIR__
    );

    $selfDeleted = @unlink(__FILE__);
    $path = '/' . strtolower($values['create_path']);

    render_page(
        'Fertig',
        '<p class="ok">Die Einrichtung ist abgeschlossen.</p>'
        . '<h2>Noch ein Schritt: der Cronjob</h2>'
        . '<p>In cPanel unter <strong>Erweitert → Cron-Jobs</strong> einen Auftrag anlegen, '
        . 'der <strong>jede Minute</strong> läuft. Diese Zeile einsetzen:</p>'
        . '<pre>' . h($cron) . '</pre>'
        . '<p>Ohne ihn werden Aufträge nur beim Anlegen bearbeitet und alte Vorschauen '
        . 'nie aufgeräumt.</p>'
        . ($selfDeleted
            ? '<p>Diese Einrichtungsdatei hat sich selbst gelöscht.</p>'
            : '<p class="warn"><strong>Bitte <code>install.php</code> jetzt von Hand löschen.</strong> '
              . 'Sie liess sich nicht selbst entfernen.</p>')
        . '<p><a class="btn" href="/">Zur Website</a> '
        . '<a class="btn" href="' . h($path) . '">Zum versteckten Bereich</a></p>'
    );
}

ob_start();
?>
<p class="lede">
    Diese Seite richtet WebAtze einmalig ein: Sie legt die Konfiguration an, erstellt die
    Tabellen in der Datenbank und das eigene Konto. Danach löscht sie sich selbst.
</p>

<h2>Was der Server mitbringt</h2>
<table>
    <?php foreach ($requirements as $check): ?>
        <tr class="<?= $check['ok'] ? 'ok' : 'bad' ?>">
            <td><?= $check['ok'] ? '✓' : '✗' ?></td>
            <td><?= h($check['label']) ?></td>
            <td class="hint"><?= h($check['hint']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php if ($blocking): ?>
    <p class="warn">
        Es fehlt etwas Notwendiges. In cPanel lässt sich die PHP-Version samt Erweiterungen
        unter „MultiPHP Manager" und „PHP-Erweiterungen auswählen" einstellen.
    </p>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="errors">
        <?php foreach ($errors as $error): ?>
            <p><?= h($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<h2>Angaben</h2>
<form method="post" autocomplete="off">
    <label for="app_url">Adresse der Website</label>
    <input type="text" id="app_url" name="app_url" value="<?= h($values['app_url']) ?>" required>
    <p class="hint">
        Genau so, wie die Seite später aufgerufen wird – mit oder ohne „www", aber einheitlich.
        Stimmt das nicht, weist der Sicherheitsschutz später jedes Formular ab.
    </p>

    <h3>Datenbank</h3>
    <p class="hint">
        In cPanel unter „MySQL-Datenbanken". Dem Namen stellt cPanel meist ein Kürzel voran
        (z.&nbsp;B. <code>abc123_db_webatze</code>) – hier gehört der vollständige Name hinein.
        Für einen Probelauf auf dem eigenen Rechner genügt <code>sqlite</code> als Server;
        dann entsteht eine Datei statt einer Datenbank.
    </p>

    <div class="pair">
        <div>
            <label for="db_host">Server</label>
            <input type="text" id="db_host" name="db_host" value="<?= h($values['db_host']) ?>">
        </div>
        <div>
            <label for="db_name">Datenbankname</label>
            <input type="text" id="db_name" name="db_name" value="<?= h($values['db_name']) ?>" required>
        </div>
    </div>

    <div class="pair">
        <div>
            <label for="db_user">Benutzername</label>
            <input type="text" id="db_user" name="db_user" value="<?= h($values['db_user']) ?>" required>
        </div>
        <div>
            <label for="db_pass">Passwort</label>
            <input type="password" id="db_pass" name="db_pass">
        </div>
    </div>

    <h3>Eigenes Konto</h3>
    <div class="pair">
        <div>
            <label for="admin_user">Benutzername</label>
            <input type="text" id="admin_user" name="admin_user"
                   value="<?= h($values['admin_user']) ?>" required>
        </div>
        <div>
            <label for="create_path">Versteckte Adresse</label>
            <input type="text" id="create_path" name="create_path"
                   value="<?= h($values['create_path']) ?>" required>
            <p class="hint">Erreichbar unter /<?= h($values['create_path']) ?></p>
        </div>
    </div>

    <div class="pair">
        <div>
            <label for="admin_pass">Passwort</label>
            <input type="password" id="admin_pass" name="admin_pass" minlength="10" required>
        </div>
        <div>
            <label for="admin_pass2">Wiederholen</label>
            <input type="password" id="admin_pass2" name="admin_pass2" minlength="10" required>
        </div>
    </div>

    <h3>Anthropic-Schlüssel</h3>
    <label for="api_key">Schlüssel (kann später nachgetragen werden)</label>
    <input type="password" id="api_key" name="api_key" placeholder="sk-ant-...">
    <p class="hint">
        Ohne ihn läuft der Generator im Übungsmodus: Struktur und Vorlagen entstehen,
        die Texte sind Platzhalter.
    </p>

    <button type="submit"<?= $blocking ? ' disabled' : '' ?>>Einrichten</button>
</form>
<?php
render_page('Einrichtung', (string) ob_get_clean());

/** Die ganze Seite ausgeben und beenden. */
function render_page(string $title, string $body): never
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');

    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>WebAtze · ' . h($title) . '</title><style>'
        . <<<'CSS'
        *,*::before,*::after{box-sizing:border-box}
        body{margin:0;padding:2.5rem 1.25rem 5rem;background:#f5f6f9;color:#16172a;
          font:16px/1.65 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        main{max-width:44rem;margin-inline:auto;background:#fff;padding:clamp(1.5rem,4vw,2.75rem);
          border-radius:1rem;box-shadow:0 1px 2px rgb(0 0 0/.05),0 12px 40px rgb(0 0 0/.06)}
        h1{margin:0 0 .35rem;font-size:1.7rem}
        h2{margin:2rem 0 .75rem;font-size:1.2rem}
        h3{margin:1.75rem 0 .5rem;font-size:1rem}
        .brand{color:#2b1b9e;font-weight:700;letter-spacing:-.01em}
        .lede{margin:0 0 .5rem;color:#5b5c6b}
        .hint{margin:.3rem 0 0;color:#6b6c7b;font-size:.88rem}
        table{width:100%;border-collapse:collapse;font-size:.92rem}
        td{padding:.4rem .5rem;border-bottom:1px solid #eceef3;vertical-align:top}
        td:first-child{width:1.5rem;font-weight:700}
        tr.ok td:first-child{color:#1e8449}
        tr.bad td:first-child{color:#c0392b}
        tr.bad{background:#fdf3f2}
        label{display:block;margin-top:1rem;font-size:.88rem;font-weight:600}
        input{width:100%;padding:.62rem .8rem;margin-top:.25rem;border:1px solid #d5d8e2;
          border-radius:.45rem;font:inherit;background:#fbfbfd}
        input:focus-visible{outline:2px solid #2b1b9e;outline-offset:1px}
        .pair{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
        @media(max-width:34rem){.pair{grid-template-columns:1fr}}
        button,.btn{display:inline-block;margin-top:1.75rem;padding:.75rem 1.6rem;border:0;
          border-radius:100px;background:#2b1b9e;color:#fff;font:inherit;font-weight:600;
          text-decoration:none;cursor:pointer}
        button:disabled{background:#b9bac6;cursor:not-allowed}
        .btn+.btn{margin-left:.5rem;background:#fff;color:#2b1b9e;border:1px solid #d5d8e2}
        pre{overflow-x:auto;padding:1rem;border-radius:.5rem;background:#16172a;color:#e8e9f0;
          font-size:.85rem}
        code{background:#eef0f6;padding:.1rem .3rem;border-radius:.25rem;font-size:.9em}
        .errors{margin:1.25rem 0;padding:1rem 1.25rem;border-left:3px solid #c0392b;
          border-radius:0 .5rem .5rem 0;background:#fdf3f2}
        .errors p{margin:.35rem 0}
        p.ok{padding:1rem 1.25rem;border-left:3px solid #1e8449;border-radius:0 .5rem .5rem 0;
          background:#f0f9f4;font-weight:600}
        p.warn{padding:1rem 1.25rem;border-left:3px solid #b7791f;border-radius:0 .5rem .5rem 0;
          background:#fdf8ef}
        CSS
        . '</style></head><body><main>'
        . '<h1><span class="brand">WebAtze</span> · ' . h($title) . '</h1>'
        . $body
        . '</main></body></html>';

    exit;
}
