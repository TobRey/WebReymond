<?php
declare(strict_types=1);

namespace SwipeGroove\Covers;

use SwipeGroove\Billing\SubscriptionService;
use SwipeGroove\Core\App;
use SwipeGroove\Core\HttpException;
use SwipeGroove\Spotify\PlaylistService;
use SwipeGroove\Users\ImageService;

/**
 * KI-Cover-Generator mit Kontingent-Reservierung (transaktionssicher) und Kostenverfolgung.
 * Nur erfolgreich erzeugte Bilder zählen; technische Fehler geben die Reservierung frei.
 */
final class CoverService
{
    public const STYLES = ['none', 'minimal', 'retro', 'neon', 'watercolor', 'collage', 'photo', 'illustration', 'abstract'];

    public function __construct(private App $app)
    {
    }

    public function provider(): ImageProviderInterface
    {
        return $this->app->service(OpenAiImageProvider::class);
    }

    private function dir(): string
    {
        $d = $this->app->root . '/storage/covers';
        if (!is_dir($d)) {
            mkdir($d, 0750, true);
        }
        return $d;
    }

    /**
     * Kontingent des Nutzers für den aktuellen Abrechnungszeitraum.
     * @return array{unlimited:bool, limit:int, used:int, remaining:int, period_key:string, resets_at:?string, premium:bool}
     */
    public function quota(object $user): array
    {
        $subs = $this->app->service(SubscriptionService::class);
        $summary = $subs->summary($user);
        $limit = $this->app->settings()->int('premium_cover_quota', 50);
        $period = $subs->currentPeriod($user);
        $used = (int) $this->app->db()->value(
            'SELECT COUNT(*) FROM cover_jobs WHERE user_id = ? AND period_key = ? AND counts_against_quota = 1 AND status IN (\'reserved\',\'generating\',\'done\',\'applied\')',
            [$user->id, $period['key']]
        );
        $unlimited = (bool) $summary['unlimited_covers'];
        return [
            'unlimited' => $unlimited,
            'limit' => $unlimited ? 0 : $limit,
            'used' => $used,
            'remaining' => $unlimited ? PHP_INT_MAX : max(0, $limit - $used),
            'period_key' => $period['key'],
            'resets_at' => $period['end'],
            'premium' => (bool) $summary['active'],
        ];
    }

