<?php
declare(strict_types=1);

/**
 * SwipeGroove Cron (cPanel): php /pfad/zu/swipegroove/cron/run.php
 * Empfohlen: stündlich.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

$app = SwipeGroove\Core\App::create(dirname(__DIR__));
if (!$app->installed) {
    fwrite(STDERR, "Nicht installiert.\n");
    exit(1);
}
$app->ensureSchema();
$result = $app->service(SwipeGroove\Admin\CronRunner::class)->run();
echo json_encode($result, JSON_PRETTY_PRINT), "\n";
