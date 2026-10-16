<?php
declare(strict_types=1);

namespace SwipeGroove\Admin;

use SwipeGroove\Auth\SessionManager;
use SwipeGroove\Core\App;
use SwipeGroove\Covers\CoverService;

/**
 * Hintergrundaufgaben (cPanel-Cron, stündlich empfohlen): Bereinigung von Caches,
 * abgelaufenen Sessions, OAuth-States, Rate-Limit-Buckets, alten Logs und Feed-Kandidaten.
 * Dokumentierte Aufbewahrung zwischengespeicherter Spotify-Daten: siehe docs/DATENSCHUTZ-TECHNIK.md.
 */
final class CronRunner
{
    public function __construct(private App $app)
    {
    }

    /** @return array<string,int|string> */
    public function run(): array
    {
        $db = $this->app->db();
        $out = ['started_at' => gmdate('c')];
        $lock = fopen($this->app->root . '/storage/cache/.cron.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['skipped' => 'locked'];
        }
        try {
            $out['sessions'] = $this->app->service(SessionManager::class)->cleanup();
            $out['oauth_states'] = $db->exec('DELETE FROM oauth_states WHERE expires_at < ?', [$db->now()]);
            $out['rate_limits'] = $this->app->limiter()->cleanup();
            // Zwischengespeicherte Spotify-Daten: Inhalte nach 24 h, Listen nach 7 Tagen Inaktivität entfernen
            $out['items_cache'] = $db->exec('DELETE i FROM playlist_items_cache i JOIN playlist_cache c ON c.id = i.playlist_cache_id WHERE c.items_synced_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
            $db->exec('UPDATE playlist_cache SET items_complete = 0 WHERE items_synced_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
            $out['playlist_cache'] = $db->exec('DELETE FROM playlist_cache WHERE synced_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
            $out['feed_candidates'] = $db->exec('DELETE FROM feed_candidates WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
            $out['swipe_actions'] = $db->exec('DELETE FROM swipe_actions WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
            $out['operations'] = $db->exec('DELETE FROM playlist_operations WHERE updated_at < ? AND status IN (\'done\',\'failed\')', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
            $out['stale_cover_jobs'] = $this->app->service(CoverService::class)->cleanupStale();
            $out['integration_log'] = $db->exec('DELETE FROM integration_log WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
            $out['error_log'] = $db->exec('DELETE FROM error_log WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
            $out['notifications'] = $db->exec('DELETE FROM notifications WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 180 * 86400)]);
            $out['stripe_events'] = $db->exec('DELETE FROM stripe_events WHERE received_at < ?', [gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
            // Alte Log-Dateien
            $deleted = 0;
            foreach (glob($this->app->root . '/storage/logs/*.log') ?: [] as $f) {
                if (filemtime($f) < time() - 60 * 86400) {
                    @unlink($f);
                    $deleted++;
                }
            }
            $out['log_files'] = $deleted;
            $this->app->settings()->set('last_cron_at', gmdate('c'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $out;
    }
}
