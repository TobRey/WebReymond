<?php
declare(strict_types=1);

namespace SwipeGroove\Admin;

use SwipeGroove\Core\App;
use SwipeGroove\Core\Request;

final class ErrorLog
{
    public function __construct(private App $app)
    {
    }

    public function record(string $scope, \Throwable $e, ?Request $request = null): void
    {
        $db = $this->app->db();
        $db->insert('error_log', [
            'scope' => mb_substr($scope, 0, 30),
            'message' => mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 1000),
            'location' => mb_substr(basename($e->getFile()) . ':' . $e->getLine(), 0, 255),
            'path' => $request ? mb_substr($request->method . ' ' . $request->path, 0, 255) : null,
            'user_id' => $request?->userId() ?: null,
            'created_at' => $db->now(),
        ]);
    }
}
