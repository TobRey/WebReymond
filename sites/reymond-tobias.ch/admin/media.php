<?php
/** Bilder hochladen und austauschen. */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/schema.php';
rt_require_login();

define('RT_CONTENT_FILE', RT_CONTENT . '/content.json');
define('RT_MAX_UPLOAD', 6 * 1024 * 1024);   // 6 MB

$slots = rt_media_slots();

/** Zulaessige Bildarten. Geprueft wird der Inhalt, nicht die Dateiendung. */
function rt_allowed_image($tmpFile)
{
    $info = @getimagesize($tmpFile);
    if (!is_array($info) || empty($info[2])) { return null; }

    $map = array(
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
    );
    if (!isset($map[$info[2]])) { return null; }
    if ((int) $info[0] < 200 || (int) $info[1] < 200) { return null; }
    if ((int) $info[0] > 8000 || (int) $info[1] > 8000) { return null; }

    return array('ext' => $map[$info[2]], 'w' => (int) $info[0], 'h' => (int) $info[1]);
}

/** Alte Datei entfernen, aber nur innerhalb des Bilderordners. */
function rt_drop_media($file)
{
    if (!is_string($file) || !preg_match('/^[A-Za-z0-9._-]+$/', $file)) { return; }
    $path = RT_MEDIA . '/' . $file;
    if (is_file($path) && strpos(realpath($path), realpath(RT_MEDIA)) === 0) {
        @unlink($path);
    }
}

$data = rt_read(RT_CONTENT_FILE, array());
if (!isset($data['media']) || !is_array($data['media'])) { $data['media'] = array(); }

if (rt_require_post_token()) {
    $slot = isset($_POST['slot']) ? (string) $_POST['slot'] : '';
    if (!isset($slots[$slot])) {
        rt_flash('bad', 'Unbekanntes Bildfeld.');
        rt_redirect('media.php');
    }

    /* --- Löschen --------------------------------------------------- */
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        if (!empty($data['media'][$slot])) { rt_drop_media($data['media'][$slot]); }
        unset($data['media'][$slot]);
        $data['updated'] = date('c');
        rt_backup(RT_CONTENT_FILE, 'content');
        rt_write(RT_CONTENT_FILE, $data, false);
        rt_flash('ok', 'Bild entfernt. Der Abschnitt „Über mich" zeigt danach nur noch den Text.');
        rt_redirect('media.php');
    }

    /* --- Hochladen ------------------------------------------------- */
    if (!isset($_FILES['bild']) || !is_array($_FILES['bild'])) {
        rt_flash('bad', 'Es wurde keine Datei übermittelt.');
        rt_redirect('media.php');
    }

    $f = $_FILES['bild'];
    if ((int) $f['error'] === UPLOAD_ERR_INI_SIZE || (int) $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        rt_flash('bad', 'Die Datei ist zu gross. Höchstens 6 MB – verkleinere das Bild vorher.');
        rt_redirect('media.php');
    }
    if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        rt_flash('bad', 'Das Hochladen hat nicht geklappt. Bitte versuch es noch einmal.');
        rt_redirect('media.php');
    }
    if ((int) $f['size'] > RT_MAX_UPLOAD) {
        rt_flash('bad', 'Die Datei ist zu gross. Höchstens 6 MB.');
        rt_redirect('media.php');
    }

    $img = rt_allowed_image($f['tmp_name']);
    if ($img === null) {
        rt_flash('bad', 'Nur JPG, PNG oder WEBP, mindestens 200 × 200 Pixel. Diese Datei passt nicht.');
        rt_redirect('media.php');
    }

    if (!rt_dir(RT_MEDIA, 0755)) {
        rt_flash('bad', 'Der Ordner content/media lässt sich nicht anlegen.');
        rt_redirect('media.php');
    }

    $name = $slot . '-' . bin2hex(random_bytes(6)) . '.' . $img['ext'];
    $dest = RT_MEDIA . '/' . $name;

    if (!@move_uploaded_file($f['tmp_name'], $dest)) {
        rt_flash('bad', 'Die Datei liess sich nicht ablegen. Bitte die Schreibrechte auf content/media prüfen.');
        rt_redirect('media.php');
    }
    @chmod($dest, 0644);

    $old = isset($data['media'][$slot]) ? $data['media'][$slot] : '';
    $data['media'][$slot] = $name;
    $data['updated'] = date('c');

    rt_backup(RT_CONTENT_FILE, 'content');
    if (rt_write(RT_CONTENT_FILE, $data, false)) {
        if ($old !== '' && $old !== $name) { rt_drop_media($old); }
        rt_flash('ok', 'Bild gespeichert (' . $img['w'] . ' × ' . $img['h'] . ' Pixel). Es ist sofort auf der Website zu sehen.');
    } else {
        @unlink($dest);
        rt_flash('bad', 'Die Zuordnung liess sich nicht speichern.');
    }
    rt_redirect('media.php');
}

