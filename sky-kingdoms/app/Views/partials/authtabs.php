<?php
/** @var string $active */
use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Url;
?>
<div class="sk-tabs">
    <a href="<?= e(Url::to('?p=login')) ?>" class="<?= $active === 'login' ? 'is-active' : '' ?>">Anmelden</a>
    <?php if (App::config('registration_open', true)): ?>
        <a href="<?= e(Url::to('?p=register')) ?>" class="<?= $active === 'register' ? 'is-active' : '' ?>">Registrieren</a>
    <?php endif; ?>
</div>
