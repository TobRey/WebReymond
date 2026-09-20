<?php

declare(strict_types=1);

namespace SkyKingdoms\Http\Controllers;

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Audit;
use SkyKingdoms\Core\Auth;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Ids;
use SkyKingdoms\Core\Logger;
use SkyKingdoms\Core\Password;
use SkyKingdoms\Core\Response;
use SkyKingdoms\Core\Security;
use SkyKingdoms\Core\Session;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\View;
use SkyKingdoms\Game\Economy;
use SkyKingdoms\Game\Player;
use SkyKingdoms\Game\Quests;
use SkyKingdoms\Game\Ranking;
use SkyKingdoms\Game\World;

/**
 * Adminbereich.
 *
 * Jede verändernde Handlung wird im Audit-Log festgehalten – inklusive
 * Zeitpunkt, handelnder Person und betroffenem Konto.
 */
final class AdminController
{
    public function handle(): void
    {
        Session::start();
        Security::headers();

        if (!App::isInstalled()) {
            Response::redirect('install/');
        }

        $user = Auth::requireAdmin();
        $page = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($_GET['p'] ?? 'dashboard'))) ?: 'dashboard';

        if (Security::isPost()) {
            Csrf::verifyOrFail();
            $this->post($page, $user);
        }

        match ($page) {
            'players'      => $this->players(),
            'player'       => $this->player(),
            'balance'      => $this->balance(),
            'quests'       => $this->quests(),
            'announce'     => $this->announce(),
            'logs'         => $this->logs(),
            'settings'     => $this->settings(),
            'ranking'      => $this->ranking(),
            'diagnose'     => $this->diagnose(),
            'backup'       => $this->backup(),
            default        => $this->dashboard(),
        };
    }

    // =================================================================
    // Seiten
    // =================================================================

    private function dashboard(): void
    {
        $ids      = Player::allIds();
        $ranking  = Ranking::snapshot();
        $active   = 0;
        $banned   = 0;
        $newToday = 0;

        foreach ($ids as $uid) {
            $card = Player::publicCard($uid);
            if ($card === null) { continue; }
            if ((int) ($card['last_seen'] ?? 0) > time() - 86400) { $active++; }
            if (!empty($card['banned'])) { $banned++; }
            if ((int) ($card['created_at'] ?? 0) > time() - 86400) { $newToday++; }
        }

        $this->render('admin/dashboard', [
            'title'    => 'Übersicht',
            'players'  => count($ids),
            'active'   => $active,
            'banned'   => $banned,
            'newToday' => $newToday,
            'top'      => array_slice((array) ($ranking['entries'] ?? []), 0, 10),
            'audit'    => Audit::recent(12),
            'storage'  => $this->storageInfo(),
        ]);
    }

    private function players(): void
    {
        $query  = mb_strtolower(Security::clean($_GET['q'] ?? '', 60));
        $result = [];

        foreach (Player::allIds() as $uid) {
            $card = Player::publicCard($uid);
            if ($card === null) { continue; }
            if ($query !== '' && !str_contains(mb_strtolower((string) $card['name']), $query) && $uid !== $query) {
                continue;
            }
            $result[] = $card;
            if (count($result) >= 200) { break; }
        }

        usort($result, static fn (array $a, array $b): int => (int) $b['score'] <=> (int) $a['score']);

        $this->render('admin/players', [
            'title'   => 'Spieler',
            'players' => $result,
            'query'   => $query,
        ]);
    }

    private function player(): void
    {
        $uid = Security::clean($_GET['id'] ?? '', 32);
        if (!Ids::isValid($uid) || !Player::exists($uid)) {
            Response::notFound('Dieser Spieler existiert nicht.');
        }

        $account = Player::account($uid) ?? [];
        $world   = Player::world($uid) ?? [];

        $this->render('admin/player', [
            'title'    => 'Spieler: ' . (string) ($account['username'] ?? ''),
            'uid'      => $uid,
            'account'  => $account,
            'world'    => $world,
            'card'     => Player::publicCard($uid) ?? [],
            'storage'  => Economy::storageOverview($world),
            'effects'  => World::effects($world),
            'rank'     => Ranking::rankOf($uid),
        ]);
    }

    private function balance(): void
    {
        $this->render('admin/balance', [
            'title'    => 'Balance',
            'balance'  => App::balance(),
            'overrides'=> App::store()->read('meta/balance.json', []) ?? [],
        ]);
    }

    private function quests(): void
    {
        $this->render('admin/quests', [
            'title'  => 'Aufgaben und Erfolge',
            'quests' => Quests::definitions(),
            'achievements' => Quests::achievementDefinitions(),
        ]);
    }

    private function announce(): void
    {
        $this->render('admin/announce', [
            'title'   => 'Ankündigungen',
            'players' => count(Player::allIds()),
        ]);
    }

    private function logs(): void
    {
        $day    = preg_replace('/[^0-9\-]/', '', (string) ($_GET['tag'] ?? date('Y-m-d')));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        $this->render('admin/logs', [
            'title'   => 'Protokolle',
            'entries' => Audit::recent(60, $offset, $day),
            'days'    => Audit::days(),
            'day'     => $day,
            'offset'  => $offset,
        ]);
    }

    private function settings(): void
    {
        $this->render('admin/settings', [
            'title'    => 'Einstellungen',
            'settings' => App::store()->read('meta/settings.json', []) ?? [],
        ]);
    }

    private function ranking(): void
    {
        $this->render('admin/ranking', [
            'title'   => 'Rangliste',
            'ranking' => Ranking::snapshot(),
        ]);
    }

    private function diagnose(): void
    {
        $this->render('admin/diagnose', [
            'title'   => 'Systemdiagnose',
            'report'  => InstallController::diagnose(),
            'storage' => $this->storageInfo(),
            'check'   => InstallController::selfCheckDataProtection(),
        ]);
    }

    /** Sicherung des Datenordners als ZIP herunterladen. */
    private function backup(): void
    {
        if (!class_exists('ZipArchive')) {
            Session::flash('error', 'Für Sicherungen wird die PHP-Erweiterung zip benötigt. '
                . 'Alternativ kannst du den Ordner storage/data im cPanel-Dateimanager herunterladen.');
            Response::redirect('admin/?p=diagnose');
        }

        $dir  = App::dataDir();
        $file = SK_ROOT . '/storage/backups/sicherung-' . date('Y-m-d-His') . '.zip';
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0770, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            Session::flash('error', 'Die Sicherungsdatei konnte nicht angelegt werden.');
            Response::redirect('admin/?p=diagnose');
        }

        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) { continue; }
            $relative = substr($item->getPathname(), strlen($dir) + 1);
            if (str_contains($relative, '.lock')) { continue; }
            $zip->addFile($item->getPathname(), 'daten/' . $relative);
            $count++;
        }
        $zip->addFromString('HINWEIS.txt',
            "Sicherung von " . App::config('name', 'Sky Kingdoms') . "\n"
            . "Erstellt am " . date('d.m.Y H:i') . "\n"
            . "Dateien: " . $count . "\n\n"
            . "Zum Wiederherstellen den Inhalt von daten/ nach storage/data/ zurückkopieren.\n");
        $zip->close();

        Audit::log('admin.backup', 'Sicherung erstellt', ['dateien' => $count]);

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . (string) filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        @unlink($file);
        exit;
    }

    // =================================================================
    // Formulare
    // =================================================================

    private function post(string $page, array $user): void
    {
        $action = Security::clean($_POST['action'] ?? '', 40);

        match ($action) {
            'ban'          => $this->actionBan(true),
            'unban'        => $this->actionBan(false),
            'role'         => $this->actionRole(),
            'resources'    => $this->actionResources(),
            'password'     => $this->actionPassword(),
            'delete'       => $this->actionDeletePlayer($user),
            'maintenance'  => $this->actionMaintenance(),
            'settings'     => $this->actionSettings(),
            'balance'      => $this->actionBalance(),
            'quests_reset' => $this->actionQuestsReset(),
            'announce'     => $this->actionAnnounce(),
            'ranking'      => $this->actionRanking(),
            default        => null,
        };
    }

    private function actionBan(bool $ban): void
    {
        $uid    = Security::clean($_POST['uid'] ?? '', 32);
        $reason = Security::clean($_POST['reason'] ?? '', 200);
        if (!Ids::isValid($uid) || !Player::exists($uid)) { return; }

        Player::updateAccount($uid, static function (array $account) use ($ban, $reason): array {
            $account['status']       = $ban ? 'banned' : 'active';
            $account['ban_reason']   = $ban ? ($reason !== '' ? $reason : 'Verstoss gegen die Spielregeln') : '';
            $account['banned_until'] = 0;
            if ($ban) { $account['remember'] = []; }

            return $account;
        });

        Player::refreshPublic($uid);
        Ranking::invalidate();
        Audit::log($ban ? 'admin.ban' : 'admin.unban',
            ($ban ? 'Konto gesperrt' : 'Konto entsperrt') . ': ' . $uid, ['grund' => $reason]);
        Session::flash('ok', $ban ? 'Konto gesperrt.' : 'Konto entsperrt.');
    }

    private function actionRole(): void
    {
        $uid  = Security::clean($_POST['uid'] ?? '', 32);
        $role = Security::clean($_POST['role'] ?? '', 20);
        if (!Ids::isValid($uid) || !in_array($role, ['player', 'moderator', 'admin'], true)) { return; }

        Player::updateAccount($uid, static function (array $account) use ($role): array {
            $roles = [Player::ROLE_PLAYER];
            if ($role === 'moderator') { $roles[] = Player::ROLE_MOD; }
            if ($role === 'admin') { $roles[] = Player::ROLE_ADMIN; }
            $account['roles'] = $roles;

            return $account;
        });

        Audit::log('admin.role', 'Rolle geändert: ' . $uid, ['rolle' => $role]);
        Session::flash('ok', 'Rolle gespeichert.');
    }

    /** Ressourcen korrigieren – immer mit Begründung und Protokoll. */
    private function actionResources(): void
    {
        $uid    = Security::clean($_POST['uid'] ?? '', 32);
        $reason = Security::clean($_POST['reason'] ?? '', 200);
        if (!Ids::isValid($uid) || !Player::exists($uid)) { return; }
        if ($reason === '') {
            Session::flash('error', 'Bitte gib eine Begründung an – sie wird protokolliert.');

            return;
        }

        $changes = [];
        foreach ((array) App::balance('resources', []) as $key => $definition) {
            $field = 'res_' . $key;
            if (!isset($_POST[$field]) || $_POST[$field] === '') { continue; }
            $value = (int) $_POST[$field];
            if ($value < 0) { continue; }
            $changes[$key] = $value;
        }

        if ($changes === []) { return; }

        Player::withWorld($uid, static function (array $world) use ($changes): array {
            foreach ($changes as $resource => $amount) {
                if ($amount === 0) {
                    unset($world['store'][$resource]);
                } else {
                    $world['store'][$resource] = $amount;
                }
            }

            return ['ok' => true, 'world' => $world];
        });

        Audit::log('admin.resources', 'Rohstoffe korrigiert: ' . $uid, [
            'werte'  => $changes,
            'grund'  => $reason,
        ]);
        Session::flash('ok', 'Rohstoffe gesetzt und protokolliert.');
    }

    /** Passwort zurücksetzen, wenn kein E-Mail-Versand eingerichtet ist. */
    private function actionPassword(): void
    {
        $uid = Security::clean($_POST['uid'] ?? '', 32);
        if (!Ids::isValid($uid) || !Player::exists($uid)) { return; }

        $new = bin2hex(random_bytes(6)) . 'Aa#1';
        Player::updateAccount($uid, static function (array $account) use ($new): array {
            $account['password'] = Password::hash($new);
            $account['remember'] = [];

            return $account;
        });

        Audit::log('admin.password', 'Passwort zurückgesetzt: ' . $uid);
        Session::flash('ok', 'Neues Passwort: ' . $new . ' – bitte dem Spieler sicher mitteilen. '
            . 'Es wird nur dieses eine Mal angezeigt.');
    }

    private function actionDeletePlayer(array $user): void
    {
        $uid = Security::clean($_POST['uid'] ?? '', 32);
        if (!Ids::isValid($uid) || !Player::exists($uid)) { return; }
        if ($uid === (string) $user['id']) {
            Session::flash('error', 'Das eigene Konto kann hier nicht gelöscht werden.');

            return;
        }

        $account = Player::account($uid) ?? [];
        Audit::log('admin.delete', 'Konto gelöscht: ' . (string) ($account['username'] ?? $uid), ['uid' => $uid]);
        Player::delete($uid);
        Session::flash('ok', 'Konto vollständig gelöscht.');
        Response::redirect('admin/?p=players');
    }

    private function actionMaintenance(): void
    {
        $on   = ($_POST['maintenance'] ?? '') === '1';
        $note = Security::clean($_POST['note'] ?? '', 200);

        App::saveSettings([
            'maintenance'      => $on,
            'maintenance_note' => $note !== '' ? $note : 'Das Königreich wird gerade erweitert.',
        ]);

        Audit::log('admin.maintenance', $on ? 'Wartungsmodus an' : 'Wartungsmodus aus');
        Session::flash('ok', $on ? 'Wartungsmodus ist aktiv.' : 'Wartungsmodus ausgeschaltet.');
    }

    private function actionSettings(): void
    {
        $settings = [
            'name'                => Security::clean($_POST['name'] ?? '', 40),
            'tagline'             => Security::clean($_POST['tagline'] ?? '', 80),
            'registration_open'   => ($_POST['registration_open'] ?? '') === '1',
            'max_offline_seconds' => max(3600, min(604800, (int) ($_POST['offline'] ?? 24) * 3600)),
            'newbie_protection'   => max(0, min(1209600, (int) ($_POST['newbie'] ?? 72) * 3600)),
            'attacks_per_defender'=> max(1, min(20, (int) ($_POST['attacks'] ?? 3))),
            'logo'                => Security::clean($_POST['logo'] ?? '', 200),
            'theme' => [
                'primary'    => $this->color($_POST['primary'] ?? '', '#3f8cff'),
                'secondary'  => $this->color($_POST['secondary'] ?? '', '#ffb43f'),
                'accent'     => $this->color($_POST['accent'] ?? '', '#7ee3a6'),
                'danger'     => $this->color($_POST['danger'] ?? '', '#ff5c6c'),
                'sky_top'    => $this->color($_POST['sky_top'] ?? '', '#5ec6ff'),
                'sky_bottom' => $this->color($_POST['sky_bottom'] ?? '', '#b9e9ff'),
            ],
            'mail' => [
                'enabled'    => ($_POST['mail_enabled'] ?? '') === '1',
                'transport'  => ($_POST['mail_transport'] ?? 'mail') === 'smtp' ? 'smtp' : 'mail',
                'from'       => Security::clean($_POST['mail_from'] ?? '', 191),
                'from_name'  => Security::clean($_POST['mail_from_name'] ?? '', 60),
                'smtp_host'  => Security::clean($_POST['smtp_host'] ?? '', 191),
                'smtp_port'  => max(1, min(65535, (int) ($_POST['smtp_port'] ?? 587))),
                'smtp_user'  => Security::clean($_POST['smtp_user'] ?? '', 191),
                'smtp_secure'=> in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', ''], true)
                    ? (string) $_POST['smtp_secure'] : 'tls',
            ],
        ];

        // Passwort nur überschreiben, wenn eines eingegeben wurde.
        $password = (string) ($_POST['smtp_pass'] ?? '');
        if ($password !== '') {
            $settings['mail']['smtp_pass'] = $password;
        }

        App::saveSettings($settings);
        Audit::log('admin.settings', 'Einstellungen geändert', ['spielname' => $settings['name']]);
        Session::flash('ok', 'Einstellungen gespeichert.');
    }

    private function color(mixed $value, string $fallback): string
    {
        $value = (string) $value;

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
    }

    /** Einzelne Balancewerte überschreiben (Punktschreibweise). */
    private function actionBalance(): void
    {
        $path  = Security::clean($_POST['path'] ?? '', 120);
        $value = Security::clean($_POST['value'] ?? '', 60);

        if ($path === '' || !preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/i', $path)) {
            Session::flash('error', 'Ungültiger Pfad. Beispiel: buildings.lumberjack.produces.wood');

            return;
        }
        if (!is_numeric($value)) {
            Session::flash('error', 'Es sind nur Zahlen erlaubt.');

            return;
        }

        $number = str_contains($value, '.') ? (float) $value : (int) $value;
        $patch  = [];
        $ref    = &$patch;
        foreach (explode('.', $path) as $part) {
            $ref[$part] = [];
            $ref = &$ref[$part];
        }
        $ref = $number;

        $old = App::balance($path);
        App::saveBalance($patch);
        Audit::log('admin.balance', 'Balancewert geändert: ' . $path, ['alt' => $old, 'neu' => $number]);
        Session::flash('ok', 'Wert gespeichert: ' . $path . ' = ' . $value);
    }

    private function actionQuestsReset(): void
    {
        Quests::install(true);
        Audit::log('admin.quests', 'Aufgaben auf Vorgabe zurückgesetzt');
        Session::flash('ok', 'Aufgaben und Erfolge auf die Vorgaben zurückgesetzt.');
    }

    private function actionAnnounce(): void
    {
        $title = Security::clean($_POST['title'] ?? '', 80);
        $text  = Security::clean($_POST['text'] ?? '', 500);
        if ($title === '') {
            Session::flash('error', 'Bitte einen Titel angeben.');

            return;
        }

        $count = 0;
        foreach (Player::allIds() as $uid) {
            Player::notify($uid, 'announcement', $title, $text);
            $count++;
        }

        Audit::log('admin.announce', 'Ankündigung verschickt: ' . $title, ['empfaenger' => $count]);
        Session::flash('ok', 'Ankündigung an ' . $count . ' Spieler verschickt.');
    }

    private function actionRanking(): void
    {
        Ranking::snapshot(true);
        Audit::log('admin.ranking', 'Rangliste neu berechnet');
        Session::flash('ok', 'Rangliste wurde neu aufgebaut.');
    }

    // =================================================================
    // Hilfen
    // =================================================================

    private function storageInfo(): array
    {
        $dir   = App::dataDir();
        $bytes = 0;
        $files = 0;

        if (is_dir($dir)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($items as $item) {
                /** @var \SplFileInfo $item */
                if ($item->isFile()) {
                    $bytes += $item->getSize();
                    $files++;
                }
            }
        }

        return [
            'path'  => $dir,
            'files' => $files,
            'bytes' => $bytes,
            'free'  => @disk_free_space($dir) ?: 0,
        ];
    }

    private function render(string $template, array $data): void
    {
        $data['wide'] = true;
        View::page($template, $data, 'partials/admin');
    }
}
