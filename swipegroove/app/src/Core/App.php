<?php
declare(strict_types=1);

namespace SwipeGroove\Core;

use SwipeGroove\Auth\SessionManager;
use SwipeGroove\Http\Routes;

/**
 * Anwendungskern: Dienste, Request-Lebenszyklus, Fehlerbehandlung.
 */
final class App
{
    public string $root;
    public ?Config $config = null;
    public Url $url;
    public I18n $i18n;
    public Logger $logger;
    public HttpClientInterface $http;
    private ?Db $db = null;
    private ?Settings $settings = null;
    private ?Crypto $crypto = null;
    private ?RateLimiter $limiter = null;
    /** @var array<string,object> */
    private array $services = [];
    public bool $installed = false;
    private static ?App $instance = null;

    private function __construct(string $root)
    {
        $this->root = $root;
        $this->config = Config::load($root);
        $this->installed = $this->config !== null && $this->config->has('db.name');
        $base = $this->installed ? $this->config->string('app.base_url') : Url::detectFromRequest($root);
        $this->url = new Url($base);
        $this->i18n = new I18n($root . '/lang');
        $this->logger = new Logger($root . '/storage/logs');
        $this->http = new CurlHttpClient();
    }

    public static function create(string $root): self
    {
        self::$instance = new self($root);
        return self::$instance;
    }

