<?php
use SkyKingdoms\Core\Url;
use SkyKingdoms\Core\View;
?>
<?= View::render('partials/brand') ?>
<div class="sk-card">
    <div class="sk-alert sk-alert--info"><span>Neue Registrierungen sind derzeit geschlossen.</span></div>
    <a class="sk-btn sk-btn--block" href="<?= e(Url::to('?p=login')) ?>">Zur Anmeldung</a>
</div>
