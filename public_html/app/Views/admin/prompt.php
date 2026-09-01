<?php

/**
 * Der fertige Auftrag zum Kopieren.
 *
 * Absichtlich schlicht: eine Schaltfläche, ein Textfeld, sonst nichts.
 * Wer hier ist, will kopieren und weitermachen.
 */

use WebAtze\Core\Csrf;

/** @var array<string, mixed> $project */
/** @var string $prompt */
/** @var string $dateiname */
/** @var string $base */

$id = (int) $project['id'];
?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <div>
            <h2 class="wa-panel__title">Auftrag für <?= e((string) $project['name']) ?></h2>
            <p class="wa-panel__hint">
                Kopieren, in Claude Code einfügen, absenden. Mehr braucht es nicht.
            </p>
        </div>

        <div class="wa-panel__actions">
            <button type="button" class="wa-btn wa-btn--primary"
                    data-copy="#auftragstext"
                    data-copy-done="Kopiert – jetzt einfügen">
                In die Zwischenablage
            </button>

            <a class="wa-btn" download="<?= e($dateiname) ?>"
               href="data:text/markdown;charset=utf-8,<?= rawurlencode($prompt) ?>">
                Als Datei
            </a>
        </div>
    </header>

    <textarea id="auftragstext" class="wa-prompt" rows="24" readonly
              spellcheck="false" aria-label="Der Auftragstext"><?= e($prompt) ?></textarea>

    <p class="wa-panel__hint">
        <?= number_format(mb_strlen($prompt), 0, ',', "'") ?> Zeichen.
        Alles aus dem Formular steht darin &ndash; wer den Text einfügt, hat
        das Formular nicht gesehen und kann nicht nachfragen.
    </p>
</section>

<section class="wa-panel">
    <h2 class="wa-panel__title">So geht es weiter</h2>

    <ol class="wa-steps">
        <li>Text kopieren und in Claude Code einfügen.</li>
        <li>Die fertigen Dateien herunterladen.</li>
        <li>
            Unter <a href="<?= e($base) ?>/projekt/<?= $id ?>/veroeffentlichen">Veröffentlichen</a>
            hochladen &ndash; oder von Hand ins Webhosting kopieren.
        </li>
    </ol>

    <p class="wa-panel__hint">
        Der Auftrag lässt sich jederzeit neu erzeugen. Änderst du etwas am
        Projekt, ändert sich auch der Text.
    </p>
</section>

<section class="wa-panel">
    <h2 class="wa-panel__title">Lieber automatisch?</h2>

    <p class="wa-panel__hint">
        WebAtze kann die Website auch selbst bauen, ohne Kopieren. Das läuft
        über die Schnittstelle, kostet Guthaben und dauert je nach Umfang
        einige Minuten.
    </p>

    <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/bauen"
          data-confirm="Die Website automatisch bauen lassen? Das kostet Guthaben.">
        <?= Csrf::field() ?>
        <button type="submit" class="wa-btn">Automatisch bauen lassen</button>
    </form>
</section>
