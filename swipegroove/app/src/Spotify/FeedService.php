<?php
declare(strict_types=1);

namespace SwipeGroove\Spotify;

use SwipeGroove\Core\App;

/**
 * Kandidatensuche für den Swipe-Feed.
 *
 * Ehrlicher Hinweis zur Methode: Spotify stellt neuen Apps seit November 2024 keine
 * Empfehlungs-, Audio-Feature- oder "Related Artists"-Endpunkte mehr bereit. Die Modi arbeiten
 * deshalb mit der Spotify-Suche und den Künstlerkatalogen (Alben) der Künstler, die bereits in
 * den Playlists des Nutzers vorkommen. Es werden keine Audiodaten analysiert, keine Stimmungen
 * oder BPM behauptet und keine Spotify-Daten an Sprachmodelle gesendet.
 */
final class FeedService
{
    public const MODES = ['match', 'discover', 'custom'];
    private const BATCH_TARGET = 30;

    public function __construct(private App $app)
    {
    }

    private function client(): SpotifyClient
    {
        return $this->app->service(SpotifyClient::class);
    }

    /**
     * Erzeugt neue Kandidaten und schreibt sie in feed_candidates.
     * @param array<string,mixed> $filters  custom: {q, artists:[{id,name}], genres:[...]}
     * @param array<string,true> $exclude   Track-IDs, die nicht vorgeschlagen werden dürfen
     * @return array{generated:int, exhausted:bool, reason:?string}
     */
    public function generate(int $userId, string $playlistId, string $mode, array $filters, array $exclude, string $filterHash): array
    {
        $playlists = $this->app->service(PlaylistService::class);
        $target = $playlists->items($userId, $playlistId);
        $targetItems = $target['items'];

        $seedArtists = $this->artistWeights($targetItems);
        $reason = null;
        $queries = [];

        switch ($mode) {
            case 'match':
                if ($seedArtists === []) {
                    return ['generated' => 0, 'exhausted' => true, 'reason' => 'empty_playlist'];
                }
                $queries = $this->matchQueries($userId, $seedArtists, $targetItems);
                break;
            case 'discover':
                $queries = $this->discoverQueries($userId, $playlistId, $seedArtists);
                if ($queries === []) {
                    return ['generated' => 0, 'exhausted' => true, 'reason' => 'no_seed_data'];
                }
                break;
            case 'custom':
                $queries = $this->customQueries($filters);
                if ($queries === []) {
                    return ['generated' => 0, 'exhausted' => true, 'reason' => 'no_filters'];
                }
                break;
        }

        $found = [];
        $calls = 0;
        $maxCalls = 12;
        foreach ($queries as $q) {
            if ($calls >= $maxCalls || count($found) >= self::BATCH_TARGET) {
                break;
            }
            try {
                if ($q['type'] === 'search') {
                    $calls++;
                    $tracks = $this->client()->searchTracks($userId, $q['q'], $q['offset'] ?? 0);
                } elseif ($q['type'] === 'album') {
                    $calls++;
                    $tracks = $this->client()->albumTracks($userId, $q['album_id'], $q['album'] ?? null);
                } else {
                    continue;
                }
            } catch (SpotifyException $e) {
                if ($e->reason === SpotifyException::RATE_LIMITED) {
                    $reason = 'rate_limited';
                    break;
                }
                continue;
            }
            foreach ($tracks as $t) {
                if (!$t['id'] || isset($exclude[$t['id']]) || isset($found[$t['id']])) {
                    continue;
                }
                if (($t['type'] ?? 'track') !== 'track') {
                    continue;
                }
                $t['source'] = $q['label'] ?? $mode;
                $found[$t['id']] = $t;
            }
        }

        $db = $this->app->db();
        $now = $db->now();
        $inserted = 0;
        foreach ($found as $id => $t) {
            $r = $db->exec(
                'INSERT IGNORE INTO feed_candidates (user_id, spotify_playlist_id, mode, filter_hash, track_id, payload, served, created_at) VALUES (?,?,?,?,?,?,0,?)',
                [$userId, $playlistId, $mode, $filterHash, $id, json_encode($t, JSON_UNESCAPED_UNICODE), $now]
            );
            $inserted += $r;
        }
        return ['generated' => $inserted, 'exhausted' => $inserted === 0 && $reason === null, 'reason' => $reason];
    }

    /** @return array<string,array{name:string,weight:int}> artistId => … */
    private function artistWeights(array $items): array
    {
        $weights = [];
        foreach ($items as $it) {
            foreach ($it['artist_ids'] as $i => $aid) {
                if (!isset($weights[$aid])) {
                    $weights[$aid] = ['name' => (string) ($it['artists'][$i] ?? ''), 'weight' => 0];
                }
                $weights[$aid]['weight']++;
            }
        }
        uasort($weights, static fn($a, $b) => $b['weight'] <=> $a['weight']);
        return $weights;
    }

    /** Gewichtete Zufallsauswahl von Künstlern. @return list<array{id:string,name:string}> */
    private function pickArtists(array $weights, int $n): array
    {
        $pool = [];
        foreach ($weights as $id => $w) {
            $pool[] = ['id' => $id, 'name' => $w['name'], 'weight' => $w['weight']];
        }
        $picked = [];
        while ($pool !== [] && count($picked) < $n) {
            $total = array_sum(array_column($pool, 'weight'));
            $r = random_int(1, max(1, $total));
            foreach ($pool as $i => $p) {
                $r -= $p['weight'];
                if ($r <= 0) {
                    $picked[] = ['id' => $p['id'], 'name' => $p['name']];
                    unset($pool[$i]);
                    $pool = array_values($pool);
                    break;
                }
            }
        }
        return $picked;
    }

