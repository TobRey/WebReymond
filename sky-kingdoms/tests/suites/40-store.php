<?php

declare(strict_types=1);

use SkyKingdoms\Core\App;
use SkyKingdoms\Store\Store;
use SkyKingdoms\Store\StoreException;

return function (): void {
    Test::suite('Datenspeicher: Atomarität, Sperren, Schutz');

    $store = TestEnv::freshStore();

    // --- Lesen und Schreiben ----------------------------------------
    Test::eq($store->read('meta/nichts.json'), null, 'Fehlendes Dokument liefert null');
    $store->write('meta/probe.json', ['a' => 1, 'text' => 'Ähre & Öl']);
    Test::eq($store->read('meta/probe.json'), ['a' => 1, 'text' => 'Ähre & Öl'], 'Geschriebenes kommt unverändert zurück');

    // --- Schutz vor direktem Webzugriff ------------------------------
    $file = $store->path('meta/probe.json');
    Test::ok(str_ends_with($file, '.php'), 'Datendateien tragen die Endung .php');
    $raw = (string) file_get_contents($file);
    Test::ok(str_starts_with($raw, '<?php exit;'), 'Jede Datendatei beginnt mit einem exit-Wächter');

    // Eingeschleuster Code darf niemals in der Datei landen
    $store->write('meta/boese.json', ['name' => '<?php system("id"); ?>']);
    $rawEvil = (string) file_get_contents($store->path('meta/boese.json'));
    Test::ok(substr_count($rawEvil, '<?php') === 1, 'Ein PHP-Block im Spielernamen erzeugt keinen zweiten <?php-Block');
    Test::eq($store->read('meta/boese.json')['name'], '<?php system("id"); ?>', 'Der Text bleibt beim Lesen unverändert erhalten');

    // --- Pfadmanipulation --------------------------------------------
    Test::throws(static fn () => $store->read('../../etc/passwd'), 'Pfadwechsel wird abgelehnt', StoreException::class);
    Test::throws(static fn () => $store->read('meta/../../x.json'), 'Verstecktes .. wird abgelehnt', StoreException::class);
    Test::throws(static fn () => $store->read('/etc/passwd'), 'Absoluter Pfad wird abgelehnt', StoreException::class);
    Test::throws(static fn () => $store->write('meta/eigen.php', ['x' => 1]), 'Eigene .php-Endung wird abgelehnt', StoreException::class);
    Test::throws(static fn () => $store->read("meta/pro\0be.json"), 'Null-Byte wird abgelehnt', StoreException::class);

    // --- Anspruchsdateien (eindeutige Namen) --------------------------
    Test::eq($store->claim('index/name/abc.json', ['uid' => 'a']), true, 'Erster Anspruch gelingt');
    Test::eq($store->claim('index/name/abc.json', ['uid' => 'b']), false, 'Zweiter Anspruch auf denselben Namen scheitert');
    Test::eq($store->read('index/name/abc.json')['uid'], 'a', 'Der erste Anspruch bleibt bestehen');

    // --- Sicherung und Wiederherstellung ------------------------------
    $store->write('meta/wichtig.json', ['stand' => 1]);
    $store->write('meta/wichtig.json', ['stand' => 2]);
    file_put_contents($store->path('meta/wichtig.json'), '<?php exit; ?>' . "\n" . '{kaputt');
    $store->clearCache();
    Test::eq($store->read('meta/wichtig.json'), ['stand' => 1], 'Beschädigte Datei wird aus der Sicherung wiederhergestellt');

    // --- Auflisten -----------------------------------------------------
    $store->write('liste/eins.json', ['n' => 1]);
    $store->write('liste/zwei.json', ['n' => 2]);
    $files = $store->listFiles('liste');
    Test::eq($files, ['eins.json', 'zwei.json'], 'Auflisten liefert die Namen ohne Schutz-Endung');

    // --- Protokollzeilen ------------------------------------------------
    $store->append('logs/test.jsonl', ['n' => 1]);
    $store->append('logs/test.jsonl', ['n' => 2]);
    $tail = $store->tail('logs/test.jsonl', 10);
    Test::eq(count($tail), 2, 'Protokoll enthält beide Zeilen');
    Test::eq($tail[0]['n'], 2, 'Neueste Zeile steht vorn');
    $rawLog = (string) file_get_contents($store->path('logs/test.jsonl'));
    Test::ok(str_starts_with($rawLog, '<?php exit;'), 'Auch Protokolle tragen den Wächter');

    // --- Gleichzeitigkeit: mehrere Prozesse buchen vom selben Bestand ---
    $dir = TestEnv::dir();
    $script = $dir . '/gleichzeitig.php';
    file_put_contents($script, '<?php
define("SK_ENTRY_DEPTH", 1);
require ' . var_export(SK_ROOT . '/app/bootstrap.php', true) . ';
$store = new SkyKingdoms\Store\Store(' . var_export($dir, true) . ');
for ($i = 0; $i < 25; $i++) {
    $store->update("konto/kasse.json", static function (array $kasse): array {
        if ((int) ($kasse["gold"] ?? 0) < 10) { return $kasse; }
        $kasse["gold"] = (int) $kasse["gold"] - 10;
        $kasse["kaeufe"] = (int) ($kasse["kaeufe"] ?? 0) + 1;
        return $kasse;
    }, ["gold" => 0, "kaeufe" => 0]);
}
');

    $store->write('konto/kasse.json', ['gold' => 1000, 'kaeufe' => 0]);

    $processes = [];
    for ($i = 0; $i < 4; $i++) {
        $processes[] = proc_open('php ' . escapeshellarg($script),
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    }
    foreach ($processes as $process) {
        if (is_resource($process)) { proc_close($process); }
    }

    $store->clearCache();
    $kasse = $store->read('konto/kasse.json', ['gold' => 0, 'kaeufe' => 0]);
    Test::eq((int) $kasse['gold'] + (int) $kasse['kaeufe'] * 10, 1000,
        'Vier gleichzeitige Prozesse geben niemals mehr aus als vorhanden (Gold ' . $kasse['gold'] . ', Käufe ' . $kasse['kaeufe'] . ')');
    Test::eq((int) $kasse['kaeufe'], 100, 'Genau 100 Buchungen à 10 Gold wurden verbucht');
    Test::eq((int) $kasse['gold'], 0, 'Der Bestand ist exakt aufgebraucht');

    @unlink($script);
};
