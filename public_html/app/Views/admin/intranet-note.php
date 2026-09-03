<?php
/**
 * Ein Beitrag im Intranet: lesen und ändern auf derselben Seite.
 *
 * Bewusst kein getrennter Bearbeitungsmodus. Eine Notiz, die man erst
 * öffnen und dann noch einmal auf «Bearbeiten» klicken muss, ändert man
 * nicht – und dann steht dort bald etwas Falsches.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Notes;

/** @var array<string, mixed>|null $beitrag */
/** @var array<int, string> $vorschlaege */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$neu = $beitrag === null;
$wert = static fn (string $feld): string => $neu ? '' : (string) ($beitrag[$feld] ?? '');
?>

<p class="wa-intro">
    <a href="<?= e($base) ?>/intranet">← Alle Beiträge</a>
</p>

<?php if (!$neu && trim((string) $beitrag['body']) !== ''): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title"><?= e((string) $beitrag['title']) ?></h2>
            <p class="wa-panel__hint">
                <?php if ((string) $beitrag['tag'] !== ''): ?>
                    <span class="wa-badge"><?= e((string) $beitrag['tag']) ?></span>
                <?php endif; ?>
                geändert <?= e(date('d.m.Y, H:i', strtotime((string) $beitrag['updated_at']))) ?>
            </p>
        </div>

        <?php /* Der Text ist beim Darstellen vollstaendig maskiert -
                 siehe Notes::render(). */ ?>
        <div class="wa-prose"><?= Notes::render((string) $beitrag['body']) ?></div>
    </section>
<?php endif; ?>

<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title"><?= $neu ? 'Neuer Beitrag' : 'Ändern' ?></h2>
    </div>

    <form method="post" action="<?= e($base) ?>/intranet" class="wa-form">
        <?= Csrf::field() ?>
        <?php if (!$neu): ?>
            <input type="hidden" name="note_id" value="<?= (int) $beitrag['id'] ?>">
        <?php endif; ?>

        <div class="wa-field">
            <label class="wa-label" for="n-title">Titel</label>
            <input class="wa-input" type="text" id="n-title" name="title" required maxlength="191"
                   value="<?= e($wert('title')) ?>" placeholder="z.B. Was bei einem Domainumzug schiefgeht">
        </div>

        <div class="wa-field">
            <label class="wa-label" for="n-tag">Stichwort</label>
            <input class="wa-input wa-input--short" type="text" id="n-tag" name="tag" maxlength="40"
                   list="stichworte" value="<?= e($wert('tag')) ?>">
            <datalist id="stichworte">
                <?php foreach ($vorschlaege as $wort): ?>
                    <option value="<?= e($wort) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="n-body">Text</label>
            <textarea class="wa-input wa-textarea" id="n-body" name="body" rows="18"
                      spellcheck="true"><?= e($wert('body')) ?></textarea>
            <p class="wa-hint">
                <code>## Überschrift</code>, <code>* Aufzählung</code>, <code>1. Nummeriert</code>,
                <code>**fett**</code>, <code>`Code`</code> und Tabellen mit <code>|</code> werden
                dargestellt. Alles andere bleibt, wie es dasteht.
            </p>
        </div>

        <div class="wa-field">
            <label class="wa-check-line">
                <input type="checkbox" name="pinned" value="1"
                       <?= !$neu && (int) $beitrag['pinned'] === 1 ? 'checked' : '' ?>>
                <span>Oben anheften</span>
            </label>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">
                <?= $neu ? 'Anlegen' : 'Speichern' ?>
            </button>
        </div>
    </form>
</section>

<?php if (!$neu): ?>
    <section class="wa-panel wa-panel--danger">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">Löschen</h2>
        </div>

        <p class="wa-panel__hint">
            Weg ist weg – es gibt keinen Papierkorb.
        </p>

        <?php /* data-confirm gehoert ans Formular - admin.js haengt am
                 submit-Ereignis, nicht am Klick. */ ?>
        <form method="post" action="<?= e($base) ?>/intranet/loeschen"
              data-confirm="Diesen Beitrag wirklich löschen?">
            <?= Csrf::field() ?>
            <input type="hidden" name="note_id" value="<?= (int) $beitrag['id'] ?>">
            <button type="submit" class="wa-btn wa-btn--danger wa-btn--sm">Löschen</button>
        </form>
    </section>
<?php endif; ?>
