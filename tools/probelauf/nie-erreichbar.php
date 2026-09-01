<?php

/**
 * Der Dienst antwortet nie - und der Bau kommt trotzdem an.
 *
 * Die haerteste Zusage dieses Systems: Ein Auftrag darf niemals
 * steckenbleiben. Frueher tat er genau das - kam ein Aufruf nicht
 * durch, wurde derselbe Versuch wiederholt, wieder und wieder, ohne
 * Fehlermeldung und ohne Fortschritt. Von aussen sah es aus, als haenge
 * er bei 22 Prozent.
 *
 * Hier antwortet die Gegenstelle auf gar nichts. Am Ende muss trotzdem
 * eine vollstaendige Website dastehen - nach dem Standardaufbau, mit
 * vorlaeufigen Texten, aber fertig und mit einem ehrlichen Hinweis
 * darauf, was ohne die KI entstanden ist.
 *
 * Aufruf:
 *   php -S 127.0.0.1:8127 tools/probelauf/api-tot.php &
 *   php tools/probelauf/nie-erreichbar.php
 */

declare(strict_types=1);
require dirname(__DIR__, 2) . '/public_html/app/bootstrap.php';
use WebAtze\Core\{Config, Db, Jobs, Schema, Settings, Crypto};
use WebAtze\Domain\Brief;

Schema::migrate();

$w = (new ReflectionClass(Config::class))->getProperty('values');
$w->setAccessible(true);
$alle = $w->getValue();
$alle['anthropic']['api_key'] = 'sk-ant-api03-attrappe';
$alle['anthropic']['base_url'] = 'http://127.0.0.1:8127';
$alle['anthropic']['timeout'] = 5;
$w->setValue(null, $alle);
Settings::put('anthropic_workspace_id', 'wrkspc_01ATTRAPPE');

$g = Brief::validate([
    'company_name' => 'Nie Erreichbar GmbH', 'slogan' => 'Trotzdem fertig',
    'industry' => 'Schreinerei',
    'description' => 'Wir bauen Moebel nach Mass aus einheimischem Holz. Kuechen, '
        . 'Einbauschraenke, Tische. Seit 1978 in Thun, zwoelf Mitarbeitende.',
    'audience' => 'Hauseigentuemer und Architekten in der Region Thun.',
    'style' => 'natural', 'tone' => 'sie', 'scope' => 'small', 'locales' => 'de',
    'color_mode' => 'manual',
    'color_primary' => '#6B4423', 'color_secondary' => '#2E1D10', 'color_accent' => '#C89F6A',
    'contact_email' => 'info@schreinerei.example', 'contact_phone' => '033 222 11 00',
    'contact_address' => "Werkstrasse 8\n3600 Thun",
    'opening_hours' => 'Mo-Fr 07:30-17:00', 'domain' => 'schreinerei.example',
    'wants_admin' => '1', 'admin_username' => 'schreiner',
    'admin_password' => 'ein-langes-Testpasswort-2026',
    'wants_stats' => '0', 'wants_support' => '0', 'wants_docs' => '0', 'extra_notes' => '',
]);
if (!$g['ok']) { echo json_encode($g['errors']); exit(1); }
$brief = $g['data'];

$id = Db::insert('projects', [
    'slug' => 'probelauf-nieerreichbar', 'name' => $brief['company_name'],
    'status' => 'building', 'brief' => Brief::withoutSecrets($brief),
    'theme' => \WebAtze\Build\Theme::build([
        'primary' => $brief['color_primary'], 'secondary' => $brief['color_secondary'],
        'accent' => $brief['color_accent']], $brief['style']),
    'locale' => 'de', 'locales' => 'de', 'domain' => $brief['domain'],
    'wants_admin' => 1, 'admin_username' => $brief['admin_username'],
    'admin_password_hash' => Crypto::hashPassword($brief['admin_password']),
    'assistant_token' => bin2hex(random_bytes(12)), 'stats_token' => bin2hex(random_bytes(16)),
    'report_email' => '', 'preview_token' => '',
    'created_at' => Db::now(), 'updated_at' => Db::now(),
]);
$jobId = Jobs::enqueue('generate', [], (int) $id);

echo "Dienst antwortet nie. Der Bau muss trotzdem ankommen.\n\n";

for ($takt = 1; $takt <= 60; $takt++) {
    Jobs::releaseStale();
    $job = Jobs::claim();
    if ($job === null) { Db::update('jobs', ['locked_until' => null], 'id = :id', ['id' => $jobId]); continue; }

    try { \WebAtze\Build\Pipeline::step($job, 40.0); }
    catch (\Throwable $e) {
        \WebAtze\Core\Jobs::fail($job['id'], $e->getMessage(),
            !($e instanceof \WebAtze\Core\ConfigurationError));
    }

    $j = Db::first('SELECT status, progress, step, message FROM jobs WHERE id = :id', ['id' => $jobId]);
    printf("%2d  %-8s %3d%%  %-10s %s\n", $takt, $j['status'], $j['progress'], $j['step'],
        mb_substr((string) $j['message'], 0, 60));

    if (in_array((string) $j['status'], ['done', 'failed', 'cancelled'], true)) { break; }
    Db::update('jobs', ['run_after' => null, 'locked_until' => null], 'id = :id', ['id' => $jobId]);
}

$j = Db::first('SELECT * FROM jobs WHERE id = :id', ['id' => $jobId]);
echo "\nEndstand: {$j['status']} - {$j['message']}\n";
$n = Db::first('SELECT COUNT(*) c FROM project_sections WHERE project_id = :p', ['p' => $id]);
$s = Db::first('SELECT COUNT(*) c FROM project_pages WHERE project_id = :p', ['p' => $id]);
echo "Seiten: {$s['c']}, Abschnitte: {$n['c']}\n";
$dist = STORAGE_DIR . '/projects/probelauf-nieerreichbar/dist';
echo 'Startseite gebaut: ' . (is_file($dist . '/index.html') ? 'ja' : 'NEIN') . "\n";

// Aufraeumen - ein Probelauf, der Spuren hinterlaesst, verfaelscht den
// naechsten und bringt build.php aus dem Tritt (das zaehlt die
// Designstudien).
foreach (['ai_calls', 'project_sections', 'project_pages', 'site_checks',
          'builds', 'showcase', 'jobs'] as $tabelle) {
    try { Db::delete($tabelle, 'project_id = :p', ['p' => $id]); } catch (\Throwable) {}
}
Db::delete('projects', 'id = :p', ['p' => $id]);
Db::delete('showcase', "slug LIKE 'nie-erreichbar%'", []);

foreach ([STORAGE_DIR . '/projects/probelauf-nieerreichbar',
          STORAGE_DIR . '/showcase/nie-erreichbar-gmbh'] as $ordner) {
    if (is_dir($ordner)) { delete_tree($ordner); }
}

echo "Aufgeraeumt.\n";
