<?php use App\Core\View; ?>
<section class="hero">
    <div class="hero__text">
        <p class="eyebrow">Federal Bureau of Investigation &middot; Field Terminal</p>
        <h1>WHERE IS TOBY?</h1>
        <p class="lead">
            Ein 17-Jaehriger verschwindet an einem Freitagabend aus seinem Zimmer. Seine Eltern widersprechen sich,
            seine Freunde erzaehlen drei verschiedene Geschichten - und auf seinem Rechner liegen Notizen zu drei
            alten Vermisstenfaellen, die niemand geloest hat.
        </p>
        <p class="lead">
            Du uebernimmst die Ermittlung: befrage Zeugen in freien Gespraechen, entsperre Geraete, rekonstruiere
            geloeschte Dateien, vergleiche Zeitstempel und stelle eine eigene Falltheorie auf. Es gibt keinen
            vorgegebenen Klickweg. Nur Beweise.
        </p>
        <div class="hero__actions">
            <a class="btn btn--primary" href="<?= View::url('/login') ?>">Anmelden</a>
            <?php if (!empty($allowRegister)): ?>
                <a class="btn" href="<?= View::url('/registrieren') ?>">Konto anlegen</a>
            <?php endif; ?>
            <?php if (!empty($allowGuests)): ?>
                <form method="post" action="<?= View::url('/gast') ?>" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
                    <button class="btn btn--ghost" type="submit">Als Gast starten</button>
                </form>
            <?php endif; ?>
        </div>
        <p class="warning-box">
            <strong>Inhaltswarnung &middot; ab 18 Jahren.</strong>
            Der Fall enthaelt Gewaltdarstellungen, Schilderungen von Toetungsdelikten, psychologischen Horror,
            Blut, Bedrohung von Jugendlichen und beklemmende Szenen. Alle Inhalte sind fiktiv.
            Simulierte Computer, Handys und Logins sind Spielelemente ohne Bezug zu realen Systemen.
        </p>
    </div>
    <div class="hero__card">
        <div class="case-card case-card--static">
            <div class="case-card__stamp">AKTE OFFEN</div>
            <img src="<?= View::asset('img/scenes/case-cover-toby.svg') ?>" alt="Aktendeckel des Falls Toby Brennan">
            <div class="case-card__body">
                <h2>Fall WIT-2024-1011</h2>
                <p>Tobias &bdquo;Toby&ldquo; Brennan, 17<br>Vermisst seit Fr, 11.10.2024, 23:14 Uhr<br>Millbrook, Vermont</p>
            </div>
        </div>
    </div>
</section>

<section class="features">
    <article><h3>Freie KI-Verhoere</h3><p>Schreibe oder sprich, was du willst. Die Figuren antworten im eigenen Stil, luegen, weichen aus - und brechen ab, wenn du zu hart wirst.</p></article>
    <article><h3>Echte Geraetearbeit</h3><p>Handys mit PIN und Muster, Laptops mit Passwoertern, geloeschte Dateien, EXIF-Daten, Ueberwachungsvideos und Audioanalyse.</p></article>
    <article><h3>Ermittlungswand</h3><p>Ziehe Personen, Orte und Beweise auf die Wand und verbinde sie. Richtige Verbindungen oeffnen neue Wege - kommentarlos.</p></article>
    <article><h3>Offline spielbar</h3><p>Ohne API-Schluessel uebernimmt ein regelbasiertes Dialogsystem. Der Fall bleibt vollstaendig loesbar.</p></article>
</section>
