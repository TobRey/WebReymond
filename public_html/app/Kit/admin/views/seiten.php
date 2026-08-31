<?php defined('WEBATZE') || exit; ?>
<?php
/**
 * Die Seiten der Website und ihre Abschnitte.
 *
 * Ein Abschnitt aufgeklappt heisst: bearbeiten. Alles steht sofort da,
 * ohne Umweg über eine zweite Seite – wer einen Text ändern will, soll
 * nicht erst suchen müssen.
 */

/** @var array $pages @var string $currentPath @var string $base */
/** @var \WebAtzeKit\Assistant $assistant */

use WebAtzeKit\{Csrf, Fields};
use WebAtze\Templates\{Catalog, Schema};

$page = null;
foreach ($pages as $candidate) {
    if ((string) ($candidate['path'] ?? '') === $currentPath) {
        $page = $candidate;
        break;
    }
}
$page ??= $pages[0] ?? null;

$assistantReady = $assistant->isConfigured();
$backTo = $base . '?ansicht=seiten&seite=' . rawurlencode((string) ($page['path'] ?? '/'));
?>

<?php if ($page === null): ?>
    <p class="k-note">Für diese Website sind keine Seiten hinterlegt.</p>
<?php else: ?>

<div class="k-tabs">
    <?php foreach ($pages as $item): ?>
        <a class="k-tab<?= ($item['path'] ?? '') === ($page['path'] ?? '') ? ' is-active' : '' ?>"
           href="?ansicht=seiten&amp;seite=<?= e(rawurlencode((string) $item['path'])) ?>">
            <?= e((string) $item['title']) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php /* ------------------------------------------------ Seitenangaben */ ?>
<details class="k-card k-collapse">
    <summary>Titel, Beschreibung und Navigation dieser Seite</summary>

    <form class="k-form" method="post" action="<?= e($base) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="page">
        <input type="hidden" name="path" value="<?= e((string) $page['path']) ?>">
        <input type="hidden" name="_back" value="<?= e($backTo) ?>">

        <label class="k-label" for="page-title">Titel der Seite</label>
        <input class="k-input" type="text" id="page-title" name="title" maxlength="120"
               value="<?= e((string) $page['title']) ?>">

        <label class="k-label" for="page-desc">
            Kurzbeschreibung
            <span class="k-hint">Steht in der Trefferliste von Suchmaschinen. Zwei Sätze genügen.</span>
        </label>
        <textarea class="k-textarea" id="page-desc" name="meta_description" rows="3"
                  maxlength="300"><?= e((string) ($page['meta_description'] ?? '')) ?></textarea>

        <label class="k-check">
            <input type="checkbox" name="in_navigation" value="1"
                   <?= !empty($page['in_navigation']) ? 'checked' : '' ?>>
            <span>Im Menü der Website anzeigen</span>
        </label>

        <button class="k-btn k-btn--primary" type="submit">Speichern</button>
    </form>
</details>

