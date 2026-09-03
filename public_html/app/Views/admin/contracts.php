<?php
/**
 * Wartungsverträge.
 *
 * Eine Website ist nicht fertig, wenn sie steht. Der Vertrag macht
 * daraus etwas Planbares – und die Rechnung entsteht am Fälligkeitstag
 * von selbst, als Entwurf.
 */

use WebAtze\Build\{Contracts, DocumentBuilder};
use WebAtze\Core\{Config, Csrf};

/** @var array $rows @var array $projects */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>

<p class="wa-intro">
    Am Fälligkeitstag entsteht ein Rechnungsentwurf. Verschickt wird er nicht von
    selbst – eine Rechnung, die niemand angesehen hat, ist irgendwann die falsche.
</p>

<section class="wa-panel wa-panel--neu">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Neuen Vertrag anlegen</h2>
    </div>

    <form method="post" action="<?= e($base) ?>/vertraege" class="wa-grid-2">
        <?= Csrf::field() ?>

        <div class="wa-field">
            <label class="wa-label" for="c-project">Website</label>
            <select class="wa-select" id="c-project" name="project_id" required>
                <option value="0">– bitte wählen –</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?= (int) $project['id'] ?>">
                        <?= e((string) $project['name']) ?><?php
                            $zusatz = array_filter([
                                trim((string) ($project['kunde'] ?? '')),
                                trim((string) ($project['domain'] ?? '')),
                            ]);
                            echo $zusatz === [] ? '' : ' · ' . e(implode(' · ', $zusatz));
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($projects === []): ?>
                <span class="wa-label__hint">
                    Noch keine Website eingetragen. Unter «Websites» hinzufügen oder eine bauen lassen.
                </span>
            <?php endif; ?>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="c-plan">Umfang</label>
            <select class="wa-select" id="c-plan" name="plan">
                <?php foreach (Contracts::PLANS as $key => $label): ?>
                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="c-price">Betrag je Abrechnung</label>
            <input class="wa-input wa-input--short" type="text" id="c-price" name="price"
                   placeholder="0.00" spellcheck="false">
            <span class="wa-label__hint">In Franken. Steht nur hier, nie auf der Website.</span>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="c-interval">Abrechnung alle … Monate</label>
            <input class="wa-input wa-input--short" type="number" id="c-interval" name="interval_months"
                   min="1" max="24" step="1" value="12">
        </div>

        <div class="wa-field">
            <label class="wa-label" for="c-start">Beginn</label>
            <input class="wa-input" type="date" id="c-start" name="started_on"
                   value="<?= e(date('Y-m-d')) ?>">
        </div>

        <div class="wa-field">
            <label class="wa-label" for="c-note">Notiz</label>
            <input class="wa-input" type="text" id="c-note" name="note" maxlength="2000"
                   placeholder="Zwei Textänderungen pro Jahr abgemacht">
            <button type="submit" class="wa-btn">Vertrag anlegen</button>
        </div>
    </form>
</section>

<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Bestehende Verträge</h2>
        <div class="wa-panel__actions">
            <form method="post" action="<?= e($base) ?>/vertraege/abrechnen">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">Fällige jetzt abrechnen</button>
            </form>
        </div>
    </div>

    <?php if ($rows === []): ?>
        <p class="wa-panel__body">Noch kein Vertrag.</p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                <tr>
                    <th>Website</th>
                    <th>Umfang</th>
                    <th class="wa-table__right">Betrag</th>
                    <th>Abrechnung</th>
                    <th>Nächste</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <a href="<?= e($base) ?>/projekt/<?= (int) $row['project_id'] ?>">
                                <?= e((string) ($row['project_name'] ?? 'Website')) ?>
                            </a>
                            <?php if ((string) $row['note'] !== ''): ?>
                                <br><span class="wa-muted"><?= e((string) $row['note']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(Contracts::PLANS[(string) $row['plan']] ?? (string) $row['plan']) ?></td>
                        <td class="wa-table__right">
                            CHF <?= e(DocumentBuilder::money((int) $row['price_rappen'])) ?>
                        </td>
                        <td>alle <?= (int) $row['interval_months'] ?> Monate</td>
                        <td>
                            <?php if (empty($row['active'])): ?>
                                <span class="wa-badge wa-badge--failed">
                                    gekündigt <?= e(date('d.m.Y', strtotime((string) $row['cancelled_on']))) ?>
                                </span>
                            <?php elseif (!empty($row['due'])): ?>
                                <span class="wa-badge wa-badge--running">jetzt fällig</span>
                            <?php else: ?>
                                <?= e(date('d.m.Y', strtotime((string) $row['next_invoice_on']))) ?>
                            <?php endif; ?>
                        </td>
                        <td class="wa-table__right">
                            <?php if (!empty($row['active'])): ?>
                                <form method="post" action="<?= e($base) ?>/vertraege/<?= (int) $row['id'] ?>/kuendigen">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">Kündigen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Was in welchem Umfang steckt</h2>
        <p class="wa-panel__hint">
            Zum Nachschlagen, wenn ein Kunde fragt. Preise stehen hier bewusst nicht –
            die machst du je Kunde aus.
        </p>
    </div>

    <?php foreach (Contracts::INCLUDES as $key => $items): ?>
        <div class="wa-check">
            <h3 class="wa-check__title"><?= e(Contracts::PLANS[$key] ?? $key) ?></h3>
            <ul class="wa-check__list">
                <?php foreach ($items as $item): ?>
                    <li><?= e($item) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
</section>
