<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Core\App;
use SwipeGroove\Core\Config;
use SwipeGroove\Core\Crypto;
use SwipeGroove\Core\Db;
use SwipeGroove\Core\I18n;
use SwipeGroove\Core\Migrator;
use SwipeGroove\Core\Request;
use SwipeGroove\Core\Response;
use SwipeGroove\Core\Url;

/**
 * Geschützter Installationsassistent. Läuft nur, solange config/config.php fehlt.
 * Nach der Installation ist er gesperrt (die Konfigurationsdatei ist die Sperre).
 */
final class InstallController
{
    public function __construct(private App $app)
    {
    }

    public function handle(Request $request): Response
    {
        $lang = I18n::normalize($request->queryStr('lang', $request->str('lang', I18n::fromAcceptLanguage($request->header('accept-language')))));
        $this->app->i18n->setLang($lang);

        if ($request->path !== '/install') {
            return Response::redirect($this->app->url->path('/install') . '?lang=' . $lang);
        }
        $checks = $this->requirements();
        $values = $this->defaults();
        $errors = [];
        if ($request->method === 'POST') {
            foreach (array_keys($values) as $k) {
                $values[$k] = trim((string) ($_POST[$k] ?? ''));
            }
            $errors = $this->validate($values, $checks);
            if ($errors === []) {
                try {
                    return $this->install($values, $lang);
                } catch (\Throwable $e) {
                    $errors[] = $this->t('install.failed', $lang) . ' ' . $e->getMessage();
                }
            }
        }
        return Response::html($this->render($values, $errors, $checks, $lang));
    }

    private function t(string $key, string $lang): string
    {
        return $this->app->i18n->t($key, [], $lang);
    }

    /** @return array<string,string> */
    private function defaults(): array
    {
        return [
            'lang' => '',
            'base_url' => $this->app->url->base(),
            'db_host' => 'localhost',
            'db_port' => '3306',
            'db_name' => '',
            'db_user' => '',
            'db_pass' => '',
            'spotify_client_id' => '',
            'spotify_client_secret' => '',
            'openai_api_key' => '',
            'stripe_mode' => 'test',
            'stripe_secret_key' => '',
            'stripe_publishable_key' => '',
            'stripe_webhook_secret' => '',
            'stripe_price_id' => '',
            'setup_key' => Crypto::randomToken(18),
        ];
    }

    /** @return array<string,array{ok:bool,label:string}> */
    private function requirements(): array
    {
        $root = $this->app->root;
        $writable = static fn(string $p): bool => is_dir($p) ? is_writable($p) : is_writable(dirname($p));
        return [
            'php' => ['ok' => PHP_VERSION_ID >= 80200, 'label' => 'PHP ≥ 8.2 (' . PHP_VERSION . ')'],
            'pdo_mysql' => ['ok' => extension_loaded('pdo_mysql'), 'label' => 'PDO MySQL'],
            'curl' => ['ok' => extension_loaded('curl'), 'label' => 'cURL'],
            'gd' => ['ok' => extension_loaded('gd'), 'label' => 'GD'],
            'sodium' => ['ok' => extension_loaded('sodium'), 'label' => 'Sodium'],
            'mbstring' => ['ok' => extension_loaded('mbstring'), 'label' => 'mbstring'],
            'json' => ['ok' => function_exists('json_encode'), 'label' => 'JSON'],
            'config_dir' => ['ok' => $writable($root . '/config'), 'label' => 'config/ ' . $this->t('install.writable', $this->app->i18n->lang())],
            'storage_dir' => ['ok' => $writable($root . '/storage') && $writable($root . '/storage/cache'), 'label' => 'storage/ ' . $this->t('install.writable', $this->app->i18n->lang())],
            'uploads_dir' => ['ok' => $writable($root . '/uploads'), 'label' => 'uploads/ ' . $this->t('install.writable', $this->app->i18n->lang())],
        ];
    }

