<?php
declare(strict_types=1);

namespace App\Repository;

use App\Core\Crypto;
use App\Core\Logger;

/**
 * Dateibasierte Benutzerverwaltung (ein JSON je Konto + Index fuer Namenssuche).
 */
final class UserRepository
{
    private const INDEX = 'users/_index.json';

    public function __construct(private JsonStore $store)
    {
    }

    /** @return array<string,mixed>|null */
    public function findById(string $id): ?array
    {
        if (!preg_match('~^[a-f0-9]{16,64}$~', $id)) {
            return null;
        }
        $data = $this->store->read('users/' . $id . '.json', []);
        return $data === [] ? null : $data;
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $index = $this->store->read(self::INDEX, ['byName' => [], 'byEmail' => []]);
        $id = $index['byName'][mb_strtolower(trim($username))] ?? null;
        return is_string($id) ? $this->findById($id) : null;
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }
        $index = $this->store->read(self::INDEX, ['byName' => [], 'byEmail' => []]);
        $id = $index['byEmail'][$email] ?? null;
        return is_string($id) ? $this->findById($id) : null;
    }

    public function usernameTaken(string $username): bool
    {
        return $this->findByUsername($username) !== null;
    }

    public function emailTaken(string $email): bool
    {
        return trim($email) !== '' && $this->findByEmail($email) !== null;
    }

    /**
     * @return array<string,mixed> Der angelegte Benutzer
     */
    public function create(string $username, string $password, string $email = '', string $role = 'player', array $extra = []): array
    {
        $id = Crypto::randomId(16);
        $now = gmdate('c');
        $user = array_merge([
            '_schema'      => WIT_SCHEMA_VERSION,
            'id'           => $id,
            'username'     => trim($username),
            'email'        => mb_strtolower(trim($email)),
            'password_hash'=> password_hash($password, PASSWORD_DEFAULT),
            'role'         => in_array($role, ['admin', 'player'], true) ? $role : 'player',
            'agent_name'   => $extra['agent_name'] ?? ('Agent ' . mb_substr(trim($username), 0, 18)),
            'created_at'   => $now,
            'updated_at'   => $now,
            'last_login'   => null,
            'must_change_password' => (bool)($extra['must_change_password'] ?? false),
            'failed_logins'=> 0,
            'locked_until' => null,
            'age_confirmed'=> (bool)($extra['age_confirmed'] ?? false),
            'settings'     => [
                'volume'          => 0.7,
                'subtitles'       => true,
                'effects'         => true,
                'reduced_motion'  => false,
                'jumpscares'      => true,
                'font_scale'      => 1.0,
            ],
            'stats' => [
                'cases_completed' => 0,
                'best_rank'       => null,
                'total_playtime'  => 0,
            ],
        ], $extra);
        $user['id'] = $id;

        $this->store->write('users/' . $id . '.json', $user);
        $this->indexUser($user);
        Logger::info('Konto angelegt', ['user' => $user['username'], 'role' => $user['role']]);
        return $user;
    }

    public function save(array $user): array
    {
        if (!isset($user['id']) || !is_string($user['id'])) {
            throw new \InvalidArgumentException('Benutzer ohne ID.');
        }
        $user['updated_at'] = gmdate('c');
        $this->store->write('users/' . $user['id'] . '.json', $user);
        $this->indexUser($user);
        return $user;
    }

    /**
     * @param callable(array):array $mutator
     */
    public function update(string $id, callable $mutator): ?array
    {
        $user = $this->findById($id);
        if ($user === null) {
            return null;
        }
        $updated = $this->store->update('users/' . $id . '.json', function (array $current) use ($mutator): array {
            $next = $mutator($current);
            $next['updated_at'] = gmdate('c');
            return $next;
        }, $user);
        $this->indexUser($updated);
        return $updated;
    }

    public function setPassword(string $id, string $password): bool
    {
        return $this->update($id, static function (array $user) use ($password): array {
            $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $user['must_change_password'] = false;
            $user['password_changed_at'] = gmdate('c');
            return $user;
        }) !== null;
    }

    public function verifyPassword(array $user, string $password): bool
    {
        $hash = (string)($user['password_hash'] ?? '');
        if ($hash === '') {
            return false;
        }
        if (!password_verify($password, $hash)) {
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->setPasswordHash((string)$user['id'], password_hash($password, PASSWORD_DEFAULT));
        }
        return true;
    }

    private function setPasswordHash(string $id, string $hash): void
    {
        $this->update($id, static function (array $user) use ($hash): array {
            $user['password_hash'] = $hash;
            return $user;
        });
    }

    public function delete(string $id): bool
    {
        $user = $this->findById($id);
        if ($user === null) {
            return false;
        }
        $this->store->update(self::INDEX, static function (array $index) use ($user): array {
            $name = mb_strtolower((string)$user['username']);
            $mail = mb_strtolower((string)($user['email'] ?? ''));
            unset($index['byName'][$name]);
            if ($mail !== '') {
                unset($index['byEmail'][$mail]);
            }
            return $index;
        }, ['byName' => [], 'byEmail' => []]);

        // Spielstaende mit entfernen
        $progressDir = $this->store->path('progress/' . $id);
        if (is_dir($progressDir)) {
            foreach (glob($progressDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($progressDir);
        }
        Logger::info('Konto geloescht', ['id' => $id]);
        return $this->store->delete('users/' . $id . '.json');
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $users = [];
        foreach ($this->store->listIds('users') as $id) {
            if (str_starts_with($id, '_')) {
                continue;
            }
            $user = $this->findById($id);
            if ($user !== null) {
                $users[] = $user;
            }
        }
        usort($users, static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        return $users;
    }

    public function count(): int
    {
        return count(array_filter($this->store->listIds('users'), static fn(string $id): bool => !str_starts_with($id, '_')));
    }

    public function adminExists(): bool
    {
        foreach ($this->all() as $user) {
            if (($user['role'] ?? '') === 'admin') {
                return true;
            }
        }
        return false;
    }

    /* ---------------- Login-Sperre ---------------- */

    public function registerFailedLogin(string $id, int $maxAttempts, int $lockMinutes): void
    {
        $this->update($id, static function (array $user) use ($maxAttempts, $lockMinutes): array {
            $user['failed_logins'] = (int)($user['failed_logins'] ?? 0) + 1;
            if ($user['failed_logins'] >= $maxAttempts) {
                $user['locked_until'] = gmdate('c', time() + $lockMinutes * 60);
                $user['failed_logins'] = 0;
            }
            return $user;
        });
    }

    public function clearFailedLogins(string $id): void
    {
        $this->update($id, static function (array $user): array {
            $user['failed_logins'] = 0;
            $user['locked_until'] = null;
            $user['last_login'] = gmdate('c');
            return $user;
        });
    }

    public function lockRemaining(array $user): int
    {
        $until = $user['locked_until'] ?? null;
        if (!is_string($until)) {
            return 0;
        }
        $ts = strtotime($until);
        return $ts !== false && $ts > time() ? $ts - time() : 0;
    }

    private function indexUser(array $user): void
    {
        $this->store->update(self::INDEX, static function (array $index) use ($user): array {
            $index['byName'] ??= [];
            $index['byEmail'] ??= [];
            $index['byName'][mb_strtolower((string)$user['username'])] = $user['id'];
            $mail = mb_strtolower((string)($user['email'] ?? ''));
            if ($mail !== '') {
                $index['byEmail'][$mail] = $user['id'];
            }
            return $index;
        }, ['byName' => [], 'byEmail' => []]);
    }
}
