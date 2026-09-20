<?php

declare(strict_types=1);

namespace SkyKingdoms\Http;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Auth;
use SkyKingdoms\Core\Response;
use SkyKingdoms\Core\Security;
use SkyKingdoms\Core\Session;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\View;
use SkyKingdoms\Game\Player;
use SkyKingdoms\Http\Controllers\AuthController;
use SkyKingdoms\Http\Controllers\GameController;

/** Verteilt Anfragen auf die Steuerungsklassen. */
final class Router
{
    public static function web(): void
    {
        Session::start();
        Security::headers();

        if (!App::isInstalled()) {
            Response::redirect('install/');
        }

        $page = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($_GET['p'] ?? ''))) ?: '';
        $auth = new AuthController();
        $game = new GameController();

        // Wartungsmodus: nur Administratoren kommen durch.
        if (App::config('maintenance', false) && !Player::isAdmin(Auth::user()) && !in_array($page, ['login', 'logout'], true)) {
            http_response_code(503);
            echo View::renderSimple(
                'Wartungsarbeiten',
                (string) App::config('maintenance_note', 'Das Königreich wird gerade erweitert.'),
                'Zur Anmeldung',
                Url::to('?p=login')
            );
            exit;
        }

        match ($page) {
            'login'     => $auth->login(),
            'register'  => $auth->register(),
            'forgot'    => $auth->forgot(),
            'reset'     => $auth->reset(),
            'logout'    => $auth->logout(),
            'profil', 'profile' => $game->profile(),
            'konto', 'account'  => $game->account(),
            'rangliste'=> $game->ranking(),
            'spiel', 'game', '' => $game->play(),
            default     => Response::notFound(),
        };
    }
}
