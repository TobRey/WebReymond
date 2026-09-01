<?php
/**
 * Veröffentlichen: Paket schnüren, herunterladen, hochladen.
 *
 * Das Paket entsteht immer – auch dann, wenn kein FTP-Zugang hinterlegt
 * ist. Es ist der verlässliche Weg, eine fertige Website in die Hand zu
 * bekommen.
 */

use WebAtze\Core\{Config, Csrf};

/** @var array $project @var array|null $target @var array $builds */
/** @var array|null $job @var array $providers @var array $brief */

$base = '/' . trim((string) Config::get('create_path', 'create'), '/');
$id = (int) $project['id'];

$protocol = (string) ($target['protocol'] ?? 'sftp');
$port = (int) ($target['port'] ?? ($protocol === 'sftp' ? 22 : 21));
$latest = $builds[0] ?? null;
?>

<p class="wa-intro">
    Jede fertige Website liegt als Paket bereit. Wer die Zugangsdaten des Kunden hinterlegt,
    kann sie zusätzlich direkt auf dessen Server laden – danach wird nachgesehen, ob die
    Seite auch wirklich erreichbar ist.
</p>

<?php if ($job !== null): ?>
    <section class="wa-panel">
        <div class="wa-panel__head"><h2 class="wa-panel__title">Läuft gerade</h2></div>
        <div class="wa-job" data-job-watch="<?= (int) $job['id'] ?>" data-job-reload="1">
            <div class="wa-job__row">
                <strong><?= (string) $job['type'] === 'deploy' ? 'Website wird hochgeladen' : 'Auftrag läuft' ?></strong>
                <span class="wa-job__value" data-job-label><?= (int) $job['progress'] ?>%</span>
            </div>
            <div class="wa-progress">
                <div class="wa-progress__bar" data-job-bar style="--value: <?= (int) $job['progress'] ?>%"></div>
            </div>
            <div class="wa-job__row">
                <span class="wa-job__step" data-job-step><?= e((string) $job['message']) ?></span>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php /* -------------------------------------------------------------- Pakete */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Paket</h2>
        <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/zip">
            <?= Csrf::field() ?>
            <button type="submit" class="wa-btn wa-btn--sm">Neues Paket erstellen</button>
        </form>
    </div>

    <?php if ($builds === []): ?>
        <div class="wa-empty-state">
            <p>Noch kein Paket vorhanden. Die Website muss zuerst gebaut werden.</p>
            <a class="wa-btn" href="<?= e($base) ?>/projekt/<?= $id ?>">Zurück zum Projekt</a>
        </div>
    <?php else: ?>
        <p class="wa-panel__hint">
            Das Paket wird in <code>public_html</code> des Kunden entpackt und läuft sofort –
            ohne Installation und ohne Kommandozeile. Ältere Versionen bleiben erhalten.
        </p>
        <div class="wa-table-wrap">
            <table class="wa-table">
                <thead>
                    <tr><th>Version</th><th>Dateien</th><th>Grösse</th><th>Erstellt</th><th>Notiz</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($builds as $build): ?>
                    <tr>
                        <td>
                            v<?= (int) $build['version'] ?>
                            <?php if ($latest !== null && (int) $build['id'] === (int) $latest['id']): ?>
                                <span class="wa-badge wa-badge--done">aktuell</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $build['files_count'] ?></td>
                        <td><?= e(format_bytes((int) $build['zip_bytes'])) ?></td>
                        <td><?= e(date('d.m.Y H:i', strtotime((string) $build['created_at']))) ?></td>
                        <td><?= e((string) $build['notes']) ?></td>
                        <td class="wa-table__right">
                            <a class="wa-btn wa-btn--quiet wa-btn--sm"
                               href="<?= e($base) ?>/projekt/<?= $id ?>/zip/<?= (int) $build['id'] ?>">
                                Herunterladen
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php /* ------------------------------------------------------------ Zugangsdaten */ ?>
<section class="wa-panel">
    <div class="wa-panel__head">
        <h2 class="wa-panel__title">Zugang zum Server des Kunden</h2>
        <p class="wa-panel__hint">
            Das Passwort wird verschlüsselt abgelegt und nie wieder angezeigt – auch hier nicht.
            Wer es ändern will, gibt einfach ein neues ein.
        </p>
    </div>

    <?php
    /**
     * Nur die Anleitung des Anbieters zeigen, der im Formular angegeben
     * wurde – neun aufgeklappte Kästen nebeneinander wären keine Hilfe,
     * sondern eine Wand. Der Rest liegt darunter, einen Klick entfernt.
     */
    $chosen = (string) ($brief['hosting_provider'] ?? 'other');
    if (!isset($providers['hosting'][$chosen])) {
        $chosen = 'other';
    }

    $helpBlock = static function (array $info): void { ?>
        <div class="wa-help__body">
            <ol>
                <?php foreach ($info['steps'] as $step): ?>
                    <li><?= $step /* fest im Code hinterlegt, kein Benutzertext */ ?></li>
                <?php endforeach; ?>
            </ol>
            <p><?= $info['note'] ?></p>
        </div>
    <?php };
    ?>

    <details class="wa-help" open>
        <summary>Wo finde ich die Zugangsdaten bei <?= e($providers['hosting'][$chosen]['name']) ?>?</summary>
        <?php $helpBlock($providers['hosting'][$chosen]); ?>
    </details>

    <details class="wa-help">
        <summary>Bei einem anderen Anbieter</summary>
        <div class="wa-help__body">
            <?php foreach ($providers['hosting'] as $key => $info): ?>
                <?php if ($key === $chosen) { continue; } ?>
                <details class="wa-help">
                    <summary><?= e($info['name']) ?></summary>
                    <?php $helpBlock($info); ?>
                </details>
            <?php endforeach; ?>
        </div>
    </details>

    <form class="wa-form" method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/ftp" autocomplete="off">
        <?= Csrf::field() ?>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="protocol">Verbindungsart</label>
                <select class="wa-select" id="protocol" name="protocol">
                    <option value="sftp" <?= $protocol === 'sftp' ? 'selected' : '' ?>>SFTP (empfohlen)</option>
                    <option value="ftps" <?= $protocol === 'ftps' ? 'selected' : '' ?>>FTP mit Verschlüsselung</option>
                    <option value="ftp" <?= $protocol === 'ftp' ? 'selected' : '' ?>>FTP</option>
                </select>
                <span class="wa-label__hint">
                    SFTP verschlüsselt alles. Reines FTP schickt das Passwort im Klartext –
                    nur nehmen, wenn der Anbieter nichts anderes anbietet.
                </span>
            </div>

            <div class="wa-field">
                <label class="wa-label" for="port">Port</label>
                <input class="wa-input" type="number" id="port" name="port" min="1" max="65535"
                       value="<?= $port ?>">
            </div>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="host">Server</label>
                <input class="wa-input" type="text" id="host" name="host" placeholder="ftp.beispiel.ch"
                       value="<?= e((string) ($target['host'] ?? '')) ?>">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="username">Benutzername</label>
                <input class="wa-input" type="text" id="username" name="username" autocomplete="off"
                       value="<?= e((string) ($target['username'] ?? '')) ?>">
            </div>
        </div>

        <div class="wa-grid-2">
            <div class="wa-field">
                <label class="wa-label" for="password">Passwort</label>
                <input class="wa-input" type="password" id="password" name="password"
                       autocomplete="new-password"
                       placeholder="<?= $target !== null ? 'unverändert lassen' : '' ?>">
            </div>

            <div class="wa-field">
                <label class="wa-label" for="path">Verzeichnis</label>
                <input class="wa-input" type="text" id="path" name="path"
                       value="<?= e((string) ($target['remote_path'] ?? '/public_html')) ?>">
                <span class="wa-label__hint">
                    Meist <code>/public_html</code>, bei Plesk <code>/httpdocs</code>.
                    Bei einer <strong>Subdomain</strong> der Ordner darunter, also etwa
                    <code>/public_html/preview</code>. Ein FTP-Zugang der Hauptdomain
                    kommt dort hin &ndash; ein eigener Zugang je Subdomain ist nicht nötig.
                </span>

                <?php
                    $ordner = (array) ($gefunden['ordner'] ?? []);
                    $vorschlag = (string) ($gefunden['vorschlag'] ?? '');
                ?>
                <?php if ($ordner !== []): ?>
                    <div class="wa-found">
                        <p class="wa-found__title">
                            Beim letzten Test dort gefunden &ndash; zum Übernehmen anklicken:
                        </p>
                        <div class="wa-found__list">
                            <?php foreach ($ordner as $eintrag): ?>
                                <button type="button" class="wa-found__item<?= $eintrag === $vorschlag ? ' is-suggested' : '' ?>"
                                        data-fill="#path" data-fill-value="<?= e((string) $eintrag) ?>">
                                    <?= e((string) $eintrag) ?><?= $eintrag === $vorschlag ? ' ·  passt vermutlich' : '' ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="wa-form__actions">
            <button type="submit" class="wa-btn wa-btn--primary">Zugangsdaten speichern</button>
        </div>
    </form>

    <?php if ($target !== null): ?>
        <div class="wa-form__actions">
            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/ftp/testen">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn">Verbindung testen</button>
            </form>
            <form method="post" action="<?= e($base) ?>/projekt/<?= $id ?>/hochladen"
                  data-confirm="Die Website jetzt auf den Server des Kunden laden? Bestehende Dateien im Zielverzeichnis werden überschrieben.">
                <?= Csrf::field() ?>
                <button type="submit" class="wa-btn wa-btn--primary">Website hochladen</button>
            </form>
        </div>

        <?php if ((string) ($target['last_result'] ?? '') !== ''): ?>
            <div class="wa-note">
                <div>
                    <strong>Zuletzt:</strong> <?= e((string) $target['last_result']) ?>
                    <?php if ((string) ($target['last_deployed_at'] ?? '') !== ''): ?>
                        (<?= e(date('d.m.Y H:i', strtotime((string) $target['last_deployed_at']))) ?>)
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
