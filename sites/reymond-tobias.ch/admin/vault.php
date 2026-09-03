<?php
/**
 * Passwortspeicher.
 *
 * Alle Felder werden verschlüsselt abgelegt. Der Schlüssel entsteht beim
 * Anmelden aus deinem Passwort und lebt nur in der Sitzung. Ohne dein
 * Passwort ist die Datei auf dem Server nicht lesbar – auch nicht für
 * jemanden, der Zugriff darauf hätte.
 */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/crypto.php';
require __DIR__ . '/inc/layout.php';
rt_require_login();

$key = rt_session_key();
if ($key === null) {
    rt_logout();
    rt_redirect('login.php');
}

$items = rt_vault_load($key);

if (rt_require_post_token()) {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $id     = isset($_POST['id']) ? preg_replace('/[^a-f0-9]/', '', (string) $_POST['id']) : '';

    if ($action === 'delete' && $id !== '') {
        $before = count($items);
        $items = array_values(array_filter($items, function ($e) use ($id) {
            return $e['id'] !== $id;
        }));
        if (count($items) < $before && rt_vault_save($items, $key)) {
            rt_flash('ok', 'Eintrag gelöscht.');
        }
        rt_redirect('vault.php');
    }

    if ($action === 'save') {
        $entry = array(
            'title'    => rt_clean($_POST['title'] ?? '', 120),
            'username' => rt_clean($_POST['username'] ?? '', 200),
            'url'      => rt_clean($_POST['url'] ?? '', 300),
            'secret'   => rt_clean($_POST['secret'] ?? '', 500),
            'note'     => rt_clean($_POST['note'] ?? '', 5000, true),
        );

        if ($entry['title'] === '') {
            rt_flash('bad', 'Ohne Bezeichnung findest du den Eintrag später nicht wieder.');
            rt_redirect('vault.php');
        }
        if ($entry['url'] !== '' && !preg_match('#^https?://#i', $entry['url'])) {
            $entry['url'] = 'https://' . ltrim($entry['url'], '/');
        }

        $now = date('c');
        $found = false;
        foreach ($items as $i => $e) {
            if ($e['id'] === $id && $id !== '') {
                /* Leeres Passwortfeld heisst: unverändert lassen. */
                if ($entry['secret'] === '') { $entry['secret'] = $e['secret']; }
                $items[$i] = array_merge($e, $entry, array('updated' => $now));
                $found = true;
                break;
            }
        }
        if (!$found) {
            $entry['id']      = bin2hex(random_bytes(8));
            $entry['created'] = $now;
            $entry['updated'] = $now;
            $items[] = $entry;
        }

        if (rt_vault_save($items, $key)) {
            rt_flash('ok', $found ? 'Eintrag geändert.' : 'Eintrag gespeichert.');
        } else {
            rt_flash('bad', 'Speichern nicht möglich. Bitte die Schreibrechte auf data/private prüfen.');
        }
        rt_redirect('vault.php');
    }
}

/* Nach Bezeichnung sortieren */
usort($items, function ($a, $b) {
    return strcasecmp((string) $a['title'], (string) $b['title']);
});

$broken = false;
foreach ($items as $e) {
    if ($e['title'] === null) { $broken = true; break; }
}

$editId = isset($_GET['edit']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['edit']) : '';
$edit = null;
foreach ($items as $e) {
    if ($e['id'] === $editId && $editId !== '') { $edit = $e; break; }
}

rt_admin_head('Passwörter', 'vault');
?>

<div class="a-head">
  <div>
    <h1>Passwortspeicher</h1>
    <p>Zugangsdaten, verschlüsselt auf deinem eigenen Server. Kein fremder Dienst ist beteiligt.</p>
  </div>
</div>

<?php if ($broken): ?>
  <div class="notice notice--bad" role="alert">
    Mindestens ein Eintrag lässt sich nicht entschlüsseln. Das passiert, wenn die Datei
    <code>data/private/users.php</code> ersetzt wurde, nachdem Einträge angelegt worden sind.
    Diese Einträge sind nicht wiederherstellbar.
  </div>
<?php endif; ?>

