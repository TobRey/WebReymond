<?php
/**
 * Passwort ändern.
 *
 * Weil der Schlüssel des Passwortspeichers aus dem Anmeldepasswort entsteht,
 * werden dabei sämtliche Einträge neu verschlüsselt. Geht dabei etwas schief,
 * wird der alte Zustand wiederhergestellt.
 */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/crypto.php';
require __DIR__ . '/inc/layout.php';
rt_require_login();

$errors = array();
$oldKey = rt_session_key();

if (rt_require_post_token()) {
    $current = isset($_POST['current']) ? (string) $_POST['current'] : '';
    $pw1     = isset($_POST['pw1']) ? (string) $_POST['pw1'] : '';
    $pw2     = isset($_POST['pw2']) ? (string) $_POST['pw2'] : '';

    $account = rt_user_load();

    if (!password_verify($current, (string) ($account['hash'] ?? ''))) {
        usleep(random_int(250000, 600000));
        $errors[] = 'Das bisherige Passwort stimmt nicht.';
    }
    if (rt_len($pw1) < 12)  { $errors[] = 'Das neue Passwort muss mindestens 12 Zeichen lang sein.'; }
    if (rt_len($pw1) > 200) { $errors[] = 'Das neue Passwort ist zu lang (höchstens 200 Zeichen).'; }
    if ($pw1 !== $pw2)      { $errors[] = 'Die beiden Eingaben für das neue Passwort stimmen nicht überein.'; }
    if ($pw1 === $current && !$errors) { $errors[] = 'Das neue Passwort ist dasselbe wie das alte.'; }
    if ($oldKey === null)   { $errors[] = 'Die Sitzung ist nicht mehr vollständig. Bitte melde dich neu an.'; }

    if (!$errors) {
        /* 1. Einträge mit dem alten Schlüssel öffnen */
        $items = rt_vault_load($oldKey);
        foreach ($items as $item) {
            if ($item['title'] === null) {
                $errors[] = 'Mindestens ein Eintrag im Passwortspeicher lässt sich nicht öffnen. '
                          . 'Das Passwort wurde deshalb nicht geändert.';
                break;
            }
        }
    }

    if (!$errors) {
        /* 2. Neuen Schlüssel bilden */
        $newSalt = bin2hex(random_bytes(16));
        $newKdf  = rt_kdf_name();
        $newKey  = rt_derive_key($pw1, $newSalt, $newKdf);

        /* 3. Speicher neu verschlüsseln */
        $vaultOk = empty($items) ? true : rt_vault_save($items, $newKey);

        if (!$vaultOk) {
            $errors[] = 'Der Passwortspeicher liess sich nicht neu verschlüsseln. Es wurde nichts geändert.';
        } else {
            /* 4. Zugangsdatei schreiben */
            $account['hash']       = rt_hash_password($pw1);
            $account['algo']       = rt_argon2id_available() ? 'argon2id' : 'bcrypt';
            $account['vault_salt'] = $newSalt;
            $account['vault_kdf']  = $newKdf;
            $account['password_changed'] = date('c');

            rt_backup(RT_USERS, 'users');
            if (rt_write(RT_USERS, $account)) {
                $_SESSION['vk'] = base64_encode($newKey);
                session_regenerate_id(true);
                rt_flash('ok', 'Passwort geändert. Der Passwortspeicher wurde neu verschlüsselt.');
                rt_redirect('password.php');
            } else {
                /* Zurück auf den alten Stand, damit nichts unlesbar wird. */
                rt_vault_save($items, $oldKey);
                $errors[] = 'Die Zugangsdatei liess sich nicht schreiben. Es wurde nichts geändert.';
            }
        }
    }
}

$account = rt_user_load();
rt_admin_head('Mein Passwort', 'password', true);
?>

<div class="a-card">
  <h1 style="font-size: var(--step-2)">Mein Passwort</h1>
  <p class="a-muted">
    Benutzername: <code><?php echo rt_h($account['user'] ?? ''); ?></code><br>
    Verfahren: <code><?php echo rt_h($account['algo'] ?? 'unbekannt'); ?></code>
    <?php if (!empty($account['password_changed'])): ?>
      <br>zuletzt geändert: <?php echo rt_h(date('d.m.Y', strtotime((string) $account['password_changed']))); ?>
    <?php elseif (!empty($account['created'])): ?>
      <br>gesetzt am: <?php echo rt_h(date('d.m.Y', strtotime((string) $account['created']))); ?>
    <?php endif; ?>
  </p>

  <?php foreach ($errors as $e): ?>
    <div class="notice notice--bad" role="alert"><?php echo rt_h($e); ?></div>
  <?php endforeach; ?>

  <form method="post" action="password.php" autocomplete="off" style="margin-top:1.5rem">
    <?php echo rt_csrf_field(); ?>

    <div class="a-field">
      <label for="current">Bisheriges Passwort</label>
      <input type="password" id="current" name="current" required autocomplete="current-password" maxlength="200">
    </div>

    <div class="a-field">
      <label for="pw1">Neues Passwort</label>
      <input type="password" id="pw1" name="pw1" required minlength="12" maxlength="200"
             autocomplete="new-password" data-strength="pwbar">
      <div class="a-strength" id="pwbar" aria-hidden="true"><i></i></div>
      <span class="hint">Mindestens 12 Zeichen.</span>
    </div>

    <div class="a-field">
      <label for="pw2">Neues Passwort wiederholen</label>
      <input type="password" id="pw2" name="pw2" required minlength="12" maxlength="200" autocomplete="new-password">
    </div>

    <div class="a-row">
      <button class="btn btn--primary" type="submit">Passwort ändern</button>
      <button class="btn btn--small" type="button" data-generate="pw1">Vorschlag erzeugen</button>
    </div>
  </form>

  <div class="notice" style="margin-top:2rem">
    Schreib dir das neue Passwort auf, bevor du speicherst. Es gibt keine Möglichkeit,
    es später auszulesen – und ohne das Passwort ist auch der Passwortspeicher verloren.
  </div>
</div>

<?php rt_admin_foot(); ?>