rt_admin_head('Bilder', 'media');
?>

<div class="a-head">
  <div>
    <h1>Bilder</h1>
    <p>Das Porträt im Abschnitt „Über mich“. Es ist bereits gesetzt — du kannst es jederzeit durch ein anderes ersetzen.</p>
  </div>
</div>

<div class="notice" style="margin-bottom:2rem">
  Erlaubt sind <strong>JPG, PNG und WEBP</strong> bis 6 MB. Grössere Bilder vorher verkleinern –
  das macht die Seite schneller. Das gleiche Bild gilt für die deutsche und die englische Fassung.
</div>

<div class="a-grid a-grid--2">
  <?php foreach ($slots as $key => $slot):
      $file = isset($data['media'][$key]) ? $data['media'][$key] : '';
      $has  = ($file !== '' && is_file(RT_MEDIA . '/' . $file));
  ?>
    <div class="a-card">
      <h2 style="font-size:1rem"><?php echo rt_h($slot['label']); ?></h2>
      <p class="a-muted"><?php echo rt_h($slot['hint']); ?></p>

      <div style="margin:1rem 0">
        <?php if ($has): ?>
          <img class="a-thumb" src="../content/media/<?php echo rt_h($file); ?>"
               alt="Aktuell hochgeladenes Bild für <?php echo rt_h($slot['label']); ?>">
        <?php else: ?>
          <div class="a-thumb a-thumb--empty">Kein Bild<br>hinterlegt</div>
        <?php endif; ?>
      </div>

      <form method="post" action="media.php" enctype="multipart/form-data">
        <?php echo rt_csrf_field(); ?>
        <input type="hidden" name="slot" value="<?php echo rt_h($key); ?>">
        <div class="a-field">
          <label for="up_<?php echo rt_h($key); ?>">Datei wählen</label>
          <input type="file" id="up_<?php echo rt_h($key); ?>" name="bild"
                 accept="image/jpeg,image/png,image/webp" required>
        </div>
        <button class="btn btn--primary btn--small" type="submit">
          <?php echo $has ? 'Bild ersetzen' : 'Bild hochladen'; ?>
        </button>
      </form>

      <?php if ($has): ?>
        <form method="post" action="media.php" style="margin-top:.7rem"
              data-confirm="Bild wirklich entfernen? Der Abschnitt zeigt danach nur noch den Text.">
          <?php echo rt_csrf_field(); ?>
          <input type="hidden" name="slot" value="<?php echo rt_h($key); ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn btn--danger btn--small" type="submit">Bild entfernen</button>
        </form>
        <p class="a-muted a-mono" style="margin-top:.7rem"><?php echo rt_h($file); ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="a-card" style="margin-top:1.4rem">
  <h2>Ein Hinweis zu Fotos von anderen Leuten</h2>
  <p class="a-muted">
    Lade nur Bilder hoch, die du selbst gemacht hast oder für die du die Erlaubnis hast.
    Sind Personen darauf zu erkennen, brauchst du deren Einverständnis. Das gilt auch für
    Fotos aus einem Club.
  </p>
</div>

<?php rt_admin_foot(); ?>
