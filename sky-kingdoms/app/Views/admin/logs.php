<?php
/** @var array $entries @var array $days @var string $day @var int $offset */
use SkyKingdoms\Core\Url;
?>
<div class="sk-card">
    <h2>Protokolle</h2>
    <p class="sk-muted">Jede administrative Änderung und jede verdächtige Aktion wird hier festgehalten.</p>

    <form method="get" action="<?= e(Url::to('admin/')) ?>" class="sk-row" style="margin-bottom:14px">
        <input type="hidden" name="p" value="logs">
        <select name="tag" class="sk-grow" style="min-height:48px;border-radius:12px;border:1.5px solid rgba(16,26,46,.12);padding:0 12px">
            <?php foreach ($days as $available): ?>
                <option value="<?= e($available) ?>" <?= $available === $day ? 'selected' : '' ?>><?= e($available) ?></option>
            <?php endforeach; ?>
            <?php if ($days === []): ?><option value="<?= e($day) ?>"><?= e($day) ?></option><?php endif; ?>
        </select>
        <button class="sk-btn sk-btn--small" type="submit">Anzeigen</button>
    </form>

    <table class="sk-table sk-table--cards">
        <thead><tr><th>Zeit</th><th>Aktion</th><th>Beschreibung</th><th>Konto</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <tr<?= str_starts_with((string) $entry['action'], 'security.') ? ' style="background:color-mix(in srgb,var(--sk-danger) 12%,#fff)"' : '' ?>>
                <td data-label="Zeit"><?= e(date('H:i:s', (int) $entry['ts'])) ?></td>
                <td data-label="Aktion"><code><?= e((string) $entry['action']) ?></code></td>
                <td data-label="Beschreibung"><?= e((string) $entry['message']) ?>
                    <?php if (!empty($entry['context'])): ?>
                        <div class="sk-muted" style="font-size:.78rem"><?= e(json_encode($entry['context'], JSON_UNESCAPED_UNICODE)) ?></div>
                    <?php endif; ?>
                </td>
                <td data-label="Konto"><?= e((string) ($entry['actor'] ?? '–')) ?></td>
                <td data-label="IP"><?= e((string) ($entry['ip'] ?? '–')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($entries === []): ?>
            <tr><td colspan="5" class="sk-muted">Keine Einträge für diesen Tag.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <div class="sk-row" style="justify-content:space-between;margin-top:14px">
        <?php if ($offset > 0): ?>
            <a class="sk-btn sk-btn--small" href="<?= e(Url::to('admin/?p=logs&tag=' . $day . '&offset=' . max(0, $offset - 60))) ?>">Neuere</a>
        <?php else: ?><span></span><?php endif; ?>
        <?php if (count($entries) >= 60): ?>
            <a class="sk-btn sk-btn--small" href="<?= e(Url::to('admin/?p=logs&tag=' . $day . '&offset=' . ($offset + 60))) ?>">Ältere</a>
        <?php else: ?><span></span><?php endif; ?>
    </div>
</div>
