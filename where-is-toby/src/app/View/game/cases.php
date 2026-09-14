<?php use App\Core\View; ?>
<section class="stack">
    <header class="section-head">
        <h1>Offene Faelle</h1>
        <p class="hint">Waehle eine Akte. Jeder Fall dauert ungefaehr 15 bis 25 Minuten.</p>
    </header>

    <?php if ($cases === []): ?>
        <div class="alert alert--warn">Derzeit ist kein Fall veroeffentlicht.</div>
    <?php endif; ?>

    <div class="case-grid">
        <?php foreach ($cases as $case): $p = $progress[$case['id']] ?? null; ?>
            <a class="case-card" href="<?= View::url('/spielen/' . $case['id']) ?>">
                <?php if (($case['status'] ?? '') !== 'published'): ?>
                    <span class="case-card__flag">Entwurf</span>
                <?php endif; ?>
                <img src="<?= View::e(View::url('/') . ltrim($case['cover'], '/')) ?>" alt="">
                <div class="case-card__body">
                    <p class="eyebrow"><?= View::e($case['code']) ?> &middot; <?= View::e($case['location']) ?></p>
                    <h2><?= View::e($case['title']) ?></h2>
                    <p><?= View::e($case['summary']) ?></p>
                    <ul class="meta">
                        <li><?= View::e($case['difficulty']) ?></li>
                        <li><?= View::e($case['duration']) ?></li>
                        <li><?= (int)$case['counts']['npcs'] ?> Personen</li>
                        <li><?= (int)$case['counts']['evidence'] ?> Beweise</li>
                    </ul>
                    <?php if ($p !== null): ?>
                        <div class="progressbar" title="Fortschritt"><span style="width: <?= (int)$p['percent'] ?>%"></span></div>
                        <p class="hint"><?= $p['completed'] ? 'Abgeschlossen &middot; Rang ' . View::e($p['rank']) : (int)$p['percent'] . ' % bearbeitet' ?></p>
                    <?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</section>