    /**
     * Reserviert ein Kontingent und erzeugt das Bild. Parallele Anfragen werden über eine
     * Zeilensperre auf dem Nutzer serialisiert, damit das Kontingent nie überschritten wird.
     */
    public function generate(object $user, string $jobUuid, string $prompt, string $style): array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $jobUuid)) {
            throw HttpException::badRequest('operation.invalid_id');
        }
        $prompt = trim($prompt);
        if (mb_strlen($prompt) < 3 || mb_strlen($prompt) > 1500) {
            throw HttpException::validation(['prompt' => 'validation.prompt_length']);
        }
        if (!in_array($style, self::STYLES, true)) {
            $style = 'none';
        }
        if (!$this->provider()->isConfigured()) {
            throw HttpException::conflict('covers.not_configured');
        }
        $db = $this->app->db();
        $existing = $db->one('SELECT * FROM cover_jobs WHERE job_uuid = ?', [$jobUuid]);
        if ($existing) {
            if ((int) $existing['user_id'] !== (int) $user->id) {
                throw HttpException::forbidden();
            }
            return $this->present($existing);
        }
        $this->app->limiter()->enforce('cover-gen:' . $user->id, 20, 600, 'covers.rate_limited');
        $settings = $this->app->settings();
        $model = $settings->str('cover_model', 'gpt-image-1-mini');
        $quality = $settings->str('cover_quality', 'medium');
        $maxParallel = max(1, $settings->int('max_parallel_cover_jobs', 2));

        // Reservierung unter Sperre
        $jobId = $db->transaction(function () use ($db, $user, $jobUuid, $prompt, $style, $model, $quality, $maxParallel): int {
            $db->run('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user->id]);
            $quota = $this->quota($user);
            if (!$quota['premium']) {
                throw HttpException::forbidden('covers.premium_required');
            }
            if (!$quota['unlimited'] && $quota['remaining'] <= 0) {
                throw HttpException::forbidden('covers.quota_exhausted', ['resets_at' => $quota['resets_at']]);
            }
            $running = (int) $db->value('SELECT COUNT(*) FROM cover_jobs WHERE user_id = ? AND status IN (\'reserved\',\'generating\') AND created_at > ?', [$user->id, gmdate('Y-m-d H:i:s', time() - 300)]);
            if ($running >= $maxParallel) {
                throw HttpException::tooMany('covers.too_many_parallel', 30);
            }
            return $db->insert('cover_jobs', [
                'job_uuid' => $jobUuid, 'user_id' => $user->id, 'prompt' => $prompt, 'style' => $style,
                'provider' => $this->provider()->name(), 'model' => $model, 'quality' => $quality, 'status' => 'reserved',
                'period_key' => $quota['period_key'], 'counts_against_quota' => $quota['unlimited'] ? 0 : 1,
                'cost_estimate_usd' => $this->estimateCost($model, $quality), 'created_at' => $db->now(),
            ]);
        });

        $db->update('cover_jobs', ['status' => 'generating'], 'id = ?', [$jobId]);
        try {
            $result = $this->provider()->generate($this->composePrompt($prompt, $style), $model, $quality);
        } catch (ImageProviderException $e) {
            // Technischer Fehler: zählt nicht gegen das Kontingent (ausser der Anbieter hat das Bild berechnet)
            $db->update('cover_jobs', [
                'status' => 'failed', 'error' => mb_substr($e->reason . ': ' . $e->getMessage(), 0, 500),
                'counts_against_quota' => $e->billed ? 1 : 0, 'finished_at' => $db->now(),
                'cost_estimate_usd' => $e->billed ? $this->estimateCost($model, $quality) : 0,
            ], 'id = ?', [$jobId]);
            $status = match ($e->reason) { 'content_policy' => 422, 'rate_limited' => 429, 'auth', 'not_configured' => 409, default => 502 };
            throw new HttpException($status, 'covers.provider_' . $e->reason, null, ['job_id' => $jobUuid]);
        }
        // Bild lokal, sicher und neu kodiert speichern (PNG/WebP/JPEG → WebP für Vorschau, JPEG für Spotify)
        $imgSvc = $this->app->service(ImageService::class);
        $gd = $imgSvc->decode($result['bytes']);
        $square = $imgSvc->cropResize($gd, 1024, 1024, null);
        imagedestroy($gd);
        $file = 'cover-' . $jobUuid . '.jpg';
        $path = $this->dir() . '/' . $file;
        imagejpeg($square, $path, 92);
        imagedestroy($square);
        @chmod($path, 0640);
        $db->update('cover_jobs', ['status' => 'done', 'file_path' => $file, 'finished_at' => $db->now(), 'model' => $result['model']], 'id = ?', [$jobId]);
        $job = $db->one('SELECT * FROM cover_jobs WHERE id = ?', [$jobId]);
        return $this->present($job ?? []);
    }

    private function composePrompt(string $prompt, string $style): string
    {
        $styleText = match ($style) {
            'minimal' => 'Minimalist flat design, few colors, clean shapes.',
            'retro' => 'Retro vintage album art, grainy texture, muted warm palette.',
            'neon' => 'Neon glow, dark background, vibrant accents.',
            'watercolor' => 'Soft watercolor painting, paper texture.',
            'collage' => 'Cut-paper collage style, layered textures.',
            'photo' => 'Photorealistic, cinematic lighting.',
            'illustration' => 'Hand-drawn illustration, bold outlines.',
            'abstract' => 'Abstract shapes and gradients, no text.',
            default => '',
        };
        return 'Square music playlist cover artwork. ' . $prompt . ($styleText ? ' Style: ' . $styleText : '') . ' No text, no letters, no logos, no watermarks.';
    }

    public function estimateCost(string $model, string $quality): float
    {
        $table = $this->app->settings()->get('cover_price_table', []);
        return (float) ($table[$model][$quality] ?? 0.0);
    }

    public function present(array $job): array
    {
        return [
            'id' => $job['job_uuid'] ?? null,
            'status' => $job['status'] ?? null,
            'prompt' => $job['prompt'] ?? '',
            'style' => $job['style'] ?? 'none',
            'model' => $job['model'] ?? null,
            'quality' => $job['quality'] ?? null,
            'error' => $job['error'] ?? null,
            'image_url' => !empty($job['file_path']) ? $this->app->url->path('/media/cover/' . $job['job_uuid']) : null,
            'applied_playlist_id' => $job['applied_playlist_id'] ?? null,
            'created_at' => $job['created_at'] ?? null,
            'finished_at' => $job['finished_at'] ?? null,
        ];
    }

    public function find(int $userId, string $uuid): array
    {
        $job = $this->app->db()->one('SELECT * FROM cover_jobs WHERE job_uuid = ? AND user_id = ?', [$uuid, $userId]);
        if (!$job) {
            throw HttpException::notFound('covers.not_found');
        }
        return $job;
    }

    public function filePath(array $job): ?string
    {
        if (empty($job['file_path'])) {
            return null;
        }
        $p = $this->dir() . '/' . basename((string) $job['file_path']);
        return is_file($p) ? $p : null;
    }

    /** @return list<array<string,mixed>> */
    public function list(int $userId): array
    {
        $rows = $this->app->db()->all('SELECT * FROM cover_jobs WHERE user_id = ? AND status IN (\'done\',\'applied\') ORDER BY id DESC LIMIT 100', [$userId]);
        return array_map([$this, 'present'], $rows);
    }

    /** Setzt das Cover nach Bestätigung auf eine eigene, bearbeitbare Playlist. */
    public function apply(int $userId, string $uuid, string $playlistId): array
    {
        $job = $this->find($userId, $uuid);
        $path = $this->filePath($job);
        if (!$path) {
            throw HttpException::notFound('covers.not_found');
        }
        $jpeg = $this->app->service(ImageService::class)->toSpotifyCoverJpeg((string) file_get_contents($path));
        $this->app->service(PlaylistService::class)->uploadCover($userId, $playlistId, $jpeg);
        $this->app->db()->update('cover_jobs', ['status' => 'applied', 'applied_playlist_id' => $playlistId], 'id = ?', [$job['id']]);
        return $this->present($this->find($userId, $uuid));
    }

    public function delete(int $userId, string $uuid): void
    {
        $job = $this->find($userId, $uuid);
        $path = $this->filePath($job);
        if ($path) {
            @unlink($path);
        }
        // Zeile bleibt für Kontingent/Kosten erhalten, Datei wird entfernt
        $this->app->db()->update('cover_jobs', ['file_path' => null], 'id = ?', [$job['id']]);
    }

    public function deleteFilesForUser(int $userId): void
    {
        foreach ($this->app->db()->all('SELECT file_path FROM cover_jobs WHERE user_id = ? AND file_path IS NOT NULL', [$userId]) as $r) {
            $p = $this->dir() . '/' . basename((string) $r['file_path']);
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    /** Verwaiste Reservierungen (Prozessabbruch) freigeben. */
    public function cleanupStale(): int
    {
        $db = $this->app->db();
        return $db->exec('UPDATE cover_jobs SET status = \'failed\', error = \'stale\', counts_against_quota = 0, finished_at = ? WHERE status IN (\'reserved\',\'generating\') AND created_at < ?',
            [$db->now(), gmdate('Y-m-d H:i:s', time() - 900)]);
    }
}
