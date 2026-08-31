<?php defined('WEBATZE') || exit; ?>
<?php
/**
 * Frühere Stände.
 *
 * Vor jeder Änderung wird der bisherige Stand weggelegt. Wer sich
 * vertippt oder etwas versehentlich löscht, holt ihn mit einem Klick
 * zurück – ohne jemanden fragen zu müssen.
 */

/** @var \WebAtzeKit\Store $store @var string $base */

use WebAtzeKit\Csrf;

$backups = $store->backups();
?>

<h1 class="k-h1">Frühere Stände</h1>

<p class="k-lede">
    Vor jeder Änderung wird der bisherige Stand gesichert. Zurückholen heisst: Die Website
    sieht danach wieder genau so aus wie zu diesem Zeitpunkt. Der aktuelle Stand wird dabei
    ebenfalls gesichert – auch das lässt sich also rückgängig machen.
</p>

<?php if ($backups === []): ?>
    <p class="k-note">Noch keine Sicherungen. Die erste entsteht mit der ersten Änderung.</p>
<?php else: ?>
    <div class="k-card">
        <table class="k-table">
            <thead><tr><th>Zeitpunkt</th><th>Grösse</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($backups as $backup): ?>
                <tr>
                    <td><?= e(date('d.m.Y H:i:s', $backup['time'])) ?></td>
                    <td><?= number_format($backup['bytes'] / 1024, 1, ',', "'") ?> KB</td>
                    <td class="k-right">
                        <?php /* Die Nachfrage hängt an data-confirm, nicht an einem
                                 onsubmit: Zeilenskripte sind durch die
                                 Content-Security-Policy gesperrt und würden
                                 wirkungslos verpuffen. */ ?>
                        <form method="post" action="<?= e($base) ?>"
                              data-confirm="Diesen Stand zurückholen? Die jetzigen Änderungen werden vorher gesichert.">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="backup" value="<?= e($backup['name']) ?>">
                            <input type="hidden" name="_back" value="<?= e($base) ?>?ansicht=sicherungen">
                            <button class="k-btn k-btn--quiet k-btn--sm" type="submit">Zurückholen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
