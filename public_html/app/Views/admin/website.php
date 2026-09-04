<?php

/**
 * Eine hinzugefügte Website.
 *
 * Bewusst schlicht: Was WebAtze nicht selbst gebaut hat, hat keine
 * Abschnitte, keine Pakete und keinen Auftrag. Was bleibt, ist das, was
 * beim Betreuen zählt – wem sie gehört, wo sie liegt, womit sie gebaut
 * ist, und ob sie läuft.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Websites;

/** @var array<string, mixed>|null $website */
/** @var array<int, array<string, mixed>> $kunden */
/** @var array<string, string> $status */
/** @var array<int, string> $plattformen */
/** @var array<string, mixed>|null $waechter */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$neu = $website === null;
$wert = static fn (string $feld, string $ersatz = ''): string =>
    $website === null ? $ersatz : (string) ($website[$feld] ?? '');
$adresse = $website !== null ? Websites::url($website) : '';
?>

<?php if (!$neu && $waechter !== null): ?>
    <div class="wa-tiles">
        <div class="wa-tile<?= (int) $waechter['last_ok'] === 1 ? '' : ' wa-tile--warn' ?>">
            <span class="wa-tile__label">Zustand</span>
            <strong class="wa-tile__value">
                <?= (string) ($waechter['last_checked_at'] ?? '') === ''
                    ? 'noch nicht geprüft'
                    : ((int) $waechter['last_ok'] === 1 ? 'läuft' : 'Ausfall') ?>
            </strong>
        </div>
        <div class="wa-tile">
            <span class="wa-tile__label">Antwortzeit</span>
            <strong class="wa-tile__value"><?= (int) $waechter['last_ms'] ?> ms</strong>
        </div>
        <div class="wa-tile">
            <span class="wa-tile__label">Überwachung</span>
            <strong class="wa-tile__value">
                <a href="<?= e($base) ?>/wartung/<?= (int) $waechter['id'] ?>">ansehen</a>
            </strong>
        </div>
    </div>
