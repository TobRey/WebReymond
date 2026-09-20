<?php
/** @var int $step */
$labels = [1 => 'Prüfung', 2 => 'Einstellungen', 3 => 'Administrator', 4 => 'Übersicht', 5 => 'Fertig'];
?>
<div class="sk-steps" role="list" aria-label="Installationsschritte">
    <?php foreach ($labels as $number => $label): ?>
        <span role="listitem"
              class="<?= $number === $step ? 'is-active' : ($number < $step ? 'is-done' : '') ?>"
              title="<?= e($label) ?>"><?= $number < $step ? '✓' : $number ?></span>
    <?php endforeach; ?>
</div>
