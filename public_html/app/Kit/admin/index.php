<?php

/**
 * Der Bearbeitungsbereich beim Kunden.
 *
 * Eine einzige Eingangsdatei, wie bei der WebAtze-Seite auch. Sie prüft
 * die Anmeldung, nimmt Formulare entgegen und zeigt die Oberfläche.
 *
 * Nach jeder Änderung wird die Website neu geschrieben. Sie besteht aus
 * fertigen HTML-Dateien; das ist der Preis dafür, dass sie überall läuft
 * und schnell ist.
 */

declare(strict_types=1);

require __DIR__ . '/lib/boot.php';

use WebAtzeKit\{Assistant, Auth, Csrf, Fields, Publisher, Store, Uploads};
use WebAtze\Templates\{Catalog, Schema};

$nonce = base64_encode(random_bytes(16));
kit_headers($nonce);

$configFile = DATA_DIR . '/admin.php';
$config = is_file($configFile) ? (require $configFile) : [];

$auth = new Auth($config, DATA_DIR);
$auth->start();

$store = new Store(DATA_DIR);
$publisher = new Publisher($store, SITE_DIR);
$assistant = new Assistant($config);

$brand = (string) ($config['brand'] ?? 'Website');
$base = Auth::basePath();

$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

/** Eine Meldung für die nächste Seite hinterlegen. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Zurück zur aufrufenden Seite. */
function back(string $fallback = ''): never
{
    $target = $fallback !== '' ? $fallback : (string) ($_POST['_back'] ?? Auth::basePath());
    header('Location: ' . $target);
    exit;
}

/** Website neu schreiben und melden, falls es klemmt. */
function republish(Publisher $publisher): void
{
    $result = $publisher->publish();

    if (!$result['ok']) {
        flash('error', 'Gespeichert – aber die Website liess sich nicht neu schreiben: '
            . $result['error']);
    }
}

// ------------------------------------------------------------ Anmeldung

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($action === 'login' && $isPost) {
    // Auch die Anmeldung braucht das Kennwort aus dem Formular. Ohne das
    // könnte eine fremde Seite jemanden ungefragt anmelden.
    if (!Csrf::check()) {
        $_SESSION['login_error'] = 'Die Seite war zu lange offen. Bitte noch einmal versuchen.';
        back($base);
    }

    $result = $auth->login(
        trim((string) ($_POST['username'] ?? '')),
        (string) ($_POST['password'] ?? '')
    );

    if (!$result['ok']) {
        $_SESSION['login_error'] = $result['error'];
    }

    back($base);
}

if (!$auth->isLoggedIn()) {
    $loginError = (string) ($_SESSION['login_error'] ?? '');
    unset($_SESSION['login_error']);

    require __DIR__ . '/views/login.php';
    exit;
}

// Ab hier ist man angemeldet. Jede ändernde Anfrage braucht das Kennwort.
if ($isPost && !Csrf::check()) {
    flash('error', 'Die Sicherheitsprüfung ist fehlgeschlagen. '
        . 'Bitte die Seite neu laden und noch einmal versuchen.');
    back($base);
}

if ($action === 'logout' && $isPost) {
    $auth->logout();
    header('Location: ' . $base);
    exit;
}

// ------------------------------------------------------------- Aktionen

