<?php
/** @var string|null $subtitle */
use SkyKingdoms\Core\App;
use SkyKingdoms\Core\Url;

$logo = (string) App::config('logo', '');
$src  = $logo !== '' ? Url::to($logo) : Url::asset('img/logo.svg');
?>
<div class="sk-brand">
    <img class="sk-brand__logo" src="<?= e($src) ?>" alt="" width="128" height="128">
    <h1 class="sk-brand__name"><?= e(App::config('name', 'Sky Kingdoms')) ?></h1>
    <p class="sk-brand__tagline"><?= e($subtitle ?? App::config('tagline', '')) ?></p>
</div>
