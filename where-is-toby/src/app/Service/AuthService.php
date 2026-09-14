<?php
declare(strict_types=1);

namespace App\Service;

use App\Core\Crypto;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Session;
use App\Core\Validator;
use App\Repository\SettingsRepository;
use App\Repository\UserRepository;

/**
 * Anmeldung, Registrierung, Gastmodus und Rollenpruefung.
 */
final class AuthService
{
    public function __construct(
        private UserRepository $users,
        private SettingsRepository $settings,
        private RateLimiter $limiter
    ) {
    }

    /** @return array<string,mixed>|null */
    public function currentUser(): ?array
    {
        $id = Session::get('user_id');
        if (!is_string($id) || $id === '') {
            return null;
        }
        if (Session::get('is_guest') === true) {
            return $this->guestRecord($id);
        }
        $user = $this->users->findById($id);
        if ($user === null) {
            Session::destroy();
            return null;
        }
        return $user;
    }

    public function requireUser(): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            throw HttpException::unauthorized('Bitte zuerst anmelden.');
        }
        return $user;
    }

    public function requireAdmin(): array
    {
        $user = $this->requireUser();
        if (($user['role'] ?? '') !== 'admin') {
            Logger::security('Unbefugter Admin-Zugriff', ['user' => $user['username'] ?? '?']);
            throw HttpException::forbidden('Dieser Bereich ist Administratoren vorbehalten.');
        }
        return $user;
    }

    public function isAdmin(): bool
    {
        $user = $this->currentUser();
        return $user !== null && ($user['role'] ?? '') === 'admin';
    }

    public function isGuest(): bool
    {
        return Session::get('is_guest') === true;
    }

    /**
     * @return array{ok:bool,error?:string,user?:array}
     */
    public function login(string $username, string $password, string $ip): array
    {
        $maxAttempts = (int)$this->settings->get('security.max_login_attempts', 6);
        $lockMinutes = (int)$this->settings->get('security.lockout_minutes', 15);

        $ipCheck = $this->limiter->hit('login:ip:' . $ip, max(10, $maxAttempts * 3), 900);
        if (!$ipCheck['allowed']) {
            Logger::security('Login-Rate-Limit (IP)', ['ip' => $ip]);
            return ['ok' => false, 'error' => 'Zu viele Anmeldeversuche. Bitte in ' . ceil($ipCheck['retryAfter'] / 60) . ' Minuten erneut versuchen.'];
        }

        $user = $this->users->findByUsername($username);
        if ($user === null && str_contains($username, '@')) {
            $user = $this->users->findByEmail($username);
        }

        if ($user === null) {
            // Zeitangleich gegen Benutzer-Enumeration
            password_verify($password, '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30M1MlVkd6HS');
            Logger::security('Anmeldung fehlgeschlagen (unbekanntes Konto)', ['username' => mb_substr($username, 0, 40)]);
            return ['ok' => false, 'error' => 'Benutzername oder Passwort ist falsch.'];
        }

        $remaining = $this->users->lockRemaining($user);
        if ($remaining > 0) {
            return ['ok' => false, 'error' => 'Konto voruebergehend gesperrt. Bitte ' . ceil($remaining / 60) . ' Minuten warten.'];
        }

        if (!$this->users->verifyPassword($user, $password)) {
            $this->users->registerFailedLogin((string)$user['id'], $maxAttempts, $lockMinutes);
            Logger::security('Anmeldung fehlgeschlagen (falsches Passwort)', ['user' => $user['username']]);
            return ['ok' => false, 'error' => 'Benutzername oder Passwort ist falsch.'];
        }

        $this->users->clearFailedLogins((string)$user['id']);
        $this->limiter->reset('login:ip:' . $ip);
        $this->startSession($user);
        Logger::info('Anmeldung erfolgreich', ['user' => $user['username'], 'role' => $user['role']]);
        return ['ok' => true, 'user' => $user];
    }

    /**
     * @return array{ok:bool,error?:string,user?:array}
     */
    public function register(string $username, string $password, string $passwordRepeat, string $email, bool $ageConfirmed, string $ip): array
    {
        if (!(bool)$this->settings->get('site.allow_register', true)) {
            return ['ok' => false, 'error' => 'Die Registrierung ist derzeit deaktiviert.'];
        }
        $perHour = (int)$this->settings->get('security.registration_per_hour', 8);
        $check = $this->limiter->hit('register:' . $ip, $perHour, 3600);
        if (!$check['allowed']) {
            return ['ok' => false, 'error' => 'Zu viele Registrierungen von dieser Verbindung. Bitte spaeter erneut versuchen.'];
        }

        $validator = Validator::make([
            'username' => $username,
            'password' => $password,
            'password_repeat' => $passwordRepeat,
            'email' => $email,
            'age' => $ageConfirmed,
        ])
            ->username()
            ->password()
            ->matches('password_repeat', 'password', 'Die Passwortwiederholung')
            ->email('email', true)
            ->accepted('age', 'Die Altersbestaetigung (18+)');

        if ($validator->fails()) {
            return ['ok' => false, 'error' => $validator->firstError()];
        }
        if ($this->users->usernameTaken($username)) {
            return ['ok' => false, 'error' => 'Dieser Benutzername ist bereits vergeben.'];
        }
        if ($email !== '' && $this->users->emailTaken($email)) {
            return ['ok' => false, 'error' => 'Zu dieser E-Mail-Adresse existiert bereits ein Konto.'];
        }

        $user = $this->users->create($username, $password, $email, 'player', ['age_confirmed' => true]);
        $this->startSession($user);
        return ['ok' => true, 'user' => $user];
    }

    /** Gastmodus: Fortschritt wird unter einer zufaelligen Gast-ID gespeichert. */
    public function loginAsGuest(): array
    {
        if (!(bool)$this->settings->get('site.allow_guests', true)) {
            throw HttpException::forbidden('Der Gastmodus ist deaktiviert.');
        }
        $existing = Session::get('guest_id');
        $guestId = is_string($existing) && $existing !== '' ? $existing : 'guest-' . Crypto::randomId(8);
        Session::regenerate();
        Session::set('user_id', $guestId);
        Session::set('guest_id', $guestId);
        Session::set('is_guest', true);
        Session::set('role', 'player');
        Session::set('agent_name', 'Agent ' . strtoupper(substr($guestId, 6, 4)));
        return $this->guestRecord($guestId);
    }

    private function guestRecord(string $guestId): array
    {
        return [
            'id'         => $guestId,
            'username'   => 'Gast',
            'email'      => '',
            'role'       => 'player',
            'is_guest'   => true,
            'agent_name' => (string)(Session::get('agent_name') ?? 'Agent Gast'),
            'age_confirmed' => (bool)Session::get('age_confirmed', false),
            'must_change_password' => false,
            'settings'   => (array)(Session::get('guest_settings') ?? [
                'volume' => 0.7, 'subtitles' => true, 'effects' => true,
                'reduced_motion' => false, 'jumpscares' => true, 'font_scale' => 1.0,
            ]),
            'stats'      => ['cases_completed' => 0, 'best_rank' => null, 'total_playtime' => 0],
        ];
    }

    public function startSession(array $user): void
    {
        Session::regenerate();
        Session::set('user_id', (string)$user['id']);
        Session::set('is_guest', false);
        Session::set('role', (string)($user['role'] ?? 'player'));
        Session::set('agent_name', (string)($user['agent_name'] ?? 'Agent'));
        Session::set('age_confirmed', (bool)($user['age_confirmed'] ?? false));
        Session::set('login_time', time());
    }

    public function logout(): void
    {
        $user = $this->currentUser();
        if ($user !== null) {
            Logger::info('Abmeldung', ['user' => $user['username'] ?? '?']);
        }
        Session::destroy();
    }

    /** @return array{ok:bool,error?:string} */
    public function changePassword(array $user, string $current, string $new, string $repeat): array
    {
        if ($this->isGuest()) {
            return ['ok' => false, 'error' => 'Im Gastmodus gibt es kein Passwort.'];
        }
        if (!$this->users->verifyPassword($user, $current)) {
            Logger::security('Passwortwechsel fehlgeschlagen', ['user' => $user['username'] ?? '?']);
            return ['ok' => false, 'error' => 'Das aktuelle Passwort ist falsch.'];
        }
        $validator = Validator::make(['password' => $new, 'password_repeat' => $repeat])
            ->password()
            ->matches('password_repeat', 'password', 'Die Passwortwiederholung');
        if ($validator->fails()) {
            return ['ok' => false, 'error' => $validator->firstError()];
        }
        if ($current === $new) {
            return ['ok' => false, 'error' => 'Das neue Passwort muss sich vom alten unterscheiden.'];
        }
        $this->users->setPassword((string)$user['id'], $new);
        Logger::info('Passwort geaendert', ['user' => $user['username'] ?? '?']);
        return ['ok' => true];
    }

    public function deleteOwnAccount(array $user): bool
    {
        if ($this->isGuest()) {
            Session::destroy();
            return true;
        }
        $deleted = $this->users->delete((string)$user['id']);
        Session::destroy();
        return $deleted;
    }
}
