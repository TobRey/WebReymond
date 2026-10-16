<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Core\App;
use SwipeGroove\Core\Request;
use SwipeGroove\Messaging\MessageService;

final class MessageController
{
    public function __construct(private App $app)
    {
    }

    private function svc(): MessageService
    {
        return $this->app->service(MessageService::class);
    }

    public function conversations(Request $r): array
    {
        return ['conversations' => $this->svc()->list($r->userId())];
    }

    public function start(Request $r): array
    {
        return $this->svc()->start($r->userId(), $r->str('username'));
    }

    public function messages(Request $r): array
    {
        return $this->svc()->messages($r->userId(), (int) $r->attributes['id'], $r->queryInt('before'), $r->queryInt('after'), max(1, min(100, $r->queryInt('limit', 40))));
    }

    public function send(Request $r): array
    {
        $link = $r->input('link');
        return $this->svc()->send($r->userId(), (int) $r->attributes['id'], $r->str('body'), is_array($link) ? $link : null);
    }

    public function markRead(Request $r): array
    {
        $this->svc()->markRead($r->userId(), (int) $r->attributes['id'], $r->int('up_to'));
        return ['ok' => true];
    }

    public function accept(Request $r): array
    {
        return $this->svc()->respond($r->userId(), (int) $r->attributes['id'], true);
    }

    public function decline(Request $r): array
    {
        return $this->svc()->respond($r->userId(), (int) $r->attributes['id'], false);
    }

    public function updates(Request $r): array
    {
        $conv = $r->queryInt('conversation');
        return $this->svc()->updates($r->userId(), $conv > 0 ? $conv : null, $r->queryInt('after'));
    }
}
