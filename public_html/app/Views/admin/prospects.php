<?php

/**
 * Kundensuche: eine Firma, eine Entscheidung.
 *
 * Bewusst nur eine Karte auf einmal. Wer zwanzig Firmen nebeneinander
 * sieht, vergleicht sie – und entscheidet sich am Ende für keine.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\{Prospects, SearchOrder};

/** @var array<string, mixed>|null $firma */
/** @var int $wartend */
/** @var array<string, int> $zahlen */
/** @var string $google */
/** @var string $karte */
/** @var string $auftrag */
/** @var array<string, mixed> $filter */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>

<div class="wa-tiles">
    <div class="wa-tile">
        <span class="wa-tile__label">Im Stapel</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['neu'] ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Vorgemerkt</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['liste'] ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Daraus geworden</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['kunde'] ?></strong>
    </div>
    <div class="wa-tile">
        <span class="wa-tile__label">Weggelegt</span>
        <strong class="wa-tile__value"><?= (int) $zahlen['abgelehnt'] ?></strong>
    </div>
</div>

<?php if ($firma !== null): ?>
    <?php
        $punkte = (int) $firma['score'];
        $zustand = Prospects::SITE_STATES[(string) $firma['site_state']] ?? '';
    ?>
    <section class="wa-card-stack">
        <article class="wa-lead">
            <header class="wa-lead__head">
                <div>
                    <h2 class="wa-lead__name"><?= e((string) $firma['name']) ?></h2>
                    <p class="wa-lead__where">
                        <?= e(trim((string) $firma['branch'] . ' · ' . (string) $firma['place'], ' ·')) ?>
                    </p>
                </div>
                <?php if ($punkte > 0): ?>
                    <span class="wa-score" title="Wie sehr sich der Anruf lohnt">
                        <?= $punkte ?><small>/100</small>
                    </span>
                <?php endif; ?>
            </header>

            <?php if ($zustand !== ''): ?>
                <p class="wa-lead__state"><?= e($zustand) ?></p>
            <?php endif; ?>

            <?php if (trim((string) $firma['reason']) !== ''): ?>
                <p class="wa-lead__reason"><?= e((string) $firma['reason']) ?></p>
            <?php endif; ?>

            <dl class="wa-lead__facts">
                <?php if ((string) $firma['website'] !== ''): ?>
                    <div><dt>Website</dt><dd>
                        <a href="<?= e((string) $firma['website']) ?>" target="_blank"
                           rel="noopener noreferrer nofollow"><?= e((string) $firma['website']) ?></a>
                    </dd></div>
                <?php endif; ?>
                <?php if ((string) $firma['phone'] !== ''): ?>
                    <div><dt>Telefon</dt><dd>
                        <a href="tel:<?= e(preg_replace('/[^\d+]/', '', (string) $firma['phone']) ?? '') ?>">
                            <?= e((string) $firma['phone']) ?>
                        </a>
                    </dd></div>
                <?php endif; ?>
                <?php if ((string) $firma['email'] !== ''): ?>
                    <div><dt>E-Mail</dt><dd>
                        <a href="mailto:<?= e((string) $firma['email']) ?>"><?= e((string) $firma['email']) ?></a>
                    </dd></div>
                <?php endif; ?>
                <?php if ((string) $firma['contact_name'] !== ''): ?>
                    <div><dt>Ansprechperson</dt><dd><?= e((string) $firma['contact_name']) ?></dd></div>
                <?php endif; ?>
                <?php if ((string) $firma['address'] !== ''): ?>
                    <div><dt>Adresse</dt><dd><?= e((string) $firma['address']) ?></dd></div>
                <?php endif; ?>
            </dl>

            <?php if (trim((string) $firma['research']) !== ''): ?>
                <div class="wa-lead__research">
                    <?php foreach (preg_split('/\R+/', (string) $firma['research']) ?: [] as $zeile): ?>
                        <?php if (trim($zeile) !== ''): ?>
                            <p><?= e(trim($zeile)) ?></p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="wa-lead__links">
                <a class="wa-btn wa-btn--small" href="<?= e($google) ?>"
                   target="_blank" rel="noopener noreferrer nofollow">Firma googlen</a>
                <a class="wa-btn wa-btn--small" href="<?= e($karte) ?>"
                   target="_blank" rel="noopener noreferrer nofollow">Auf der Karte</a>
                <?php if ((string) $firma['website'] !== ''): ?>
                    <a class="wa-btn wa-btn--small" href="<?= e((string) $firma['website']) ?>"
                       target="_blank" rel="noopener noreferrer nofollow">Website ansehen</a>
                <?php endif; ?>
            </div>

            <footer class="wa-lead__decide">
                <form method="post" action="<?= e($base) ?>/kundensuche/entscheiden">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="prospect_id" value="<?= (int) $firma['id'] ?>">
                    <button type="submit" name="wahl" value="nein" class="wa-swipe wa-swipe--no">
                        Weglegen
                    </button>
                    <button type="submit" name="wahl" value="nie" class="wa-swipe wa-swipe--never"
                            title="Diese Firma taucht auch bei künftigen Suchen nicht mehr auf">
                        Nie wieder
                    </button>
                    <button type="submit" name="wahl" value="ja" class="wa-swipe wa-swipe--yes">
                        Als potenziellen Kunden eintragen
                    </button>
                </form>
            </footer>
        </article>

        <p class="wa-panel__hint">
            Noch <?= (int) $wartend ?> <?= $wartend === 1 ? 'Firma' : 'Firmen' ?> im Stapel.
            <a href="<?= e($base) ?>/potenzielle-kunden">Zur Liste der vorgemerkten</a>
        </p>
    </section>
