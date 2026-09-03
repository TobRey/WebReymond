<?php

/**
 * Der Tresor.
 *
 * Auf dieser Seite steht kein einziges Passwort. Was hier steht, sind
 * Namen, Benutzernamen und Adressen; das Passwort selbst wird einzeln
 * geholt, wenn der Knopf gedrückt wird, und geht direkt in die
 * Zwischenablage.
 */

use WebAtze\Core\{Config, Csrf};
use WebAtze\Domain\Vault;

/** @var array<int, array<string, mixed>> $eintraege */
/** @var string $suche */
/** @var bool $offen */
/** @var int $restzeit */
/** @var array<string, string> $arten */
/** @var array<int, array<string, mixed>> $kunden */
/** @var string $vorschlag */
/** @var string $schluesselFehler */
/** @var bool $schluesselSchreibbar */
/** @var int $verschluesselt */
/** @var string $vorschlagSchluessel */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
?>

<?php if ($schluesselFehler !== ''): ?>
    <?php /*
        Ohne Schlüssel kann der Tresor nichts verschlüsseln. Früher endete
        das Speichern hier in einer Fehlerseite, die nicht sagte, woran es
        liegt. Jetzt steht es da - samt dem Weg hinaus.
    */ ?>
    <section class="wa-panel wa-panel--alarm">
        <header class="wa-panel__head">
            <h2 class="wa-panel__title">Der Tresor kann nicht verschlüsseln</h2>
        </header>

        <p class="wa-alert wa-alert--bad"><?= e($schluesselFehler) ?></p>

        <p class="wa-panel__hint">
            Der Schlüssel steht in <code>app/config.php</code> unter <code>crypto_key</code>.
            Er gehört dorthin und nicht in die Datenbank – so nützt eine gestohlene
            Datenbanksicherung allein nichts.
        </p>

        <?php if (!function_exists('sodium_crypto_secretbox')): ?>
            <p class="wa-panel__hint">
                Hier hilft nur cPanel: <strong>Select PHP Version → Extensions → sodium</strong>
                einschalten. Danach diese Seite neu laden.
            </p>
        <?php elseif ($verschluesselt > 0): ?>
            <p class="wa-panel__hint">
                Es liegen <strong><?= (int) $verschluesselt ?></strong> verschlüsselte Einträge vor.
                Ein neuer Schlüssel würde sie unlesbar machen. Trage deshalb den
                <strong>ursprünglichen</strong> <code>crypto_key</code> wieder ein.
            </p>
        <?php elseif ($schluesselSchreibbar): ?>
            <p class="wa-panel__hint">
                Es ist noch nichts verschlüsselt – ein neuer Schlüssel kostet also nichts.
            </p>
            <form method="post" action="<?= e($base) ?>/passwoerter/schluessel"
                  data-confirm="Einen neuen Schlüssel in app/config.php eintragen?">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn wa-btn--primary">Schlüssel jetzt anlegen</button>
            </form>
        <?php else: ?>
            <p class="wa-panel__hint">
                <code>app/config.php</code> ist nicht beschreibbar. Trage die Zeile im
                cPanel-Dateimanager von Hand ein:
            </p>
            <div class="wa-copybox">
                <input class="wa-input" type="text" id="schluesselzeile" readonly
                       value="'crypto_key' =&gt; '<?= e($vorschlagSchluessel) ?>',"
                       aria-label="Zeile für app/config.php">
                <button type="button" class="wa-btn wa-btn--primary" data-copy="#schluesselzeile">
                    Zeile kopieren
                </button>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (!$offen): ?>
    <section class="wa-panel wa-panel--locked">
        <header class="wa-panel__head">
            <h2 class="wa-panel__title">Der Tresor ist zu</h2>
        </header>

        <p class="wa-panel__hint">
            Zum Ansehen der Passwörter muss das Anmeldepasswort noch einmal eingegeben werden.
            Danach bleibt der Tresor <?= (int) round(Vault::OPEN_SECONDS / 60) ?> Minuten offen.
            Das klingt umständlich und ist es auch – genau darum geht es: Ein offen stehender
            Bildschirm gibt so nicht gleich alle Kundenzugänge her.
        </p>

        <form method="post" action="<?= e($base) ?>/passwoerter/oeffnen" class="wa-form wa-form--inline">
            <?= Csrf::field() ?>
            <div class="wa-field">
                <label class="wa-label" for="v-pass">Dein Anmeldepasswort</label>
                <input class="wa-input" type="password" id="v-pass" name="password"
                       autocomplete="current-password" required>
            </div>
            <div class="wa-form__actions">
                <button type="submit" class="wa-btn wa-btn--primary">Aufschliessen</button>
            </div>
        </form>
    </section>
