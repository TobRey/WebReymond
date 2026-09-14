<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Container;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    public function __construct(protected Container $container)
    {
    }

    protected function render(string $template, array $data = [], string $layout = 'layout'): Response
    {
        $view = $this->container->view();
        $user = $this->container->auth()->currentUser();
        $settings = $this->container->settings();

        $data = array_merge([
            'csrf'      => Csrf::token(),
            'user'      => $user,
            'isAdmin'   => $user !== null && ($user['role'] ?? '') === 'admin',
            'isGuest'   => $this->container->auth()->isGuest(),
            'siteName'  => (string)$settings->get('site.name', 'WHERE IS TOBY?'),
            'tagline'   => (string)$settings->get('site.tagline', ''),
            'flashOk'   => Session::flash('ok'),
            'flashError'=> Session::flash('error'),
            'settings'  => $settings,
            'title'     => (string)($data['title'] ?? 'WHERE IS TOBY?'),
        ], $data);

        $content = $view->render($template, $data);
        return Response::html($view->layout($layout, $content, $data));
    }

    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function ok(array $data = []): Response
    {
        return Response::json(array_merge(['ok' => true], $data));
    }

    protected function fail(string $message, int $status = 400): Response
    {
        return Response::json(['ok' => false, 'error' => $message], $status);
    }

    protected function e(mixed $value): string
    {
        return View::e($value);
    }
}