<?php else: ?>
    <section class="wa-panel">
        <p class="wa-empty">
            Der Stapel ist leer. Unten den Suchauftrag holen, einfügen, wo eine KI mit
            Websuche läuft – und die Antwort hier wieder einsetzen.
        </p>
    </section>
<?php endif; ?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <h2 class="wa-panel__title">Nachschub holen</h2>
    </header>

    <p class="wa-panel__hint">
        Dieser Server durchsucht Google nicht selbst – das wäre gegen die Nutzungsbedingungen
        und würde an manchen Tagen einfach eine Sperrseite liefern. Stattdessen schreibt
        WebAtze den Auftrag, du lässt ihn dort laufen, wo eine KI mit Websuche zur Verfügung
        steht, und fügst die Antwort hier wieder ein. Das kostet nichts und liefert bessere
        Ergebnisse.
    </p>

    <form method="post" action="<?= e($base) ?>/kundensuche/auftrag" class="wa-form wa-form--inline">
        <?= Csrf::field() ?>

        <div class="wa-field">
            <label class="wa-label" for="s-region">Gegend</label>
            <input class="wa-input" type="text" id="s-region" name="region"
                   value="<?= e((string) $filter['region']) ?>"
                   placeholder="z.B. im Kanton Zürich">
        </div>

        <div class="wa-field">
            <label class="wa-label" for="s-branch">Branche</label>
            <input class="wa-input" type="text" id="s-branch" name="branch"
                   value="<?= e((string) $filter['branch']) ?>"
                   placeholder="z.B. Handwerk, Gastronomie – oder leer für alle">
        </div>

        <div class="wa-field">
            <label class="wa-label" for="s-count">Wie viele</label>
            <input class="wa-input wa-input--short" type="number" id="s-count" name="count"
                   min="1" max="25" value="<?= (int) $filter['count'] ?>">
        </div>

        <fieldset class="wa-field wa-field--wide">
            <legend class="wa-label">Worauf es ankommt</legend>
            <div class="wa-checks">
                <?php foreach (SearchOrder::CRITERIA as $wert => $text): ?>
                    <label class="wa-check-line">
                        <input type="checkbox" name="criteria[]" value="<?= e($wert) ?>"
                            <?= in_array($wert, (array) $filter['criteria'], true) ? 'checked' : '' ?>>
                        <span><?= e($text) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="wa-field wa-field--wide">
            <label class="wa-label" for="s-extra">Noch etwas?</label>
            <input class="wa-input" type="text" id="s-extra" name="extra"
                   value="<?= e((string) $filter['extra']) ?>"
                   placeholder="z.B. keine Ketten, höchstens 20 Mitarbeitende">
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn">Auftrag aktualisieren</button>
        </div>
    </form>

    <div class="wa-panel__actions">
        <button type="button" class="wa-btn wa-btn--primary"
                data-copy="#suchauftrag"
                data-copy-done="Kopiert – jetzt einfügen">
            Suchauftrag kopieren
        </button>
        <span class="wa-hint"><?= number_format(mb_strlen($auftrag), 0, '.', "'") ?> Zeichen</span>
    </div>

    <textarea id="suchauftrag" class="wa-prompt" rows="16" readonly
              aria-label="Der Suchauftrag zum Kopieren"><?= e($auftrag) ?></textarea>

    <form method="post" action="<?= e($base) ?>/kundensuche/einlesen" class="wa-form">
        <?= Csrf::field() ?>

        <div class="wa-field wa-field--wide">
            <label class="wa-label" for="antwort">Antwort hier einsetzen</label>
            <textarea class="wa-input wa-textarea" id="antwort" name="antwort" rows="6"
                      placeholder="Alles von der ersten eckigen Klammer bis zur letzten"></textarea>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Firmen einlesen</button>
        </div>
    </form>
</section>
