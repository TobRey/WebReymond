<?php
declare(strict_types=1);

namespace SwipeGroove\Http\Controllers;

use SwipeGroove\Auth\AccountService;
use SwipeGroove\Core\App;
use SwipeGroove\Core\Request;
use SwipeGroove\Core\Response;
use SwipeGroove\Http\Controllers\MeController;

/**
 * Liefert die SPA-Hülle mit eingebetteter Startkonfiguration (keine Geheimnisse).
 */
final class ShellController
{
    public function __construct(private App $app)
    {
    }

    public function shell(Request $request): Response
    {
        $assets = $this->assets();
        $lang = $this->app->i18n->lang();
        $boot = [
            'basePath' => $this->app->url->basePath(),
            'baseUrl' => $this->app->url->base(),
            'lang' => $lang,
            'csrf' => $request->sessionToken,
            'version' => SG_VERSION,
            'user' => $request->user ? $this->app->service(MeController::class)->payload($request->user) : null,
            'platform' => $this->platformInfo(),
            'i18n' => $this->app->i18n->bundle($lang),
        ];
        $json = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';
        $base = htmlspecialchars($this->app->url->basePath() . '/');
        $css = '';
        foreach ($assets['css'] as $href) {
            $css .= '<link rel="stylesheet" href="' . htmlspecialchars($href) . '">';
        }
        $js = '';
        foreach ($assets['js'] as $src) {
            $js .= '<script type="module" src="' . htmlspecialchars($src) . '"></script>';
        }
        $title = 'SwipeGroove';
        $html = <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#F7F7FA">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta name="robots" content="noindex">
<title>{$title}</title>
<link rel="icon" href="{$base}assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="{$base}assets/apple-touch-icon.png">
<link rel="manifest" href="{$base}assets/site.webmanifest">
{$css}
</head>
<body>
<div id="app"></div>
<script type="application/json" id="sg-boot">{$json}</script>
{$js}
</body>
</html>
HTML;
        $r = Response::html($html);
        $r->headers['Cache-Control'] = 'no-store';
        $r->headers['Content-Security-Policy'] = $this->csp();
        return $r;
    }

    /** CSP; mit aktivierter Wiedergabe zusätzlich die Quellen des Spotify Web Playback SDK. */
    private function csp(): string
    {
        $script = "'self'";
        $connect = "'self'";
        $frame = 'https://checkout.stripe.com https://billing.stripe.com';
        $media = "'self'";
        if ($this->app->settings()->str('playback_mode', 'off') === 'connect') {
            $script .= ' https://sdk.scdn.co';
            $connect .= ' https://*.spotify.com wss://*.spotify.com https://*.scdn.co https://*.spotifycdn.com';
            $frame .= ' https://sdk.scdn.co';
            $media .= ' blob: https://*.scdn.co https://*.spotifycdn.com';
        }
        return "default-src 'self'; img-src 'self' data: blob: https://*.scdn.co https://*.spotifycdn.com; style-src 'self' 'unsafe-inline'; font-src 'self'; "
            . "script-src $script; connect-src $connect; media-src $media; frame-src $frame; "
            . "form-action 'self' https://accounts.spotify.com https://checkout.stripe.com https://billing.stripe.com; base-uri 'self'; object-src 'none'";
    }

    /** @return array{css:list<string>, js:list<string>} */
    private function assets(): array
    {
        $manifestFile = $this->app->root . '/assets/manifest.json';
        $css = [];
        $js = [];
        if (is_file($manifestFile)) {
            $manifest = json_decode((string) file_get_contents($manifestFile), true) ?: [];
            foreach ($manifest as $entry) {
                if (!is_array($entry) || empty($entry['isEntry'])) {
                    continue;
                }
                $js[] = $this->app->url->path('/assets/' . $entry['file']);
                foreach ($entry['css'] ?? [] as $c) {
                    $css[] = $this->app->url->path('/assets/' . $c);
                }
            }
        }
        if ($js === []) {
            $js[] = $this->app->url->path('/assets/app.js');
            $css[] = $this->app->url->path('/assets/app.css');
        }
        return ['css' => $css, 'js' => $js];
    }

    /** Öffentliche Plattforminformationen für die SPA. */
    public function platformInfo(): array
    {
        $settings = $this->app->settings();
        $accounts = $this->app->service(AccountService::class);
        $cfg = $this->app->config;
        return [
            'owner_registered' => $accounts->ownerExists(),
            'registration_open' => $accounts->registrationOpen(),
            'operation_mode' => $settings->str('operation_mode', 'setup'),
            'spotify_configured' => (bool) $cfg?->string('spotify.client_id'),
            'playback_mode' => $settings->str('playback_mode', 'off'),
            'covers_available' => (bool) $cfg?->string('images.openai_api_key'),
            'cover_model' => $settings->str('cover_model'),
            'billing_available' => (bool) $cfg?->string('stripe.secret_key') && (bool) $cfg?->string('stripe.price_id'),
            'billing_mode' => $cfg?->string('stripe.mode', 'test') ?? 'test',
            'premium_price_chf' => $settings->str('premium_price_chf', '2.00'),
            'premium_cover_quota' => $settings->int('premium_cover_quota', 50),
            'legal' => [
                'operator_name' => $settings->str('legal_operator_name'),
                'operator_address' => $settings->str('legal_operator_address'),
                'operator_email' => $settings->str('legal_operator_email'),
                'operator_country' => $settings->str('legal_operator_country'),
            ],
        ];
    }
}
