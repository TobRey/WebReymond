<?php
/**
 * Einmalige Einrichtung: Passwort setzen.
 *
 * Diese Seite lässt sich nur aufrufen, solange noch kein Zugang besteht.
 * Danach antwortet sie mit einer Sperre. Das Passwort wird ausschliesslich
 * als Argon2id-Hash gespeichert – im Klartext steht es nirgends.
 */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/crypto.php';
require __DIR__ . '/inc/layout.php';

$errors = array();
$done   = false;

if (rt_user_exists()) {
    http_response_code(403);
    rt_admin_head('Einrichtung abgeschlossen', '', true);
    ?>
    <div class="a-card">
      <h1>Der Zugang steht bereits</h1>
      <p>Es ist schon ein Passwort hinterlegt. Aus Sicherheitsgründen lässt sich diese Seite
         danach nicht mehr benutzen.</p>
      <p><strong>Bitte lösche die Datei <code>admin/setup.php</code> vom Server.</strong>
         Sie wird nicht mehr gebraucht.</p>
      <p>Passwort vergessen? Dann lösche die Datei <code>data/private/users.php</code> auf dem
         Server – danach lässt sich hier ein neues Passwort setzen. Achtung: die im
         Passwortspeicher abgelegten Zugangsdaten sind mit dem alten Passwort verschlüsselt und
         danach nicht mehr lesbar.</p>
      <p style="margin-top:1.5rem"><a class="btn btn--primary" href="login.php">Zur Anmeldung</a></p>
    </div>
    <?php
    rt_admin_foot();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    /* Ohne bestehenden Zugang gibt es noch keine Sitzung mit Token –
       deshalb wird hier nur geprueft, wenn bereits eines vergeben wurde. */
    if (!empty($_SESSION['csrf']) && !rt_csrf_ok()) {
        $errors[] = 'Die Seite war zu lange offen. Bitte lade sie neu.';
    }

    $pw1 = isset($_POST['pw1']) ? (string) $_POST['pw1'] : '';
    $pw2 = isset($_POST['pw2']) ? (string) $_POST['pw2'] : '';

    if (rt_len($pw1) < 12) {
        $errors[] = 'Das Passwort muss mindestens 12 Zeichen lang sein. Länger ist besser als kompliziert.';
    }
    if (rt_len($pw1) > 200) {
        $errors[] = 'Das Passwort ist zu lang (höchstens 200 Zeichen).';
    }
    if ($pw1 !== $pw2) {
        $errors[] = 'Die beiden Eingaben stimmen nicht überein.';
    }
    if (rt_lower($pw1) === rt_lower(RT_ADMIN_USER)) {
        $errors[] = 'Das Passwort darf nicht dem Benutzernamen entsprechen.';
    }

    if (!$errors) {
        if (!rt_dir(RT_PRIVATE)) {
            $errors[] = 'Der Ordner data/private lässt sich nicht anlegen. Bitte die Schreibrechte prüfen.';
        } else {
            $account = array(
                'user'        => RT_ADMIN_USER,
                'hash'        => rt_hash_password($pw1),
                'algo'        => rt_argon2id_available() ? 'argon2id' : 'bcrypt',
                'vault_salt'  => bin2hex(random_bytes(16)),
                'vault_kdf'   => rt_kdf_name(),
                'created'     => date('c'),
            );
            if (rt_write(RT_USERS, $account)) {
                $done = true;
            } else {
                $errors[] = 'Die Datei liess sich nicht schreiben. Bitte die Schreibrechte auf data/private prüfen.';
            }
        }
    }
}

rt_admin_head('Einrichtung', '', true);
?>

<div class="a-card">
  <h1>Bearbeitungsbereich einrichten</h1>

  <?php if ($done): ?>
    <div class="notice notice--ok" role="status">
      Der Zugang steht. Du kannst dich jetzt anmelden.
    </div>
    <p><strong>Benutzername:</strong> <code><?php echo rt_h(RT_ADMIN_USER); ?></code></p>
    <p class="notice notice--bad">
      Letzter Schritt: Lösche die Datei <code>admin/setup.php</code> vom Server.
      Solange sie dort liegt, ist sie zwar gesperrt, aber sie wird nicht mehr gebraucht.
    </p>
    <p style="margin-top:1.5rem"><a class="btn btn--primary" href="login.php">Zur Anmeldung</a></p>

  <?php else: ?>
    <p>Hier legst du einmalig dein Passwort fest. Der Benutzername steht fest:
       <code><?php echo rt_h(RT_ADMIN_USER); ?></code></p>

    <?php if (!rt_argon2id_available()): ?>
      <div class="notice notice--bad">
        Auf diesem Server fehlt Argon2id. Das Passwort wird dann mit bcrypt gesichert –
        ebenfalls sicher, aber nicht das, was vorgesehen war. Frag beim Hosting nach,
        ob PHP mit Argon2-Unterstützung verfügbar ist (PHP 7.3 oder neuer).
      </div>
    <?php endif; ?>

    <?php foreach ($errors as $e): ?>
      <div class="notice notice--bad" role="alert"><?php echo rt_h($e); ?></div>
    <?php endforeach; ?>

    <form method="post" action="setup.php" autocomplete="off">
      <?php echo rt_csrf_field(); ?>

      <div class="a-field">
        <label for="pw1">Neues Passwort</label>
        <input type="password" id="pw1" name="pw1" required minlength="12" maxlength="200"
               autocomplete="new-password" data-strength="pwbar">
        <div class="a-strength" id="pwbar" aria-hidden="true"><i></i></div>
        <span class="hint">Mindestens 12 Zeichen. Am besten vier zufällige Wörter hintereinander –
              das merkt man sich und es ist schwer zu erraten.</span>
      </div>

      <div class="a-field">
        <label for="pw2">Passwort wiederholen</label>
        <input type="password" id="pw2" name="pw2" required minlength="12" maxlength="200"
               autocomplete="new-password">
      </div>

      <div class="a-row">
        <button class="btn btn--primary" type="submit">Passwort setzen</button>
        <button class="btn btn--small" type="button" data-generate="pw1">Vorschlag erzeugen</button>
      </div>

      <p class="a-muted" style="margin-top:1.5rem">
        Schreib dir das Passwort auf, bevor du es setzt. Es lässt sich nicht auslesen –
        gespeichert wird nur eine Prüfsumme.
      </p>
    </form>
  <?php endif; ?>
</div>

<?php rt_admin_foot(); ?>
