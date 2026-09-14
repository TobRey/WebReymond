<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthController extends Controller
{
    public function login(Request $request): Response
    {
        $auth = $this->container->auth();
        if ($auth->currentUser() !== null && !$request->isPost()) {
            return Response::redirect('/faelle');
        }

        $error = '';
        if ($request->isPost()) {
            $result = $auth->login(
                $request->str('username', '', 100),
                $request->str('password', '', 200),
                $request->ip()
            );
            if ($result['ok']) {
                $user = $result['user'];
                if (!empty($user['must_change_password'])) {
                    Session::flash('ok', 'Bitte aendere jetzt das Startpasswort.');
                    return Response::redirect('/konto');
                }
                $target = ($user['role'] ?? '') === 'admin' ? '/admin' : '/faelle';
                return Response::redirect($target);
            }
            $error = (string)$result['error'];
        }

        return $this->render('auth/login', [
            'title' => 'Anmeldung',
            'error' => $error,
            'allowRegister' => (bool)$this->container->settings()->get('site.allow_register', true),
            'allowGuests'   => (bool)$this->container->settings()->get('site.allow_guests', true),
        ], 'layout_public');
    }

    public function register(Request $request): Response
    {
        $settings = $this->container->settings();
        if (!(bool)$settings->get('site.allow_register', true)) {
            Session::flash('error', 'Die Registrierung ist derzeit deaktiviert.');
            return Response::redirect('/login');
        }

        $error = '';
        $values = ['username' => '', 'email' => ''];
        if ($request->isPost()) {
            $values['username'] = $request->str('username', '', 64);
            $values['email'] = $request->str('email', '', 190);
            $result = $this->container->auth()->register(
                $values['username'],
                $request->str('password', '', 200),
                $request->str('password_repeat', '', 200),
                $values['email'],
                $request->bool('age_confirm'),
                $request->ip()
            );
            if ($result['ok']) {
                Session::set('age_confirmed', true);
                return Response::redirect('/faelle');
            }
            $error = (string)$result['error'];
        }

        return $this->render('auth/register', [
            'title'  => 'Konto anlegen',
            'error'  => $error,
            'values' => $values,
        ], 'layout_public');
    }

    public function guest(Request $request): Response
    {
        $this->container->auth()->loginAsGuest();
        return Response::redirect('/faelle');
    }

    public function logout(Request $request): Response
    {
        $this->container->auth()->logout();
        return Response::redirect('/');
    }

    public function account(Request $request): Response
    {
        $auth = $this->container->auth();
        $user = $auth->requireUser();
        $progressList = $auth->isGuest() ? [] : $this->container->progress()->listForUser((string)$user['id']);
        $cases = [];
        foreach ($this->container->cases()->listSummaries(true) as $summary) {
            $cases[$summary['id']] = $summary;
        }

        return $this->render('auth/account', [
            'title'    => 'Mein Konto',
            'account'  => $user,
            'progress' => $progressList,
            'cases'    => $cases,
        ], 'layout_public');
    }

    public function changePassword(Request $request): Response
    {
        $auth = $this->container->auth();
        $user = $auth->requireUser();
        $result = $auth->changePassword(
            $user,
            $request->str('current_password', '', 200),
            $request->str('password', '', 200),
            $request->str('password_repeat', '', 200)
        );
        if ($result['ok']) {
            Session::flash('ok', 'Passwort geaendert.');
        } else {
            Session::flash('error', (string)$result['error']);
        }
        return Response::redirect('/konto');
    }

    public function deleteAccount(Request $request): Response
    {
        $auth = $this->container->auth();
        $user = $auth->requireUser();
        if ($request->str('confirm') !== 'LOESCHEN') {
            Session::flash('error', 'Zur Bestaetigung bitte das Wort LOESCHEN eingeben.');
            return Response::redirect('/konto');
        }
        if (($user['role'] ?? '') === 'admin' && $this->container->users()->count() <= 1) {
            Session::flash('error', 'Das letzte Administratorkonto kann nicht geloescht werden.');
            return Response::redirect('/konto');
        }
        $auth->deleteOwnAccount($user);
        return Response::redirect('/');
    }
}
