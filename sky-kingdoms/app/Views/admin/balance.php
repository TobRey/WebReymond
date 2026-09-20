<?php
/** @var array $balance @var array $overrides */
use SkyKingdoms\Core\Csrf;
use SkyKingdoms\Core\Url;

$flat = [];
$walk = static function (array $data, string $prefix = '') use (&$walk, &$flat): void {
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $walk($value, $path);
        } elseif (is_numeric($value)) {
            $flat[$path] = $value;
        }
    }
};
$walk($balance);
ksort($flat);
?>
<div class="sk-card">
    <h2>Balancewerte</h2>
    <p class="sk-muted">Alle Zahlen des Spiels. Änderungen wirken sofort und werden protokolliert.
        Die Vorgaben stehen in <code>config/balance.php</code>; hier gesetzte Werte überschreiben sie.</p>

    <form method="post" action="<?= e(Url::to('admin/?p=balance')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="balance">
        <div class="sk-row">
            <div class="sk-field sk-grow" style="margin:0"><label for="path">Pfad</label>
                <input id="path" name="path" list="sk-paths" placeholder="buildings.lumberjack.produces.wood" required></div>
            <div class="sk-field" style="margin:0;min-width:120px"><label for="value">Wert</label>
                <input id="value" name="value" required inputmode="decimal"></div>
        </div>
        <datalist id="sk-paths">
            <?php foreach (array_slice(array_keys($flat), 0, 900) as $path): ?>
                <option value="<?= e($path) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <button class="sk-btn sk-btn--small" type="submit" style="margin-top:12px">Wert setzen</button>
    </form>
</div>

<?php if ($overrides !== []): ?>
<div class="sk-card" style="margin-top:16px">
    <h2>Eigene Werte</h2>
    <p class="sk-muted">Diese Werte weichen von den Vorgaben ab (gespeichert in
        <code>storage/data/meta/balance.json</code>).</p>
    <pre style="overflow:auto;background:#f0f3f9;padding:12px;border-radius:12px;font-size:.82rem"><?= e(json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php endif; ?>

<div class="sk-card" style="margin-top:16px">
    <h2>Alle Werte</h2>
    <table class="sk-table sk-table--cards">
        <thead><tr><th>Pfad</th><th>Wert</th></tr></thead>
        <tbody>
        <?php $shown = 0; foreach ($flat as $path => $value): if ($shown++ > 400) { break; } ?>
            <tr><td data-label="Pfad"><code style="font-size:.8rem"><?= e($path) ?></code></td>
                <td data-label="Wert"><?= e((string) $value) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (count($flat) > 400): ?>
        <p class="sk-muted">… und <?= e((string) (count($flat) - 400)) ?> weitere. Nutze das Feld oben mit Autovervollständigung.</p>
    <?php endif; ?>
</div>