    /** @return list<string> */
    private function validate(array $v, array $checks): array
    {
        $lang = $this->app->i18n->lang();
        $errors = [];
        foreach ($checks as $c) {
            if (!$c['ok']) {
                $errors[] = $this->t('install.requirements_missing', $lang);
                break;
            }
        }
        if (!preg_match('#^https?://[^/\s]+(/[^\s?\#]*)?$#', $v['base_url'])) {
            $errors[] = $this->t('install.base_url_invalid', $lang);
        }
        if ($v['db_name'] === '' || $v['db_user'] === '') {
            $errors[] = $this->t('install.db_required', $lang);
        }
        if (strlen($v['setup_key']) < 12) {
            $errors[] = $this->t('install.setup_key_short', $lang);
        }
        if ($v['spotify_client_id'] !== '' && !preg_match('/^[a-f0-9]{32}$/i', $v['spotify_client_id'])) {
            $errors[] = $this->t('install.spotify_id_invalid', $lang);
        }
        if (!in_array($v['stripe_mode'], ['test', 'live'], true)) {
            $errors[] = 'Stripe mode';
        }
        if ($errors === []) {
            try {
                $db = Db::connect($v['db_host'], (int) $v['db_port'], $v['db_name'], $v['db_user'], $v['db_pass']);
                $db->value('SELECT 1');
            } catch (\Throwable $e) {
                $errors[] = $this->t('install.db_connect_failed', $lang) . ' ' . $e->getMessage();
            }
        }
        return $errors;
    }

