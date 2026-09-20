<?php

declare(strict_types=1);

/**
 * Testlauf: php tests/run.php [suite]
 */

define('SK_ENTRY_DEPTH', 1);
define('SK_TESTING', true);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/Test.php';
require __DIR__ . '/helper.php';

$filter = $argv[1] ?? '';
$files  = glob(__DIR__ . '/suites/*.php') ?: [];
sort($files);

foreach ($files as $file) {
    $name = basename($file, '.php');
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }

    $suite = require $file;
    if (!is_callable($suite)) {
        continue;
    }

    try {
        $suite();
    } catch (Throwable $e) {
        Test::ok(false, 'Suite ' . $name . ' abgebrochen: ' . $e->getMessage()
            . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

TestEnv::cleanup();
exit(Test::summary());
