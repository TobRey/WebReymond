<?php
/** Besucherzahlen. Grundlage sind die selbst gezählten Aufrufe. */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rt_require_login();

$dir = RT_PRIVATE . '/stats';
$files = @glob($dir . '/*.php');
$months = array();
if (is_array($files)) {
    foreach ($files as $f) {
        $name = basename($f, '.php');
        if (preg_match('/^\d{4}-\d{2}$/', $name)) { $months[] = $name; }
    }
}
rsort($months);
if (!$months) { $months = array(date('Y-m')); }

$month = isset($_GET['m']) ? (string) $_GET['m'] : $months[0];
if (!preg_match('/^\d{4}-\d{2}$/', $month) || !in_array($month, $months, true)) {
    $month = $months[0];
}

$data = rt_read($dir . '/' . $month . '.php', array());
ksort($data);

$byDay   = array();
$byPage  = array();
$totalV  = 0;
$totalS  = 0;

foreach ($data as $day => $pages) {
    if (!is_array($pages)) { continue; }
    $dv = 0; $ds = 0;
    foreach ($pages as $page => $counts) {
        $v = (int) ($counts['v'] ?? 0);
        $s = (int) ($counts['s'] ?? 0);
        $dv += $v; $ds += $s;
        if (!isset($byPage[$page])) { $byPage[$page] = array('v' => 0, 's' => 0); }
        $byPage[$page]['v'] += $v;
        $byPage[$page]['s'] += $s;
    }
    $byDay[$day] = array('v' => $dv, 's' => $ds);
    $totalV += $dv;
    $totalS += $ds;
}

uasort($byPage, function ($a, $b) { return $b['v'] <=> $a['v']; });
$maxDay  = $byDay ? max(array_map(function ($d) { return $d['v']; }, $byDay)) : 0;
$maxPage = $byPage ? max(array_map(function ($p) { return $p['v']; }, $byPage)) : 0;

function rt_pct($value, $max)
{
    if ($max <= 0) { return 0; }
    return max(2, (int) round(($value / $max) * 100));
}

/** Monat und Wochentag auf Deutsch – ohne Abhaengigkeit von Servereinstellungen. */
function rt_monat($ym)
{
    $namen = array('Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
                   'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember');
    $teile = explode('-', $ym);
    $nr = isset($teile[1]) ? (int) $teile[1] : 1;
    $nr = ($nr >= 1 && $nr <= 12) ? $nr : 1;
    return $namen[$nr - 1] . ' ' . (isset($teile[0]) ? $teile[0] : '');
}

function rt_wochentag($datum)
{
    $kurz = array('So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa');
    $ts = strtotime($datum);
    return $kurz[(int) date('w', $ts)] . ' ' . date('d.m.', $ts);
}

rt_admin_head('Zahlen', 'stats');
?>

<div class="a-head">
  <div>
    <h1>Besucherzahlen</h1>
    <p>Selbst gezählt, ohne fremden Dienst und ohne IP-Adressen.</p>
  </div>
  <form method="get" action="stats.php" class="a-row">
    <label for="m">Monat</label>
    <select id="m" name="m" style="width:auto">
      <?php foreach ($months as $m): ?>
        <option value="<?php echo rt_h($m); ?>"<?php echo $m === $month ? ' selected' : ''; ?>>
          <?php echo rt_h(rt_monat($m)); ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn--small" type="submit">Anzeigen</button>
  </form>
</div>

<div class="a-grid a-grid--3">
  <div class="a-card">
    <h2 style="font-size:1rem">Seitenaufrufe</h2>
    <p style="font-size:2.2rem;font-weight:750;margin:.3rem 0 0"><?php echo number_format($totalV, 0, '.', "'"); ?></p>
    <p class="a-muted">im gewählten Monat</p>
  </div>
  <div class="a-card">
    <h2 style="font-size:1rem">Besuche</h2>
    <p style="font-size:2.2rem;font-weight:750;margin:.3rem 0 0"><?php echo number_format($totalS, 0, '.', "'"); ?></p>
    <p class="a-muted">ein Besuch = ein Browser-Tab, eine Sitzung</p>
  </div>
  <div class="a-card">
    <h2 style="font-size:1rem">Tage mit Aufrufen</h2>
    <p style="font-size:2.2rem;font-weight:750;margin:.3rem 0 0"><?php echo count($byDay); ?></p>
    <p class="a-muted">von <?php echo (int) date('t', strtotime($month . '-01')); ?> Tagen</p>
  </div>
</div>

<div class="a-card" style="margin-top:1.4rem">
  <h2>Tag für Tag</h2>
  <?php if (!$byDay): ?>
    <p class="a-muted">Für diesen Monat liegen noch keine Zahlen vor.</p>
  <?php else: ?>
    <div style="margin-top:1.2rem">
      <?php foreach ($byDay as $day => $c): ?>
        <div class="a-bar">
          <span class="a-bar__label"><?php echo rt_h(rt_wochentag($day)); ?></span>
          <span class="a-bar__track">
            <span class="a-bar__fill" style="width: <?php echo rt_pct($c['v'], $maxDay); ?>%"></span>
          </span>
          <span class="a-bar__value"><?php echo (int) $c['v']; ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="a-muted" style="margin-top:1rem">Die Zahl rechts sind die Seitenaufrufe des Tages.</p>
  <?php endif; ?>
</div>

<div class="a-card">
  <h2>Welche Seiten</h2>
  <?php if (!$byPage): ?>
    <p class="a-muted">Noch nichts gezählt.</p>
  <?php else: ?>
    <div class="table-wrap" style="margin-top:1.2rem">
      <table>
        <caption class="visually-hidden">Aufrufe je Seite im Monat <?php echo rt_h($month); ?></caption>
        <thead>
          <tr>
            <th scope="col">Adresse</th>
            <th scope="col" style="text-align:right">Aufrufe</th>
            <th scope="col" style="text-align:right">Besuche</th>
            <th scope="col" style="width:35%">Anteil</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($byPage, 0, 30, true) as $page => $c): ?>
          <tr>
            <th scope="row" class="a-mono" style="font-weight:400"><?php echo rt_h($page); ?></th>
            <td style="text-align:right"><?php echo (int) $c['v']; ?></td>
            <td style="text-align:right"><?php echo (int) $c['s']; ?></td>
            <td>
              <span class="a-bar__track" style="display:block;height:.8rem">
                <span class="a-bar__fill" style="display:block;width: <?php echo rt_pct($c['v'], $maxPage); ?>%"></span>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="a-card">
  <h2>Was hier nicht steht</h2>
  <p class="a-muted">
    Es gibt keine Herkunftsländer, keine Geräte, keine Suchbegriffe und keine Wiedererkennung
    einzelner Personen. Gespeichert werden nur Datum, Adresse und zwei Zahlen. Das ist bewusst so:
    Damit braucht die Website kein Zustimmungsbanner. Wer mehr sehen will, müsste einen Dienst
    einbinden – und genau das war hier nicht gewollt.
  </p>
</div>

<?php rt_admin_foot(); ?>
