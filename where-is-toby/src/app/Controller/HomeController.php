<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;

final class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $auth = $this->container->auth();
        $user = $auth->currentUser();
        $settings = $this->container->settings();

        if ($user !== null) {
            return Response::redirect('/faelle');
        }

        return $this->render('home/landing', [
            'title'         => 'WHERE IS TOBY? - FBI Ermittlungsterminal',
            'allowGuests'   => (bool)$settings->get('site.allow_guests', true),
            'allowRegister' => (bool)$settings->get('site.allow_register', true),
            'cases'         => $this->container->cases()->listSummaries(),
        ], 'layout_public');
    }

    public function imprint(Request $request): Response
    {
        return $this->render('home/text', [
            'title'   => 'Impressum',
            'heading' => 'Impressum',
            'body'    => (string)$this->container->settings()->get('site.imprint', ''),
        ], 'layout_public');
    }

    public function privacy(Request $request): Response
    {
        return $this->render('home/text', [
            'title'   => 'Datenschutz',
            'heading' => 'Datenschutzhinweis',
            'body'    => (string)$this->container->settings()->get('site.privacy', ''),
        ], 'layout_public');
    }

    public function help(Request $request): Response
    {
        return $this->render('home/help', ['title' => 'Hilfe und Bedienung'], 'layout_public');
    }

    public function error(Request $request, array $args): Response
    {
        $code = (int)($args['code'] ?? 404);
        $code = in_array($code, [400, 401, 403, 404, 405, 429, 500, 503], true) ? $code : 404;
        $messages = [
            400 => 'Die Anfrage war fehlerhaft.',
            401 => 'Dafuer ist eine Anmeldung noetig.',
            403 => 'Dieser Bereich ist gesperrt.',
            404 => 'Diese Akte existiert nicht.',
            405 => 'Methode nicht erlaubt.',
            429 => 'Zu viele Anfragen. Bitte kurz warten.',
            500 => 'Unerwarteter Serverfehler.',
            503 => 'Der Dienst ist derzeit nicht verfuegbar.',
        ];
        return Response::html(\App\Core\ErrorHandler::errorPage($code, $messages[$code], null), $code);
    }
}
