<?php
/** @var array $summary @var array $perProject @var array $perMonth @var int $total @var string $month */
use WebAtze\Core\Config;
$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$usd = static fn (int $micro): string => '$' . number_format($micro / 1e6, 2);
?>

<div class="wa-stats">
    <div class="wa-stat">
        <span class="wa-stat__value"><?= e($usd((int) ($summary['cost'] ?? 0))) ?></span>
        <span class="wa-stat__label">diesen Monat</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value"><?= e($usd($total)) ?></span>
        <span class="wa-stat__label">insgesamt</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value"><?= number_format((int) ($summary['calls'] ?? 0)) ?></span>
        <span class="wa-stat__label">Anfragen diesen Monat</span>
    </div>
    <div class="wa-stat">
        <span class="wa-stat__value"><?= number_format((int) ($summary['output_tokens'] ?? 0)) ?></span>
        <span class="wa-stat__label">erzeugte Tokens</span>
    </div>
</div>

<div class="wa-note">
    <div>
        <strong>Wie die Beträge zustande kommen</strong>
        Sie werden aus den gemeldeten Tokenzahlen und den Listenpreisen von Anthropic berechnet
        und sind in US-Dollar angegeben. Die tatsächliche Abrechnung steht im Anthropic-Konto;
        kleine Abweichungen sind möglich. Zwischengespeicherte Eingaben kosten nur einen Bruchteil –
        das senkt die Kosten bei jeder weiteren Seite desselben Projekts spürbar.
    </div>
</div>

<section class="wa-panel">
    <div class="wa-panel__head"><h2 class="wa-panel__title">Nach Projekt</h2></div>
    <?php if ($perProject === []): ?>
        <div class="wa-empty-state"><p>Noch keine KI-Anfragen.</p></div>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead><tr><th>Projekt</th><th>Anfragen</th><th>Kosten</th></tr></thead>
                <tbody>
                <?php foreach ($perProject as $row): ?>
                    <tr>
                        <td><a href="<?= e($base) ?>/projekt/<?= (int) $row['id'] ?>"><?= e((string) $row['name']) ?></a></td>
                        <td><?= number_format((int) $row['calls']) ?></td>
                        <td><?= e($usd((int) $row['cost'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($perMonth !== []): ?>
<section class="wa-panel">
    <div class="wa-panel__head"><h2 class="wa-panel__title">Nach Monat</h2></div>
    <div class="wa-table-wrap">
        <table class="wa-table">
            <thead><tr><th>Monat</th><th>Anfragen</th><th>Kosten</th></tr></thead>
            <tbody>
            <?php foreach ($perMonth as $row): ?>
                <tr>
                    <td><?= e((string) $row['month']) ?></td>
                    <td><?= number_format((int) $row['calls']) ?></td>
                    <td><?= e($usd((int) $row['cost'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
