<?php
/** @var string $uid @var array $account @var array $world @var array $card @var array $storage @var array $effects @var int $rank */
use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Num;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Game\Player;

$banned = ($account['status'] ?? 'active') === 'banned';
$role   = in_array(Player::ROLE_ADMIN, (array) ($account['roles'] ?? []), true) ? 'admin'
        : (in_array(Player::ROLE_MOD, (array) ($account['roles'] ?? []), true) ? 'moderator' : 'player');
?>
<div class="sk-card">
    <div class="sk-row" style="margin-bottom:12px">
        <h2 class="sk-grow" style="margin:0"><?= e((string) ($account['username'] ?? '')) ?></h2>
        <?php if ($banned): ?><span class="sk-pill sk-pill--red">gesperrt</span>
        <?php else: ?><span class="sk-pill sk-pill--green">aktiv</span><?php endif; ?>
    </div>

    <table class="sk-table sk-table--cards">
        <tbody>
        <tr><td data-label="Kennung"><strong>Kennung</strong></td><td data-label=" "><code><?= e($uid) ?></code></td></tr>
        <tr><td data-label="E-Mail"><strong>E-Mail</strong></td><td data-label=" "><?= e((string) ($account['email'] ?? '')) ?></td></tr>
        <tr><td data-label="Registriert"><strong>Registriert</strong></td><td data-label=" "><?= e(date('d.m.Y H:i', (int) ($account['created_at'] ?? 0))) ?></td></tr>
        <tr><td data-label="Zuletzt online"><strong>Zuletzt online</strong></td><td data-label=" "><?= e($account['last_seen'] ? date('d.m.Y H:i', (int) $account['last_seen']) : '–') ?></td></tr>
        <tr><td data-label="Punkte"><strong>Punkte</strong></td><td data-label=" "><?= e(Num::full((int) ($card['score'] ?? 0))) ?> (Platz <?= e((string) $rank) ?>)</td></tr>
        <tr><td data-label="Inseln"><strong>Inseln / Gebäude</strong></td><td data-label=" "><?= e((string) count((array) ($world['islands'] ?? []))) ?> / <?= e((string) count((array) ($world['buildings'] ?? []))) ?></td></tr>
        <tr><td data-label="Einwohner"><strong>Einwohner / Arbeiter</strong></td><td data-label=" "><?= e((string) $effects['population']) ?> / <?= e((string) $effects['workers_needed']) ?></td></tr>
        <tr><td data-label="Truppen"><strong>Truppen</strong></td><td data-label=" "><?= e(Num::full(array_sum(array_map('intval', (array) ($world['units'] ?? []))))) ?></td></tr>
        <tr><td data-label="Schild"><strong>Schutz bis</strong></td><td data-label=" "><?= e(($world['flags']['shield_until'] ?? 0) > time() ? date('d.m.Y H:i', (int) $world['flags']['shield_until']) : '–') ?></td></tr>
        </tbody>
    </table>
</div>

<div class="sk-card" style="margin-top:16px">
    <h2>Lager</h2>
    <?php foreach ($storage as $class): ?>
        <div style="margin-bottom:10px">
            <div class="sk-row" style="justify-content:space-between">
                <strong><?= e((string) $class['name']) ?></strong>
                <span class="sk-muted"><?= e(Num::compact((int) $class['used'])) ?> / <?= e(Num::compact((int) $class['cap'])) ?></span>
            </div>
            <div class="sk-bar"><div class="sk-bar__fill<?= $class['full'] ? ' is-full' : '' ?>" style="width:<?= e((string) round($class['ratio'] * 100)) ?>%"></div></div>
        </div>
    <?php endforeach; ?>

    <h3 style="margin-top:18px">Rohstoffe korrigieren</h3>
    <p class="sk-muted">Jede Korrektur wird mit Begründung im Protokoll festgehalten.
        Leere Felder bleiben unverändert.</p>
    <form method="post" action="<?= e(Url::to('admin/?p=player&id=' . $uid)) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="resources">
        <input type="hidden" name="uid" value="<?= e($uid) ?>">

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px">
            <?php foreach ((array) App::balance('resources', []) as $key => $definition): ?>
                <div class="sk-field" style="margin:0">
                    <label for="res_<?= e($key) ?>"><?= e((string) $definition['name']) ?></label>
                    <input id="res_<?= e($key) ?>" name="res_<?= e($key) ?>" type="number" min="0"
                           placeholder="<?= e((string) (int) ($world['store'][$key] ?? 0)) ?>">
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sk-field" style="margin-top:12px">
            <label for="reason">Begründung (Pflicht)</label>
            <input id="reason" name="reason" required maxlength="200" placeholder="z. B. Ausgleich nach Fehler XY">
        </div>
        <button class="sk-btn sk-btn--small" type="submit">Rohstoffe setzen</button>
    </form>