    /** Für Tests: App mit bestehender Konfiguration/DB erzeugen. */
    public static function createForTests(string $root, Config $config, Db $db, HttpClientInterface $http): self
    {
        $app = new self($root);
        $app->config = $config;
        $app->installed = true;
        $app->url = new Url($config->string('app.base_url'));
        $app->db = $db;
        $app->http = $http;
        self::$instance = $app;
        return $app;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('App nicht initialisiert');
        }
        return self::$instance;
    }

    public function db(): Db
    {
        if ($this->db === null) {
            if (!$this->installed || $this->config === null) {
                throw new \RuntimeException('Anwendung ist nicht installiert.');
            }
            $this->db = Db::connect(
                $this->config->string('db.host', 'localhost'),
                (int) $this->config->get('db.port', 3306),
                $this->config->string('db.name'),
                $this->config->string('db.user'),
                $this->config->string('db.pass'),
                $this->config->string('db.socket') ?: null,
            );
        }
        return $this->db;
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db());
    }

    public function crypto(): Crypto
    {
        return $this->crypto ??= new Crypto($this->config?->string('app.key') ?? '');
    }

    public function limiter(): RateLimiter
    {
        return $this->limiter ??= new RateLimiter($this->db());
    }

    /**
     * Lazy Service-Locator: $app->service(Foo::class) erzeugt Foo($app) einmalig.
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function service(string $class): object
    {
        if (!isset($this->services[$class])) {
            $this->services[$class] = new $class($this);
        }
        /** @var T */
        return $this->services[$class];
    }

    public function setService(string $class, object $instance): void
    {
        $this->services[$class] = $instance;
    }

    public function t(string $key, array $params = []): string
    {
        return $this->i18n->t($key, $params);
    }

    public function isDebug(): bool
    {
        return (bool) ($this->config?->get('app.debug', false) ?? false);
    }

    // ---------------------------------------------------------------- Lebenszyklus

    public function run(): void
    {
        $request = Request::fromGlobals($this->url->basePath());
        $response = $this->handle($request);
        $response->send();
    }

    public function handle(Request $request): Response
    {
        try {
            if (!$this->installed) {
                return $this->service(\SwipeGroove\Http\Controllers\InstallController::class)->handle($request);
            }
            $this->ensureSchema();
            $this->service(SessionManager::class)->resolve($request);
            $this->i18n->setLang($this->detectLang($request));

            $router = new Router();
            Routes::register($router);
            $match = $router->match($request->method, $request->path);
            if ($match === null) {
                if (str_starts_with($request->path, '/api/')) {
                    throw HttpException::notFound();
                }
                return $this->service(\SwipeGroove\Http\Controllers\ShellController::class)->shell($request);
            }
            $request->attributes = $match['params'];
            $this->applyGuards($request, $match['opts']);
            $handler = $match['handler'];
            if (is_array($handler) && is_string($handler[0])) {
                $controller = $this->service($handler[0]);
                $result = $controller->{$handler[1]}($request);
            } else {
                $result = $handler($request, $this);
            }
            if ($result instanceof Response) {
                return $result;
            }
            return Response::json($result);
        } catch (HttpException $e) {
            return $this->errorResponse($request, $e);
        } catch (\Throwable $e) {
            $this->logger->error('Unbehandelter Fehler', [
                'type' => get_class($e), 'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(), 'path' => $request->path ?? '',
            ]);
            try {
                $this->service(\SwipeGroove\Admin\ErrorLog::class)->record('app', $e, $request);
            } catch (\Throwable) {
            }
            $ex = new HttpException(500, 'error.server', $this->isDebug() ? $e->getMessage() : null);
            return $this->errorResponse($request, $ex, $e);
        }
    }

    private function detectLang(Request $request): string
    {
        if ($request->user && !empty($request->user->lang)) {
            return (string) $request->user->lang;
        }
        $cookie = $_COOKIE['sg_lang'] ?? null;
        if ($cookie && in_array($cookie, I18n::LANGS, true)) {
            return $cookie;
        }
        return I18n::fromAcceptLanguage($request->header('accept-language'));
    }

    /** @param array<string,mixed> $opts */
    private function applyGuards(Request $request, array $opts): void
    {
        $auth = $opts['auth'] ?? 'user';
        if ($auth === 'user' || $auth === 'admin' || $auth === 'premium') {
            if (!$request->user) {
                throw HttpException::unauthorized();
            }
            if ($request->user->status !== 'active') {
                throw HttpException::forbidden('error.account_suspended');
            }
            if ($auth === 'admin' && $request->user->role !== 'admin') {
                throw HttpException::forbidden('error.admin_only');
            }
            if ($auth === 'premium' && !$this->service(\SwipeGroove\Billing\SubscriptionService::class)->isPremium($request->user)) {
                throw HttpException::forbidden('error.premium_required');
            }
        }
        if (($opts['csrf'] ?? true) && in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $this->service(SessionManager::class)->verifyCsrf($request);
        }
    }

    private function errorResponse(Request $request, HttpException $e, ?\Throwable $orig = null): Response
    {
        $wantsJson = str_starts_with($request->path, '/api/') || str_contains($request->header('accept') ?? '', 'application/json');
        $payload = [
            'error' => $e->errorCode,
            'message' => $this->i18n->t($e->errorCode, $e->messageParams),
        ] + $e->extra;
        if ($this->isDebug() && $orig) {
            $payload['debug'] = $orig->getMessage() . ' @ ' . $orig->getFile() . ':' . $orig->getLine();
        }
        if ($wantsJson) {
            $r = Response::json($payload, $e->status);
            if ($e->status === 429 && isset($e->extra['retry_after'])) {
                $r->withHeader('Retry-After', (string) $e->extra['retry_after']);
            }
            return $r;
        }
        $html = '<!doctype html><html lang="' . $this->i18n->lang() . '"><head><meta charset="utf-8"><title>SwipeGroove</title>'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>body{font-family:system-ui,sans-serif;background:#0f1117;color:#eef0f6;display:grid;place-items:center;min-height:100vh;margin:0}'
            . '.c{max-width:28rem;padding:2rem;text-align:center}a{color:#8be9fd}</style></head><body><div class="c">'
            . '<h1>' . htmlspecialchars((string) $e->status) . '</h1><p>' . htmlspecialchars($payload['message']) . '</p>'
            . '<p><a href="' . htmlspecialchars($this->url->path('/')) . '">SwipeGroove</a></p></div></body></html>';
        return Response::html($html, $e->status);
    }

    /**
     * Führt ausstehende Migrationen automatisch aus (Updates ohne Neuinstallation).
     */
    public function ensureSchema(): void
    {
        $marker = $this->root . '/storage/cache/schema.json';
        $files = glob($this->root . '/migrations/*.php') ?: [];
        sort($files, SORT_NATURAL);
        $fingerprint = hash('sha256', implode('|', array_map('basename', $files)));
        if (is_file($marker)) {
            $data = json_decode((string) file_get_contents($marker), true);
            if (is_array($data) && ($data['fingerprint'] ?? '') === $fingerprint) {
                return;
            }
        }
        $lock = fopen($this->root . '/storage/cache/.migrate.lock', 'c');
        if ($lock && flock($lock, LOCK_EX)) {
            try {
                $migrator = new Migrator($this->db(), $this->root . '/migrations');
                $done = $migrator->migrate();
                if ($done) {
                    $this->logger->info('Migrationen angewendet', ['versions' => $done]);
                }
                file_put_contents($marker, json_encode(['fingerprint' => $fingerprint, 'at' => gmdate('c')]));
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
