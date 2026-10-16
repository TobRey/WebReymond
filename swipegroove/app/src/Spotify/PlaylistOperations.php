<?php
declare(strict_types=1);

namespace SwipeGroove\Spotify;

use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;

/**
 * Mehrschrittige Playlist-Operationen (Verschieben, Kopieren, Zusammenführen, Duplizieren,
 * Duplikate entfernen). Jede Operation ist idempotent (op_uuid), wird in Schritten protokolliert
 * und kann nach einem Teilfehler wieder aufgenommen werden.
 *
 * Verschieben: zuerst erfolgreich zum Ziel hinzufügen, erst danach aus der Quelle entfernen.
 */
final class PlaylistOperations
{
    public function __construct(private App $app)
    {
    }

    private function playlists(): PlaylistService
    {
        return $this->app->service(PlaylistService::class);
    }

    private function client(): SpotifyClient
    {
        return $this->app->service(SpotifyClient::class);
    }

    public static function validUuid(string $uuid): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId, string $uuid): ?array
    {
        return $this->app->db()->one('SELECT * FROM playlist_operations WHERE user_id = ? AND op_uuid = ?', [$userId, $uuid]);
    }

    public function present(array $op): array
    {
        return [
            'id' => $op['op_uuid'],
            'type' => $op['type'],
            'status' => $op['status'],
            'source_playlist_id' => $op['source_playlist_id'],
            'target_playlist_id' => $op['target_playlist_id'],
            'progress' => json_decode((string) ($op['progress'] ?? '{}'), true) ?: [],
            'error' => $op['error'],
            'created_at' => $op['created_at'],
            'updated_at' => $op['updated_at'],
        ];
    }

    /**
     * Startet (oder liefert bei Wiederholung) eine Operation.
     * @param array<string,mixed> $payload
     */
    public function start(int $userId, string $uuid, string $type, ?string $source, ?string $target, array $payload): array
    {
        if (!self::validUuid($uuid)) {
            throw HttpException::badRequest('operation.invalid_id');
        }
        $db = $this->app->db();
        $existing = $this->find($userId, $uuid);
        if ($existing) {
            if (in_array($existing['status'], ['done'], true)) {
                return $this->present($existing);
            }
            return $this->run($existing);
        }
        $now = $db->now();
        $id = $db->insert('playlist_operations', [
            'op_uuid' => $uuid, 'user_id' => $userId, 'type' => $type,
            'source_playlist_id' => $source, 'target_playlist_id' => $target,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'status' => 'pending',
            'progress' => json_encode(['added' => [], 'removed' => [], 'failed' => []]),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $op = $db->one('SELECT * FROM playlist_operations WHERE id = ?', [$id]);
        return $this->run($op ?? []);
    }

    public function resume(int $userId, string $uuid): array
    {
        $op = $this->find($userId, $uuid);
        if (!$op) {
            throw HttpException::notFound('operation.not_found');
        }
        if ($op['status'] === 'done') {
            return $this->present($op);
        }
        return $this->run($op);
    }

    /** @param array<string,mixed> $op */
    private function run(array $op): array
    {
        $db = $this->app->db();
        // Sperre gegen parallele Ausführung derselben Operation (Doppelklick, Retry, paralleles Fortsetzen)
        $locked = $db->exec(
            'UPDATE playlist_operations SET locked_until = ? WHERE id = ? AND (locked_until IS NULL OR locked_until < ?)',
            [gmdate('Y-m-d H:i:s', time() + 300), $op['id'], $db->now()]
        );
        if ($locked === 0) {
            throw HttpException::conflict('operation.in_progress');
        }
        $op = $db->one('SELECT * FROM playlist_operations WHERE id = ?', [$op['id']]) ?? $op;
        $payload = json_decode((string) $op['payload'], true) ?: [];
        $progress = json_decode((string) ($op['progress'] ?? '{}'), true) ?: ['added' => [], 'removed' => [], 'failed' => []];
        $userId = (int) $op['user_id'];
        try {
            switch ($op['type']) {
                case 'copy':
                    $this->doCopy($userId, $op, $payload, $progress);
                    break;
                case 'move':
                    $this->doMove($userId, $op, $payload, $progress);
                    break;
                case 'merge':
                    $this->doMerge($userId, $op, $payload, $progress);
                    break;
                case 'duplicate':
                    $this->doDuplicate($userId, $op, $payload, $progress);
                    break;
                case 'remove_duplicates':
                    $this->doRemoveDuplicates($userId, $op, $payload, $progress);
                    break;
            }
            $this->save($op, 'done', $progress, null);
        } catch (SpotifyException $e) {
            $this->save($op, 'partial', $progress, $e->reason . ($e->spotifyMessage ? ': ' . $e->spotifyMessage : ''));
        } catch (HttpException $e) {
            $this->save($op, 'partial', $progress, $e->errorCode);
        } catch (\Throwable $e) {
            $this->save($op, 'partial', $progress, 'internal: ' . $e->getMessage());
            $this->app->logger->error('Playlist-Operation fehlgeschlagen', ['op' => $op['op_uuid'], 'message' => $e->getMessage()]);
        }
        return $this->present($this->find($userId, (string) $op['op_uuid']) ?? $op);
    }

    private function save(array $op, string $status, array $progress, ?string $error): void
    {
        $db = $this->app->db();
        $db->update('playlist_operations', [
            'status' => $status, 'progress' => json_encode($progress, JSON_UNESCAPED_UNICODE),
            'error' => $error !== null ? mb_substr($error, 0, 500) : null, 'updated_at' => $db->now(), 'locked_until' => null,
        ], 'id = ?', [$op['id']]);
    }

    /** Fügt URIs in Blöcken hinzu, protokolliert jeden erfolgreichen Block. */
    private function addInChunks(int $userId, string $target, array $uris, array &$progress, string $key = 'added'): void
    {
        $pending = array_values(array_diff($uris, $progress[$key] ?? []));
        foreach (array_chunk($pending, SpotifyClient::MAX_ITEMS_PER_WRITE) as $chunk) {
            $this->client()->addItems($userId, $target, $chunk);
            $progress[$key] = array_values(array_merge($progress[$key] ?? [], $chunk));
            $this->persistProgress($progress);
        }
        $this->playlists()->invalidateItems($userId, $target, count($pending));
    }

    private ?array $currentOp = null;

    private function persistProgress(array $progress): void
    {
        if ($this->currentOp) {
            $this->app->db()->update('playlist_operations', ['progress' => json_encode($progress, JSON_UNESCAPED_UNICODE), 'updated_at' => $this->app->db()->now()], 'id = ?', [$this->currentOp['id']]);
        }
    }

    private function doCopy(int $userId, array $op, array $payload, array &$progress): void
    {
        $this->currentOp = $op;
        $target = (string) $op['target_playlist_id'];
        $this->playlists()->assertEditable($this->playlists()->cacheRow($userId, $target));
        $uris = PlaylistService::cleanUris($payload['uris'] ?? []);
        if (!empty($payload['skip_existing'])) {
            $uris = $this->withoutExisting($userId, $target, $uris, $progress);
        }
        $this->addInChunks($userId, $target, $uris, $progress);
    }

    private function doMove(int $userId, array $op, array $payload, array &$progress): void
    {
        $this->currentOp = $op;
        $source = (string) $op['source_playlist_id'];
        $target = (string) $op['target_playlist_id'];
        $pls = $this->playlists();
        $pls->assertEditable($pls->cacheRow($userId, $source));
        $pls->assertEditable($pls->cacheRow($userId, $target));
        /** @var list<array{uri:string, positions:list<int>}> $items */
        $items = [];
        foreach ($payload['items'] ?? [] as $it) {
            if (is_array($it) && !empty($it['uri']) && PlaylistService::isTrackUri((string) $it['uri'])) {
                $items[] = ['uri' => (string) $it['uri'], 'positions' => array_values(array_map('intval', (array) ($it['positions'] ?? [])))];
            }
        }
        if ($items === []) {
            throw HttpException::badRequest('playlist.no_items');
        }
        $uris = array_values(array_unique(array_map(static fn($i) => $i['uri'], $items)));
        // Schritt 1: zum Ziel hinzufügen (übersprungen, wenn schon geschehen)
        if (!empty($payload['skip_existing'])) {
            $uris = $this->withoutExisting($userId, $target, $uris, $progress);
        }
        $this->addInChunks($userId, $target, $uris, $progress);
        $this->app->db()->update('playlist_operations', ['status' => 'added'], 'id = ?', [$op['id']]);
        // Schritt 2: aus der Quelle entfernen – nur Vorkommen, die noch nicht entfernt wurden
        $toRemove = [];
        foreach ($items as $it) {
            if (!in_array($it['uri'], $progress['removed'] ?? [], true)) {
                $toRemove[] = $it;
            }
        }
        if ($toRemove !== []) {
            $expected = $payload['snapshot_id'] ?? null;
            $current = $this->client()->playlist($userId, $source)['snapshot_id'] ?? null;
            if ($expected && $current && $expected !== $current && empty($progress['removed'])) {
                // Quelle wurde zwischenzeitlich verändert: Positionen nicht mehr verlässlich → nur per URI (alle Vorkommen) ist riskant.
                throw HttpException::conflict('playlist.snapshot_conflict', ['snapshot_id' => $current, 'stage' => 'remove']);
            }
            $this->client()->removeItems($userId, $source, $toRemove, is_string($current) ? $current : null);
            $progress['removed'] = array_values(array_merge($progress['removed'] ?? [], array_map(static fn($i) => $i['uri'], $toRemove)));
            $this->persistProgress($progress);
            $count = 0;
            foreach ($toRemove as $it) {
                $count += max(1, count($it['positions']));
            }
            $pls->invalidateItems($userId, $source, -$count);
        }
    }

    private function doMerge(int $userId, array $op, array $payload, array &$progress): void
    {
        $this->currentOp = $op;
        $pls = $this->playlists();
        $sources = array_values(array_filter((array) ($payload['sources'] ?? []), 'is_string'));
        if ($sources === []) {
            throw HttpException::badRequest('playlist.no_items');
        }
        $target = (string) ($progress['target'] ?? $op['target_playlist_id'] ?? '');
        if ($target === '' && !empty($payload['new_name'])) {
            $created = $pls->create($userId, (string) $payload['new_name'], (string) ($payload['new_description'] ?? ''), (bool) ($payload['new_public'] ?? false));
            $target = $created['id'];
            $progress['target'] = $target;
            $this->app->db()->update('playlist_operations', ['target_playlist_id' => $target], 'id = ?', [$op['id']]);
            $this->persistProgress($progress);
        }
        if ($target === '') {
            throw HttpException::badRequest('playlist.no_target');
        }
        $pls->assertEditable($pls->cacheRow($userId, $target));
        $uris = [];
        foreach ($sources as $src) {
            PlaylistService::assertId($src);
            foreach ($pls->items($userId, $src)['items'] as $it) {
                if ($it['uri'] && !$it['is_local']) {
                    $uris[] = $it['uri'];
                }
            }
        }
        $uris = array_values(array_unique($uris));
        if (!empty($payload['skip_existing'])) {
            $uris = $this->withoutExisting($userId, $target, $uris, $progress);
        }
        $this->addInChunks($userId, $target, $uris, $progress);
        $progress['total'] = count($uris);
    }

    private function doDuplicate(int $userId, array $op, array $payload, array &$progress): void
    {
        $this->currentOp = $op;
        $pls = $this->playlists();
        $source = (string) $op['source_playlist_id'];
        $data = $pls->items($userId, $source);
        $target = (string) ($progress['target'] ?? '');
        if ($target === '') {
            $name = trim((string) ($payload['name'] ?? '')) ?: ($data['playlist']['name'] . ' (Kopie)');
            $created = $pls->create($userId, $name, (string) ($data['playlist']['description'] ?? ''), false);
            $target = $created['id'];
            $progress['target'] = $target;
            $this->app->db()->update('playlist_operations', ['target_playlist_id' => $target], 'id = ?', [$op['id']]);
            $this->persistProgress($progress);
        }
        $uris = [];
        foreach ($data['items'] as $it) {
            if ($it['uri'] && !$it['is_local']) {
                $uris[] = $it['uri'];
            }
        }
        // Reihenfolge inkl. Duplikaten beibehalten: Blöcke sequenziell anhängen
        $pending = array_slice($uris, (int) ($progress['count'] ?? 0));
        foreach (array_chunk($pending, SpotifyClient::MAX_ITEMS_PER_WRITE) as $chunk) {
            $this->client()->addItems($userId, $target, $chunk);
            $progress['count'] = (int) ($progress['count'] ?? 0) + count($chunk);
            $this->persistProgress($progress);
        }
        $pls->invalidateItems($userId, $target, count($pending));
        $progress['total'] = count($uris);
    }

    private function doRemoveDuplicates(int $userId, array $op, array $payload, array &$progress): void
    {
        $this->currentOp = $op;
        $pls = $this->playlists();
        $playlistId = (string) $op['source_playlist_id'];
        $removals = [];
        foreach ($payload['removals'] ?? [] as $r) {
            if (is_array($r) && !empty($r['uri']) && !empty($r['positions'])) {
                $removals[] = ['uri' => (string) $r['uri'], 'positions' => array_values(array_map('intval', (array) $r['positions']))];
            }
        }
        if ($removals === []) {
            throw HttpException::badRequest('playlist.no_items');
        }
        $pls->removeItems($userId, $playlistId, $removals, $payload['snapshot_id'] ?? null);
        $progress['removed'] = array_map(static fn($r) => $r['uri'], $removals);
    }

    /** Filtert URIs heraus, die im Ziel bereits enthalten sind. */
    private function withoutExisting(int $userId, string $target, array $uris, array &$progress): array
    {
        $existing = [];
        foreach ($this->playlists()->items($userId, $target)['items'] as $it) {
            if ($it['uri']) {
                $existing[$it['uri']] = true;
            }
        }
        $kept = [];
        $skipped = $progress['skipped'] ?? [];
        foreach ($uris as $u) {
            if (isset($existing[$u]) && !in_array($u, $progress['added'] ?? [], true)) {
                $skipped[] = $u;
            } else {
                $kept[] = $u;
            }
        }
        $progress['skipped'] = array_values(array_unique($skipped));
        return $kept;
    }
}