<div class="a-card">
  <h2><?php echo $edit ? 'Eintrag bearbeiten' : 'Neuer Eintrag'; ?></h2>
  <form method="post" action="vault.php" autocomplete="off">
    <?php echo rt_csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?php echo rt_h($edit['id'] ?? ''); ?>">

    <div class="a-grid a-grid--2">
      <div class="a-field">
        <label for="title">Bezeichnung <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="title" name="title" required maxlength="120"
               value="<?php echo rt_h($edit['title'] ?? ''); ?>">
        <span class="hint">Zum Beispiel: Hosting, E-Mail-Konto, Kundenportal.</span>
      </div>

      <div class="a-field">
        <label for="username">Benutzername oder E-Mail</label>
        <input type="text" id="username" name="username" maxlength="200" autocomplete="off"
               value="<?php echo rt_h($edit['username'] ?? ''); ?>">
      </div>
    </div>

    <div class="a-field">
      <label for="url">Adresse</label>
      <input type="text" id="url" name="url" maxlength="300" inputmode="url"
             value="<?php echo rt_h($edit['url'] ?? ''); ?>" placeholder="beispiel.ch/login">
    </div>

    <div class="a-field">
      <label for="secret">Passwort<?php echo $edit ? ' (leer lassen = unverändert)' : ''; ?></label>
      <input type="password" id="secret" name="secret" maxlength="500" autocomplete="new-password"
             data-strength="vbar">
      <div class="a-strength" id="vbar" aria-hidden="true"><i></i></div>
      <div class="a-row" style="margin-top:.4rem">
        <button class="btn btn--small" type="button" data-generate="secret">Vorschlag erzeugen</button>
      </div>
    </div>

    <div class="a-field">
      <label for="note">Notiz</label>
      <textarea id="note" name="note" maxlength="5000"><?php echo rt_h($edit['note'] ?? ''); ?></textarea>
      <span class="hint">Zum Beispiel Sicherheitsfragen, Vertragsnummer oder ein Hinweis für später.</span>
    </div>

    <div class="a-row">
      <button class="btn btn--primary" type="submit"><?php echo $edit ? 'Änderung speichern' : 'Eintrag speichern'; ?></button>
      <?php if ($edit): ?><a class="btn" href="vault.php">Abbrechen</a><?php endif; ?>
    </div>
  </form>
</div>

<h2 style="margin-top:2.5rem">Gespeichert <span class="a-muted" style="font-weight:400">(<?php echo count($items); ?>)</span></h2>

<?php if (!$items): ?>
  <div class="a-card"><p class="a-muted" style="margin:0">Noch nichts gespeichert.</p></div>
<?php else: ?>
  <ul class="a-list" style="margin-top:1rem">
    <?php foreach ($items as $e):
        $eid = rt_h($e['id']);
        $secret = ($e['secret'] === null) ? '' : (string) $e['secret'];
    ?>
      <li class="a-entry">
        <h3><?php echo rt_h($e['title'] === null ? '⚠ nicht lesbar' : $e['title']); ?></h3>
        <p class="a-entry__meta">
          <?php if (!empty($e['username'])): ?><?php echo rt_h($e['username']); ?> · <?php endif; ?>
          geändert <?php echo rt_h(date('d.m.Y', strtotime((string) ($e['updated'] ?: 'now')))); ?>
        </p>

        <?php if (!empty($e['url'])): ?>
          <p style="margin:0 0 .8rem">
            <a class="a-link" href="<?php echo rt_h($e['url']); ?>" target="_blank" rel="noopener noreferrer">
              <?php echo rt_h($e['url']); ?><span class="visually-hidden"> (öffnet in neuem Tab)</span>
            </a>
          </p>
        <?php endif; ?>

        <div class="a-secret">
          <output id="s_<?php echo $eid; ?>" data-value="<?php echo rt_h($secret); ?>">••••••••••••</output>
          <button class="btn btn--small" type="button" aria-pressed="false"
                  data-reveal-target="s_<?php echo $eid; ?>">Anzeigen</button>
          <button class="btn btn--small" type="button"
                  data-copy="<?php echo rt_h($secret); ?>">Kopieren</button>
        </div>

        <?php if (!empty($e['note'])): ?>
          <p class="a-entry__body" style="margin-top:1rem"><?php echo rt_h($e['note']); ?></p>
        <?php endif; ?>

        <div class="a-row" style="margin-top:1rem">
          <a class="btn btn--small" href="vault.php?edit=<?php echo $eid; ?>">Bearbeiten</a>
          <form method="post" action="vault.php" class="a-inline"
                data-confirm="Diesen Eintrag wirklich löschen? Das lässt sich nicht rückgängig machen.">
            <?php echo rt_csrf_field(); ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo $eid; ?>">
            <button class="btn btn--danger btn--small" type="submit">Löschen</button>
          </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<div class="a-card" style="margin-top:2rem">
  <h2>Wie sicher ist das?</h2>
  <ul class="a-muted">
    <li>Jedes Feld wird einzeln verschlüsselt, bevor es auf die Platte geschrieben wird.</li>
    <li>Der Schlüssel entsteht aus deinem Anmeldepasswort und steht nirgends in einer Datei.</li>
    <li>Änderst du dein Passwort über „Mein Passwort", werden alle Einträge neu verschlüsselt.</li>
    <li>Verlierst du dein Passwort, sind die Einträge endgültig weg. Das ist kein Fehler, sondern der Punkt daran.</li>
    <li>Solange diese Seite offen ist, steht ein angezeigtes Passwort im Browser. Melde dich ab, wenn du fertig bist.</li>
  </ul>
</div>

<?php rt_admin_foot(); ?>