<?php endif; ?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">
            <?= $neu ? 'Website hinzufügen' : e((string) $website['name']) ?>
        </h2>
        <?php if ($adresse !== ''): ?>
            <a class="wa-btn wa-btn--small" href="<?= e($adresse) ?>"
               target="_blank" rel="noopener noreferrer">Website öffnen</a>
        <?php endif; ?>
    </header>

    <?php if ($neu): ?>
        <p class="wa-panel__hint">
            Für eine Website, die es schon gibt – von jemand anderem gebaut oder früher
            selbst gemacht. Sie erscheint danach in derselben Liste wie die von WebAtze
            gebauten und lässt sich genauso einem Kunden zuordnen, überwachen und abrechnen.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e($base) ?>/websites" class="wa-form wa-form--inline">
        <?= Csrf::field() ?>
        <?php if (!$neu): ?>
            <input type="hidden" name="website_id" value="<?= (int) $website['id'] ?>">
        <?php endif; ?>

        <div class="wa-field">
            <label class="wa-label" for="w-name">Name</label>
            <input class="wa-input" type="text" id="w-name" name="name" required
                   value="<?= e($wert('name')) ?>" placeholder="z.B. Holzbau Steiner AG">
        </div>

        <div class="wa-field">
            <label class="wa-label" for="w-domain">Adresse</label>
            <input class="wa-input" type="text" id="w-domain" name="domain"
                   value="<?= e($wert('domain')) ?>" placeholder="steiner.ch">
        </div>

        <div class="wa-field">
            <label class="wa-label" for="w-customer">Kunde</label>
            <select class="wa-select" id="w-customer" name="customer_id">
                <option value="0">– keiner –</option>
                <?php foreach ($kunden as $k): ?>
                    <option value="<?= (int) $k['id'] ?>"
                        <?= (int) ($website['customer_id'] ?? 0) === (int) $k['id'] ? ' selected' : '' ?>>
                        <?= e((string) $k['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="w-status">Zustand</label>
            <select class="wa-select" id="w-status" name="status">
                <?php foreach ($status as $schluessel => $text): ?>
                    <?php if (in_array($schluessel, ['building', 'failed'], true) && $neu) { continue; } ?>
                    <option value="<?= e($schluessel) ?>"
                        <?= $wert('status', 'live') === $schluessel ? ' selected' : '' ?>>
                        <?= e($text) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="w-platform">Womit gebaut</label>
            <input class="wa-input" type="text" id="w-platform" name="platform" list="plattformen"
                   value="<?= e($wert('platform')) ?>" placeholder="z.B. WordPress">
            <datalist id="plattformen">
                <?php foreach ($plattformen as $p): ?>
                    <option value="<?= e($p) ?>">
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="wa-field">
            <label class="wa-label" for="w-hosting">Wo gehostet</label>
            <input class="wa-input" type="text" id="w-hosting" name="hosting"
                   value="<?= e($wert('hosting')) ?>" placeholder="z.B. Hostpoint">
        </div>

        <div class="wa-field">
            <label class="wa-label" for="w-since">Online seit</label>
            <input class="wa-input" type="date" id="w-since" name="live_since"
                   value="<?= e($wert('live_since')) ?>">
        </div>

        <div class="wa-field wa-field--wide">
            <label class="wa-label" for="w-notes">Notizen</label>
            <textarea class="wa-input wa-textarea" rows="3" id="w-notes"
                      name="notes"><?= e($wert('notes')) ?></textarea>
        </div>

        <?php /*
            Die Ueberwachung ist keine Entscheidung: Eine Website, von
            der niemand merkt, dass sie steht, nuetzt niemandem. Die
            Zaehlung dagegen schon - sie braucht eine Zeile auf der
            fremden Website, und die setzt man nicht ungefragt.
        */ ?>
        <div class="wa-field wa-field--wide">
            <label class="wa-check-line">
                <input type="checkbox" name="zaehlen" value="1"
                       <?= $neu
                            ? ''
                            : (\WebAtze\Domain\Visits::counts((int) $website['id']) ? 'checked' : '') ?>>
                <span>Besucher zählen</span>
            </label>
            <p class="wa-hint">
                Erzeugt eine Zählzeile zum Einsetzen und lässt die Website unter
                «Besucher» erscheinen. Ohne IP-Adresse, ohne Cookie – deshalb
                braucht die Website dafür kein Zustimmungsbanner. Ausschalten nimmt
                nur die Zählung weg; die bisherigen Zahlen bleiben stehen.
            </p>
        </div>

        <?php if ($waechter === null): ?>
            <div class="wa-field wa-field--wide">
                <p class="wa-hint">
                    <strong>Ohnehin dabei:</strong> Sobald eine Domain eingetragen ist,
                    wird die Adresse alle 15 Minuten aufgerufen; bei einem Ausfall kommt
                    eine E-Mail. Das lässt sich im Wartungscenter jederzeit ändern.
                </p>
            </div>
        <?php endif; ?>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">
                <?= $neu ? 'Hinzufügen' : 'Speichern' ?>
            </button>
        </div>
    </form>
</section>

<?php if (!$neu): ?>
    <?php $einzeiler = \WebAtze\Domain\Visits::counts((int) $website['id'])
        ? \WebAtze\Domain\Visits::snippet((int) $website['id'])
        : ''; ?>
    <?php if ($einzeiler !== ''): ?>
        <section class="wa-panel">
            <header class="wa-panel__head">
                <h2 class="wa-panel__title">Besucher zählen</h2>
            </header>

            <p class="wa-panel__hint">
                Diese Zeile vor <code>&lt;/body&gt;</code> einsetzen – in WordPress, Wix,
                Jimdo oder einer von Hand gebauten Seite, das ist gleich. Danach erscheinen
                die Zahlen unter <a href="<?= e($base) ?>/zahlen">Besucher</a>. Sie lädt
                nichts nach, setzt kein Cookie und speichert keine IP-Adresse – deshalb
                braucht die Website dafür kein Zustimmungsbanner.
            </p>

            <div class="wa-copybox">
                <input class="wa-input" type="text" readonly
                       value="<?= e($einzeiler) ?>"
                       onclick="this.select()"
                       aria-label="Zählzeile zum Kopieren">
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php if (!$neu): ?>
<section class="wa-panel wa-panel--danger">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Aus der Liste entfernen</h2>
    </header>

    <p class="wa-panel__hint">
        Entfernt nur den Eintrag hier. Die Website selbst bleibt, wo sie ist – WebAtze hat
        keinen Zugriff darauf. Eine bestehende Überwachung bleibt ebenfalls stehen und lässt
        sich im Wartungscenter beenden.
    </p>

    <form method="post" action="<?= e($base) ?>/websites/entfernen"
          data-confirm="Diesen Eintrag entfernen?">
        <?= Csrf::field() ?>
        <input type="hidden" name="website_id" value="<?= (int) $website['id'] ?>">
        <button type="submit" class="wa-btn">Eintrag entfernen</button>
    </form>
</section>
<?php endif; ?>
