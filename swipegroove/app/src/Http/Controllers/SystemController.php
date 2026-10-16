<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Core\Request;
use SwipeGroove\Core\Response;

final class SystemController
{
    public function __construct(private App $app)
    {
    }

    public function installedNotice(Request $request): Response
    {
        throw HttpException::forbidden('install.already_installed');
    }

    public function platform(Request $request): array
    {
        return $this->app->service(ShellController::class)->platformInfo();
    }

    public function health(Request $request): array
    {
        return ['ok' => true, 'version' => SG_VERSION];
    }

    /** GET /cron/run?key=… – Alternative zum CLI-Cron auf Hostings ohne PHP-CLI-Cron */
    public function cron(Request $request): array
    {
        $key = (string) $this->app->config?->string('app.cron_key');
        $given = $request->queryStr('key');
        if ($key === '' || $given === '' || !hash_equals($key, $given)) {
            throw HttpException::forbidden();
        }
        return $this->app->service(\SwipeGroove\Admin\CronRunner::class)->run();
    }
}
