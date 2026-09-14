<?php
/**
 * WHERE IS TOBY? - Front-Controller
 *
 * Alle Anfragen laufen ueber diese Datei. PHP-Dateien der Anwendung liegen in
 * /app und sind per .htaccess nicht direkt erreichbar.
 */
declare(strict_types=1);

use App\Controller\AdminApiController;
use App\Controller\AdminController;
use App\Controller\ApiController;
use App\Controller\AuthController;
use App\Controller\GameController;
use App\Controller\HomeController;
use App\Controller\MediaController;
use App\Core\Csrf;
use App\Core\Environment;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

$container = require __DIR__ . '/app/bootstrap.php';

$request = Request::capture();

/* ------------------------------------------------------------------
 |  Sicherheitsheader (inkl. Content Security Policy)
 ------------------------------------------------------------------ */
if (!headers_sent()) {
    $csp = "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; "
        . "media-src 'self' data: blob:; "
        . "font-src 'self'; "
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'";
    header('Content-Security-Policy: ' . $csp);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), payment=(), usb=()');
    header_remove('X-Powered-By');
    if (Environment::isHttps()) {
        header('Strict-Transport-Security: max-age=15552000');
    }
}

/* ------------------------------------------------------------------
 |  Installationsweiche
 ------------------------------------------------------------------ */
if (!WIT_INSTALLED) {
    $path = $request->path();
    if (!str_starts_with($path, '/assets')) {
        if (is_file(WIT_ROOT . '/install.php')) {
            Response::redirect('/install.php')->send();
        } else {
            Response::html(\App\Core\ErrorHandler::errorPage(
                503,
                'Die Anwendung ist noch nicht installiert und install.php fehlt. Bitte die Datei install.php erneut hochladen.',
                null
            ), 503)->send();
        }
        exit;
    }
}

/* ------------------------------------------------------------------
 |  Routen
 ------------------------------------------------------------------ */
$router = new Router();

$home = new HomeController($container);
$auth = new AuthController($container);
$game = new GameController($container);
$api = new ApiController($container);
$admin = new AdminController($container);
$adminApi = new AdminApiController($container);
$media = new MediaController($container);

/* Oeffentlich */
$router->get('/', [$home, 'index']);
$router->get('/impressum', [$home, 'imprint']);
$router->get('/datenschutz', [$home, 'privacy']);
$router->get('/hilfe', [$home, 'help']);
$router->get('/error/{code}', [$home, 'error']);

/* Konten */
$router->any('/login', [$auth, 'login']);
$router->any('/registrieren', [$auth, 'register']);
$router->post('/logout', [$auth, 'logout']);
$router->post('/gast', [$auth, 'guest']);
$router->any('/konto', [$auth, 'account']);
$router->post('/konto/passwort', [$auth, 'changePassword']);
$router->post('/konto/loeschen', [$auth, 'deleteAccount']);

/* Spiel */
$router->get('/faelle', [$game, 'caseList']);
$router->get('/spielen/{case}', [$game, 'play']);

/* Spiel-API */
$router->get('/api/session', [$api, 'session']);
$router->post('/api/age-confirm', [$api, 'confirmAge']);
$router->post('/api/settings', [$api, 'saveSettings']);
$router->get('/api/cases', [$api, 'cases']);
$router->get('/api/case/{case}/state', [$api, 'state']);
$router->post('/api/case/{case}/start', [$api, 'start']);
$router->post('/api/case/{case}/reset', [$api, 'reset']);
$router->post('/api/case/{case}/puzzle', [$api, 'puzzle']);
$router->post('/api/case/{case}/evidence', [$api, 'evidence']);
$router->get('/api/case/{case}/device/{device}/app/{app}', [$api, 'deviceApp']);
$router->get('/api/case/{case}/media/{media}', [$api, 'media']);
$router->get('/api/case/{case}/chat/{npc}', [$api, 'chatHistory']);
$router->post('/api/case/{case}/chat', [$api, 'chat']);
$router->post('/api/case/{case}/hint', [$api, 'hint']);
$router->post('/api/case/{case}/note', [$api, 'note']);
$router->post('/api/case/{case}/note/delete', [$api, 'deleteNote']);
$router->post('/api/case/{case}/board', [$api, 'board']);
$router->post('/api/case/{case}/report', [$api, 'saveReport']);
$router->post('/api/case/{case}/report/submit', [$api, 'submitReport']);
$router->post('/api/case/{case}/heartbeat', [$api, 'heartbeat']);
$router->post('/api/case/{case}/event', [$api, 'event']);

