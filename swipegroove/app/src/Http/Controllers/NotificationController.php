<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Community\NotificationService;
use SwipeGroove\Core\App;
use SwipeGroove\Core\Request;

final class NotificationController
{
    public function __construct(private App $app)
    {
    }

    public function index(Request $r): array
    {
        return $this->app->service(NotificationService::class)->list($r->userId(), $r->queryInt('before'));
    }

    public function markRead(Request $r): array
    {
        $ids = $r->input('ids');
        $n = $this->app->service(NotificationService::class)->markRead($r->userId(), is_array($ids) ? $ids : null);
        return ['marked' => $n];
    }
}
