<?php
use SkyKingdoms\Core\Url;
?>
<div class="sk-brand">
    <img class="sk-brand__logo" src="<?= e(Url::asset('img/logo.svg')) ?>" alt="">
    <h1 class="sk-brand__name">Bereits installiert</h1>
</div>

<div class="sk-card">
    <div class="sk-alert sk-alert--info">
        <span>Dieses Spiel ist bereits eingerichtet. Der Installationsassistent ist deshalb gesperrt.</span>
    </div>
    <p>Möchtest du wirklich neu installieren, lösche die Datei <code>config/config.php</code>.
        <strong>Achtung:</strong> Die Spielstände in <code>storage/data</code> bleiben dabei erhalten und passen
        danach möglicherweise nicht mehr zum neuen Schlüssel – sichere sie vorher.</p>
    <a class="sk-btn sk-btn--block" href="<?= e(Url::to('')) ?>">Zum Spiel</a>
</div>
