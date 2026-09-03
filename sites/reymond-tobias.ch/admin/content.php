<?php
/** Texte, Listen und Verweise der Website ändern. */

require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/schema.php';
require_once RT_ROOT . '/lib/content.php';
rt_require_login();

$lang = (isset($_GET['lang']) && $_GET['lang'] === 'en') ? 'en' : 'de';

/** Einen einzelnen Wert saeubern – je nach Art des Feldes. */
function rt_field_value($raw, $field)
{
    $max = isset($field['max']) ? (int) $field['max'] : 400;
    $type = isset($field['type']) ? $field['type'] : 'text';
    if ($type === 'url') {
        return rt_url(rt_clean($raw, $max, false));
    }
    return rt_clean($raw, $max, $type === 'textarea');
}

if (rt_require_post_token()) {
    $target = (isset($_POST['lang']) && $_POST['lang'] === 'en') ? 'en' : 'de';

    $data = rt_read(RT_CONTENT_FILE, array());
    if (!isset($data['de']) || !is_array($data['de'])) { $data['de'] = array(); }
    if (!isset($data['en']) || !is_array($data['en'])) { $data['en'] = array(); }
    if (!isset($data['media']) || !is_array($data['media'])) { $data['media'] = array(); }

    $clean = array();
    $badLinks = array();

    /* --- Einzelne Felder ------------------------------------------------ */
    foreach (rt_schema_fields() as $key => $field) {
        if (!array_key_exists($key, $_POST)) { continue; }
        $raw   = is_string($_POST[$key]) ? $_POST[$key] : '';
        $value = rt_field_value($raw, $field);
        if ($value === '' && $field['type'] === 'url' && trim($raw) !== '') {
            $badLinks[] = trim($raw);
        }
        if ($value !== '') { $clean[$key] = $value; }
    }

    /* --- Listen mit beliebig vielen Eintraegen --------------------------- */
    foreach (rt_schema_lists() as $key => $list) {
        $rows = (isset($_POST[$key]) && is_array($_POST[$key])) ? $_POST[$key] : array();
        $max  = isset($list['max']) ? (int) $list['max'] : 20;
        $out  = array();

        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            if (count($out) >= $max) { break; }

            $item = array();
            $hasContent = false;
            foreach ($list['fields'] as $field) {
                $raw   = isset($row[$field['key']]) && is_string($row[$field['key']]) ? $row[$field['key']] : '';
                $value = rt_field_value($raw, $field);
                if ($value === '' && $field['type'] === 'url' && trim($raw) !== '') {
                    $badLinks[] = trim($raw);
                }
                $item[$field['key']] = $value;
                if ($value !== '') { $hasContent = true; }
            }
            /* Ein Eintrag, in dem nichts steht, wird stillschweigend verworfen. */
            if ($hasContent) { $out[] = $item; }
        }

        /* Listen werden immer gespeichert – sonst liesse sich kein Eintrag löschen. */
        $clean[$key] = $out;
    }

    $data[$target]   = $clean;
    $data['updated'] = date('c');

    rt_backup(RT_CONTENT_FILE, 'content');
    if (rt_write(RT_CONTENT_FILE, $data, false)) {
        if ($badLinks) {
            $show = array_slice(array_unique($badLinks), 0, 3);
            rt_flash('bad', 'Gespeichert – aber diese Verweise wurden nicht übernommen, weil die Adresse nicht stimmt: '
                . implode(' · ', $show) . '. Erlaubt sind https://…, mailto:…, tel:… und Sprungmarken wie #kontakt.');
        } else {
            rt_flash('ok', 'Gespeichert. Schau auf der Website nach – die Änderung ist sofort sichtbar.');
        }
    } else {
        rt_flash('bad', 'Speichern nicht möglich. Bitte die Schreibrechte auf den Ordner content/ prüfen.');
    }
    rt_redirect('content.php?lang=' . $target);
}

/* Angezeigt wird immer der Stand, den die Website gerade zeigt:
   Gespeichertes, sonst der Grundtext aus lib/defaults.php. */
$values = rt_content($lang);

/** Ein einzelnes Eingabefeld ausgeben. */
function rt_render_field($field, $name, $id, $value)
{
    $type = isset($field['type']) ? $field['type'] : 'text';
    $max  = isset($field['max']) ? (int) $field['max'] : 400;
    ?>
    <div class="a-field">
      <label for="<?php echo rt_h($id); ?>"><?php echo rt_h($field['label']); ?></label>
      <?php if ($type === 'textarea'): ?>
        <textarea id="<?php echo rt_h($id); ?>" name="<?php echo rt_h($name); ?>"
                  maxlength="<?php echo $max; ?>"
                  class="<?php echo !empty($field['tall']) ? 'tall' : ''; ?>"><?php echo rt_h($value); ?></textarea>
      <?php else: ?>
        <input type="text" id="<?php echo rt_h($id); ?>" name="<?php echo rt_h($name); ?>"
               maxlength="<?php echo $max; ?>" value="<?php echo rt_h($value); ?>"
               <?php echo $type === 'url' ? 'inputmode="url" spellcheck="false" class="a-mono"' : ''; ?>>
      <?php endif; ?>
      <?php if (!empty($field['hint'])): ?>
        <span class="hint"><?php echo rt_h($field['hint']); ?></span>
      <?php endif; ?>
    </div>
    <?php
}