    private function install(array $v, string $lang): Response
    {
        $root = $this->app->root;
        $baseUrl = rtrim($v['base_url'], '/');
        $config = [
            'app' => [
                'base_url' => $baseUrl,
                'key' => Crypto::generateKey(),
                'debug' => false,
                'cron_key' => Crypto::randomToken(24),
                'installed_at' => gmdate('c'),
            ],
            'db' => [
                'host' => $v['db_host'], 'port' => (int) $v['db_port'], 'name' => $v['db_name'],
                'user' => $v['db_user'], 'pass' => $v['db_pass'], 'socket' => '',
            ],
            'spotify' => [
                'client_id' => $v['spotify_client_id'],
                'client_secret' => $v['spotify_client_secret'],
            ],
            'images' => [
                'provider' => 'openai',
                'openai_api_key' => $v['openai_api_key'],
            ],
            'stripe' => [
                'mode' => $v['stripe_mode'],
                'secret_key' => $v['stripe_secret_key'],
                'publishable_key' => $v['stripe_publishable_key'],
                'webhook_secret' => $v['stripe_webhook_secret'],
                'price_id' => $v['stripe_price_id'],
            ],
        ];
        $db = Db::connect($v['db_host'], (int) $v['db_port'], $v['db_name'], $v['db_user'], $v['db_pass']);
        (new Migrator($db, $root . '/migrations'))->migrate();
        $db->run('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            ['setup_key_hash', json_encode(password_hash($v['setup_key'], PASSWORD_DEFAULT)), $db->now()]);
        $db->run('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            ['operation_mode', json_encode('setup'), $db->now()]);
        foreach (['storage/logs', 'storage/cache', 'storage/covers', 'storage/tmp', 'storage/exports', 'uploads/avatars', 'uploads/banners', 'uploads/backgrounds'] as $d) {
            if (!is_dir($root . '/' . $d)) {
                @mkdir($root . '/' . $d, 0750, true);
            }
        }
        Config::write($root, $config);
        @file_put_contents($root . '/storage/cache/schema.json', json_encode(['fingerprint' => '', 'at' => gmdate('c')]));

        $url = new Url($baseUrl);
        $info = [
            'callback' => $url->to('/auth/spotify/callback'),
            'webhook' => $url->to('/webhooks/stripe'),
            'cron' => 'php ' . $root . '/cron/run.php',
            'cron_url' => $url->to('/cron/run', ['key' => $config['app']['cron_key']]),
            'setup' => $url->to('/setup'),
            'setup_key' => $v['setup_key'],
        ];
        return Response::html($this->renderDone($info, $lang));
    }

    private function render(array $v, array $errors, array $checks, string $lang): string
    {
        $t = fn(string $k): string => htmlspecialchars($this->t($k, $lang));
        $e = static fn(string $s): string => htmlspecialchars($s);
        $other = $lang === 'de' ? 'en' : 'de';
        $errHtml = '';
        foreach ($errors as $err) {
            $errHtml .= '<div class="err">' . $e($err) . '</div>';
        }
        $checkHtml = '';
        foreach ($checks as $c) {
            $checkHtml .= '<li class="' . ($c['ok'] ? 'ok' : 'bad') . '">' . ($c['ok'] ? '✓' : '✗') . ' ' . $e($c['label']) . '</li>';
        }
        $field = static function (string $name, string $label, string $value, string $type = 'text', string $hint = '', bool $required = false) use ($e): string {
            return '<label><span>' . $e($label) . ($required ? ' *' : '') . '</span><input type="' . $type . '" name="' . $name . '" value="' . $e($value) . '" autocomplete="off"' . ($required ? ' required' : '') . '>'
                . ($hint ? '<small>' . $e($hint) . '</small>' : '') . '</label>';
        };
        $callbackPreview = rtrim($v['base_url'], '/') . '/auth/spotify/callback';
        $webhookPreview = rtrim($v['base_url'], '/') . '/webhooks/stripe';
        return '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SwipeGroove – ' . $t('install.title') . '</title>'
            . '<style>' . self::CSS . '</style></head><body><main>'
            . '<header><div class="logo"></div><h1>SwipeGroove</h1><a class="lang" href="?lang=' . $other . '">' . strtoupper($other) . '</a></header>'
            . '<h2>' . $t('install.title') . '</h2><p class="muted">' . $t('install.intro') . '</p>'
            . '<section><h3>' . $t('install.requirements') . '</h3><ul class="checks">' . $checkHtml . '</ul></section>'
            . $errHtml
            . '<form method="post" action="?lang=' . $lang . '"><input type="hidden" name="lang" value="' . $lang . '">'
            . '<section><h3>' . $t('install.section_app') . '</h3>'
            . $field('base_url', $t('install.base_url'), $v['base_url'], 'url', $t('install.base_url_hint'), true)
            . '<div class="info"><strong>' . $t('install.callback_label') . '</strong><code>' . $e($callbackPreview) . '</code><strong>' . $t('install.webhook_label') . '</strong><code>' . $e($webhookPreview) . '</code></div>'
            . '</section>'
            . '<section><h3>' . $t('install.section_db') . '</h3>'
            . $field('db_host', 'Host', $v['db_host'], 'text', '', true) . $field('db_port', 'Port', $v['db_port'], 'text', '', true)
            . $field('db_name', $t('install.db_name'), $v['db_name'], 'text', '', true) . $field('db_user', $t('install.db_user'), $v['db_user'], 'text', '', true)
            . $field('db_pass', $t('install.db_pass'), $v['db_pass'], 'password')
            . '</section>'
            . '<section><h3>' . $t('install.section_spotify') . '</h3><p class="muted">' . $t('install.spotify_hint') . '</p>'
            . $field('spotify_client_id', 'Spotify Client ID', $v['spotify_client_id']) . $field('spotify_client_secret', 'Spotify Client Secret (optional)', $v['spotify_client_secret'], 'password', $t('install.secret_optional'))
            . '</section>'
            . '<section><h3>' . $t('install.section_images') . '</h3><p class="muted">' . $t('install.images_hint') . '</p>'
            . $field('openai_api_key', 'OpenAI API Key (optional)', $v['openai_api_key'], 'password')
            . '</section>'
            . '<section><h3>' . $t('install.section_stripe') . '</h3><p class="muted">' . $t('install.stripe_hint') . '</p>'
            . '<label><span>' . $t('install.stripe_mode') . '</span><select name="stripe_mode"><option value="test"' . ($v['stripe_mode'] === 'test' ? ' selected' : '') . '>Test</option><option value="live"' . ($v['stripe_mode'] === 'live' ? ' selected' : '') . '>Live</option></select></label>'
            . $field('stripe_secret_key', 'Stripe Secret Key', $v['stripe_secret_key'], 'password') . $field('stripe_publishable_key', 'Stripe Publishable Key', $v['stripe_publishable_key'])
            . $field('stripe_webhook_secret', 'Stripe Webhook Signing Secret', $v['stripe_webhook_secret'], 'password') . $field('stripe_price_id', 'Stripe Price ID (CHF 2.00 / ' . $t('install.month') . ')', $v['stripe_price_id'])
            . '</section>'
            . '<section><h3>' . $t('install.section_setup_key') . '</h3><p class="muted">' . $t('install.setup_key_hint') . '</p>'
            . $field('setup_key', $t('install.setup_key'), $v['setup_key'], 'text', '', true)
            . '</section>'
            . '<button type="submit">' . $t('install.submit') . '</button></form></main></body></html>';
    }

    private function renderDone(array $info, string $lang): string
    {
        $t = fn(string $k): string => htmlspecialchars($this->t($k, $lang));
        $e = static fn(string $s): string => htmlspecialchars($s);
        return '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SwipeGroove – ' . $t('install.done_title') . '</title>'
            . '<style>' . self::CSS . '</style></head><body><main>'
            . '<header><div class="logo"></div><h1>SwipeGroove</h1></header>'
            . '<h2>' . $t('install.done_title') . '</h2><p>' . $t('install.done_intro') . '</p>'
            . '<section><h3>' . $t('install.next_steps') . '</h3><ol class="steps">'
            . '<li>' . $t('install.step_callback') . '<code>' . $e($info['callback']) . '</code></li>'
            . '<li>' . $t('install.step_webhook') . '<code>' . $e($info['webhook']) . '</code></li>'
            . '<li>' . $t('install.step_cron') . '<code>' . $e($info['cron']) . '</code><small>' . $t('install.step_cron_alt') . '</small><code>' . $e($info['cron_url']) . '</code></li>'
            . '<li>' . $t('install.step_setup') . '<code>' . $e($info['setup']) . '</code><br>' . $t('install.setup_key') . ': <code>' . $e($info['setup_key']) . '</code></li>'
            . '</ol></section>'
            . '<p class="muted">' . $t('install.locked_note') . '</p>'
            . '<a class="btn" href="' . $e($info['setup']) . '">' . $t('install.go_setup') . '</a>'
            . '</main></body></html>';
    }

    private const CSS = 'body{margin:0;background:#0b0d14;color:#eef0f6;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;line-height:1.5}'
        . 'main{max-width:40rem;margin:0 auto;padding:1.5rem 1rem 4rem}header{display:flex;align-items:center;gap:.75rem;margin-bottom:1rem}'
        . '.logo{width:40px;height:40px;border-radius:12px;background:conic-gradient(from 200deg,#ff5fa2,#8b5cf6,#22d3ee,#ff5fa2)}'
        . 'h1{font-size:1.4rem;margin:0}h2{font-size:1.25rem;margin:1rem 0 .25rem}h3{font-size:1rem;margin:0 0 .5rem}'
        . '.lang{margin-left:auto;color:#a9b0c6;text-decoration:none;border:1px solid #2a3042;border-radius:999px;padding:.25rem .7rem;font-size:.85rem}'
        . 'section{background:#151926;border:1px solid #232a3b;border-radius:16px;padding:1rem;margin:1rem 0}'
        . 'label{display:block;margin:.6rem 0}label span{display:block;font-size:.85rem;color:#a9b0c6;margin-bottom:.25rem}'
        . 'input,select{width:100%;box-sizing:border-box;background:#0f1320;color:#eef0f6;border:1px solid #2a3042;border-radius:10px;padding:.65rem .75rem;font-size:1rem}'
        . 'input:focus,select:focus{outline:2px solid #8b5cf6;border-color:#8b5cf6}small{display:block;color:#8b93ab;font-size:.8rem;margin-top:.25rem}'
        . 'button,.btn{display:inline-block;background:linear-gradient(135deg,#ff5fa2,#8b5cf6);color:#fff;border:0;border-radius:999px;padding:.85rem 1.5rem;font-size:1rem;font-weight:600;cursor:pointer;text-decoration:none}'
        . '.err{background:#3a1420;border:1px solid #ff5fa2;border-radius:12px;padding:.75rem;margin:.75rem 0}.muted{color:#a9b0c6}'
        . 'code{display:block;background:#0f1320;border:1px solid #2a3042;border-radius:8px;padding:.5rem;margin:.35rem 0;word-break:break-all;font-size:.85rem}'
        . '.checks{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:1fr 1fr;gap:.25rem}.checks .ok{color:#4ade80}.checks .bad{color:#ff5fa2}'
        . '.info{background:#0f1320;border-radius:10px;padding:.6rem;margin-top:.5rem;font-size:.85rem}.info strong{display:block;color:#a9b0c6;margin-top:.4rem}'
        . '.steps li{margin:.75rem 0}@media(max-width:480px){.checks{grid-template-columns:1fr}}';
}
