<?php

/**
 * Mitarbeitende.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Billing;

/** @var array<int, array<string, mixed>> $leute */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Wer mitarbeitet</h2>
    </header>

    <?php if ($leute === []): ?>
        <p class="wa-empty">Noch niemand erfasst.</p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Rolle</th>
                        <th>Kontakt</th>
                        <th class="wa-table__num">Stundensatz</th>
                        <th class="wa-table__num">Kunden</th>
                        <th class="wa-table__num">Offen</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leute as $m): ?>
                        <tr<?= empty($m['active']) ? ' class="is-inactive"' : '' ?>>
                            <td>
                                <span class="wa-table__main"><?= e((string) $m['name']) ?></span>
                                <?php if (empty($m['active'])): ?>
                                    <span class="wa-badge wa-badge--waiting">nicht mehr aktiv</span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__quiet"><?= e((string) $m['role']) ?></td>
                            <td class="wa-table__quiet">
                                <?= e((string) $m['email']) ?>
                                <?php if ((string) $m['phone'] !== ''): ?>
                                    <br><?= e((string) $m['phone']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__num">
                                <?= (int) $m['hourly_rappen'] > 0
                                    ? e(Billing::money((int) $m['hourly_rappen'])) : '–' ?>
                            </td>
                            <td class="wa-table__num"><?= (int) $m['kunden'] ?: '–' ?></td>
                            <td class="wa-table__num"><?= (int) $m['offene_todos'] ?: '–' ?></td>
                            <td class="wa-table__actions">
                                <button type="button" class="wa-btn wa-btn--small"
                                        data-toggles="#person-<?= (int) $m['id'] ?>">Ändern</button>
                            </td>
                        </tr>
                        <tr id="person-<?= (int) $m['id'] ?>" hidden>
                            <td colspan="7">
                                <?= View_partial('admin/partials/employee-form', [
                                    'base' => $base, 'person' => $m,
                                ]) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <details class="wa-details"<?= $leute === [] ? ' open' : '' ?>>
        <summary>Jemanden hinzufügen</summary>
        <?= View_partial('admin/partials/employee-form', ['base' => $base, 'person' => null]) ?>
    </details>
</section>
