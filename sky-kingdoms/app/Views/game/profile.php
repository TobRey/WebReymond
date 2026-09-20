<?php
/** @var array $user @var array $card @var array $world @var int $rank @var array $storage @var array $achievements */
use SkyKingdoms\Core\Num;
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\View;
?>
<?= View::render('partials/brand', ['subtitle' => 'Spielerprofil']) ?>

<div class="sk-card">
    <div class="sk-row" style="margin-bottom:14px">
        <span class="sk-pill"><?= e((string) $card['name']) ?></span>
        <span class="sk-pill sk-pill--gold">Stufe <?= e((string) $card['level']) ?></span>
        <?php if ($rank > 0): ?><span class="sk-pill sk-pill--green">Platz <?= e((string) $rank) ?></span><?php endif; ?>
    </div>

    <table class="sk-table sk-table--cards">
        <tbody>
        <tr><td data-label="Königreich"><strong>Königreich</strong></td><td data-label=" "><?= e((string) ($card['kingdom'] ?: '–')) ?></td></tr>
        <tr><td data-label="Punkte"><strong>Punkte</strong></td><td data-label=" "><?= e(Num::full((int) $card['score'])) ?></td></tr>
        <tr><td data-label="Inseln"><strong>Inseln</strong></td><td data-label=" "><?= e((string) $card['islands']) ?></td></tr>
        <tr><td data-label="Gebäude"><strong>Gebäude</strong></td><td data-label=" "><?= e((string) $card['buildings']) ?></td></tr>
        <tr><td data-label="Truppen"><strong>Truppen</strong></td><td data-label=" "><?= e((string) $card['army']) ?></td></tr>
        <tr><td data-label="Angriffe"><strong>Gewonnene Angriffe</strong></td><td data-label=" "><?= e((string) (int) ($world['stats']['attacks_won'] ?? 0)) ?></td></tr>
        <tr><td data-label="Abwehr"><strong>Abgewehrte Angriffe</strong></td><td data-label=" "><?= e((string) (int) ($world['stats']['defenses_won'] ?? 0)) ?></td></tr>
        <tr><td data-label="Verbesserungen"><strong>Verbesserungen</strong></td><td data-label=" "><?= e(Num::full((int) ($world['stats']['upgrades'] ?? 0))) ?></td></tr>
        <tr><td data-label="Spielzeit"><strong>Berechnete Spielzeit</strong></td><td data-label=" "><?= e(Num::duration((int) ($world['stats']['playtime'] ?? 0))) ?></td></tr>
        <tr><td data-label="Allianz"><strong>Allianz</strong></td><td data-label=" "><?= e((string) ($card['alliance'] ? 'ja' : 'keine')) ?></td></tr>
        </tbody>
    </table>

    <h2 style="margin-top:22px">Lager</h2>
    <?php foreach ($storage as $class): ?>
        <div style="margin-bottom:10px">
            <div class="sk-row" style="justify-content:space-between">
                <strong><?= e((string) $class['name']) ?></strong>
                <span class="sk-muted"><?= e(Num::compact((int) $class['used'])) ?> / <?= e(Num::compact((int) $class['cap'])) ?></span>
            </div>
            <div class="sk-bar"><div class="sk-bar__fill <?= $class['full'] ? 'is-full' : '' ?>" style="width:<?= e((string) round($class['ratio'] * 100)) ?>%"></div></div>
        </div>
    <?php endforeach; ?>

    <h2 style="margin-top:22px">Erfolge</h2>
    <ul class="sk-check-list">
        <?php foreach ($achievements as $achievement): ?>
            <li>
                <span class="sk-check-list__icon <?= $achievement['done'] ? 'is-ok' : 'is-warn' ?>"><?= $achievement['done'] ? '✓' : '·' ?></span>
                <span class="sk-grow">
                    <span class="sk-check-list__label"><?= e((string) $achievement['name']) ?></span>
                    <span class="sk-check-list__value"><?= e((string) $achievement['desc']) ?> – <?= e(Num::compact((int) $achievement['current'])) ?> / <?= e(Num::compact((int) $achievement['goal'])) ?></span>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="sk-row" style="margin-top:18px">
        <a class="sk-btn sk-grow" href="<?= e(Url::to('?p=game')) ?>">Zum Königreich</a>
        <a class="sk-btn sk-btn--ghost sk-grow" href="<?= e(Url::to('?p=konto')) ?>" style="color:var(--sk-primary);box-shadow:inset 0 0 0 1.5px var(--sk-primary)">Konto</a>
    </div>
</div>