</div>

<div class="sk-card" style="margin-top:16px">
    <h2>Verwaltung</h2>

    <form method="post" action="<?= e(Url::to('admin/?p=player&id=' . $uid)) ?>" style="margin-bottom:16px">
        <?= Csrf::field() ?>
        <input type="hidden" name="uid" value="<?= e($uid) ?>">
        <input type="hidden" name="action" value="role">
        <div class="sk-field">
            <label for="role">Rolle</label>
            <select id="role" name="role">
                <option value="player"    <?= $role === 'player' ? 'selected' : '' ?>>Spieler</option>
                <option value="moderator" <?= $role === 'moderator' ? 'selected' : '' ?>>Moderation</option>
                <option value="admin"     <?= $role === 'admin' ? 'selected' : '' ?>>Administrator</option>
            </select>
        </div>
        <button class="sk-btn sk-btn--small" type="submit">Rolle speichern</button>
    </form>

    <?php if ($banned): ?>
        <form method="post" action="<?= e(Url::to('admin/?p=player&id=' . $uid)) ?>" style="margin-bottom:16px">
            <?= Csrf::field() ?>
            <input type="hidden" name="uid" value="<?= e($uid) ?>">
            <input type="hidden" name="action" value="unban">
            <p class="sk-muted">Grund der Sperre: <?= e((string) ($account['ban_reason'] ?? '')) ?></p>
            <button class="sk-btn sk-btn--small sk-btn--green" type="submit">Konto entsperren</button>
        </form>
    <?php else: ?>
        <form method="post" action="<?= e(Url::to('admin/?p=player&id=' . $uid)) ?>" style="margin-bottom:16px">
            <?= Csrf::field() ?>
            <input type="hidden" name="uid" value="<?= e($uid) ?>">
            <input type="hidden" name="action" value="ban">
            <div class="sk-field">
                <label for="banreason">Grund der Sperre</label>
                <input id="banreason" name="reason" maxlength="200" placeholder="Verstoss gegen die Spielregeln">
            </div>
            <button class="sk-btn sk-btn--small sk-btn--danger" type="submit">Konto sperren</button>
        </form>
    <?php endif; ?>

    <form method="post" action="<?= e(Url::to('admin/?p=player&id=' . $uid)) ?>" style="margin-bottom:16px">
        <?= Csrf::field() ?>
        <input type="hidden" name="uid" value="<?= e($uid) ?>">
        <input type="hidden" name="action" value="password">
        <p class="sk-muted">Falls kein E-Mail-Versand eingerichtet ist: neues Passwort erzeugen
            und dem Spieler auf sicherem Weg mitteilen.</p>
        <button class="sk-btn sk-btn--small" type="submit">Passwort zurücksetzen</button>
    </form>

    <form method="post" action="<?= e(Url::to('admin/?p=player&id=' . $uid)) ?>"
          onsubmit="return confirm('Konto und Königreich endgültig löschen?')">
        <?= Csrf::field() ?>
        <input type="hidden" name="uid" value="<?= e($uid) ?>">
        <input type="hidden" name="action" value="delete">
        <button class="sk-btn sk-btn--small sk-btn--danger" type="submit">Konto endgültig löschen</button>
    </form>
</div>

<div class="sk-card" style="margin-top:16px">
    <h2>Inseln und Gebäude</h2>
    <?php foreach ((array) ($world['islands'] ?? []) as $island): ?>
        <h3><?= e((string) $island['name']) ?>
            <span class="sk-pill sk-pill--muted"><?= e((string) App::balance('island_types.' . $island['type'] . '.name', $island['type'])) ?></span></h3>
        <table class="sk-table sk-table--cards">
            <thead><tr><th>Gebäude</th><th>Stufe</th><th>Position</th><th>Zustand</th></tr></thead>
            <tbody>
            <?php foreach ((array) ($world['buildings'] ?? []) as $building): ?>
                <?php if ($building['island'] !== $island['id']) { continue; } ?>
                <tr>
                    <td data-label="Gebäude"><?= e((string) App::balance('buildings.' . $building['type'] . '.name', $building['type'])) ?></td>
                    <td data-label="Stufe"><?= e(Num::full((int) $building['level'])) ?></td>
                    <td data-label="Position"><?= e((string) $building['x']) ?>/<?= e((string) $building['y']) ?></td>
                    <td data-label="Zustand"><?= ((float) ($building['damage'] ?? 0)) > 0.01
                        ? e(round((float) $building['damage'] * 100) . ' % beschädigt') : 'in Ordnung' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</div>
