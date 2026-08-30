<?php
/**
 * Referenzen.
 *
 * Sie entstehen automatisch, sobald eine Kundenwebsite fertig ist. Hier
 * lässt sich der Text nachschärfen, die Reihenfolge festlegen und ein
 * Eintrag ausblenden – etwa solange der Kunde noch nicht einverstanden ist.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $entries @var array $candidates */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>

<p class="wa-intro">
    Diese Einträge erscheinen auf der Website unter „Referenzen". Die Vorschau entsteht aus der
    gespeicherten Kopie der fertigen Seite – nicht aus einem Bildschirmfoto, das veraltet.
</p>

<?php if ($candidates !== []): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">Noch ohne Referenz</h2>
            <p class="wa-panel__hint">
                Diese Projekte sind fertig, stehen aber noch nicht auf der Website.
            </p>
        </div>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead><tr><th>Projekt</th><th>Domain</th><th>Fertig seit</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($candidates as $candidate): ?>
                    <tr>
                        <td>
                            <a href="<?= e($base) ?>/projekt/<?= (int) $candidate['id'] ?>">
                                <?= e((string) $candidate['name']) ?>
                            </a>
                        </td>
                        <td><?= (string) $candidate['domain'] !== '' ? e((string) $candidate['domain']) : '–' ?></td>
                        <td><?= e(date('d.m.Y', strtotime((string) $candidate['updated_at']))) ?></td>
                        <td class="wa-table__right">
                            <form method="post" action="<?= e($base) ?>/referenzen/<?= (int) $candidate['id'] ?>">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="create">
                                <button type="submit" class="wa-btn wa-btn--sm">Referenz anlegen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($entries === []): ?>
    <section class="wa-panel">
        <div class="wa-empty-state">
            <p>Noch keine Referenzen. Die erste entsteht automatisch mit der ersten fertigen Website.</p>
        </div>
    </section>
<?php else: ?>
    <?php foreach ($entries as $entry): ?>
        <section class="wa-panel">
            <div class="wa-panel__head">
                <div>
                    <h2 class="wa-panel__title">
                        <?= e((string) $entry['title']) ?>
                        <?php if (!(bool) $entry['published']): ?>
                            <span class="wa-badge wa-badge--waiting">ausgeblendet</span>
                        <?php endif; ?>
                    </h2>
                    <?php if ((string) $entry['subtitle'] !== ''): ?>
                        <p class="wa-panel__hint"><?= e((string) $entry['subtitle']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="wa-panel__actions">
                    <form method="post" action="<?= e($base) ?>/referenzen/<?= (int) $entry['id'] ?>">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">
                            <?= (bool) $entry['published'] ? 'Ausblenden' : 'Sichtbar machen' ?>
                        </button>
                    </form>
                    <form method="post" action="<?= e($base) ?>/referenzen/<?= (int) $entry['id'] ?>"
                          data-confirm="Diese Referenz wirklich entfernen?">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">Entfernen</button>
                    </form>
                </div>
            </div>

            <?php /* Eingeklappt, weil sonst sieben ausgefüllte Formulare
                     untereinander stünden und man nichts mehr findet. */ ?>
            <details class="wa-collapse">
                <summary>Text bearbeiten</summary>
            <form class="wa-form" method="post" action="<?= e($base) ?>/referenzen/<?= (int) $entry['id'] ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save">

                <div class="wa-grid-2">
                    <div class="wa-field">
                        <label class="wa-label" for="title-<?= (int) $entry['id'] ?>">Titel</label>
                        <input class="wa-input" type="text" id="title-<?= (int) $entry['id'] ?>"
                               name="title" value="<?= e((string) $entry['title']) ?>">
                    </div>
                    <div class="wa-field">
                        <label class="wa-label" for="subtitle-<?= (int) $entry['id'] ?>">Untertitel</label>
                        <input class="wa-input" type="text" id="subtitle-<?= (int) $entry['id'] ?>"
                               name="subtitle" value="<?= e((string) $entry['subtitle']) ?>">
                    </div>
                </div>

                <div class="wa-field">
                    <label class="wa-label" for="body_de-<?= (int) $entry['id'] ?>">Text (Deutsch)</label>
                    <textarea class="wa-textarea" id="body_de-<?= (int) $entry['id'] ?>"
                              name="body_de" rows="4"><?= e((string) $entry['body_de']) ?></textarea>
                </div>

                <div class="wa-field">
                    <label class="wa-label" for="body_en-<?= (int) $entry['id'] ?>">Text (Englisch)</label>
                    <textarea class="wa-textarea" id="body_en-<?= (int) $entry['id'] ?>"
                              name="body_en" rows="4"><?= e((string) $entry['body_en']) ?></textarea>
                </div>

                <div class="wa-grid-2">
                    <div class="wa-field">
                        <label class="wa-label" for="tags-<?= (int) $entry['id'] ?>">Schlagworte</label>
                        <input class="wa-input" type="text" id="tags-<?= (int) $entry['id'] ?>"
                               name="tags" value="<?= e((string) $entry['tags']) ?>">
                        <span class="wa-label__hint">Mit Komma getrennt, z.B. „Restaurant, Onepager"</span>
                    </div>
                    <div class="wa-field">
                        <label class="wa-label" for="live_url-<?= (int) $entry['id'] ?>">Adresse der Website</label>
                        <input class="wa-input" type="url" id="live_url-<?= (int) $entry['id'] ?>"
                               name="live_url" value="<?= e((string) $entry['live_url']) ?>"
                               placeholder="https://beispiel.ch">
                    </div>
                </div>

                <div class="wa-grid-2">
                    <div class="wa-field">
                        <label class="wa-label" for="sort_order-<?= (int) $entry['id'] ?>">Reihenfolge</label>
                        <input class="wa-input" type="number" id="sort_order-<?= (int) $entry['id'] ?>"
                               name="sort_order" value="<?= (int) $entry['sort_order'] ?>">
                        <span class="wa-label__hint">Kleinere Zahl steht weiter vorne.</span>
                    </div>
                    <div class="wa-field">
                        <span class="wa-label">Vorschau</span>
                        <p class="wa-label__hint">
                            <?= (string) $entry['preview_path'] !== ''
                                ? 'Gespeicherte Kopie vorhanden.'
                                : 'Noch keine Kopie – sie entsteht beim nächsten Bauen.' ?>
                            <?php if ((string) ($entry['project_slug'] ?? '') !== ''): ?>
                                Projekt: <code><?= e((string) $entry['project_slug']) ?></code>
                            <?php else: ?>
                                Das zugehörige Projekt wurde gelöscht – der Eintrag bleibt bestehen.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="wa-form__actions">
                    <button type="submit" class="wa-btn wa-btn--primary">Speichern</button>
                </div>
            </form>
            </details>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