if ($isPost) {
    switch ($action) {

        // --- Texte eines Abschnitts speichern -------------------------
        case 'section':
            $id = (int) ($_POST['section_id'] ?? 0);
            $found = $store->section($id);

            if ($found === null) {
                flash('error', 'Diesen Abschnitt gibt es nicht.');
                back();
            }

            $type = (string) ($found['section']['type'] ?? '');
            $values = (array) ($_POST['content'] ?? []);

            // Geprüft wird gegen das Schema des Abschnittstyps. Was dort
            // nicht steht, kommt nicht hinein – und mehr als diesen einen
            // Abschnitt kann dieser Vorgang gar nicht erreichen.
            $ok = $store->updateSection($id, static function (array $section) use ($type, $values): array {
                $section['content'] = Fields::merge($type, $section['content'] ?? [], $values);
                return $section;
            });

            if ($ok) {
                republish($publisher);
                flash('success', 'Gespeichert.');
            } else {
                flash('error', 'Das Speichern hat nicht geklappt.');
            }
            back();

        // --- Vorlage wechseln -----------------------------------------
        case 'template':
            $id = (int) ($_POST['section_id'] ?? 0);
            $key = (string) ($_POST['template'] ?? '');
            $found = $store->section($id);

            if ($found === null) {
                flash('error', 'Diesen Abschnitt gibt es nicht.');
                back();
            }

            $type = (string) ($found['section']['type'] ?? '');

            if (!Catalog::exists($type, $key)) {
                flash('error', 'Diese Vorlage gibt es für diesen Abschnitt nicht.');
                back();
            }

            $store->updateSection($id, static function (array $section) use ($key): array {
                $section['template_key'] = $key;
                return $section;
            });

            republish($publisher);
            flash('success', 'Vorlage gewechselt: ' . Catalog::label($type, $key));
            back();

        // --- Ein- und ausblenden --------------------------------------
        case 'toggle':
            $id = (int) ($_POST['section_id'] ?? 0);
            $hidden = null;

            $store->updateSection($id, static function (array $section) use (&$hidden): array {
                $hidden = empty($section['hidden']);
                $section['hidden'] = $hidden;
                return $section;
            });

            republish($publisher);
            flash('success', $hidden ? 'Abschnitt ausgeblendet.' : 'Abschnitt wieder sichtbar.');
            back();

        // --- Verschieben ----------------------------------------------
        case 'move':
            $id = (int) ($_POST['section_id'] ?? 0);
            $direction = ($_POST['direction'] ?? 'up') === 'up' ? -1 : 1;
            $found = $store->section($id);

            if ($found === null) {
                flash('error', 'Diesen Abschnitt gibt es nicht.');
                back();
            }

            $site = $store->all();
            $sections = $site['pages'][$found['pageIndex']]['sections'];
            $from = $found['index'];
            $to = $from + $direction;

            // Kopfzeile und Fusszeile bleiben, wo sie sind.
            if ($to < 1 || $to > count($sections) - 2 || $from < 1 || $from > count($sections) - 2) {
                flash('warning', 'Weiter lässt sich der Abschnitt nicht verschieben.');
                back();
            }

            [$sections[$from], $sections[$to]] = [$sections[$to], $sections[$from]];
            $site['pages'][$found['pageIndex']]['sections'] = array_values($sections);

            $store->save($site);
            republish($publisher);
            flash('success', 'Abschnitt verschoben.');
            back();

        // --- Bild austauschen -----------------------------------------
        case 'image':
            $id = (int) ($_POST['section_id'] ?? 0);
            $field = (string) ($_POST['field'] ?? 'image');

            $uploads = new Uploads(SITE_DIR . '/assets/img');
            $result = $uploads->accept($_FILES['bild'] ?? []);

            if (!$result['ok']) {
                flash('error', $result['error']);
                back();
            }

            $store->updateSection($id, static function (array $section) use ($field, $result): array {
                $content = $section['content'] ?? [];
                $alt = is_array($content[$field] ?? null)
                    ? (string) ($content[$field]['alt'] ?? '')
                    : '';

                $content[$field] = [
                    'src' => $result['src'],
                    'alt' => $alt,
                    'width' => $result['width'],
                    'height' => $result['height'],
                ];

                $section['content'] = $content;
                return $section;
            });

            republish($publisher);
            flash('success', 'Bild ausgetauscht.');
            back();

        // --- Der Assistent --------------------------------------------
        case 'assistant':
            $id = (int) ($_POST['section_id'] ?? 0);
            $instruction = (string) ($_POST['instruction'] ?? '');

            $result = $assistant->edit($id, $instruction);

            if (!$result['ok']) {
                flash('error', $result['error']);
                back();
            }

            $store->updateSection($id, static function (array $section) use ($result): array {
                $section['content'] = $result['content'];
                $section['overrides'] = $result['overrides'];
                if ($result['template'] !== '') {
                    $section['template_key'] = $result['template'];
                }
                return $section;
            });

            // Im Verlauf steht der Assistent, nicht das Werkzeug dahinter.
            $site = $store->all();
            $site['changes'] = array_slice(array_merge([[
                'at' => date('c'),
                'by' => 'Angepasst durch den WebAtze-Assistent',
                'what' => $result['summary'],
                'section' => $id,
            ]], $site['changes'] ?? []), 0, 100);
            $store->save($site);

            republish($publisher);
            flash('success', $result['summary']);
            back();

        // --- Seitenangaben (SEO) --------------------------------------
        case 'page':
            $path = (string) ($_POST['path'] ?? '');

            $ok = $store->updatePage($path, static function (array $page): array {
                $page['title'] = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 120);
                $page['meta_description'] = mb_substr(
                    trim((string) ($_POST['meta_description'] ?? '')),
                    0,
                    300
                );
                $page['in_navigation'] = !empty($_POST['in_navigation']);
                return $page;
            });

            if ($ok) {
                republish($publisher);
                flash('success', 'Seitenangaben gespeichert.');
            } else {
                flash('error', 'Diese Seite gibt es nicht.');
            }
            back();

        // --- Eine Sicherung zurückholen -------------------------------
        case 'restore':
            if ($store->restore((string) ($_POST['backup'] ?? ''))) {
                republish($publisher);
                flash('success', 'Der frühere Stand ist zurück.');
            } else {
                flash('error', 'Diese Sicherung liess sich nicht zurückholen.');
            }
            back();

        // --- Anfrage als gelesen markieren ----------------------------
        case 'lead':
            $leadsFile = DATA_DIR . '/leads.php';
            $leads = Store::readGuarded($leadsFile);
            $wanted = (string) ($_POST['lead'] ?? '');

            foreach ($leads as $index => $lead) {
                if ((string) ($lead['id'] ?? '') === $wanted) {
                    $leads[$index]['read'] = ($_POST['state'] ?? 'read') === 'read';
                    break;
                }
            }

            Store::writeGuarded($leadsFile, $leads);
            back();
    }
}

// ------------------------------------------------------------- Anzeigen

$view = (string) ($_GET['ansicht'] ?? 'seiten');
$currentPath = (string) ($_GET['seite'] ?? '/');
$sectionId = (int) ($_GET['abschnitt'] ?? 0);

require __DIR__ . '/views/layout.php';