    /** Playlist-Match: Künstler der Playlist → weitere Titel dieser Künstler (Suche + Alben). */
    private function matchQueries(int $userId, array $seedArtists, array $targetItems): array
    {
        $queries = [];
        $artists = $this->pickArtists($seedArtists, 6);
        $albumIds = [];
        foreach ($targetItems as $it) {
            if ($it['album_id']) {
                $albumIds[$it['album_id']] = true;
            }
        }
        foreach ($artists as $a) {
            if ($a['name'] === '') {
                continue;
            }
            $queries[] = ['type' => 'search', 'q' => 'artist:"' . $a['name'] . '"', 'offset' => random_int(0, 2) * 10, 'label' => 'artist:' . $a['name']];
            // Ein zufälliges Album des Künstlers, das noch nicht in der Playlist vertreten ist
            try {
                $albums = $this->client()->artistAlbums($userId, $a['id'], 20);
            } catch (SpotifyException) {
                $albums = [];
            }
            $albums = array_values(array_filter($albums, static fn($al) => !isset($albumIds[$al['id']])));
            if ($albums !== []) {
                $al = $albums[random_int(0, count($albums) - 1)];
                $queries[] = ['type' => 'album', 'album_id' => $al['id'], 'album' => $al, 'label' => 'album:' . $al['name']];
            }
        }
        shuffle($queries);
        return $queries;
    }

    /** Entdecken: Künstler aus den anderen Playlists des Nutzers + breitere Suchen. */
    private function discoverQueries(int $userId, string $playlistId, array $seedArtists): array
    {
        $db = $this->app->db();
        $rows = $db->all(
            'SELECT i.artists, i.artist_ids FROM playlist_items_cache i JOIN playlist_cache c ON c.id = i.playlist_cache_id
             WHERE c.user_id = ? AND c.spotify_playlist_id <> ? AND c.items_complete = 1 ORDER BY RAND() LIMIT 300',
            [$userId, $playlistId]
        );
        $other = [];
        foreach ($rows as $r) {
            $names = json_decode((string) $r['artists'], true) ?: [];
            $ids = json_decode((string) $r['artist_ids'], true) ?: [];
            foreach ($ids as $i => $id) {
                if (isset($seedArtists[$id])) {
                    continue; // Künstler aus der Zielplaylist bleiben dem Match-Modus vorbehalten
                }
                $other[$id] = ['name' => (string) ($names[$i] ?? ''), 'weight' => ($other[$id]['weight'] ?? 0) + 1];
            }
        }
        $queries = [];
        foreach ($this->pickArtists($other, 4) as $a) {
            if ($a['name'] !== '') {
                $queries[] = ['type' => 'search', 'q' => 'artist:"' . $a['name'] . '"', 'offset' => random_int(0, 3) * 10, 'label' => 'library:' . $a['name']];
                try {
                    $albums = $this->client()->artistAlbums($userId, $a['id'], 20);
                } catch (SpotifyException) {
                    $albums = [];
                }
                if ($albums !== []) {
                    $al = $albums[random_int(0, count($albums) - 1)];
                    $queries[] = ['type' => 'album', 'album_id' => $al['id'], 'album' => $al, 'label' => 'album:' . $al['name']];
                }
            }
        }
        // Breite Suchen mit Jahresfiltern, die auf den Zielplaylist-Künstlern aufbauen
        foreach ($this->pickArtists($seedArtists, 2) as $a) {
            if ($a['name'] !== '') {
                $year = (int) date('Y');
                $queries[] = ['type' => 'search', 'q' => $a['name'] . ' year:' . ($year - 1) . '-' . $year, 'offset' => random_int(0, 2) * 10, 'label' => 'recent:' . $a['name']];
            }
        }
        shuffle($queries);
        return $queries;
    }

    /** Selbst einstellen: Suchbegriffe, Künstler, Genres (Spotify-Suchfilter). */
    private function customQueries(array $filters): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $artists = array_values(array_filter(array_map(static fn($a) => is_array($a) ? trim((string) ($a['name'] ?? '')) : trim((string) $a), (array) ($filters['artists'] ?? []))));
        $genres = array_values(array_filter(array_map(static fn($g) => preg_replace('/[^a-z0-9\- ]/', '', strtolower(trim((string) $g))) ?? '', (array) ($filters['genres'] ?? []))));
        $years = trim((string) ($filters['years'] ?? ''));
        $queries = [];
        $suffix = '';
        if ($years !== '' && preg_match('/^\d{4}(-\d{4})?$/', $years)) {
            $suffix .= ' year:' . $years;
        }
        foreach (array_slice($artists, 0, 5) as $a) {
            $base = 'artist:"' . $a . '"' . ($q !== '' ? ' ' . $q : '');
            $queries[] = ['type' => 'search', 'q' => $base . $suffix, 'offset' => 0, 'label' => 'artist:' . $a];
            $queries[] = ['type' => 'search', 'q' => $base . $suffix, 'offset' => 10, 'label' => 'artist:' . $a];
        }
        foreach (array_slice($genres, 0, 5) as $g) {
            $base = 'genre:"' . $g . '"' . ($q !== '' ? ' ' . $q : '');
            $queries[] = ['type' => 'search', 'q' => $base . $suffix, 'offset' => random_int(0, 4) * 10, 'label' => 'genre:' . $g];
        }
        if ($artists === [] && $genres === [] && $q !== '') {
            for ($i = 0; $i < 3; $i++) {
                $queries[] = ['type' => 'search', 'q' => $q . $suffix, 'offset' => $i * 10, 'label' => 'search'];
            }
        }
        return $queries;
    }
}