/** Einen Eintrag einer Liste ausgeben (auch als Vorlage fuer neue Eintraege). */
function rt_render_slot($list, $index, $item)
{
    $key = $list['key'];
    ?>
    <div class="a-slot" data-slot>
      <div class="a-slot__head">
        <span class="a-slot__nr"><?php echo rt_h($list['item']); ?> <b data-nr>1</b></span>
        <span class="a-slot__tools">
          <button class="btn btn--small" type="button" data-move="up" aria-label="Nach oben schieben">&uarr;</button>
          <button class="btn btn--small" type="button" data-move="down" aria-label="Nach unten schieben">&darr;</button>
          <button class="btn btn--small btn--danger" type="button" data-remove>Entfernen</button>
        </span>
      </div>
      <?php foreach ($list['fields'] as $field):
          $name  = $key . '[' . $index . '][' . $field['key'] . ']';
          $id    = 'f_' . $key . '_' . $index . '_' . $field['key'];
          $value = isset($item[$field['key']]) && is_string($item[$field['key']]) ? $item[$field['key']] : '';
          rt_render_field($field, $name, $id, $value);
      endforeach; ?>
    </div>
    <?php
}

/** Eine ganze Liste ausgeben: Eintraege, Knopf zum Hinzufuegen, Vorlage. */
function rt_render_list($list, $values)
{
    $key   = $list['key'];
    $items = rt_list($values, $key);
    $max   = isset($list['max']) ? (int) $list['max'] : 20;
    ?>
    <div class="a-list" data-list data-name="<?php echo rt_h($key); ?>" data-max="<?php echo $max; ?>">
      <div class="a-list__head">
        <h3><?php echo rt_h($list['label']); ?></h3>
        <?php if (!empty($list['note'])): ?><p class="a-muted"><?php echo rt_h($list['note']); ?></p><?php endif; ?>
      </div>

      <div class="a-slots" data-slots>
        <?php foreach ($items as $i => $item) { rt_render_slot($list, $i, $item); } ?>
      </div>

      <p class="a-list__empty"<?php echo $items ? ' hidden' : ''; ?>>
        Hier steht noch nichts. Mit dem Knopf unten legst du den ersten Eintrag an.
      </p>

      <div class="a-row">
        <button class="btn" type="button" data-add>+ <?php echo rt_h($list['add']); ?></button>
        <span class="a-muted a-list__count" data-count>Höchstens <?php echo $max; ?> Einträge.</span>
      </div>

      <template data-template><?php rt_render_slot($list, '__I__', array()); ?></template>
    </div>
    <?php
}

rt_admin_head('Texte', 'content');
?>

<div class="a-head">
  <div>
    <h1>Texte ändern</h1>
    <p>Alle Felder sind bereits ausgefüllt. Du überschreibst, was dir nicht passt — und löschst ein Feld leer, wenn wieder der Grundtext erscheinen soll.</p>
  </div>
  <div class="a-row">
    <a class="btn<?php echo $lang === 'de' ? ' btn--primary' : ''; ?>" href="content.php?lang=de">Deutsch</a>
    <a class="btn<?php echo $lang === 'en' ? ' btn--primary' : ''; ?>" href="content.php?lang=en" lang="en">English</a>
  </div>
</div>

<div class="notice" style="margin-bottom:2rem">
  Du bearbeitest gerade die <strong><?php echo $lang === 'de' ? 'deutsche' : 'englische'; ?></strong> Fassung.
  Die andere Sprache wird dabei nicht verändert — denk daran, beide zu pflegen.
  Bei den Listen kannst du jederzeit einen Eintrag <strong>hinzufügen</strong>, verschieben oder entfernen;
  jeder Eintrag darf einen eigenen Verweis bekommen.
</div>

<form method="post" action="content.php?lang=<?php echo rt_h($lang); ?>">
  <?php echo rt_csrf_field(); ?>
  <input type="hidden" name="lang" value="<?php echo rt_h($lang); ?>">

  <?php foreach (rt_schema() as $group): ?>
    <fieldset class="a-fieldset">
      <legend><?php echo rt_h($group['title']); ?></legend>
      <?php if (!empty($group['note'])): ?>
        <p class="a-muted" style="margin-top:-.4rem"><?php echo rt_h($group['note']); ?></p>
      <?php endif; ?>

      <?php foreach (array('fields', 'lists', 'fields_after', 'lists_after') as $slot):
          if (empty($group[$slot])) { continue; }
          foreach ($group[$slot] as $entry) {
              if ($slot === 'fields' || $slot === 'fields_after') {
                  $value = rt_raw($values, $entry['key']);
                  rt_render_field($entry, $entry['key'], 'f_' . $entry['key'], $value);
              } else {
                  rt_render_list($entry, $values);
              }
          }
      endforeach; ?>
    </fieldset>
  <?php endforeach; ?>

  <div class="a-save">
    <button class="btn btn--primary" type="submit">Speichern</button>
    <a class="btn" href="../<?php echo $lang === 'en' ? 'en/' : ''; ?>" target="_blank" rel="noopener">Ergebnis ansehen<span class="visually-hidden"> (öffnet in neuem Tab)</span></a>
  </div>
</form>

<?php rt_admin_foot(); ?>
