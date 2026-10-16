<?php
declare(strict_types=1);

namespace SwipeGroove\Admin;

use SwipeGroove\Core\App;
use SwipeGroove\Core\Crypto;
use SwipeGroove\Core\Request;

final class AuditLog
{
    public function __construct(private App $app)
    {
    }

    public function record(?int $adminId, string $action, ?string $targetType, ?string $targetId, array $details = [], ?Request $request = null): void
    {
        $db = $this->app->db();
        $db->insert('audit_log', [
            'admin_id' => $adminId,
            'action' => mb_substr($action, 0, 60),
            'target_type' => $targetType,
            'target_id' => $targetId !== null ? mb_substr($targetId, 0, 64) : null,
            'details' => $details ? json_encode(\SwipeGroove\Core\Logger::scrub($details), JSON_UNESCAPED_UNICODE) : null,
            'ip_hash' => $request ? Crypto::hash($request->ip) : null,
            'created_at' => $db->now(),
        ]);
    }
}
