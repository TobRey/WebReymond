<?php
/** Anmeldung. */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (!rt_user_exists()) { rt_redirect('setup.php'); }

$next = isset($_GET['next']) ? preg_replace('/[^a-z0-9_.-]/i', '', (string) $_GET['next']) : 'index.php';
if ($next === '' || !preg_match('/^[a-z0-9_-]+\.php$/i', $next)) { $next = 'index.php'; }

if (rt_is_logged_in()) { rt_redirect($next); }

$error = '';
$user  = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!rt_csrf_ok()) {
        $error = 'Die Seite war zu lange offen. Bitte versuch es noch einmal.';
    } else {
        $user = rt_clean($_POST['user'] ?? '', 60);
        $pass = isset($_POST['pass']) ? (string) $_POST['pass'] : '';

        $wait = rt_lock_remaining($user !== '' ? $user : RT_ADMIN_USER);
        if ($wait > 0) {
            $error = 'Zu viele Fehlversuche. Bitte warte noch ' . ceil($wait / 60) . ' Minute(n).';
        } elseif ($user === '' || $pass === '') {
            $error = 'Bitte Benutzername und Passwort eingeben.';
        } elseif (rt_login($user, $pass)) {
            rt_clear_failures($user);
            rt_flash('ok', 'Willkommen zurück.');
            rt_redirect($next);
        } else {
            /* Immer dieselbe Meldung – sie verrät nicht, was falsch war. */
            rt_note_failure($user !== '' ? $user : RT_ADMIN_USER);
            usleep(random_int(250000, 600000));
            $error = 'Benutzername oder Passwort stimmt nicht.';
        }
    }
}

rt_admin_head('Anmeldung', '', true);
?>

<div class="a-card a-login">
  <h1 style="font-size: var(--step-2)">Anmelden</h1>
  <p class="a-muted">Dieser Bereich ist nur für dich.</p>

  <?php if ($error !== ''): ?>
    <div class="notice notice--bad" role="alert"><?php echo rt_h($error); ?></div>
  <?php endif; ?>

  <form method="post" action="login.php?next=<?php echo rt_h(urlencode($next)); ?>">
    <?php echo rt_csrf_field(); ?>

    <div class="a-field">
      <label for="user">Benutzername</label>
      <input type="text" id="user" name="user" required autocomplete="username"
             maxlength="60" value="<?php echo rt_h($user); ?>" autocapitalize="none" spellcheck="false">
    </div>

    <div class="a-field">
      <label for="pass">Passwort</label>
      <input type="password" id="pass" name="pass" required autocomplete="current-password" maxlength="200">
    </div>

    <button class="btn btn--primary" type="submit" style="width:100%; justify-content:center">Anmelden</button>
  </form>

  <p class="a-muted" style="margin-top:1.5rem">
    Nach mehreren Fehlversuchen wird der Zugang für kurze Zeit gesperrt.
    Ohne Anmeldung ist von hier aus nichts zu sehen.
  </p>
  <p class="a-muted"><a class="a-link" href="../">Zurück zur Website</a></p>
</div>

<?php rt_admin_foot(); ?>