<?php /* --------------------------------------------------- Abschnitte */ ?>
<?php foreach ($page['sections'] ?? [] as $index => $section): ?>
    <?php
    $id = (int) ($section['id'] ?? 0);
    $type = (string) ($section['type'] ?? '');
    $meta = Schema::forType($type) ?? ['label' => $type, 'description' => '', 'fields' => []];
    $content = (array) ($section['content'] ?? []);
    $hidden = !empty($section['hidden']);
    $images = Fields::imageFields($type);
    ?>

    <section class="k-card k-section<?= $hidden ? ' is-hidden' : '' ?>">
        <header class="k-section__head">
            <div>
                <h2 class="k-section__title"><?= e((string) $meta['label']) ?></h2>
                <p class="k-section__sub">
                    <?= e(Catalog::label($type, (string) ($section['template_key'] ?? ''))) ?>
                    <?php if ($hidden): ?> · <strong>ausgeblendet</strong><?php endif; ?>
                </p>
            </div>

            <div class="k-section__tools">
                <?php foreach ([['up', 'Höher'], ['down', 'Tiefer']] as [$dir, $label]): ?>
                    <form method="post" action="<?= e($base) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="move">
                        <input type="hidden" name="section_id" value="<?= $id ?>">
                        <input type="hidden" name="direction" value="<?= e($dir) ?>">
                        <input type="hidden" name="_back" value="<?= e($backTo) ?>">
                        <button class="k-btn k-btn--quiet k-btn--sm" type="submit"><?= e($label) ?></button>
                    </form>
                <?php endforeach; ?>

                <form method="post" action="<?= e($base) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="section_id" value="<?= $id ?>">
                    <input type="hidden" name="_back" value="<?= e($backTo) ?>">
                    <button class="k-btn k-btn--quiet k-btn--sm" type="submit">
                        <?= $hidden ? 'Einblenden' : 'Ausblenden' ?>
                    </button>
                </form>
            </div>
        </header>

        <?php /* --- Inhalte ---
                 Eingeklappt: Neun Abschnitte mit allen Feldern zugleich wären
                 eine Wand aus Eingabefeldern. Wer etwas ändern will, öffnet
                 den einen Abschnitt, um den es geht. */ ?>
        <details class="k-collapse">
            <summary>Texte bearbeiten</summary>

        <form class="k-form" method="post" action="<?= e($base) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="section">
            <input type="hidden" name="section_id" value="<?= $id ?>">
            <input type="hidden" name="_back" value="<?= e($backTo) ?>">

            <?php require __DIR__ . '/fields.php'; ?>

            <button class="k-btn k-btn--primary" type="submit">Speichern</button>
        </form>

        </details>

        <?php /* --- Bilder --- */ ?>
        <?php if ($images !== []): ?>
            <details class="k-collapse">
                <summary>Bild austauschen</summary>
                <?php foreach ($images as $field => $label): ?>
                    <form class="k-inline" method="post" action="<?= e($base) ?>"
                          enctype="multipart/form-data">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="image">
                        <input type="hidden" name="section_id" value="<?= $id ?>">
                        <input type="hidden" name="field" value="<?= e($field) ?>">
                        <input type="hidden" name="_back" value="<?= e($backTo) ?>">

                        <?php $current = is_array($content[$field] ?? null) ? $content[$field] : []; ?>
                        <?php if (!empty($current['src'])): ?>
                            <img class="k-thumb" src="../<?= e((string) $current['src']) ?>" alt="" loading="lazy">
                        <?php endif; ?>

                        <label class="k-label k-label--inline"><?= e($label) ?></label>
                        <input class="k-input k-input--file" type="file" name="bild"
                               accept="image/jpeg,image/png,image/webp,image/gif" required>
                        <button class="k-btn k-btn--sm" type="submit">Austauschen</button>
                    </form>
                <?php endforeach; ?>
            </details>
        <?php endif; ?>

        <?php /* --- Vorlage --- */ ?>
        <details class="k-collapse">
            <summary>Anderes Aussehen wählen (<?= count(Catalog::forType($type)) ?> Möglichkeiten)</summary>
            <div class="k-variants">
                <?php foreach (Catalog::forType($type) as $key => $variant): ?>
                    <form method="post" action="<?= e($base) ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="template">
                        <input type="hidden" name="section_id" value="<?= $id ?>">
                        <input type="hidden" name="template" value="<?= e($key) ?>">
                        <input type="hidden" name="_back" value="<?= e($backTo) ?>">
                        <button class="k-variant<?= ($section['template_key'] ?? '') === $key ? ' is-current' : '' ?>"
                                type="submit"><?= e((string) $variant['label']) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </details>

        <?php /* --- Assistent --- */ ?>
        <details class="k-collapse">
            <summary>Mit eigenen Worten ändern lassen</summary>

            <?php if (!$assistantReady): ?>
                <p class="k-note">
                    Für diese Website ist der Assistent nicht eingerichtet.
                    Änderungen lassen sich trotzdem alle von Hand vornehmen.
                </p>
            <?php else: ?>
                <form class="k-form" method="post" action="<?= e($base) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="assistant">
                    <input type="hidden" name="section_id" value="<?= $id ?>">
                    <input type="hidden" name="_back" value="<?= e($backTo) ?>">

                    <label class="k-label" for="ai-<?= $id ?>">
                        Was soll anders sein?
                        <span class="k-hint">
                            Zum Beispiel: „Die Überschrift kürzer und persönlicher" oder
                            „Die Schaltfläche auffälliger". Der Assistent ändert nur diesen
                            Abschnitt – der Rest der Website bleibt, wie er ist.
                        </span>
                    </label>
                    <textarea class="k-textarea" id="ai-<?= $id ?>" name="instruction" rows="3"
                              maxlength="600" required></textarea>

                    <button class="k-btn" type="submit">Ändern lassen</button>
                </form>
            <?php endif; ?>
        </details>
    </section>
<?php endforeach; ?>

<?php endif; ?>
