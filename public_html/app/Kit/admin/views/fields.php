<?php defined('WEBATZE') || exit; ?>
<?php
/**
 * Die Eingabefelder eines Abschnitts – erzeugt aus seinem Schema.
 *
 * Dasselbe Schema prüft in Fields::merge() auch, was zurückkommt. Was
 * hier kein Feld hat, lässt sich nicht ändern.
 *
 * @var array $meta @var array $content @var int $id
 */

$render = static function (array $field, string $name, mixed $value, string $inputName, string $inputId) use (&$render): void {
    $type = (string) ($field['type'] ?? 'text');
    $label = (string) ($field['label'] ?? $name);

    switch ($type) {
        case 'text':
            ?>
            <label class="k-label" for="<?= e($inputId) ?>"><?= e($label) ?></label>
            <input class="k-input" type="text" id="<?= e($inputId) ?>" name="<?= e($inputName) ?>"
                   maxlength="<?= (int) ($field['max'] ?? 200) ?>"
                   value="<?= e(is_scalar($value) ? (string) $value : '') ?>">
            <?php
            break;

        case 'textarea':
        case 'richtext':
            ?>
            <label class="k-label" for="<?= e($inputId) ?>">
                <?= e($label) ?>
                <?php if ($type === 'richtext'): ?>
                    <?php /* Genau das, was der Renderer wirklich kann. Ein
                             Hinweis auf Auszeichnungen, die nachher als
                             Sternchen auf der Seite stünden, wäre schlimmer
                             als gar keiner. */ ?>
                    <span class="k-hint">
                        Eine Leerzeile beginnt einen neuen Absatz.
                    </span>
                <?php endif; ?>
            </label>
            <textarea class="k-textarea" id="<?= e($inputId) ?>" name="<?= e($inputName) ?>"
                      rows="<?= $type === 'richtext' ? 8 : 4 ?>"
                      maxlength="<?= (int) ($field['max'] ?? 2000) ?>"><?= e(is_scalar($value) ? (string) $value : '') ?></textarea>
            <?php
            break;

        case 'link':
            $link = is_array($value) ? $value : [];
            ?>
            <fieldset class="k-fieldset">
                <legend><?= e($label) ?></legend>
                <div class="k-pair">
                    <div>
                        <label class="k-label" for="<?= e($inputId) ?>-label">Beschriftung</label>
                        <input class="k-input" type="text" id="<?= e($inputId) ?>-label"
                               name="<?= e($inputName) ?>[label]" maxlength="80"
                               value="<?= e((string) ($link['label'] ?? '')) ?>">
                    </div>
                    <div>
                        <label class="k-label" for="<?= e($inputId) ?>-url">Ziel</label>
                        <input class="k-input" type="text" id="<?= e($inputId) ?>-url"
                               name="<?= e($inputName) ?>[url]" maxlength="300"
                               placeholder="kontakt.html"
                               value="<?= e((string) ($link['url'] ?? '')) ?>">
                    </div>
                </div>
            </fieldset>
            <?php
            break;

        case 'flag':
            ?>
            <label class="k-check">
                <input type="checkbox" name="<?= e($inputName) ?>" value="1"
                       <?= !empty($value) ? 'checked' : '' ?>>
                <span><?= e($label) ?></span>
            </label>
            <?php
            break;

        case 'number':
            ?>
            <label class="k-label" for="<?= e($inputId) ?>"><?= e($label) ?></label>
            <input class="k-input k-input--short" type="number" id="<?= e($inputId) ?>"
                   name="<?= e($inputName) ?>"
                   min="<?= (int) ($field['min'] ?? 0) ?>" max="<?= (int) ($field['max'] ?? 9999) ?>"
                   value="<?= (int) (is_scalar($value) ? $value : 0) ?>">
            <?php
            break;

        case 'image':
            $image = is_array($value) ? $value : [];
            ?>
            <label class="k-label" for="<?= e($inputId) ?>-alt">
                Bildbeschreibung für <?= e($label) ?>
                <span class="k-hint">
                    Wird vorgelesen, wenn jemand die Seite nicht sehen kann – und angezeigt,
                    solange das Bild lädt. Das Bild selbst wechselt man weiter unten.
                </span>
            </label>
            <input class="k-input" type="text" id="<?= e($inputId) ?>-alt"
                   name="<?= e($inputName) ?>[alt]" maxlength="200"
                   value="<?= e((string) ($image['alt'] ?? '')) ?>">
            <?php
            break;

        case 'icon':
            ?>
            <label class="k-label" for="<?= e($inputId) ?>"><?= e($label) ?></label>
            <input class="k-input k-input--short" type="text" id="<?= e($inputId) ?>"
                   name="<?= e($inputName) ?>" maxlength="40"
                   value="<?= e(is_scalar($value) ? (string) $value : '') ?>">
            <?php
            break;

        case 'list':
            $rows = is_array($value) ? $value : [];
            $max = (int) ($field['max'] ?? 8);

            // Eine leere Zeile mehr, damit sich ohne Umweg etwas ergänzen lässt.
            $count = min($max, max(count($rows) + 1, (int) ($field['min'] ?? 1)));
            ?>
            <fieldset class="k-fieldset">
                <legend>
                    <?= e($label) ?>
                    <span class="k-hint">Eine Zeile leeren heisst: Eintrag entfernen.</span>
                </legend>

                <?php for ($i = 0; $i < $count; $i++): ?>
                    <?php $row = is_array($rows[$i] ?? null) ? $rows[$i] : []; ?>
                    <div class="k-listrow">
                        <span class="k-listrow__num"><?= $i + 1 ?></span>
                        <div class="k-listrow__body">
                            <?php foreach ((array) ($field['item'] ?? []) as $sub => $subField): ?>
                                <?php $render(
                                    $subField,
                                    $sub,
                                    $row[$sub] ?? null,
                                    $inputName . '[' . $i . '][' . $sub . ']',
                                    $inputId . '-' . $i . '-' . $sub
                                ); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </fieldset>
            <?php
            break;
    }
};

foreach ((array) ($meta['fields'] ?? []) as $name => $field) {
    $render(
        $field,
        $name,
        $content[$name] ?? null,
        'content[' . $name . ']',
        'f-' . $id . '-' . $name
    );
}
