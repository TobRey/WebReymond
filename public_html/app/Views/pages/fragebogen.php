<?php
/**
 * Der Fragebogen für den Kunden.
 *
 * Eine eigenständige Seite mit eigenem Stylesheet – kein Zusammenhang
 * mit dem 3D-Auftritt. Wer hier ist, will ausfüllen und wieder weg,
 * nicht bewundern. Ausserdem lädt so nichts nach: Das Formular
 * erscheint sofort, auch auf einem alten Telefon im Zug.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $row @var array $fields @var array $values @var array $errors @var bool $done */

$marke = (string) Config::get('mail.from_name', 'WebAtze');
$danke = isset($_GET['danke']) || $done;

$wert = static fn (string $name): string => (string) ($values[$name] ?? '');
$fehler = static fn (string $name): string => (string) ($errors[$name] ?? '');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ein paar Fragen zu Ihrer Website</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<style>
* { box-sizing: border-box; }
body {
    margin: 0; padding: 0 1.25rem 6rem;
    font: 1rem/1.65 system-ui, -apple-system, "Segoe UI", sans-serif;
    color: #17181c; background: #fbfbfd;
}
.wrap { max-width: 40rem; margin: 0 auto; }
header { padding: 3.5rem 0 1.5rem; }
h1 { font-size: clamp(1.6rem, 5vw, 2.2rem); margin: 0 0 .5rem; letter-spacing: -.02em; }
.lead { color: #5a5b6a; margin: 0; max-width: 34rem; }
.feld { margin: 2rem 0 0; }
label { display: block; font-weight: 600; margin-bottom: .35rem; }
.hint { display: block; font-weight: 400; color: #6b6c7b; font-size: .9rem; margin-top: .15rem; }
input, textarea, select {
    width: 100%; padding: .7rem .85rem; font: inherit; color: inherit;
    background: #fff; border: 1px solid #cfd0da; border-radius: .45rem;
}
input:focus-visible, textarea:focus-visible, select:focus-visible {
    outline: 2px solid #2b1b9e; outline-offset: 1px; border-color: #2b1b9e;
}
textarea { min-height: 7rem; resize: vertical; }
.fehler { color: #b91c1c; font-size: .9rem; margin-top: .3rem; }
[aria-invalid="true"] { border-color: #b91c1c; }
.pflicht { color: #b91c1c; }
button {
    margin-top: 2.5rem; padding: .85rem 1.6rem; font: inherit; font-weight: 600;
    color: #fff; background: #2b1b9e; border: 0; border-radius: .45rem; cursor: pointer;
}
button:hover { background: #22157e; }
button:focus-visible { outline: 2px solid #17c8c8; outline-offset: 2px; }
.falle { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
.box {
    background: #fff; border: 1px solid #e4e4ec; border-left: 4px solid #17c8c8;
    border-radius: .5rem; padding: 1.1rem 1.3rem; margin: 2rem 0;
}
.box p:first-child { margin-top: 0; }
.box p:last-child { margin-bottom: 0; }
footer { margin-top: 3rem; color: #7b7c8b; font-size: .9rem; }
@media (prefers-color-scheme: dark) {
    body { background: #101116; color: #e8e8ef; }
    .lead, .hint, footer { color: #9b9caa; }
    input, textarea, select { background: #171822; border-color: #33343f; }
    .box { background: #171822; border-color: #2a2b36; }
}
</style>
</head>
<body>
<div class="wrap">

<?php if ($danke): ?>

    <header>
        <h1>Danke – das war alles.</h1>
        <p class="lead">
            Ihre Angaben sind angekommen. Ich melde mich, sobald ich etwas zu
            zeigen habe.
        </p>
    </header>

    <div class="box">
        <p>
            Ist Ihnen noch etwas eingefallen? Schreiben Sie es mir einfach – der
            Fragebogen ist kein Vertrag, sondern ein Anfang.
        </p>
    </div>

<?php else: ?>

    <header>
        <h1>Ein paar Fragen zu Ihrer Website</h1>
        <p class="lead">
            Damit die Website zu Ihnen passt, brauche ich ein paar Angaben. Es
            dauert etwa zehn Minuten. Was Sie nicht wissen, lassen Sie leer –
            das lässt sich später nachtragen.
        </p>
    </header>

    <?php if ($errors !== []): ?>
        <div class="box" role="alert" style="border-left-color:#b91c1c">
            <p>Ein paar Angaben fehlen noch. Sie sind unten rot markiert.</p>
        </div>
    <?php endif; ?>

    <form method="post" action="/fragebogen/<?= e((string) $row['token']) ?>" novalidate>
        <?= Csrf::field() ?>

        <?php /* Die Falle für Maschinen. Ein Mensch sieht dieses Feld nie. */ ?>
        <div class="falle" aria-hidden="true">
            <label for="website">Website</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <?php foreach ($fields as $field): ?>
            <?php
            $name = (string) $field['name'];
            $type = (string) ($field['type'] ?? 'text');
            $err = $fehler($name);
            $invalid = $err !== '' ? ' aria-invalid="true"' : '';
            ?>
            <div class="feld">
                <label for="f-<?= e($name) ?>">
                    <?= e((string) $field['label']) ?>
                    <?php if (!empty($field['required'])): ?>
                        <span class="pflicht" title="Wird gebraucht">*</span>
                    <?php endif; ?>
                    <?php if (!empty($field['hint'])): ?>
                        <span class="hint"><?= e((string) $field['hint']) ?></span>
                    <?php endif; ?>
                </label>

                <?php if ($type === 'textarea'): ?>
                    <textarea id="f-<?= e($name) ?>" name="<?= e($name) ?>"
                              maxlength="4000"<?= $invalid ?>><?= e($wert($name)) ?></textarea>

                <?php elseif ($type === 'choice'): ?>
                    <select id="f-<?= e($name) ?>" name="<?= e($name) ?>"<?= $invalid ?>>
                        <option value="">Bitte wählen</option>
                        <?php foreach ((array) ($field['options'] ?? []) as $key => $label): ?>
                            <option value="<?= e((string) $key) ?>"
                                <?= $wert($name) === (string) $key ? ' selected' : '' ?>>
                                <?= e((string) $label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                <?php else: ?>
                    <input type="<?= $type === 'email' ? 'email' : 'text' ?>"
                           id="f-<?= e($name) ?>" name="<?= e($name) ?>"
                           maxlength="300" value="<?= e($wert($name)) ?>"<?= $invalid ?>>
                <?php endif; ?>

                <?php if ($err !== ''): ?>
                    <p class="fehler"><?= e($err) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <button type="submit">Angaben abschicken</button>
    </form>

    <div class="box">
        <p>
            Ihre Angaben gehen an mich und an niemanden sonst. Sie liegen auf
            meinem Server, nicht bei einem fremden Dienst, und werden gelöscht,
            sobald Ihre Website steht.
        </p>
    </div>

<?php endif; ?>

<footer><?= e($marke) ?></footer>
</div>
</body>
</html>
