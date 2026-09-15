<?php
/**
 * Test der Basispfad-Erkennung ohne Webserver.
 *
 * Aufruf: php tools/test_basepath.php
 *
 * Geprueft werden die Serverkonstellationen, die in der Praxis vorkommen: Anwendung
 * direkt im Dokumentenstamm, Anwendung in einem Unterordner, falsch gesetzter
 * DOCUMENT_ROOT, CGI-Wrapper in SCRIPT_NAME und ein falscher Wert aus der Konfiguration.
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/app/Core/Environment.php';

$GLOBALS['tests'] = ['ok' => 0, 'fail' => 0, 'messages' => []];
$GLOBALS['jar'] = '';
require_once __DIR__ . '/test_helpers.php';

/**
 * Fuehrt die Erkennung in einem isolierten Prozess aus, weil WIT_ROOT eine Konstante ist.
 */
function basePath(string $root, array $server, mixed $stored): string
{
    $script = <<<'PHP'
<?php
declare(strict_types=1);
[$root, $server, $stored] = json_decode((string)file_get_contents($argv[1]), true);
define('WIT_ROOT', $root);
$_SERVER = $server + $_SERVER;
require $argv[2];
echo \App\Core\Environment::resolveBasePath($stored);
PHP;
    $payload = tempnam(sys_get_temp_dir(), 'wit_bp_');
    $runner  = tempnam(sys_get_temp_dir(), 'wit_run_') . '.php';
    file_put_contents($payload, json_encode([$root, $server, $stored]));
    file_put_contents($runner, $script);
    $output = (string)shell_exec(
        escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' ' . escapeshellarg($payload)
        . ' ' . escapeshellarg(__DIR__ . '/../src/app/Core/Environment.php') . ' 2>&1'
    );
    @unlink($payload);
    @unlink($runner);
    return trim($output);
}

section('Basispfad-Erkennung');

/* 1. Anwendung direkt im Dokumentenstamm */
check('Hauptverzeichnis: kein Basispfad', basePath(
    '/home/kunde/public_html',
    ['DOCUMENT_ROOT' => '/home/kunde/public_html', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/faelle'],
    ''
) === '');

/* 2. Unterordner, alles sauber gemeldet */
check('Unterordner wird erkannt', basePath(
    '/var/www/vhosts/beispiel.de/htdocs/spiel',
    ['DOCUMENT_ROOT' => '/var/www/vhosts/beispiel.de/htdocs', 'SCRIPT_NAME' => '/spiel/index.php', 'REQUEST_URI' => '/spiel/faelle'],
    ''
) === '/spiel');

/* 3. DOCUMENT_ROOT zeigt woanders hin - Erkennung ueber die Adresse */
check('Falscher DOCUMENT_ROOT wird umgangen', basePath(
    '/kunden/12345/apps/spiel',
    ['DOCUMENT_ROOT' => '/var/www/default', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/spiel/faelle'],
    ''
) === '/spiel');

/* 4. DOCUMENT_ROOT fehlt ganz */
check('Ohne DOCUMENT_ROOT wird die Adresse ausgewertet', basePath(
    '/kunden/12345/htdocs/apps/spiel',
    ['SCRIPT_NAME' => '/cgi-sys/php8.cgi', 'REQUEST_URI' => '/apps/spiel/faelle'],
    ''
) === '/apps/spiel');

/* 5. CGI-Wrapper in SCRIPT_NAME darf nicht als Ordner durchgehen */
check('CGI-Wrapper wird nicht als Basispfad verwendet', basePath(
    '/home/kunde/public_html',
    ['DOCUMENT_ROOT' => '', 'SCRIPT_NAME' => '/cgi-sys/php8.cgi', 'REQUEST_URI' => '/faelle'],
    ''
) === '');

/* 6. Falscher Wert aus der Konfiguration wird verworfen */
check('Falscher gespeicherter Wert wird verworfen', basePath(
    '/var/www/vhosts/beispiel.de/htdocs/spiel',
    ['DOCUMENT_ROOT' => '/var/www/vhosts/beispiel.de/htdocs', 'SCRIPT_NAME' => '/spiel/index.php', 'REQUEST_URI' => '/spiel/faelle'],
    '/cgi-sys'
) === '/spiel');

/* 7. Richtiger gespeicherter Wert bleibt erhalten */
check('Richtiger gespeicherter Wert bleibt', basePath(
    '/var/www/vhosts/beispiel.de/htdocs/spiel',
    ['DOCUMENT_ROOT' => '/var/www/vhosts/beispiel.de/htdocs', 'SCRIPT_NAME' => '/spiel/index.php', 'REQUEST_URI' => '/spiel/faelle'],
    '/spiel'
) === '/spiel');

/* 8. Startseite des Unterordners (Adresse endet auf den Ordner) */
check('Startseite des Unterordners', basePath(
    '/var/www/vhosts/beispiel.de/htdocs/spiel',
    ['DOCUMENT_ROOT' => '/var/www/vhosts/beispiel.de/htdocs', 'SCRIPT_NAME' => '/spiel/index.php', 'REQUEST_URI' => '/spiel/'],
    ''
) === '/spiel');

/* 9. Gleichnamiger Ordner im Hauptverzeichnis fuehrt nicht in die Irre */
check('Hauptverzeichnis mit gleichnamiger Route', basePath(
    '/home/kunde/public_html',
    ['DOCUMENT_ROOT' => '/home/kunde/public_html', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/beweise'],
    ''
) === '');

/* 10. Adresse und Ordner heissen unterschiedlich (uebergeordnete Seite bildet den
       Namen auf einen Ordner ab): Ordner ".../public_html/games/whereistoby",
       oeffentlich erreichbar unter "/whereistoby". */
check('Adresse weicht vom Ordnerpfad ab', basePath(
    '/home/rl0v84po3umh/public_html/games/whereistoby',
    [
        'DOCUMENT_ROOT' => '/home/rl0v84po3umh/public_html',
        'SCRIPT_NAME'   => '/games/whereistoby/index.php',
        'REQUEST_URI'   => '/whereistoby/faelle',
    ],
    '/games/whereistoby'
) === '/whereistoby');

check('Startseite bei abweichender Adresse', basePath(
    '/home/rl0v84po3umh/public_html/games/whereistoby',
    [
        'DOCUMENT_ROOT' => '/home/rl0v84po3umh/public_html',
        'SCRIPT_NAME'   => '/games/whereistoby/index.php',
        'REQUEST_URI'   => '/whereistoby/',
    ],
    '/games/whereistoby'
) === '/whereistoby');

section('Ergebnis');
printf("%d Pruefungen bestanden, %d fehlgeschlagen\n", $GLOBALS['tests']['ok'], $GLOBALS['tests']['fail']);
foreach ($GLOBALS['tests']['messages'] as $message) {
    echo ' - ' . $message . "\n";
}
exit($GLOBALS['tests']['fail'] > 0 ? 1 : 0);
