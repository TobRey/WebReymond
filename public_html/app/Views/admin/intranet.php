<?php
/**
 * Das Intranet – meine eigenen Beiträge.
 *
 * Alles hier liegt hinter der Anmeldung. Kein Kunde sieht es, keine
 * Schnittstelle gibt es heraus. Deshalb dürfen hier Preise stehen, die
 * auf der Website nie stehen dürfen.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Notes;

/** @var array<int, array<string, mixed>> $beitraege */
/** @var string $suche */
/** @var string $stichwort */
/** @var array<string, int> $stichworte */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

// Eine Vorschau von ein paar Zeilen. Der ganze Text stünde sonst
// dreimal untereinander und die Liste waere keine Liste mehr.
$anriss = static function (string $text): string {
    $klar = trim(preg_replace('/[#*`|>-]+/', ' ', $text) ?? $text);
    $klar = trim(preg_replace('/\s+/', ' ', $klar) ?? $klar);

    return mb_strlen($klar) > 180 ? mb_substr($klar, 0, 180) . ' …' : $klar;
};
?>

<div class="wa-page-head">
    <p class="wa-intro">
        Was ich mir merken will. Nur für mich – nichts davon verlässt diesen Server.
    </p>
    <a class="wa-btn wa-btn--primary wa-btn--sm" href="<?= e($base) ?>/intranet/neu">
        Neuer Beitrag
    </a>
</div>

<form class="wa-filter" method="get" action="<?= e($base) ?>/intranet">
    <input class="wa-input" type="search" name="suche" value="<?= e($suche) ?>"
           placeholder="Suchen in Titel und Text" aria-label="Beiträge durchsuchen">
    <select class="wa-select" name="stichwort" aria-label="Nach Stichwort filtern">
        <option value="">– alle Stichwörter –</option>
        <?php foreach ($stichworte as $wort => $anzahl): ?>
            <option value="<?= e($wort) ?>"<?= $stichwort === $wort ? ' selected' : '' ?>>
                <?= e($wort) ?> (<?= (int) $anzahl ?>)
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="wa-btn wa-btn--sm">Anzeigen</button>
    <?php if ($suche !== '' || $stichwort !== ''): ?>
        <a class="wa-btn wa-btn--quiet wa-btn--sm" href="<?= e($base) ?>/intranet">Zurücksetzen</a>
    <?php endif; ?>
</form>

<?php if ($beitraege === []): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">
                <?= $suche !== '' || $stichwort !== '' ? 'Nichts gefunden' : 'Noch nichts notiert' ?>
            </h2>
        </div>
        <p class="wa-panel__body">
            <?= $suche !== '' || $stichwort !== ''
                ? 'Zu dieser Suche gibt es keinen Beitrag.'
                : 'Der erste Beitrag ist immer der schwerste. Danach steht hier, was du sonst dreimal nachschlagen müsstest.' ?>
        </p>
    </section>
<?php endif; ?>

<?php foreach ($beitraege as $beitrag): ?>
    <article class="wa-panel wa-note-card<?= (int) $beitrag['pinned'] === 1 ? ' is-pinned' : '' ?>">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">
                <a href="<?= e($base) ?>/intranet/<?= (int) $beitrag['id'] ?>">
                    <?= e((string) $beitrag['title']) ?>
                </a>
            </h2>
            <p class="wa-panel__hint">
                <?php if ((string) $beitrag['tag'] !== ''): ?>
                    <span class="wa-badge"><?= e((string) $beitrag['tag']) ?></span>
                <?php endif; ?>
                geändert <?= e(date('d.m.Y', strtotime((string) $beitrag['updated_at']))) ?>
            </p>
            <div class="wa-panel__actions">
                <form method="post" action="<?= e($base) ?>/intranet/anheften">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="note_id" value="<?= (int) $beitrag['id'] ?>">
                    <input type="hidden" name="pinned" value="<?= (int) $beitrag['pinned'] === 1 ? '0' : '1' ?>">
                    <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">
                        <?= (int) $beitrag['pinned'] === 1 ? 'Lösen' : 'Anheften' ?>
                    </button>
                </form>
            </div>
        </div>

        <p class="wa-note-card__anriss"><?= e($anriss((string) $beitrag['body'])) ?></p>
    </article>
<?php endforeach; ?>
