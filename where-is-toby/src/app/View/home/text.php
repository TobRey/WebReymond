<?php use App\Core\View; ?>
<article class="document">
    <h1><?= View::e($heading) ?></h1>
    <div class="document__body">
        <?php foreach (preg_split('~\n{2,}~', (string)$body) ?: [] as $paragraph): ?>
            <p><?= nl2br(View::e($paragraph)) ?></p>
        <?php endforeach; ?>
    </div>
    <p class="hint">
        Hinweis: Diese Anwendung speichert ausschliesslich Daten, die fuer den Spielstand noetig sind.
        Es werden keine Tracker, keine Werbe-Cookies und keine externen Schriftarten geladen.
        Das Sitzungs-Cookie ist technisch notwendig. Konten koennen jederzeit im Bereich
        <a href="<?= View::url('/konto') ?>">Konto</a> vollstaendig geloescht werden.
    </p>
</article>
