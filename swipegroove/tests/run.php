<?php
declare(strict_types=1);

/**
 * Testläufer: php tests/run.php [Filter]
 * Benötigt eine MariaDB/MySQL-Testdatenbank (ENV SG_TEST_DSN_* oder Standardwerte).
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/support/TestCase.php';
require __DIR__ . '/support/FakeHttp.php';
require __DIR__ . '/support/TestApp.php';

$filter = $argv[1] ?? null;
$files = glob(__DIR__ . '/cases/*Test.php') ?: [];
sort($files);
$totalPassed = 0;
$failures = [];
$start = microtime(true);
foreach ($files as $file) {
    $class = 'SwipeGroove\\Tests\\Cases\\' . basename($file, '.php');
    if ($filter && stripos($class, $filter) === false) {
        continue;
    }
    require_once $file;
    /** @var SwipeGroove\Tests\TestCase $case */
    $case = new $class();
    $r = $case->run();
    $totalPassed += $r['passed'];
    $status = $r['failed'] ? 'FAIL' : 'ok';
    printf("%-55s %3d ok %3d fail  %s\n", basename($file, '.php'), $r['passed'], count($r['failed']), $status);
    foreach ($r['failed'] as [$name, $e]) {
        $failures[] = [basename($file, '.php') . '::' . $name, $e];
    }
}
printf("\n%d Tests bestanden, %d fehlgeschlagen (%.1fs)\n", $totalPassed, count($failures), microtime(true) - $start);
foreach ($failures as [$name, $e]) {
    echo "\n--- $name\n" . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
    foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 6) as $line) {
        echo '   ' . $line . "\n";
    }
}
exit($failures ? 1 : 0);
