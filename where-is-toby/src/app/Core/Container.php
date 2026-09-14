<?php
declare(strict_types=1);

namespace App\Core;

use App\Repository\CaseRepository;
use App\Repository\JsonStore;
use App\Repository\ProgressRepository;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;
use App\Service\Ai\AiClient;
use App\Service\AuthService;
use App\Service\BackupService;
use App\Service\CaseEngine;
use App\Service\CaseValidator;
use App\Service\DiagnosticsService;
use App\Service\HintService;
use App\Service\HorrorService;
use App\Service\MediaGenerator;
use App\Service\NpcChatService;
use App\Service\OfflineDialogService;
use App\Service\PuzzleEngine;
use App\Service\ReportService;
use App\Service\UploadService;

/**
 * Schlanker Service-Container ohne externe Abhaengigkeiten.
 */
final class Container
{
    private array $instances = [];
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function boot(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            Session::start();
        }
        return self::$instance;
    }

    public static function instance(): self
    {
        return self::$instance ?? self::boot();
    }

    private function share(string $key, callable $factory): mixed
    {
        return $this->instances[$key] ??= $factory();
    }

    public function store(): JsonStore
    {
        return $this->share('store', static fn() => new JsonStore(WIT_STORAGE, WIT_STORAGE . '/backups'));
    }

    public function settings(): SettingsRepository
    {
        return $this->share('settings', fn() => new SettingsRepository($this->store()));
    }

    public function users(): UserRepository
    {
        return $this->share('users', fn() => new UserRepository($this->store()));
    }

    public function cases(): CaseRepository
    {
        return $this->share('cases', fn() => new CaseRepository($this->store()));
    }

    public function progress(): ProgressRepository
    {
        return $this->share('progress', fn() => new ProgressRepository($this->store()));
    }

    public function limiter(): RateLimiter
    {
        return $this->share('limiter', static fn() => new RateLimiter(WIT_STORAGE . '/cache/ratelimit'));
    }

    public function auth(): AuthService
    {
        return $this->share('auth', fn() => new AuthService($this->users(), $this->settings(), $this->limiter()));
    }

    public function view(): View
    {
        return $this->share('view', static fn() => new View(WIT_APP . '/View'));
    }

    public function puzzles(): PuzzleEngine
    {
        return $this->share('puzzles', static fn() => new PuzzleEngine());
    }

    public function horror(): HorrorService
    {
        return $this->share('horror', fn() => new HorrorService($this->settings()));
    }

    public function hints(): HintService
    {
        return $this->share('hints', fn() => new HintService($this->settings(), $this->ai()));
    }

    public function offlineDialog(): OfflineDialogService
    {
        return $this->share('offlineDialog', static fn() => new OfflineDialogService());
    }

    public function ai(): AiClient
    {
        return $this->share('ai', fn() => new AiClient($this->settings(), $this->limiter()));
    }

    public function npcChat(): NpcChatService
    {
        return $this->share('npcChat', fn() => new NpcChatService(
            $this->ai(),
            $this->offlineDialog(),
            $this->settings(),
            $this->limiter()
        ));
    }

    public function engine(): CaseEngine
    {
        return $this->share('engine', fn() => new CaseEngine(
            $this->cases(),
            $this->progress(),
            $this->puzzles(),
            $this->horror(),
            $this->settings()
        ));
    }

    public function report(): ReportService
    {
        return $this->share('report', static fn() => new ReportService());
    }

    public function uploads(): UploadService
    {
        return $this->share('uploads', static fn() => new UploadService(WIT_UPLOADS));
    }

    public function mediaGenerator(): MediaGenerator
    {
        return $this->share('mediaGenerator', static fn() => new MediaGenerator(WIT_UPLOADS));
    }

    public function backups(): BackupService
    {
        return $this->share('backups', static fn() => new BackupService(WIT_STORAGE, WIT_UPLOADS));
    }

    public function diagnostics(): DiagnosticsService
    {
        return $this->share('diagnostics', fn() => new DiagnosticsService($this));
    }

    public function caseValidator(): CaseValidator
    {
        return $this->share('caseValidator', static fn() => new CaseValidator());
    }
}
