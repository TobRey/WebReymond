<?php
/**
 * Frontend-Fix: die eigene Website per Textbefehl anpassen.
 *
 * Ein Feld, ein Satz, und die Änderung ist auf der Seite. Darunter die
 * Liste dessen, was gilt – jede Zeile einzeln abschaltbar. Das ist der
 * eigentliche Sicherheitsgurt: Was schiefgeht, ist mit einem Klick
 * wieder weg.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array<int, array<string, mixed>> $anpassungen */
/** @var int $aktiv */
/** @var bool $bereit */
/** @var string $letzter */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>

<p class="wa-intro">
    Schreib in einem Satz, was anders aussehen soll. Die Änderung ist danach
    sofort auf der Website – ohne Hochladen, ohne Dateimanager.
</p>

<?php if (!$bereit): ?>
    <div class="wa-note wa-note--danger">
        <div>
            <strong>Es ist kein Schlüssel für die Schnittstelle hinterlegt.</strong>
            <p class="wa-hint">
                Er gehört in <code>app/config.php</code> unter
                <code>anthropic.api_key</code>. Ohne ihn nimmt dieses Feld nichts
                entgegen.
            </p>
        </div>
    </div>
<?php endif; ?>

<section class="wa-panel wa-panel--neu">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Was soll anders sein?</h2>
    </div>

    <form method="post" action="<?= e($base) ?>/frontend-fix" class="wa-form">
        <?= Csrf::field() ?>

        <div class="wa-field">
            <label class="wa-label" for="befehl">Dein Befehl</label>
            <textarea class="wa-input wa-textarea" id="befehl" name="befehl" rows="3"
                      maxlength="2000" required minlength="8"
                      placeholder="z.B. Mach den Abschnitt Leistungen mit einem dunkleren Hintergrund"
                      <?= $bereit ? '' : 'disabled' ?>><?= e($letzter) ?></textarea>
            <p class="wa-hint">
                Je genauer, desto besser: welcher Abschnitt, was daran. «Die Karten
                bei den Leistungen sollen mehr Abstand haben» ist brauchbar,
                «schöner machen» nicht.
            </p>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary" <?= $bereit ? '' : 'disabled' ?>>
                Ändern
            </button>
            <a class="wa-btn wa-btn--quiet" href="/" target="_blank" rel="noopener">
                Website ansehen
            </a>
        </div>
    </form>

    <?php /*
        Ehrlich gesagt, was nicht geht - sonst probiert er es dreimal
        und glaubt, es sei kaputt.
    */ ?>
    <div class="wa-note">
        <div>
            <strong>Was hier geht und was nicht.</strong>
            <p class="wa-hint">
                <strong>Geht:</strong> Farben, Hintergründe, Abstände, Schriftgrössen,
                Rahmen, Schatten, Ecken, etwas ein- oder ausblenden, Reihenfolge
                innerhalb eines Abschnitts.
                <br>
                <strong>Geht nicht:</strong> neue Texte, neue Abschnitte, neue Seiten,
                andere Bilder. Das sichtbare Frontend ist ein gebautes Paket – dafür
                braucht es den Weg über den Quelltext.
            </p>
        </div>
    </div>
</section>

<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">
            Was gerade gilt
            <?php if ($aktiv > 0): ?>
                <span class="wa-badge wa-badge--ok"><?= (int) $aktiv ?> aktiv</span>
            <?php endif; ?>
        </h2>
        <?php if ($anpassungen !== []): ?>
            <div class="wa-panel__actions">
                <form method="post" action="<?= e($base) ?>/frontend-fix/zuruecksetzen"
                      data-confirm="Wirklich alle Anpassungen entfernen? Die Website sieht danach wieder aus wie gebaut.">
                    <?= Csrf::field() ?>
                    <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm wa-btn--danger">
                        Alles zurücksetzen
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($anpassungen === []): ?>
        <p class="wa-empty">
            Noch nichts geändert. Die Website sieht aus, wie sie gebaut wurde.
        </p>
    <?php else: ?>
        <?php foreach ($anpassungen as $a): ?>
            <?php $an = (int) $a['active'] === 1; ?>
            <article class="wa-panel wa-fix<?= $an ? '' : ' is-aus' ?>">
                <div class="wa-panel__head">
                    <h3 class="wa-panel__title">
                        <?= e((string) $a['summary']) ?>
                    </h3>
                    <p class="wa-panel__hint">
                        <?= e(date('d.m.Y, H:i', strtotime((string) $a['created_at']) ?: time())) ?>
                        <?php if (!$an): ?>
                            · <span class="wa-badge">abgeschaltet</span>
                        <?php endif; ?>
                    </p>
                    <div class="wa-panel__actions">
                        <form method="post" action="<?= e($base) ?>/frontend-fix/umschalten">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="fix_id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="aktiv" value="<?= $an ? '0' : '1' ?>">
                            <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm">
                                <?= $an ? 'Abschalten' : 'Einschalten' ?>
                            </button>
                        </form>
                        <form method="post" action="<?= e($base) ?>/frontend-fix/loeschen"
                              data-confirm="Diese Anpassung löschen?">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="fix_id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="wa-btn wa-btn--quiet wa-btn--sm wa-btn--danger">
                                Löschen
                            </button>
                        </form>
                    </div>
                </div>

                <p class="wa-fix__befehl">
                    <span class="wa-hint">Dein Befehl war:</span>
                    <?= e((string) $a['prompt']) ?>
                </p>

                <details class="wa-fix__code">
                    <summary>Was daraus wurde</summary>
                    <pre class="wa-code"><code><?= e((string) $a['css']) ?></code></pre>
                </details>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
