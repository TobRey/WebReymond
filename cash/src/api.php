<?php
/**
 * CASH – JSON-API. Alle Daten werden pro Benutzer serverseitig geprüft und gespeichert.
 */
declare(strict_types=1);

const CASH_ENTRY = true;
require __DIR__ . '/app/bootstrap.php';

Http::securityHeaders(false);

const FINANCE_ACTIONS = [
    'onboarding.complete', 'account.save', 'account.setBalance', 'account.archive', 'account.delete',
    'category.save', 'category.archive', 'category.delete', 'tx.save', 'tx.delete', 'rate.set',
    'budget.save', 'budget.archive', 'budget.delete', 'bill.save', 'bill.active', 'bill.delete',
    'occ.pay', 'occ.unpay', 'occ.skip', 'occ.unskip', 'goal.save', 'goal.delete', 'goal.allocate',
    'goal.allocDelete', 'debt.save', 'debt.delete', 'debt.pay', 'debt.payDelete',
];

function cash_month(mixed $m): string
{
    return is_string($m) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : substr(cash_today(), 0, 7);
}

/** Aktueller Datenstand inkl. Erzeugung fälliger Rechnungen (unter Sperre, ohne Duplikate). */
function cash_state(string $uid, string $month): array
{
    $today = cash_today();
    return Store::mutate(Finance::file($uid), function (array &$d) use ($month, $today) {
        Finance::normalize($d);
        Finance::generateBills($d, $today);
        return Finance::state($d, $month, $today);
    }, Finance::blank());
}

function cash_status(): array
{
    $user = Auth::current();
    return [
        'installed' => Auth::hasUsers(),
        'user' => $user ? Auth::publicUser($user) : null,
        'csrf' => Auth::csrf(),
        'registrationOpen' => Auth::registrationOpen(),
        'version' => CASH_VERSION,
    ];
}

/** Liest die Import-Vorschau des Benutzers (max. 2 h gültig). */
function cash_import_preview(string $uid, string $id): array
{
    $file = Import::previewFile($uid);
    $p = is_file($file) ? Store::read($file) : [];
    if (($p['id'] ?? '') === '' || !hash_equals((string)$p['id'], $id) || ($p['created'] ?? 0) < time() - Import::PREVIEW_TTL) {
        throw new ApiError('Die Import-Vorschau ist abgelaufen. Bitte die Datei erneut wählen.', 409);
    }
    return $p;
}

