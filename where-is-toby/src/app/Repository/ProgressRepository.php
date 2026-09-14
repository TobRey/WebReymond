<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Validator;

/**
 * Spielfortschritt je Konto und Fall.
 */
final class ProgressRepository
{
    private const MAX_MESSAGES_PER_NPC = 60;

    public function __construct(private JsonStore $store)
    {
    }

    public static function blank(string $userId, string $caseId): array
    {
        $now = gmdate('c');
        return [
            '_schema'       => WIT_SCHEMA_VERSION,
            'user_id'       => $userId,
            'case_id'       => $caseId,
            'started_at'    => $now,
            'updated_at'    => $now,
            'completed_at'  => null,
            'playtime'      => 0,
            'last_seen'     => time(),
            'flags'         => [],
            'solved'        => [],
            'attempts'      => [],
            'evidence'      => [],
            'devices'       => [],
            'apps'          => [],
            'notes'         => [],
            'npc'           => [],
            'board'         => ['nodes' => [], 'links' => [], 'view' => ['x' => 0, 'y' => 0, 'zoom' => 1]],
            'timeline'      => [],
            'hints_used'    => 0,
            'hint_log'      => [],
            'horror_seen'   => [],
            'horror_last'   => 0,
            'horror_pending'=> [],
            'report'        => [],
            'result'        => null,
            'intro_done'    => false,
            'discovered'    => [],
            'visited'       => [],
        ];
    }

    private function file(string $userId, string $caseId): string
    {
        $userId = preg_match('~^[a-zA-Z0-9_\-]{3,64}$~', $userId) ? $userId : 'guest';
        $caseId = Validator::id($caseId, 'Fall-ID');
        return 'progress/' . $userId . '/' . $caseId . '.json';
    }

    public function get(string $userId, string $caseId): array
    {
        $data = $this->store->read($this->file($userId, $caseId), []);
        if ($data === []) {
            return self::blank($userId, $caseId);
        }
        return array_merge(self::blank($userId, $caseId), $data);
    }

    public function exists(string $userId, string $caseId): bool
    {
        return $this->store->exists($this->file($userId, $caseId));
    }

    public function save(array $progress): array
    {
        $progress['updated_at'] = gmdate('c');
        $progress = $this->trim($progress);
        $this->store->write($this->file((string)$progress['user_id'], (string)$progress['case_id']), $progress);
        return $progress;
    }

    /**
     * Sicheres Read-Modify-Write unter Sperre.
     * @param callable(array):array $mutator
     */
    public function update(string $userId, string $caseId, callable $mutator): array
    {
        return $this->store->update(
            $this->file($userId, $caseId),
            function (array $current) use ($mutator, $userId, $caseId): array {
                $current = $current === [] ? self::blank($userId, $caseId) : array_merge(self::blank($userId, $caseId), $current);
                $next = $mutator($current);
                $next['updated_at'] = gmdate('c');
                return $this->trim($next);
            },
            self::blank($userId, $caseId)
        );
    }

    public function reset(string $userId, string $caseId): array
    {
        $fresh = self::blank($userId, $caseId);
        $this->store->write($this->file($userId, $caseId), $fresh);
        return $fresh;
    }

    public function delete(string $userId, string $caseId): bool
    {
        return $this->store->delete($this->file($userId, $caseId));
    }

    /** @return array<int,array<string,mixed>> */
    public function listForUser(string $userId): array
    {
        $out = [];
        foreach ($this->store->listIds('progress/' . $userId) as $caseId) {
            $out[] = $this->get($userId, $caseId);
        }
        return $out;
    }

    /** Begrenzt gespeicherte Chatverlaeufe und Notizen, damit Dateien klein bleiben. */
    private function trim(array $progress): array
    {
        foreach ($progress['npc'] ?? [] as $npcId => $state) {
            if (isset($state['messages']) && is_array($state['messages']) && count($state['messages']) > self::MAX_MESSAGES_PER_NPC) {
                $progress['npc'][$npcId]['messages'] = array_slice($state['messages'], -self::MAX_MESSAGES_PER_NPC);
            }
        }
        if (isset($progress['notes']) && is_array($progress['notes']) && count($progress['notes']) > 100) {
            $progress['notes'] = array_slice($progress['notes'], -100);
        }
        if (isset($progress['hint_log']) && count($progress['hint_log']) > 50) {
            $progress['hint_log'] = array_slice($progress['hint_log'], -50);
        }
        return $progress;
    }
}
