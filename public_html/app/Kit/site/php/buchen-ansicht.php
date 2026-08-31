<?php

/**
 * Die Oberfläche der Terminbuchung.
 *
 * Getrennt von der Rechnerei, damit beides für sich lesbar bleibt. Die
 * Gestaltung kommt aus dem Stylesheet der Website – die Buchung soll
 * wie ein Teil der Seite aussehen, nicht wie ein fremdes Werkzeug.
 *
 * Ohne JavaScript: Die Wahl von Leistung und Tag lädt die Seite neu.
 * Das ist ein Klick mehr und dafür ein Formular, das immer funktioniert.
 */

declare(strict_types=1);

defined('GUARD') || exit;

/** @var string $brand @var array $services @var int $serviceIndex @var array $service */
/** @var string $day @var array $slots @var array $tage @var array $errors @var bool $done */
/** @var array $setup */

$title = (string) ($setup['titel'] ?? 'Termin buchen');
$lead = (string) ($setup['einleitung'] ?? '');

http_response_code($errors === [] ? 200 : 422);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, follow');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · <?= h($brand) ?></title>
<meta name="description" content="Termin bei <?= h($brand) ?> buchen.">
<link rel="stylesheet" href="assets/css/site.css">
<link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
<style>
.b-wrap { max-width: 46rem; margin: 0 auto; padding: 3rem 1.25rem 6rem; }
.b-step { margin: 2.5rem 0 0; }
.b-step__nr {
    display: inline-block; min-width: 1.6rem; margin-right: .5rem;
    font-variant-numeric: tabular-nums; opacity: .5;
}
.b-choices { display: flex; flex-wrap: wrap; gap: .5rem; margin: .8rem 0 0; padding: 0; list-style: none; }
.b-choice {
    display: inline-block; padding: .55rem .95rem; border-radius: .5rem;
    border: 1px solid currentColor; text-decoration: none; font-size: .95rem;
    opacity: .72;
}
.b-choice:hover, .b-choice:focus-visible { opacity: 1; }
.b-choice.is-active { opacity: 1; font-weight: 600; }
.b-slots { display: flex; flex-wrap: wrap; gap: .45rem; margin: .8rem 0 0; }
.b-slot { position: relative; }
.b-slot input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.b-slot span {
    display: block; padding: .5rem .8rem; border: 1px solid currentColor;
    border-radius: .45rem; font-variant-numeric: tabular-nums; opacity: .72;
}
.b-slot input:checked + span { opacity: 1; font-weight: 700; }
.b-slot input:focus-visible + span { outline: 2px solid currentColor; outline-offset: 2px; }
.b-fields { display: grid; gap: 1rem; margin: .8rem 0 0; }
@media (min-width: 34rem) { .b-fields { grid-template-columns: 1fr 1fr; } }
.b-fields label { display: block; font-weight: 600; margin-bottom: .3rem; }
.b-fields input, .b-fields textarea {
    width: 100%; padding: .7rem .85rem; font: inherit; color: inherit;
    background: transparent; border: 1px solid currentColor; border-radius: .45rem;
}
.b-wide { grid-column: 1 / -1; }
.b-falle { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
.b-error, .b-ok {
    padding: 1rem 1.2rem; border-radius: .5rem; border: 1px solid currentColor;
    margin: 1.5rem 0;
}
.b-error ul { margin: .4rem 0 0; padding-left: 1.2rem; }
.b-summe { margin: 1.5rem 0 0; padding: 1rem 1.2rem; border-radius: .5rem; border: 1px dashed currentColor; }
</style>
</head>
<body>

<div class="b-wrap">

<?php if ($done): ?>

    <h1><?= h('Ihr Termin ist eingetragen') ?></h1>
    <div class="b-ok">
        <p>
            Wir haben Ihnen eine Bestätigung geschickt. Kommt sie nicht an, sehen Sie
            bitte im Spam-Ordner nach – der Termin steht trotzdem.
        </p>
        <p>
            Passt es doch nicht? Antworten Sie einfach auf die Bestätigung, dann
            verschieben wir ihn.
        </p>
    </div>
    <p><a href="index.html">Zurück zur Startseite</a></p>

<?php else: ?>

    <h1><?= h($title) ?></h1>
    <?php if ($lead !== ''): ?>
        <p><?= h($lead) ?></p>
    <?php endif; ?>

    <?php if ($errors !== []): ?>
        <div class="b-error" role="alert">
            <strong>Das hat noch nicht geklappt:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php /* Schritt 1: Leistung ------------------------------------ */ ?>
    <?php if (count($services) > 1): ?>
        <section class="b-step">
            <h2><span class="b-step__nr">1</span>Was darf es sein?</h2>
            <ul class="b-choices">
                <?php foreach ($services as $i => $option): ?>
                    <li>
                        <a class="b-choice<?= $i === $serviceIndex ? ' is-active' : '' ?>"
                           href="?leistung=<?= (int) $i ?><?= $day !== '' ? '&amp;tag=' . h($day) : '' ?>">
                            <?= h((string) ($option['name'] ?? 'Termin')) ?>
                            <small>· <?= (int) ($option['dauer'] ?? 30) ?> Min.</small>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php /* Schritt 2: Tag ----------------------------------------- */ ?>
    <section class="b-step">
        <h2>
            <span class="b-step__nr"><?= count($services) > 1 ? '2' : '1' ?></span>
            An welchem Tag?
        </h2>

        <?php if ($tage === []): ?>
            <p>Zurzeit sind keine Termine buchbar. Bitte melden Sie sich telefonisch.</p>
        <?php else: ?>
            <ul class="b-choices">
                <?php foreach ($tage as $option): ?>
                    <li>
                        <a class="b-choice<?= $option === $day ? ' is-active' : '' ?>"
                           href="?leistung=<?= (int) $serviceIndex ?>&amp;tag=<?= h($option) ?>#zeiten">
                            <?= h(datum($option)) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php /* Schritt 3: Zeit und Angaben ------------------------------ */ ?>
    <?php if ($day !== ''): ?>
        <form method="post" action="buchen.php" id="zeiten">
            <input type="hidden" name="leistung" value="<?= (int) $serviceIndex ?>">
            <input type="hidden" name="tag" value="<?= h($day) ?>">
            <input type="hidden" name="gestartet" value="<?= (int) time() ?>">

            <div class="b-falle" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <section class="b-step">
                <h2>
                    <span class="b-step__nr"><?= count($services) > 1 ? '3' : '2' ?></span>
                    Um wie viel Uhr?
                </h2>

                <?php if ($slots === []): ?>
                    <p>
                        An diesem Tag ist nichts mehr frei. Wählen Sie oben einen anderen Tag.
                    </p>
                <?php else: ?>
                    <div class="b-slots">
                        <?php foreach ($slots as $i => $slot): ?>
                            <label class="b-slot">
                                <input type="radio" name="zeit" value="<?= h($slot) ?>"
                                       <?= $i === 0 ? 'checked' : '' ?> required>
                                <span><?= h($slot) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($slots !== []): ?>
                <section class="b-step">
                    <h2>
                        <span class="b-step__nr"><?= count($services) > 1 ? '4' : '3' ?></span>
                        Wer kommt?
                    </h2>

                    <div class="b-fields">
                        <div>
                            <label for="b-name">Name</label>
                            <input type="text" id="b-name" name="name" maxlength="120" required
                                   autocomplete="name"
                                   value="<?= h((string) ($_POST['name'] ?? '')) ?>">
                        </div>
                        <div>
                            <label for="b-email">E-Mail</label>
                            <input type="email" id="b-email" name="email" maxlength="190" required
                                   autocomplete="email"
                                   value="<?= h((string) ($_POST['email'] ?? '')) ?>">
                        </div>
                        <div>
                            <label for="b-tel">Telefon<?= empty($setup['telefon_pflicht']) ? ' (freiwillig)' : '' ?></label>
                            <input type="tel" id="b-tel" name="telefon" maxlength="60"
                                   autocomplete="tel"
                                   value="<?= h((string) ($_POST['telefon'] ?? '')) ?>">
                        </div>
                        <div class="b-wide">
                            <label for="b-note">Möchten Sie uns noch etwas mitteilen?</label>
                            <textarea id="b-note" name="nachricht" rows="3" maxlength="2000"><?= h((string) ($_POST['nachricht'] ?? '')) ?></textarea>
                        </div>
                    </div>

                    <div class="b-summe">
                        <strong><?= h((string) ($service['name'] ?? 'Termin')) ?></strong><br>
                        <?= h(datum($day)) ?>, Dauer etwa
                        <?= (int) ($service['dauer'] ?? 30) ?> Minuten
                    </div>

                    <p style="margin-top:1.5rem">
                        <button class="s-btn" type="submit">Termin verbindlich buchen</button>
                    </p>

                    <p style="opacity:.7;font-size:.9rem">
                        Ihre Angaben bleiben auf diesem Server und gehen an niemanden sonst.
                        Sie bekommen sofort eine Bestätigung per E-Mail.
                    </p>
                </section>
            <?php endif; ?>
        </form>
    <?php endif; ?>

<?php endif; ?>

</div>
</body>
</html>
