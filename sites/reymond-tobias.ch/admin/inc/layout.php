<?php
/**
 * Gemeinsamer Rahmen aller Seiten im Bearbeitungsbereich.
 */

function rt_admin_head($title, $active = '', $narrow = false)
{
    $nonce = $GLOBALS['rt_nonce'];
    $nav = array(
        'index'    => array('Übersicht',   'index.php'),
        'content'  => array('Texte',       'content.php'),
        'media'    => array('Bilder',      'media.php'),
        'intranet' => array('Intranet',    'intranet.php'),
        'vault'    => array('Passwörter',  'vault.php'),
        'stats'    => array('Zahlen',      'stats.php'),
        'password' => array('Mein Passwort', 'password.php'),
    );
    ?><!DOCTYPE html>
<html lang="de-CH">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?php echo rt_h($title); ?> — Bearbeitungsbereich</title>
<link rel="icon" href="../favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="admin">
<a class="skip-link" href="#a-inhalt">Zum Inhalt springen</a>

<header class="a-top">
  <div class="a-top__inner">
    <a class="a-brand" href="index.php">
      <span class="brand__mark" aria-hidden="true">RT</span>
      <span>Bearbeitungsbereich</span>
    </a>
    <div class="a-top__right">
      <a class="a-link" href="../" target="_blank" rel="noopener">Website ansehen<span class="visually-hidden"> (öffnet in neuem Tab)</span></a>
      <?php if (!empty($_SESSION['auth'])): ?>
        <form method="post" action="logout.php" class="a-inline">
          <?php echo rt_csrf_field(); ?>
          <button class="btn" type="submit">Abmelden</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php if (!empty($_SESSION['auth'])): ?>
<nav class="a-nav" aria-label="Bereiche">
  <ul>
    <?php foreach ($nav as $key => $item): ?>
      <li><a href="<?php echo rt_h($item[1]); ?>"<?php echo $active === $key ? ' aria-current="page"' : ''; ?>><?php echo rt_h($item[0]); ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
<?php endif; ?>

<main id="a-inhalt" class="a-main<?php echo $narrow ? ' a-main--narrow' : ''; ?>">
<?php
    $flash = rt_flash();
    if ($flash) {
        echo '<div class="notice notice--' . ($flash['kind'] === 'ok' ? 'ok' : 'bad') . '" role="status">'
           . rt_h($flash['text']) . '</div>';
    }
}

function rt_admin_foot()
{
    $nonce = $GLOBALS['rt_nonce'];
    ?>
</main>

<footer class="a-foot">
  <p>Reymond Tobias · reymond-tobias.ch — dieser Bereich ist nicht öffentlich und wird von Suchmaschinen nicht erfasst.</p>
</footer>

<script nonce="<?php echo rt_h($nonce); ?>" src="assets/admin.js"></script>
</body>
</html><?php
}