try {
    cash_data_dir();
    Auth::startSession();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = is_string($_GET['a'] ?? null) ? $_GET['a'] : '';

    // 1) Nur über HTTPS (lokale Tests ausgenommen).
    if (!Http::isHttps() && !Http::isLocalHost() && (cash_local_config()['force_https'] ?? true)) {
        Http::json(['ok' => false, 'error' => 'Nur über HTTPS erreichbar.'], 403);
    }
    // 2) Ohne gültige PIN-Session keine einzige Aktion – auch keine Statusabfrage.
    if (!Pin::verified()) {
        Http::json(['ok' => false, 'error' => 'PIN erforderlich.', 'pin' => true], 401);
    }

    if ($method === 'GET') {
        switch ($action) {
            case 'auth.status':
                Http::json(['ok' => true] + cash_status());

            case 'state':
                $user = Auth::requireUser();
                Http::json(['ok' => true, 'user' => Auth::publicUser($user), 'state' => cash_state($user['id'], cash_month($_GET['month'] ?? null))]);

            case 'sessions.list':
                $user = Auth::requireUser();
                Http::json(['ok' => true, 'sessions' => Auth::listTokens($user['id']), 'backups' => Backup::list($user['id']), 'pinDevices' => Pin::deviceCount()]);

            case 'backup.download':
                $user = Auth::requireUser();
                $payload = Backup::export($user['id'], $user);
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="cash-backup-' . date('Y-m-d-His') . '.json"');
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                exit;

            case 'export.csv':
                $user = Auth::requireUser();
                $d = Store::read(Finance::file($user['id']), Finance::blank());
                Finance::normalize($d);
                $month = isset($_GET['month']) && $_GET['month'] !== '' ? cash_month($_GET['month']) : null;
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="cash-buchungen-' . ($month ?? 'alle') . '.csv"');
                echo Backup::csv($d, $month);
                exit;
        }
        throw new ApiError('Unbekannte Aktion.', 404);
    }

    if ($method !== 'POST') {
        throw new ApiError('Methode nicht erlaubt.', 405);
    }
    Http::checkSameOrigin();
    Auth::checkCsrf();
    $in = Http::body();

    switch ($action) {
        case 'auth.setup':
            $user = Auth::createUser($in, true);
            Auth::login(['username' => $user['username'], 'password' => $in['password'] ?? '', 'remember' => !empty($in['remember'])]);
            Http::json(['ok' => true] + cash_status());

        case 'auth.register':
            if (!Auth::registrationOpen()) {
                throw new ApiError('Die Registrierung ist deaktiviert.', 403);
            }
            $user = Auth::createUser($in, false);
            Auth::login(['username' => $user['username'], 'password' => $in['password'] ?? '', 'remember' => !empty($in['remember'])]);
            Http::json(['ok' => true] + cash_status());

        case 'auth.login':
            Auth::login($in);
            Http::json(['ok' => true] + cash_status());

        case 'auth.logout':
            Auth::logout();
            Http::json(['ok' => true, 'pin' => true]);

        case 'pin.lock':
            Pin::lock();
            Http::json(['ok' => true, 'pin' => true]);
    }

    $user = Auth::requireUser();
    $uid = $user['id'];

    switch ($action) {
        case 'profile.save':
            $u = Auth::updateProfile($uid, $in);
            Http::json(['ok' => true, 'message' => 'Profil gespeichert.', 'user' => Auth::publicUser($u)]);

        case 'password.change':
            Auth::changePassword($uid, $in);
            Http::json(['ok' => true, 'message' => 'Passwort geändert. Andere Geräte wurden abgemeldet.', 'csrf' => Auth::csrf()]);

        case 'sessions.revoke':
            Auth::revokeToken($uid, is_string($in['id'] ?? null) ? $in['id'] : '');
            Http::json(['ok' => true, 'message' => 'Gerät abgemeldet.', 'sessions' => Auth::listTokens($uid)]);

        case 'sessions.revokeOthers':
            Auth::revokeOthers($uid);
            Http::json(['ok' => true, 'message' => 'Alle anderen Geräte wurden abgemeldet.', 'sessions' => Auth::listTokens($uid)]);

        case 'admin.registration':
            if ($user['role'] !== 'owner') {
                throw new ApiError('Nur der Hauptaccount darf dies ändern.', 403);
            }
            Auth::setRegistration(!empty($in['open']));
            Http::json(['ok' => true, 'message' => !empty($in['open']) ? 'Registrierung geöffnet.' : 'Registrierung deaktiviert.', 'registrationOpen' => Auth::registrationOpen()]);

        case 'pin.change':
            if ($user['role'] !== 'owner') {
                throw new ApiError('Nur der Hauptaccount darf die PIN ändern.', 403);
            }
            Pin::change($in['current'] ?? '', $in['pin'] ?? '', $in['pin2'] ?? '');
            Http::json(['ok' => true, 'message' => 'PIN geändert. Alle anderen Geräte müssen die neue PIN eingeben.', 'csrf' => Auth::csrf()]);

        case 'pin.forgetDevices':
            if ($user['role'] !== 'owner') {
                throw new ApiError('Nur der Hauptaccount darf dies ändern.', 403);
            }
            Pin::forgetDevices();
            Http::json(['ok' => true, 'message' => 'Andere Geräte vergessen.', 'pinDevices' => Pin::deviceCount()]);

        case 'import.preview':
            $content = is_string($in['content'] ?? null) ? base64_decode($in['content'], true) : false;
            if ($content === false || $content === '') {
                throw new ApiError('Die Datei konnte nicht gelesen werden.');
            }
            $accId = is_string($in['accountId'] ?? null) && $in['accountId'] !== '' ? $in['accountId'] : null;
            $d = Store::read(Finance::file($uid), Finance::blank());
            $p = Import::buildPreview($d, $content, $accId, is_string($in['filename'] ?? null) ? $in['filename'] : '');
            unset($content);
            if (!empty($p['needAccount'])) {
                Http::json(['ok' => true, 'needAccount' => true, 'iban' => $p['iban'], 'format' => $p['format']]);
            }
            Store::replace(Import::previewFile($uid), $p);
            Http::json(['ok' => true, 'preview' => Import::publicPreview($p, $d)]);

        case 'import.commit':
            $p = cash_import_preview($uid, is_string($in['previewId'] ?? null) ? $in['previewId'] : '');
            $decisions = is_array($in['rows'] ?? null) ? $in['rows'] : [];
            Backup::snapshot($uid, 'vor-import');
            $today = cash_today();
            $out = Store::mutate(Finance::file($uid), function (array &$d) use ($p, $decisions, $in, $today) {
                $res = Import::commit($d, $p, $decisions, !empty($in['syncBalance']), $today);
                Finance::generateBills($d, $today);
                return ['result' => $res, 'state' => Finance::state($d, cash_month($in['month'] ?? null), $today)];
            }, Finance::blank());
            @unlink(Import::previewFile($uid));
            Http::json(['ok' => true] + $out['result'] + ['state' => $out['state']]);

        case 'import.cancel':
            @unlink(Import::previewFile($uid));
            Http::json(['ok' => true]);

        case 'import.undo':
            $today = cash_today();
            $out = Store::mutate(Finance::file($uid), function (array &$d) use ($in, $today) {
                Finance::normalize($d);
                $res = Import::undo($d, is_string($in['batchId'] ?? null) ? $in['batchId'] : '');
                return ['result' => $res, 'state' => Finance::state($d, cash_month($in['month'] ?? null), $today)];
            }, Finance::blank());
            Http::json(['ok' => true] + $out['result'] + ['state' => $out['state']]);

        case 'backup.restore':
            Backup::restore($uid, $in['backup'] ?? null);
            Auth::setOnboarded($uid);
            Http::json(['ok' => true, 'message' => 'Sicherung wiederhergestellt. Der vorherige Stand wurde gesichert.', 'state' => cash_state($uid, cash_month($in['month'] ?? null))]);
    }

    if (!in_array($action, FINANCE_ACTIONS, true)) {
        throw new ApiError('Unbekannte Aktion.', 404);
    }

    $requestId = is_string($in['requestId'] ?? null) && preg_match('/^[A-Za-z0-9\-]{8,64}$/', $in['requestId']) ? $in['requestId'] : null;
    $today = cash_today();
    $month = cash_month($in['month'] ?? null);
    Backup::dailySnapshot($uid);

    $out = Store::mutate(Finance::file($uid), function (array &$d) use ($action, $in, $requestId, $today, $month) {
        Finance::normalize($d);
        $now = time();
        foreach ($d['requests'] as $k => $ts) {
            if (!is_int($ts) || $ts < $now - 172800) {
                unset($d['requests'][$k]);
            }
        }
        if ($requestId !== null && isset($d['requests'][$requestId])) {
            // Doppelklick / erneut gesendete Anfrage: nichts doppelt speichern.
            Finance::generateBills($d, $today);
            return ['result' => ['message' => 'Bereits gespeichert.', 'duplicate' => true], 'state' => Finance::state($d, $month, $today)];
        }
        $result = Finance::apply($action, $in, $d, $today);
        if ($requestId !== null) {
            $d['requests'][$requestId] = $now;
        }
        return ['result' => $result, 'state' => Finance::state($d, $month, $today)];
    }, Finance::blank());

    if ($action === 'onboarding.complete') {
        Auth::setOnboarded($uid);
        $user = Auth::findUser($uid);
    }
    Http::json(['ok' => true, 'user' => Auth::publicUser($user)] + $out['result'] + ['state' => $out['state']]);
} catch (ApiError $e) {
    $payload = ['ok' => false, 'error' => $e->getMessage()];
    if (session_status() === PHP_SESSION_ACTIVE && Pin::verified()) {
        $payload['csrf'] = Auth::csrf();
    }
    Http::json($payload, $e->status);
} catch (Throwable $e) {
    // Keine Inhalte loggen: Meldung gekürzt, längere Zahlenfolgen (Beträge, IBAN, Konten) maskiert.
    $safe = mb_substr(preg_replace('/\d{4,}/', '#', $e->getMessage()) ?? '', 0, 160);
    error_log('[CASH] ' . $e::class . ': ' . $safe . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    Http::json(['ok' => false, 'error' => 'Ein interner Fehler ist aufgetreten. Bitte erneut versuchen.'], 500);
}
