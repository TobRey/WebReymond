<?php

/**
 * Die potenziellen Kunden.
 *
 * Was hier steht, wurde bewusst angenommen. Deshalb geht es hier nicht
 * mehr um ja oder nein, sondern darum, wie weit es gediehen ist.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Prospects;

/** @var array<int, array<string, mixed>> $liste */
/** @var array<int, array<string, mixed>> $verworfen */
/** @var array<string, int> $zahlen */
/** @var array<string, string> $status */
/** @var array<string, string> $zustaende */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');

// Nur die Zustände, die in dieser Liste sinnvoll sind.
$waehlbar = array_intersect_key($status, array_flip(Prospects::IN_LIST));
?>

<div class="wa-tiles">
    <div class="wa-tile">
        <span class="wa-tile__label">In der Liste</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['liste'] ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Im Stapel wartend</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['neu'] ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Zu Kunden geworden</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['kunde'] ?></strong>
    </div>
</div>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Potenzielle Kunden</h2>
        <a class="wa-btn" href="<?= e($base) ?>/kundensuche">Weitersuchen</a>
    </header>

    <?php if ($liste === []): ?>
        <p class="wa-empty">
            Noch nichts vorgemerkt.
            <a href="<?= e($base) ?>/kundensuche">In der Kundensuche durchblättern.</a>
        </p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Firma</th>
                        <th>Kontakt</th>
                        <th>Website</th>
                        <th>Stand</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liste as $p): ?>
                        <tr>
                            <td>
                                <strong class="wa-table__main"><?= e((string) $p['name']) ?></strong>
                                <?php if ((string) $p['place'] !== '' || (string) $p['branch'] !== ''): ?>
                                    <span class="wa-table__quiet">
                                        <?= e(trim((string) $p['branch'] . ' · ' . (string) $p['place'], ' ·')) ?>
                                    </span>
                                <?php endif; ?>
                                <a class="wa-table__quiet" href="<?= e(Prospects::googleUrl($p)) ?>"
                                   target="_blank" rel="noopener noreferrer nofollow">googlen</a>
                            </td>
                            <td>
                                <?php if ((string) $p['phone'] !== ''): ?>
                                    <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $p['phone']) ?? '') ?>">
                                        <?= e((string) $p['phone']) ?>
                                    </a><br>
                                <?php endif; ?>
                                <?php if ((string) $p['email'] !== ''): ?>
                                    <a href="mailto:<?= e((string) $p['email']) ?>"><?= e((string) $p['email']) ?></a>
                                <?php endif; ?>
                                <?php if ((string) $p['contact_name'] !== ''): ?>
                                    <span class="wa-table__quiet"><?= e((string) $p['contact_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__quiet">
                                <?php if ((string) $p['website'] !== ''): ?>
                                    <a href="<?= e((string) $p['website']) ?>" target="_blank"
                                       rel="noopener noreferrer nofollow">ansehen</a><br>
                                <?php endif; ?>
                                <?= e($zustaende[(string) $p['site_state']] ?? '') ?>
                            </td>
                            <td>
                                <form method="post" action="<?= e($base) ?>/potenzielle-kunden/status">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="prospect_id" value="<?= (int) $p['id'] ?>">
                                    <select class="wa-select wa-input--short" name="status"
                                            aria-label="Stand von <?= e((string) $p['name']) ?>">
                                        <?php foreach ($waehlbar as $wert => $text): ?>
                                            <option value="<?= e($wert) ?>"
                                                <?= (string) $p['status'] === $wert ? ' selected' : '' ?>>
                                                <?= e($text) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="wa-btn wa-btn--small">Setzen</button>
                                </form>
                            </td>
                            <td class="wa-table__actions">
                                <form method="post" action="<?= e($base) ?>/potenzielle-kunden/uebernehmen">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="prospect_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="wa-btn wa-btn--small wa-btn--primary">
                                        Kunde hinzufügen
                                    </button>
                                </form>
                                <form method="post" action="<?= e($base) ?>/potenzielle-kunden/loeschen"
                                      data-confirm="Diesen Eintrag endgültig löschen?">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="prospect_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="wa-btn wa-btn--small">Löschen</button>
                                </form>
                            </td>
                        </tr>
                        <?php if (trim((string) $p['research']) !== '' || trim((string) $p['note']) !== ''): ?>
                            <tr class="wa-table__sub">
                                <td colspan="5">
                                    <details class="wa-details">
                                        <summary>Was bekannt ist</summary>
                                        <?php if (trim((string) $p['research']) !== ''): ?>
                                            <p class="wa-note"><?= nl2br(e((string) $p['research'])) ?></p>
                                        <?php endif; ?>
                                        <form method="post" action="<?= e($base) ?>/potenzielle-kunden/notiz"
                                              class="wa-form">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="prospect_id" value="<?= (int) $p['id'] ?>">
                                            <div class="wa-field wa-field--wide">
                                                <label class="wa-label" for="notiz-<?= (int) $p['id'] ?>">
                                                    Eigene Notiz
                                                </label>
                                                <textarea class="wa-input wa-textarea" rows="3" name="note"
                                                          id="notiz-<?= (int) $p['id'] ?>"><?= e((string) $p['note']) ?></textarea>
                                            </div>
                                            <div class="wa-form__actions">
                                                <button type="submit" class="wa-btn wa-btn--small">Notiz speichern</button>
                                            </div>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <details class="wa-details">
        <summary>Firma von Hand eintragen</summary>
        <form method="post" action="<?= e($base) ?>/potenzielle-kunden" class="wa-form wa-form--inline">
            <?= Csrf::field() ?>

            <div class="wa-field">
                <label class="wa-label" for="p-name">Firma</label>
                <input class="wa-input" type="text" id="p-name" name="name" required>
            </div>
            <div class="wa-field">
                <label class="wa-label" for="p-branch">Branche</label>
                <input class="wa-input" type="text" id="p-branch" name="branch">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="p-place">Ort</label>
                <input class="wa-input" type="text" id="p-place" name="place">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="p-website">Website</label>
                <input class="wa-input" type="text" id="p-website" name="website">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="p-phone">Telefon</label>
                <input class="wa-input" type="text" id="p-phone" name="phone">
            </div>
            <div class="wa-field">
                <label class="wa-label" for="p-email">E-Mail</label>
                <input class="wa-input" type="email" id="p-email" name="email">
            </div>
            <div class="wa-field wa-field--wide">
                <label class="wa-label" for="p-research">Was du schon weisst</label>
                <textarea class="wa-input wa-textarea" rows="3" id="p-research" name="research"></textarea>
            </div>

            <div class="wa-form__actions">
                <button type="submit" class="wa-btn wa-btn--primary">Eintragen</button>
            </div>
        </form>
    </details>
</section>

<?php if ($verworfen !== []): ?>
<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Weggelegt</h2>
    </header>

    <p class="wa-panel__hint">
        Die stehen hier, damit sie bei der nächsten Suche nicht wieder vorgeschlagen werden.
        Ein Fehlgriff lässt sich zurückholen.
    </p>

    <div class="wa-table-wrap">
        <table class="wa-table">
            <tbody>
                <?php foreach ($verworfen as $p): ?>
                    <tr>
                        <td>
                            <?= e((string) $p['name']) ?>
                            <span class="wa-table__quiet"><?= e((string) $p['place']) ?></span>
                        </td>
                        <td class="wa-table__quiet">
                            <?= (string) $p['status'] === 'nie' ? 'nie wieder zeigen' : 'abgelehnt' ?>
                        </td>
                        <td class="wa-table__actions">
                            <form method="post" action="<?= e($base) ?>/potenzielle-kunden/status">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="prospect_id" value="<?= (int) $p['id'] ?>">
                                <input type="hidden" name="status" value="vorgemerkt">
                                <button type="submit" class="wa-btn wa-btn--small">Zurückholen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