/* Medien (geschuetzte Auslieferung von Uploads) */
$router->get('/medien/{file}', [$media, 'show']);
$router->get('/medien/thumb/{file}', [$media, 'thumb']);
$router->get('/klang/{track}.wav', [$media, 'audio']);

/* Adminbereich */
$router->get('/admin', [$admin, 'dashboard']);
$router->get('/admin/faelle', [$admin, 'cases']);
$router->get('/admin/fall/{case}', [$admin, 'caseEditor']);
$router->get('/admin/medien', [$admin, 'media']);
$router->get('/admin/spieler', [$admin, 'players']);
$router->get('/admin/einstellungen', [$admin, 'settings']);
$router->get('/admin/ki', [$admin, 'ai']);
$router->get('/admin/sicherung', [$admin, 'backups']);
$router->get('/admin/protokolle', [$admin, 'logs']);
$router->get('/admin/diagnose', [$admin, 'diagnostics']);
$router->get('/admin/konto', [$admin, 'account']);
$router->get('/admin/test/{case}', [$admin, 'testCase']);

/* Admin-API */
$router->post('/api/admin/case/save', [$adminApi, 'saveCase']);
$router->post('/api/admin/case/status', [$adminApi, 'setStatus']);
$router->post('/api/admin/case/delete', [$adminApi, 'deleteCase']);
$router->post('/api/admin/case/duplicate', [$adminApi, 'duplicateCase']);
$router->post('/api/admin/case/import', [$adminApi, 'importCase']);
$router->get('/api/admin/case/{case}/export', [$adminApi, 'exportCase']);
$router->get('/api/admin/case/{case}/raw', [$adminApi, 'rawCase']);
$router->get('/api/admin/case/{case}/validate', [$adminApi, 'validateCase']);
$router->get('/api/admin/case/{case}/versions', [$adminApi, 'versions']);
$router->post('/api/admin/case/version/restore', [$adminApi, 'restoreVersion']);
$router->post('/api/admin/case/reset-test', [$adminApi, 'resetTest']);
$router->post('/api/admin/hint/suggest', [$adminApi, 'suggestHint']);
$router->post('/api/admin/ai/suggest', [$adminApi, 'suggest']);
$router->post('/api/admin/ai/test', [$adminApi, 'testAi']);
$router->post('/api/admin/ai/save', [$adminApi, 'saveAi']);
$router->post('/api/admin/settings/save', [$adminApi, 'saveSettings']);
$router->post('/api/admin/media/upload', [$adminApi, 'uploadMedia']);
$router->post('/api/admin/media/delete', [$adminApi, 'deleteMedia']);
$router->post('/api/admin/media/generate', [$adminApi, 'generateMedia']);
$router->get('/api/admin/media/list', [$adminApi, 'listMedia']);
$router->post('/api/admin/user/save', [$adminApi, 'saveUser']);
$router->post('/api/admin/user/delete', [$adminApi, 'deleteUser']);
$router->post('/api/admin/user/reset-progress', [$adminApi, 'resetProgress']);
$router->post('/api/admin/backup/create', [$adminApi, 'createBackup']);
$router->post('/api/admin/backup/restore', [$adminApi, 'restoreBackup']);
$router->post('/api/admin/backup/delete', [$adminApi, 'deleteBackup']);
$router->get('/api/admin/backup/download', [$adminApi, 'downloadBackup']);
$router->get('/api/admin/logs', [$adminApi, 'logs']);
$router->post('/api/admin/logs/clear', [$adminApi, 'clearLogs']);
$router->get('/api/admin/diagnostics', [$adminApi, 'diagnostics']);
$router->post('/api/admin/password', [$adminApi, 'changePassword']);

/* ------------------------------------------------------------------
 |  Ausfuehrung
 ------------------------------------------------------------------ */
try {
    // CSRF fuer alle schreibenden Anfragen
    if ($request->isPost()) {
        Csrf::check($request);
        $container->limiter()->enforce(
            'api:' . $request->ip(),
            (int)$container->settings()->get('security.api_per_minute', 120),
            60
        );
    }
    $response = $router->dispatch($request);
    $response->send();
} catch (HttpException $e) {
    if ($request->wantsJson()) {
        Response::json(['ok' => false, 'error' => $e->getMessage()], $e->getStatusCode())->send();
    } else {
        Response::html(\App\Core\ErrorHandler::errorPage($e->getStatusCode(), $e->getMessage(), null), $e->getStatusCode())->send();
    }
} catch (Throwable $e) {
    \App\Core\ErrorHandler::handle($e);
}
