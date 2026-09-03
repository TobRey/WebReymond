<?php
/**
 * Intranet: Beiträge, die nur nach der Anmeldung sichtbar sind.
 * Sie stehen nirgends auf der öffentlichen Website.
 */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rt_require_login();

define('RT_INTRANET', RT_PRIVATE . '/intranet.php');

$posts = rt_read(RT_INTRANET, array());
if (!is_array($posts)) { $posts = array(); }

if (rt_require_post_token()) {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $id     = isset($_POST['id']) ? preg_replace('/[^a-f0-9]/', '', (string) $_POST['id']) : '';

    if ($action === 'delete' && $id !== '') {
        $before = count($posts);
        $posts = array_values(array_filter($posts, function ($p) use ($id) {
            return ($p['id'] ?? '') !== $id;
        }));
        if (count($posts) < $before) {
            rt_backup(RT_INTRANET, 'intranet');
            rt_write(RT_INTRANET, $posts);
            rt_flash('ok', 'Beitrag gelöscht.');
        }
        rt_redirect('intranet.php');
    }

    if ($action === 'save') {
        $title = rt_clean($_POST['title'] ?? '', 120);
        $body  = rt_clean($_POST['body'] ?? '', 20000, true);
        $pin   = isset($_POST['pinned']) && $_POST['pinned'] === '1';

        if ($title === '' && $body === '') {
            rt_flash('bad', 'Titel und Text sind beide leer – da gibt es nichts zu speichern.');
            rt_redirect('intranet.php');
        }
        if ($title === '') { $title = 'Ohne Titel'; }

        $now = date('c');
        $found = false;
        foreach ($posts as $i => $p) {
            if (($p['id'] ?? '') === $id && $id !== '') {
                $posts[$i]['title']   = $title;
                $posts[$i]['body']    = $body;
                $posts[$i]['pinned']  = $pin;
                $posts[$i]['updated'] = $now;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $posts[] = array(
                'id'      => bin2hex(random_bytes(8)),
                'title'   => $title,
                'body'    => $body,
                'pinned'  => $pin,
                'created' => $now,
                'updated' => $now,
            );
        }

        rt_backup(RT_INTRANET, 'intranet');
        if (rt_write(RT_INTRANET, $posts)) {
            rt_flash('ok', $found ? 'Beitrag geändert.' : 'Beitrag gespeichert.');
        } else {
            rt_flash('bad', 'Speichern nicht möglich. Bitte die Schreibrechte auf data/private prüfen.');
        }
        rt_redirect('intranet.php');
    }
}

/* Sortieren: angeheftete zuerst, danach die neuesten. */
usort($posts, function ($a, $b) {
    $pa = !empty($a['pinned']) ? 1 : 0;
    $pb = !empty($b['pinned']) ? 1 : 0;
    if ($pa !== $pb) { return $pb - $pa; }
    return strcmp((string) ($b['updated'] ?? ''), (string) ($a['updated'] ?? ''));
});

/* Suche */
$q = rt_clean($_GET['q'] ?? '', 60);
if ($q !== '') {
    $needle = rt_lower($q);
    $posts = array_values(array_filter($posts, function ($p) use ($needle) {
        return strpos(rt_lower(($p['title'] ?? '') . ' ' . ($p['body'] ?? '')), $needle) !== false;
    }));
}

/* Bearbeiten? */
$editId = isset($_GET['edit']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['edit']) : '';
$edit = null;
if ($editId !== '') {
    foreach ($posts as $p) {
        if (($p['id'] ?? '') === $editId) { $edit = $p; break; }
    }
}

rt_admin_head('Intranet', 'intranet');
?>

<div class="a-head">
  <div>
    <h1>Intranet</h1>
    <p>Deine Beiträge und Notizen. Sichtbar nur, solange du angemeldet bist – auf der Website steht davon nichts.</p>
  </div>
  <form method="get" action="intranet.php" class="a-row">
    <label class="visually-hidden" for="q">Beiträge durchsuchen</label>
    <input type="search" id="q" name="q" value="<?php echo rt_h($q); ?>" placeholder="Suchen …" style="width:14rem">
    <button class="btn btn--small" type="submit">Suchen</button>
    <?php if ($q !== ''): ?><a class="btn btn--small" href="intranet.php">Zurücksetzen</a><?php endif; ?>
  </form>
</div>

<div class="a-card">
  <h2><?php echo $edit ? 'Beitrag bearbeiten' : 'Neuer Beitrag'; ?></h2>
  <form method="post" action="intranet.php">
    <?php echo rt_csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo rt_h($edit['id'] ?? ''); ?>">

    <div class="a-field">
      <label for="title">Titel</label>
      <input type="text" id="title" name="title" maxlength="120" value="<?php echo rt_h($edit['title'] ?? ''); ?>">
    </div>

    <div class="a-field">
      <label for="body">Text</label>
      <textarea id="body" name="body" class="tall" maxlength="20000"><?php echo rt_h($edit['body'] ?? ''); ?></textarea>
      <span class="hint">Absätze und Zeilenumbrüche bleiben erhalten.</span>
    </div>

    <div class="a-field">
      <label class="check">
        <input type="checkbox" name="pinned" value="1" <?php echo !empty($edit['pinned']) ? 'checked' : ''; ?>>
        <span>Oben anheften</span>
      </label>
    </div>

    <div class="a-row">
      <button class="btn btn--primary" type="submit"><?php echo $edit ? 'Änderung speichern' : 'Beitrag speichern'; ?></button>
      <?php if ($edit): ?><a class="btn" href="intranet.php">Abbrechen</a><?php endif; ?>
    </div>
  </form>
</div>

<h2 style="margin-top:2.5rem">
  <?php echo $q !== '' ? 'Treffer' : 'Alle Beiträge'; ?>
  <span class="a-muted" style="font-weight:400">(<?php echo count($posts); ?>)</span>
</h2>

<?php if (!$posts): ?>
  <div class="a-card">
    <p class="a-muted" style="margin:0">
      <?php echo $q !== '' ? 'Zu dieser Suche gibt es nichts.' : 'Noch nichts vorhanden. Schreib oben den ersten Beitrag.'; ?>
    </p>
  </div>
<?php else: ?>
  <ul class="a-list" style="margin-top:1rem">
    <?php foreach ($posts as $p): ?>
      <li class="a-entry">
        <div class="a-spread">
          <div style="min-width:0;flex:1">
            <h3>
              <?php echo rt_h($p['title'] ?? ''); ?>
              <?php if (!empty($p['pinned'])): ?><span class="a-tag a-tag--warn">angeheftet</span><?php endif; ?>
            </h3>
            <p class="a-entry__meta">
              angelegt <?php echo rt_h(date('d.m.Y H:i', strtotime((string) ($p['created'] ?? 'now')))); ?>
              <?php if (($p['updated'] ?? '') !== ($p['created'] ?? '')): ?>
                · geändert <?php echo rt_h(date('d.m.Y H:i', strtotime((string) $p['updated']))); ?>
              <?php endif; ?>
            </p>
          </div>
        </div>

        <p class="a-entry__body"><?php echo rt_h($p['body'] ?? ''); ?></p>

        <div class="a-row">
          <a class="btn btn--small" href="intranet.php?edit=<?php echo rt_h($p['id'] ?? ''); ?>">Bearbeiten</a>
          <form method="post" action="intranet.php" class="a-inline"
                data-confirm="Diesen Beitrag wirklich löschen?">
            <?php echo rt_csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo rt_h($p['id'] ?? ''); ?>">
            <button class="btn btn--danger btn--small" type="submit">Löschen</button>
          </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php rt_admin_foot(); ?>
