<?php
/** Übersicht des Bearbeitungsbereichs. */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/crypto.php';
require __DIR__ . '/inc/layout.php';
rt_require_login();

/* --- Kurzer Blick auf die Zahlen der letzten sieben Tage ---------- */
$views = 0;
$visits = 0;
$statsFile = RT_PRIVATE . '/stats/' . date('Y-m') . '.php';
$stats = rt_read($statsFile, array());
$prevFile = RT_PRIVATE . '/stats/' . date('Y-m', strtotime('-1 month')) . '.php';
if (is_file($prevFile)) { $stats = array_merge(rt_read($prevFile, array()), $stats); }

for ($i = 0; $i < 7; $i++) {
    $day = date('Y-m-d', strtotime('-' . $i . ' days'));
    if (!isset($stats[$day]) || !is_array($stats[$day])) { continue; }
    foreach ($stats[$day] as $page) {
        $views  += (int) ($page['v'] ?? 0);
        $visits += (int) ($page['s'] ?? 0);
    }
}

$posts  = rt_read(RT_PRIVATE . '/intranet.php', array());
$vault  = rt_read(RT_PRIVATE . '/vault.php', array());
$account = rt_user_load();

/* --- Zustand des Servers ----------------------------------------- */
$checks = array();
$checks[] = array(
    'PHP-Version',
    PHP_VERSION,
    PHP_VERSION_ID >= 70300 ? 'ok' : 'warn',
    PHP_VERSION_ID >= 70300 ? '' : 'Empfohlen ist PHP 7.3 oder neuer.'
);
$checks[] = array(
    'Argon2id',
    rt_argon2id_available() ? 'vorhanden' : 'fehlt',
    rt_argon2id_available() ? 'ok' : 'warn',
    rt_argon2id_available() ? '' : 'Dein Passwort ist mit bcrypt gesichert. Ebenfalls sicher, aber frag beim Hosting nach Argon2.'
);
$checks[] = array(
    'Verschlüsselung Passwortspeicher',
    function_exists('sodium_crypto_secretbox') ? 'libsodium' : (function_exists('openssl_encrypt') ? 'OpenSSL (AES-256-GCM)' : 'fehlt'),
    (function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt')) ? 'ok' : 'bad',
    (function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt')) ? '' : 'Ohne eine dieser Erweiterungen lassen sich keine Passwörter speichern.'
);
$checks[] = array(
    'Ordner data/ beschreibbar',
    is_writable(RT_DATA) ? 'ja' : 'nein',
    is_writable(RT_DATA) ? 'ok' : 'bad',
    is_writable(RT_DATA) ? '' : 'Setze die Schreibrechte auf 755 (Ordner) – sonst lässt sich nichts speichern.'
);
$checks[] = array(
    'Ordner content/ beschreibbar',
    is_writable(RT_CONTENT) ? 'ja' : 'nein',
    is_writable(RT_CONTENT) ? 'ok' : 'bad',
    is_writable(RT_CONTENT) ? '' : 'Ohne Schreibrecht lassen sich Texte und Bilder nicht ändern.'
);
$checks[] = array(
    'E-Mail-Versand (mail)',
    function_exists('mail') ? 'verfügbar' : 'nicht verfügbar',
    function_exists('mail') ? 'ok' : 'warn',
    function_exists('mail') ? '' : 'Das Kontaktformular kann dann nichts versenden. Beim Hosting nachfragen.'
);
$setupThere = is_file(__DIR__ . '/setup.php');
$checks[] = array(
    'Datei admin/setup.php',
    $setupThere ? 'liegt noch auf dem Server' : 'gelöscht',
    $setupThere ? 'warn' : 'ok',
    $setupThere ? 'Sie wird nicht mehr gebraucht. Lösch sie vom Server.' : ''
);

rt_admin_head('Übersicht', 'index');
?>

<div class="a-head">
  <div>
    <h1>Hallo <?php echo rt_h($account['user'] ?? ''); ?></h1>
    <p>Von hier aus pflegst du deine Website. Was du speicherst, ist sofort online.</p>
  </div>
  <a class="btn" href="../" target="_blank" rel="noopener">Website öffnen<span class="visually-hidden"> (öffnet in neuem Tab)</span></a>
</div>

<div class="a-grid a-grid--3">
  <div class="a-card">
    <h2 style="font-size:1rem">Letzte 7 Tage</h2>
    <p style="font-size:2rem;font-weight:750;margin:.4rem 0 0"><?php echo number_format($views, 0, '.', "'"); ?></p>
    <p class="a-muted">Seitenaufrufe · <?php echo number_format($visits, 0, '.', "'"); ?> Besuche</p>
    <p style="margin-top:1rem"><a class="a-link" href="stats.php">Zahlen ansehen →</a></p>
  </div>

  <div class="a-card">
    <h2 style="font-size:1rem">Intranet</h2>
    <p style="font-size:2rem;font-weight:750;margin:.4rem 0 0"><?php echo count($posts); ?></p>
    <p class="a-muted">Beiträge, nur für dich sichtbar</p>
    <p style="margin-top:1rem"><a class="a-link" href="intranet.php">Beiträge öffnen →</a></p>
  </div>

  <div class="a-card">
    <h2 style="font-size:1rem">Passwortspeicher</h2>
    <p style="font-size:2rem;font-weight:750;margin:.4rem 0 0"><?php echo count($vault); ?></p>
    <p class="a-muted">Einträge, verschlüsselt abgelegt</p>
    <p style="margin-top:1rem"><a class="a-link" href="vault.php">Speicher öffnen →</a></p>
  </div>
</div>

<div class="a-grid a-grid--2" style="margin-top:1.4rem">
  <div class="a-card">
    <h2>Was möchtest du tun?</h2>
    <ul class="a-list" style="margin-top:1rem">
      <li><a class="btn" href="content.php" style="width:100%">Texte der Website ändern</a></li>
      <li><a class="btn" href="media.php" style="width:100%">Bilder hochladen oder austauschen</a></li>
      <li><a class="btn" href="intranet.php" style="width:100%">Notiz im Intranet schreiben</a></li>
      <li><a class="btn" href="vault.php" style="width:100%">Zugangsdaten speichern</a></li>
    </ul>
    <p class="a-muted" style="margin-top:1.2rem">
      Neu hier? In der <a class="a-link" href="../doc.html" target="_blank" rel="noopener">Anleitung</a>
      steht Schritt für Schritt, wie alles funktioniert.
    </p>
  </div>

  <div class="a-card">
    <h2>Zustand</h2>
    <table style="margin-top:1rem">
      <caption class="visually-hidden">Technische Prüfpunkte dieser Installation</caption>
      <thead>
        <tr><th scope="col">Prüfpunkt</th><th scope="col">Zustand</th></tr>
      </thead>
      <tbody>
      <?php foreach ($checks as $c): ?>
        <tr>
          <th scope="row" style="font-weight:600"><?php echo rt_h($c[0]); ?></th>
          <td>
            <span class="a-tag a-tag--<?php echo rt_h($c[2]); ?>"><?php echo rt_h($c[1]); ?></span>
            <?php if ($c[3] !== ''): ?><br><span class="a-muted"><?php echo rt_h($c[3]); ?></span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="a-card" style="margin-top:1.4rem">
  <h2>Sicherungskopien</h2>
  <p class="a-muted">
    Vor jeder Änderung wird die vorherige Fassung gesichert. Die Kopien liegen auf dem Server
    unter <code>data/backups/</code> und sind von aussen nicht abrufbar. Es werden je Bereich die
    letzten 40 Fassungen behalten.
  </p>
  <?php
    $backups = @glob(RT_BACKUPS . '/*.php');
    $count = is_array($backups) ? count($backups) : 0;
  ?>
  <p style="margin-top:.8rem"><span class="a-tag"><?php echo (int) $count; ?> Sicherungen abgelegt</span></p>
</div>

<?php rt_admin_foot(); ?>
