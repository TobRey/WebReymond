<?php
/** @var array $data @var array $errors @var array $zones */
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Url;
?>
<div class="sk-brand">
    <img class="sk-brand__logo" src="<?= e(Url::asset('img/logo.svg')) ?>" alt="">
    <h1 class="sk-brand__name">Grundeinstellungen</h1>
    <p class="sk-brand__tagline">Schritt 2 von 5</p>
</div>

<?= \SkyKingdoms\Core\View::render('partials/steps', ['step' => 2]) ?>

<div class="sk-card">
    <form method="post" action="<?= e(Url::to('install/?step=2')) ?>" novalidate>
        <?= Csrf::field() ?>

        <div class="sk-field <?= isset($errors['name']) ? 'sk-field--error' : '' ?>">
            <label for="name">Name des Spiels</label>
            <input id="name" name="name" type="text" required maxlength="40"
                   value="<?= e($data['name'] ?? '') ?>" autocomplete="off">
            <?php if (isset($errors['name'])): ?><div class="sk-field__error"><?= e($errors['name']) ?></div><?php endif; ?>
            <div class="sk-field__hint">Später jederzeit im Adminbereich oder in <code>config/game.php</code> änderbar.</div>
        </div>

        <div class="sk-field <?= isset($errors['timezone']) ? 'sk-field--error' : '' ?>">
            <label for="timezone">Zeitzone</label>
            <select id="timezone" name="timezone">
                <?php foreach ($zones as $zone): ?>
                    <option value="<?= e($zone) ?>" <?= ($data['timezone'] ?? '') === $zone ? 'selected' : '' ?>><?= e($zone) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="sk-field <?= isset($errors['offline']) ? 'sk-field--error' : '' ?>">
            <label for="offline">Maximal nachberechnete Abwesenheit (Stunden)</label>
            <input id="offline" name="offline" type="number" min="1" max="168" inputmode="numeric"
                   value="<?= e((string) ($data['offline'] ?? 24)) ?>">
            <?php if (isset($errors['offline'])): ?><div class="sk-field__error"><?= e($errors['offline']) ?></div><?php endif; ?>
            <div class="sk-field__hint">War jemand länger weg, wird höchstens dieser Zeitraum nachgerechnet. Schont den Server.</div>
        </div>

        <div class="sk-field">
            <label for="open">Registrierung</label>
            <select id="open" name="open">
                <option value="1" <?= ($data['open'] ?? '1') === '1' ? 'selected' : '' ?>>Offen – jeder kann ein Konto anlegen</option>
                <option value="0" <?= ($data['open'] ?? '1') === '0' ? 'selected' : '' ?>>Geschlossen – nur der Administrator legt Konten an</option>
            </select>
        </div>

        <button class="sk-btn sk-btn--block sk-btn--green" type="submit">Weiter zum Administratorkonto</button>
    </form>
</div>
