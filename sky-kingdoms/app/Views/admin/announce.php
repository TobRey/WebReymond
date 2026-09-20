<?php
/** @var int $players */
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Url;
?>
<div class="sk-card">
    <h2>Ankündigung an alle</h2>
    <p class="sk-muted">Die Nachricht erscheint bei allen <?= e((string) $players) ?> Spielern
        unter „Benachrichtigungen".</p>

    <form method="post" action="<?= e(Url::to('admin/?p=announce')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="announce">
        <div class="sk-field"><label for="title">Titel</label>
            <input id="title" name="title" maxlength="80" required placeholder="Neue Insel verfügbar!"></div>
        <div class="sk-field"><label for="text">Text</label>
            <textarea id="text" name="text" rows="4" maxlength="500"
                      placeholder="Ab sofort könnt ihr die Handelsinsel freischalten."></textarea></div>
        <button class="sk-btn sk-btn--block" type="submit">An alle senden</button>
    </form>
</div>
