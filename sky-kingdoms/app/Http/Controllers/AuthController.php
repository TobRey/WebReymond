<?php

declare(strict_types=1);

namespace SkyKingdoms\Http\Controllers;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Audit;
use SkyKingdoms\Core\Auth;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Mailer;
use SkyKingdoms\Core\RateLimit;
use SkyKingdoms\Core\Response;
use SkyKingdoms\Core\Security;
use SkyKingdoms\Core\Session;
use SkyKingdoms\Core\Validator;
use SkyKingdoms\Core\View;

/**
 * Registrierung, Anmeldung, Abmeldung und Passwort-Zurücksetzen.
 * Einen Gastmodus gibt es bewusst nicht.
 */
final class AuthController
{
    public function login(): void
    {
        if (Auth::check()) {
            Response::redirect('?p=game');
        }

        $errors = [];
        $values = ['login' => ''];

        if (Security::isPost()) {
            Csrf::verifyOrFail();

            $values['login'] = Security::clean($_POST['login'] ?? '', 191);
            $password        = (string) ($_POST['password'] ?? '');
            $remember        = ($_POST['remember'] ?? '') === '1';

            if ($values['login'] === '' || $password === '') {
                $errors['login'] = 'Bitte Spielername und Passwort eingeben.';
            } else {
                $result = Auth::login($values['login'], $password, $remember);
                if ($result['ok']) {
                    Session::flash('ok', 'Willkommen zurück!');
                    Response::redirect('?p=game');
                }
                $errors['login'] = (string) $result['error'];
            }
        }

        View::page('auth/login', [
            'title'  => 'Anmelden',
            'errors' => $errors,
            'values' => $values,
        ], 'partials/plain');
    }

    public function register(): void
    {
        if (Auth::check()) {
            Response::redirect('?p=game');
        }

        if (!App::config('registration_open', true)) {
            View::page('auth/closed', ['title' => 'Registrierung geschlossen'], 'partials/plain');
        }

        $errors = [];
        $values = ['username' => '', 'email' => '', 'kingdom' => ''];

        if (Security::isPost()) {
            Csrf::verifyOrFail();

            $limit = RateLimit::attempt('register', RateLimit::identity());
            if (!$limit['allowed']) {
                $errors['username'] = 'Zu viele Versuche. Bitte später erneut probieren.';
            } else {
                $v = Validator::make($_POST)
                    ->username('username')
                    ->email('email')
                    ->text('kingdom', 'Der Name des Königreichs', 0, 30, false)
                    ->password('password')
                    ->matches('password_confirm', 'password', 'Die beiden Passwörter stimmen nicht überein.')
                    ->accepted('rules', 'Bitte bestätige die Spielregeln.');

                $values['username'] = (string) $v->value('username', '');
                $values['email']    = (string) $v->value('email', '');
                $values['kingdom']  = (string) $v->value('kingdom', '');

                if ($v->fails()) {
                    $errors = $v->errors();
                } else {
                    $result = Auth::register(
                        $values['username'],
                        $values['email'],
                        (string) $_POST['password'],
                        [\SkyKingdoms\Game\Player::ROLE_PLAYER],
                        $values['kingdom']
                    );

                    if ($result['ok']) {
                        Auth::login($values['username'], (string) $_POST['password'], false);
                        Session::flash('ok', 'Dein Königreich wartet auf dich!');
                        Response::redirect('?p=game');
                    }

                    $errors[(string) ($result['field'] ?? 'username')] = (string) $result['error'];
                }
            }
        }

        View::page('auth/register', [
            'title'  => 'Registrieren',
            'errors' => $errors,
            'values' => $values,
        ], 'partials/plain');
    }

    public function logout(): void
    {
        if (Security::isPost()) {
            Csrf::verifyOrFail();
        }
        Auth::logout();
        Session::start();
        Session::flash('info', 'Du wurdest abgemeldet.');
        Response::redirect('?p=login');
    }

    public function forgot(): void
    {
        $sent   = false;
        $errors = [];
        $note   = '';

        if (Security::isPost()) {
            Csrf::verifyOrFail();

            $limit = RateLimit::attempt('reset', RateLimit::identity());
            if (!$limit['allowed']) {
                $errors['email'] = 'Zu viele Anfragen. Bitte später erneut versuchen.';
            } else {
                $v = Validator::make($_POST)->email('email');
                if ($v->fails()) {
                    $errors = $v->errors();
                } else {
                    $result = Auth::requestPasswordReset((string) $v->value('email', ''));
                    $sent   = true;
                    $note   = ($result['mailed'] ?? false)
                        ? 'Wir haben dir eine E-Mail geschickt. Schau auch im Spam-Ordner nach.'
                        : 'Falls ein Konto existiert, wurde die Anfrage vermerkt. Ist kein E-Mail-Versand eingerichtet, setzt der Administrator dein Passwort zurück.';
                }
            }
        }

        View::page('auth/forgot', [
            'title'  => 'Passwort vergessen',
            'errors' => $errors,
            'sent'   => $sent,
            'note'   => $note,
            'mail'   => Mailer::enabled(),
        ], 'partials/plain');
    }

    public function reset(): void
    {
        $token = Security::clean($_GET['token'] ?? ($_POST['token'] ?? ''), 64);
        $uid   = $token === '' ? null : Auth::checkResetToken($token);
        $errors = [];

        if ($uid === null) {
            View::page('auth/reset', [
                'title'   => 'Passwort zurücksetzen',
                'invalid' => true,
                'token'   => '',
                'errors'  => [],
            ], 'partials/plain');
        }

        if (Security::isPost()) {
            Csrf::verifyOrFail();

            $v = Validator::make($_POST)
                ->password('password', 'nichts', 'nichts')
                ->matches('password_confirm', 'password', 'Die beiden Passwörter stimmen nicht überein.');

            if ($v->fails()) {
                $errors = $v->errors();
            } else {
                $result = Auth::completePasswordReset($token, (string) $_POST['password']);
                if ($result['ok']) {
                    Audit::log('account.reset_used', 'Passwort über Link zurückgesetzt', [], $uid);
                    Session::flash('ok', 'Dein Passwort wurde geändert. Du kannst dich jetzt anmelden.');
                    Response::redirect('?p=login');
                }
                $errors['password'] = (string) $result['error'];
            }
        }

        View::page('auth/reset', [
            'title'   => 'Passwort zurücksetzen',
            'invalid' => false,
            'token'   => $token,
            'errors'  => $errors,
        ], 'partials/plain');
    }
}
