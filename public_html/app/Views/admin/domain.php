<?php
/**
 * Der Domain-Assistent.
 *
 * Ein Domainumzug scheitert fast nie an der Technik, sondern daran, dass
 * unklar ist, wo man was einträgt und ob es angekommen ist. Genau das
 * beantwortet diese Seite: Schritt für Schritt, mit einer Prüfung, die
 * nachsieht statt zu raten.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $project @var array|null $transfer @var array $steps */
/** @var array $checks @var array $providers */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$id = (int) $project['id'];

$mode = (string) ($transfer['mode'] ?? 'point');
$domain = (string) ($transfer['domain'] ?? $project['domain'] ?? '');
$registrar = (string) ($transfer['registrar'] ?? 'other');
$targetIp = (string) ($transfer['target_ip'] ?? '');
$checkedAt = (string) ($checks['checked_at'] ?? '');
?>

<p class="wa-intro">
    Zwei Wege führen zum Ziel: Die Domain bleibt beim bisherigen Anbieter und zeigt nur auf den
    neuen Server – das geht schnell und ist jederzeit rückgängig zu machen. Oder sie zieht ganz
    um, dann liegt alles an einem Ort. Der Assistent kennt beide Wege.
</p>

<section class="wa-panel">
    <div class="wa-panel__head"><h2 class="wa-panel__title">Domain</h2></div>

    <form class="wa-form" method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/domain">
        <?= Csrf::field() ?>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="domain">Domainname</label>
                <input class="wa-input" type="text" id="domain" name="domain"
                       placeholder="beispiel.ch" value="<?= e($domain) ?>">
                <span class="wa-label__hint">Ohne <code>https://</code> und ohne <code>www.</code></span>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="mode">Was soll passieren?</label>
                <select class="wa-select" id="mode" name="mode">
                    <option value="point" <?= $mode === 'point' ? 'selected' : '' ?>>
                        Domain bleibt, zeigt auf den neuen Server
                    </option>
                    <option value="transfer" <?= $mode === 'transfer' ? 'selected' : '' ?>>
                        Domain zieht zum neuen Anbieter um
                    </option>
                    <option value="none" <?= $mode === 'none' ? 'selected' : '' ?>>
                        Nichts – bleibt vorerst so
                    </option>
                </select>
            </div>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="registrar">Wo ist die Domain registriert?</label>
                <select class="wa-select" id="registrar" name="registrar">
                    <?php foreach ($providers['registrar'] as $key => $info): ?>
                        <option value="<?= e($key) ?>" <?= $registrar === $key ? 'selected' : '' ?>>
                            <?= e($info['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="target_ip">Adresse des neuen Servers</label>
                <input class="wa-input" type="text" id="target_ip" name="target_ip"
                       placeholder="z.B. 185.12.34.56" value="<?= e($targetIp) ?>">
                <span class="wa-label__hint">
                    Steht im Kundenbereich des Hosting-Anbieters, meist unter „Serverdaten".
                    Wird sie eingetragen, kann die Prüfung nicht nur sehen <em>ob</em> die Domain
                    auflöst, sondern auch <em>ob sie richtig zeigt</em>.
                </span>
            </div>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Speichern</button>
        </div>
    </form>
</section>

<?php if ($steps !== []): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">
                <?= $mode === 'transfer' ? 'Umzug Schritt für Schritt' : 'Umstellung Schritt für Schritt' ?>
            </h2>
            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/domain/pruefen">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn wa-btn--sm">Jetzt nachsehen</button>
            </form>
        </div>

        <?php if ((int) ($transfer['current_step'] ?? 0) >= count($steps)): ?>
            <div class="wa-note wa-note--success">
                <div>Alle Schritte sind abgehakt. Bleibt nur noch nachzusehen, ob die
                     Änderung überall angekommen ist.</div>
            </div>
        <?php endif; ?>

        <ol class="wa-steps">
            <?php foreach ($steps as $step): ?>
                <?php
                $state = $step['done'] ? 'done' : ($step['active'] ? 'active' : 'waiting');
                $result = $step['result'] ?? null;
                ?>
                <li class="wa-step wa-step--<?= e($state) ?>">
                    <span class="wa-step__num"><?= (int) $step['index'] + 1 ?></span>
                    <div class="wa-step__body">
                        <h3 class="wa-step__title"><?= e((string) $step['title']) ?></h3>
                        <p><?= $step['text'] /* fest im Code hinterlegter Hilfetext */ ?></p>
                        <?php if (!empty($step['detail'])): ?>
                            <p class="wa-step__detail"><?= $step['detail'] ?></p>
                        <?php endif; ?>
                        <?php if (is_array($result) && isset($result['message'])): ?>
                            <div class="wa-note wa-note--<?= !empty($result['ok']) ? 'success' : 'warning' ?>">
                                <div><?= e((string) $result['message']) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php /* Was sich nicht von aussen nachsehen lässt, hakt man
                                 selbst ab – sonst käme der Assistent nie weiter. */ ?>
                        <?php if ($step['active']): ?>
                            <form class="wa-step__actions" method="post"
                                  action="<?= e($base) ?>/projekt/<?= $id ?>/domain/schritt">
                                <?= Csrf::field() ?>
                                <button type="submit" name="direction" value="next"
                                        class="wa-btn wa-btn--sm">Erledigt, weiter</button>
                                <?php if ((int) $step['index'] > 0): ?>
                                    <button type="submit" name="direction" value="back"
                                            class="wa-btn wa-btn--quiet wa-btn--sm">Einen zurück</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

<?php if ($checks !== []): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">Letzte Prüfung</h2>
            <?php if ($checkedAt !== ''): ?>
                <p class="wa-panel__hint">
                    <?= e(date('d.m.Y H:i', strtotime($checkedAt))) ?> –
                    DNS-Änderungen brauchen meist zwischen zehn Minuten und vier Stunden,
                    bis sie überall angekommen sind.
                </p>
            <?php endif; ?>
        </div>

        <?php foreach (['dns' => 'Wohin zeigt die Domain?',
                        'www' => 'Und die www-Adresse?',
                        'https' => 'Verschlüsselung'] as $key => $label): ?>
            <?php $entry = $checks[$key] ?? null; ?>
            <?php if (!is_array($entry)) { continue; } ?>
            <div class="wa-check">
                <span class="wa-badge wa-badge--<?= !empty($entry['ok']) ? 'done' : 'waiting' ?>">
                    <?= !empty($entry['ok']) ? 'in Ordnung' : 'noch offen' ?>
                </span>
                <div>
                    <strong><?= e($label) ?></strong>
                    <p><?= e((string) ($entry['message'] ?? '')) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($registrar !== '' && isset($providers['registrar'][$registrar])): ?>
    <section class="wa-panel">
        <div class="wa-panel__head">
            <h2 class="wa-panel__title">
                Wo findet man was bei <?= e($providers['registrar'][$registrar]['name']) ?>?
            </h2>
        </div>
        <dl class="wa-facts">
            <?php foreach (['dns' => 'DNS-Einträge ändern',
                            'authcode' => 'Auth-Code (Umzugscode) holen',
                            'lock' => 'Transfersperre aufheben',
                            'nameserver' => 'Nameserver ändern'] as $key => $label): ?>
                <?php $text = (string) ($providers['registrar'][$registrar][$key] ?? ''); ?>
                <?php if (trim($text) === '') { continue; } ?>
                <dt><?= e($label) ?></dt>
                <dd><?= $text /* fest im Code hinterlegter Hilfetext */ ?></dd>
            <?php endforeach; ?>
        </dl>
        <p class="wa-panel__hint">
            Anbieter bauen ihre Oberflächen gelegentlich um. Die Angaben beschreiben deshalb
            den Weg, nicht die genaue Beschriftung eines Knopfes.
        </p>
    </section>
<?php endif; ?>
