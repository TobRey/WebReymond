<?php use App\Core\View; ?>
<div data-admin-page="diagnostics">
    <div class="toolbar">
        <button class="btn btn--primary" data-action="diag-run">Diagnose erneut ausfuehren</button>
        <button class="btn" data-action="diag-deep">Mit Belastungstest (paralleles Schreiben)</button>
        <span class="hint" data-role="diag-status"></span>
    </div>
    <div data-role="diag-result">
        <?php foreach ($result['groups'] as $group): ?>
            <section class="panel-box">
                <h2><?= View::e($group['title']) ?></h2>
                <div class="check-list">
                    <?php foreach ($group['checks'] as $check): ?>
                        <div class="check-row">
                            <span class="status-dot status-<?= View::e($check['status']) ?>"></span>
                            <span><?= View::e($check['name']) ?></span>
                            <span><?= View::e($check['message']) ?>
                                <?php if (($check['fix'] ?? '') !== '' && $check['status'] !== 'ok'): ?>
                                    <span class="fix"><br>Loesung: <?= View::e($check['fix']) ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</div>
