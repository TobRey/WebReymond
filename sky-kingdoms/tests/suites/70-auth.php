<?php

declare(strict_types=1);

use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Auth;
use SkyKingdoms\Core\Password;
use SkyKingdoms\Core\RateLimit;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\Validator;
use SkyKingdoms\Game\Player;

return function (): void {
    Test::suite('Konten und Sicherheit');

    TestEnv::freshStore();
    App::setConfig('registration_open', true);

    // --- Passwortregeln ----------------------------------------------
    Test::ok(Password::problems('kurz') !== [], 'Zu kurzes Passwort wird abgelehnt');
    Test::ok(Password::problems('allesklein123') !== [], 'Nur Kleinbuchstaben und Ziffern reichen nicht');
    Test::ok(Password::problems('Passwort2026!') !== [], 'Bekanntes Passwort wird abgelehnt');
    Test::ok(Password::problems('WolkenFeste#77', 'WolkenFeste') !== [], 'Passwort mit Benutzernamen wird abgelehnt');
    Test::eq(Password::problems('HimmelBurg#2026'), [], 'Ein starkes Passwort wird angenommen');
    Test::eq(Password::problems('aaaaaaaaaaaaaa'), Password::problems('aaaaaaaaaaaaaa'), 'Prüfung ist reproduzierbar');

    $hash = Password::hash('HimmelBurg#2026');
    Test::ok(Password::verify('HimmelBurg#2026', $hash), 'Richtiges Passwort wird erkannt');
    Test::ok(!Password::verify('HimmelBurg#2025', $hash), 'Falsches Passwort wird abgelehnt');
    Test::ok(!str_contains($hash, 'HimmelBurg'), 'Das Passwort steht nicht im Hash');
    Test::ok(Password::strength('HimmelBurg#2026') >= 3, 'Starke Passwörter erhalten eine hohe Bewertung');

    // --- Registrierung --------------------------------------------------
    $created = Auth::register('Wolkenfürst', 'wolke@example.test', 'HimmelBurg#2026');
    Test::ok($created['ok'], 'Registrierung gelingt');
    $uid = (string) $created['uid'];
    Test::ok(Player::exists($uid), 'Das Konto existiert im Speicher');
    Test::ok(Player::world($uid) !== null, 'Zum Konto gehört ein Königreich');

    $account = Player::account($uid);
    Test::ok(!isset($account['password_plain']), 'Das Klartextpasswort wird nirgends gespeichert');
    Test::ok(str_starts_with((string) $account['password'], '$'), 'Das Passwort liegt als Hash vor');

    $duplicate = Auth::register('Wolkenfürst', 'andere@example.test', 'HimmelBurg#2026');
    Test::ok(!$duplicate['ok'], 'Doppelter Spielername wird abgelehnt');
    Test::eq($duplicate['field'], 'username', 'Das betroffene Feld wird benannt');

    $duplicateMail = Auth::register('Andere', 'wolke@example.test', 'HimmelBurg#2026');
    Test::ok(!$duplicateMail['ok'], 'Doppelte E-Mail-Adresse wird abgelehnt');
    Test::eq($duplicateMail['field'], 'email', 'Das betroffene Feld wird benannt');

    $caseDuplicate = Auth::register('WOLKENFÜRST', 'gross@example.test', 'HimmelBurg#2026');
    Test::ok(!$caseDuplicate['ok'], 'Gross- und Kleinschreibung schützt nicht vor Doppelnamen');

    $weak = Auth::register('Schwach', 'schwach@example.test', 'passwort');
    Test::ok(!$weak['ok'], 'Schwaches Passwort verhindert die Registrierung');

    // --- Eingabeprüfung ---------------------------------------------------
    $v = Validator::make(['username' => 'ab', 'email' => 'keine-adresse'])
        ->username('username')->email('email');
    Test::ok($v->fails(), 'Ungültige Eingaben werden erkannt');
    Test::eq(count($v->errors()), 2, 'Beide Fehler werden gemeldet');

    $ok = Validator::make(['username' => 'Guter Name', 'email' => 'gut@example.test'])
        ->username('username')->email('email');
    Test::ok(!$ok->fails(), 'Gültige Eingaben werden angenommen');

    $xss = Validator::make(['username' => 'Böse<script>alert(1)</script>'])->username('username');
    Test::ok($xss->fails(), 'Spitze Klammern im Namen werden abgelehnt');

    // --- Rate-Limit -----------------------------------------------------------
    App::setConfig('rate_limits.login', [3, 900]);
    $allowed = 0;
    for ($i = 0; $i < 6; $i++) {
        if (RateLimit::attempt('login', 'pruefung')['allowed']) { $allowed++; }
    }
    Test::eq($allowed, 3, 'Nach drei Versuchen greift die Sperre');
    Test::ok(RateLimit::peek('login', 'pruefung')['retry_after'] > 0, 'Es wird eine Wartezeit genannt');
    RateLimit::clear('login', 'pruefung');
    Test::ok(RateLimit::peek('login', 'pruefung')['allowed'], 'Nach dem Zurücksetzen ist wieder frei');

    // --- Passwort zurücksetzen --------------------------------------------------
    $request = Auth::requestPasswordReset('wolke@example.test');
    Test::ok($request['ok'], 'Zurücksetzen kann angefordert werden');
    $token = (string) $request['token'];
    Test::eq(Auth::checkResetToken($token), $uid, 'Das Merkmal gehört zum richtigen Konto');
    Test::eq(Auth::checkResetToken('falsch'), null, 'Ein erfundenes Merkmal wird abgelehnt');

    $unknown = Auth::requestPasswordReset('gibtesnicht@example.test');
    Test::ok($unknown['ok'], 'Auch bei unbekannter Adresse gibt es keine Fehlermeldung (kein Nutzer-Orakel)');
    Test::ok(!isset($unknown['token']), 'Für unbekannte Adressen entsteht kein Merkmal');

    $reset = Auth::completePasswordReset($token, 'NeuesReich#2026');
    Test::ok($reset['ok'], 'Neues Passwort kann gesetzt werden');
    Test::eq(Auth::checkResetToken($token), null, 'Das Merkmal ist danach verbraucht');
    Test::ok(Password::verify('NeuesReich#2026', (string) Player::account($uid)['password']), 'Das neue Passwort gilt');
    Test::eq((array) Player::account($uid)['remember'], [], 'Alle Anmelde-Merkmale wurden verworfen');

    $weakReset = Auth::completePasswordReset($token, 'kurz');
    Test::ok(!$weakReset['ok'], 'Ein verbrauchtes Merkmal funktioniert nicht erneut');

    // --- Passwort ändern -----------------------------------------------------------
    Test::ok(!Auth::changePassword($uid, 'falsch', 'AndersHerum#9')['ok'], 'Falsches bisheriges Passwort wird abgelehnt');
    Test::ok(Auth::changePassword($uid, 'NeuesReich#2026', 'AndersHerum#9')['ok'], 'Passwortwechsel gelingt');

    // --- Rollen ----------------------------------------------------------------------
    Test::ok(!Player::isAdmin(Player::account($uid)), 'Ein neues Konto ist kein Administrator');
    $admin = Auth::register('Chefin', 'chefin@example.test', 'HimmelBurg#2026', [Player::ROLE_PLAYER, Player::ROLE_ADMIN]);
    Test::ok(Player::isAdmin(Player::account((string) $admin['uid'])), 'Ein Administratorkonto wird erkannt');

    // --- Sperren ---------------------------------------------------------------------
    Player::updateAccount($uid, static function (array $a): array {
        $a['status'] = 'banned';
        $a['ban_reason'] = 'Prüfung';

        return $a;
    });
    Test::eq(Player::banReason(Player::account($uid)), 'Prüfung', 'Eine Sperre wird mit Grund gemeldet');
    $blocked = Auth::login('Wolkenfürst', 'AndersHerum#9');
    Test::ok(!$blocked['ok'], 'Ein gesperrtes Konto kann sich nicht anmelden');

    // --- Konto löschen ------------------------------------------------------------------
    Player::delete($uid);
    Test::ok(!Player::exists($uid), 'Das Konto ist vollständig entfernt');
    Test::eq(Player::findByUsername('Wolkenfürst'), null, 'Der Name ist danach wieder frei');
    Test::eq(Player::findByEmail('wolke@example.test'), null, 'Die E-Mail-Adresse ist danach wieder frei');

    $again = Auth::register('Wolkenfürst', 'wolke@example.test', 'HimmelBurg#2026');
    Test::ok($again['ok'], 'Nach dem Löschen kann der Name neu vergeben werden');

    // --- URL-Helfer (Pfadunabhängigkeit) --------------------------------------------------
    Url::setBase('');
    Test::eq(Url::to(''), '/', 'Im Wurzelverzeichnis führt der Grundpfad auf /');
    Test::eq(Url::to('api/'), '/api/', 'API-Pfad im Wurzelverzeichnis');
    Test::eq(Url::asset('css/app.css'), '/assets/css/app.css?v=' . Url::assetVersion(), 'Asset-Pfad im Wurzelverzeichnis');

    Url::setBase('/spiele/himmelreich');
    Test::eq(Url::to(''), '/spiele/himmelreich/', 'Im Unterordner führt der Grundpfad in den Ordner');
    Test::eq(Url::to('api/?a=state'), '/spiele/himmelreich/api/?a=state', 'API-Pfad im Unterordner');
    Test::eq(Url::asset('js/app.js'), '/spiele/himmelreich/assets/js/app.js?v=' . Url::assetVersion(), 'Asset-Pfad im Unterordner');
    Test::eq(Url::to('/mit/schraegstrich'), '/spiele/himmelreich/mit/schraegstrich', 'Führende Schrägstriche werden bereinigt');

    Url::setBase(null);
};