<?php else: ?>
    <div class="wa-tiles">
        <div class="wa-tile">
            <span class="wa-tile__label">Zugänge</span>
            <strong class="wa-tile__value"><?= count($eintraege) ?></strong>
        </div>
        <div class="wa-tile">
            <span class="wa-tile__label">Tresor offen noch</span>
            <strong class="wa-tile__value"><?= (int) ceil($restzeit / 60) ?> Min.</strong>
        </div>
        <div class="wa-tile">
            <form method="post" action="<?= e($base) ?>/passwoerter/schliessen">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn">Jetzt zuschliessen</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<section class="wa-panel">
    <header class="wa-panel__head">
        <form method="get" class="wa-filter">
            <input class="wa-input wa-input--short" type="search" name="suche"
                   value="<?= e($suche) ?>" placeholder="Suchen …" aria-label="Zugänge suchen">
            <button type="submit" class="wa-btn">Zeigen</button>
        </form>

        <?php if ($offen): ?>
            <form method="post" action="<?= e($base) ?>/passwoerter/uebernehmen">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn wa-btn--small">FTP-Zugänge übernehmen</button>
            </form>
        <?php endif; ?>
    </header>

    <?php if ($eintraege === []): ?>
        <p class="wa-empty">Noch nichts im Tresor.</p>
    <?php else: ?>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr>
                        <th>Wofür</th>
                        <th>Kunde</th>
                        <th>Benutzername</th>
                        <th>Passwort</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eintraege as $s): ?>
                        <tr>
                            <td>
                                <strong class="wa-table__main"><?= e((string) $s['label']) ?></strong>
                                <span class="wa-table__quiet"><?= e($arten[(string) $s['kind']] ?? '') ?></span>
                                <?php if ((string) $s['url'] !== ''): ?>
                                    <span class="wa-table__quiet"><?= e((string) $s['url']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__quiet"><?= e((string) ($s['kunde'] ?? '–')) ?></td>
                            <td>
                                <?php if ((string) $s['username'] !== ''): ?>
                                    <span id="benutzer-<?= (int) $s['id'] ?>"><?= e((string) $s['username']) ?></span>
                                    <button type="button" class="wa-btn wa-btn--small"
                                            data-copy="#benutzer-<?= (int) $s['id'] ?>">Kopieren</button>
                                <?php else: ?>
                                    <span class="wa-table__quiet">–</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $s['hat_geheimnis'] !== 1): ?>
                                    <span class="wa-table__quiet">keines hinterlegt</span>
                                <?php elseif (!$offen): ?>
                                    <span class="wa-table__quiet">••••••••</span>
                                <?php else: ?>
                                    <button type="button" class="wa-btn wa-btn--small wa-btn--primary"
                                            data-reveal="<?= e($base) ?>/passwoerter/zeigen"
                                            data-secret-id="<?= (int) $s['id'] ?>"
                                            data-reveal-into="#klartext-<?= (int) $s['id'] ?>">
                                        Kopieren
                                    </button>
                                    <?php /* Nur der Notausgang ohne HTTPS: bleibt leer und
                                             versteckt, bis die Bedienung ihn braucht. */ ?>
                                    <input class="wa-input wa-input--short" type="text" hidden readonly
                                           id="klartext-<?= (int) $s['id'] ?>" value=""
                                           aria-label="Passwort im Klartext">
                                <?php endif; ?>
                            </td>
                            <td class="wa-table__actions">
                                <?php if ($offen): ?>
                                    <details class="wa-details">
                                        <summary>Ändern</summary>
                                        <form method="post" action="<?= e($base) ?>/passwoerter/speichern"
                                              class="wa-form">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="secret_id" value="<?= (int) $s['id'] ?>">

                                            <div class="wa-field">
                                                <label class="wa-label" for="l-<?= (int) $s['id'] ?>">Wofür</label>
                                                <input class="wa-input" type="text" name="label"
                                                       id="l-<?= (int) $s['id'] ?>"
                                                       value="<?= e((string) $s['label']) ?>" required>
                                            </div>

                                            <div class="wa-field">
                                                <label class="wa-label" for="u-<?= (int) $s['id'] ?>">Benutzername</label>
                                                <input class="wa-input" type="text" name="username"
                                                       id="u-<?= (int) $s['id'] ?>" autocomplete="off"
                                                       value="<?= e((string) $s['username']) ?>">
                                            </div>

                                            <div class="wa-field">
                                                <label class="wa-label" for="s-<?= (int) $s['id'] ?>">
                                                    Neues Passwort
                                                </label>
                                                <input class="wa-input" type="text" name="secret"
                                                       id="s-<?= (int) $s['id'] ?>" autocomplete="off"
                                                       placeholder="leer lassen, dann bleibt das bisherige">
                                                <button type="button" class="wa-btn wa-btn--small"
                                                        data-generate-password="#s-<?= (int) $s['id'] ?>">
                                                    Neues erzeugen
                                                </button>
                                            </div>

                                            <div class="wa-field">
                                                <label class="wa-label" for="k-<?= (int) $s['id'] ?>">Art</label>
                                                <select class="wa-select" name="kind" id="k-<?= (int) $s['id'] ?>">
                                                    <?php foreach ($arten as $wert => $text): ?>
                                                        <option value="<?= e($wert) ?>"
                                                            <?= (string) $s['kind'] === $wert ? ' selected' : '' ?>>
                                                            <?= e($text) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="wa-field">
                                                <label class="wa-label" for="c-<?= (int) $s['id'] ?>">Kunde</label>
                                                <select class="wa-select" name="customer_id"
                                                        id="c-<?= (int) $s['id'] ?>">
                                                    <option value="0">– keiner –</option>
                                                    <?php foreach ($kunden as $k): ?>
                                                        <option value="<?= (int) $k['id'] ?>"
                                                            <?= (int) ($s['customer_id'] ?? 0) === (int) $k['id']
                                                                ? ' selected' : '' ?>>
                                                            <?= e((string) $k['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="wa-field wa-field--wide">
                                                <label class="wa-label" for="w-<?= (int) $s['id'] ?>">Adresse</label>
                                                <input class="wa-input" type="text" name="url"
                                                       id="w-<?= (int) $s['id'] ?>"
                                                       value="<?= e((string) $s['url']) ?>">
                                            </div>

                                            <div class="wa-field wa-field--wide">
                                                <label class="wa-label" for="n-<?= (int) $s['id'] ?>">Notiz</label>
                                                <textarea class="wa-input wa-textarea" rows="2" name="note"
                                                          id="n-<?= (int) $s['id'] ?>"><?= e((string) $s['note']) ?></textarea>
                                            </div>

                                            <div class="wa-form__actions">
                                                <button type="submit" class="wa-btn wa-btn--primary">Speichern</button>
                                            </div>
                                        </form>

                                        <form method="post" action="<?= e($base) ?>/passwoerter/loeschen"
                                              data-confirm="Diesen Zugang endgültig löschen?">
                                            <?= Csrf::field() ?>
                                            <input type="hidden" name="secret_id" value="<?= (int) $s['id'] ?>">
                                            <button type="submit" class="wa-btn wa-btn--small">Löschen</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($offen): ?>
        <details class="wa-details">
            <summary>Zugang hinzufügen</summary>
            <form method="post" action="<?= e($base) ?>/passwoerter/speichern" class="wa-form wa-form--inline">
                <?= Csrf::field() ?>

                <div class="wa-field">
                    <label class="wa-label" for="neu-label">Wofür</label>
                    <input class="wa-input" type="text" id="neu-label" name="label" required
                           placeholder="z.B. Muster AG – cPanel">
                </div>

                <div class="wa-field">
                    <label class="wa-label" for="neu-kind">Art</label>
                    <select class="wa-select" id="neu-kind" name="kind">
                        <?php foreach ($arten as $wert => $text): ?>
                            <option value="<?= e($wert) ?>"><?= e($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wa-field">
                    <label class="wa-label" for="neu-customer">Kunde</label>
                    <select class="wa-select" id="neu-customer" name="customer_id">
                        <option value="0">– keiner –</option>
                        <?php foreach ($kunden as $k): ?>
                            <option value="<?= (int) $k['id'] ?>"><?= e((string) $k['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wa-field">
                    <label class="wa-label" for="neu-user">Benutzername</label>
                    <input class="wa-input" type="text" id="neu-user" name="username" autocomplete="off">
                </div>

                <div class="wa-field">
                    <label class="wa-label" for="neu-secret">Passwort</label>
                    <input class="wa-input" type="text" id="neu-secret" name="secret" autocomplete="off"
                           value="<?= e($vorschlag) ?>">
                    <button type="button" class="wa-btn wa-btn--small"
                            data-generate-password="#neu-secret">Neues erzeugen</button>
                </div>

                <div class="wa-field">
                    <label class="wa-label" for="neu-url">Adresse</label>
                    <input class="wa-input" type="text" id="neu-url" name="url"
                           placeholder="z.B. https://cpanel.kunde.ch">
                </div>

                <div class="wa-field wa-field--wide">
                    <label class="wa-label" for="neu-note">Notiz</label>
                    <textarea class="wa-input wa-textarea" rows="2" id="neu-note" name="note"></textarea>
                </div>

                <div class="wa-form__actions">
                    <button type="submit" class="wa-btn wa-btn--primary">In den Tresor legen</button>
                </div>
            </form>
        </details>
    <?php endif; ?>

    <p class="wa-panel__hint">
        Wie das hier geschützt ist: Jedes Passwort liegt verschlüsselt in der Datenbank, der
        Schlüssel dazu steht in <code>app/config.php</code> – eine gestohlene Datenbanksicherung
        allein nützt also nichts. Kein Passwort steht je im Quelltext dieser Seite. Vor dem
        ersten Ansehen wird das Anmeldepasswort verlangt, falsche Versuche werden gebremst, und
        jeder Blick landet im Protokoll. Kopiertes wird nach 45 Sekunden aus der Zwischenablage
        entfernt.
    </p>
</section>
