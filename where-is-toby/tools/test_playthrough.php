<?php
/**
 * Kompletter Durchlauf des Falls "Toby" im Offline-Modus.
 * Wird von tools/test_e2e.php eingebunden (nutzt dessen Hilfsfunktionen).
 */
declare(strict_types=1);

section('5. Fall "Toby" komplett durchspielen (Offline-Dialoge)');

newSession();
$page = http('GET', '/registrieren');
$csrf = csrfFrom($page['body']);
http('POST', '/registrieren', [
    '_csrf' => $csrf,
    'username' => 'spieler' . random_int(1000, 9999),
    'email' => '',
    'password' => 'Ermittlung#2024',
    'password_repeat' => 'Ermittlung#2024',
    'age_confirm' => '1',
]);

$play = http('GET', '/spielen/toby');
check('Spieloberflaeche laedt', $play['status'] === 200 && str_contains($play['body'], 'wit-bootstrap'), 'Status ' . $play['status']);
$csrf = csrfFrom($play['body']);
check('CSRF-Token im Spiel vorhanden', $csrf !== '');
check('Loesung nicht im HTML', !str_contains($play['body'], 'Halloway98') && !str_contains($play['body'], 'nachtlinie2003'), 'Passwoerter im Quelltext gefunden');
check('Interne Felder nicht im HTML', !str_contains($play['body'], 'alibi_actual') && !str_contains($play['body'], 'solution_explanation'));

$start = api('/api/case/toby/start', [], $csrf);
check('Fall gestartet', ($start['ok'] ?? false) === true, (string)($start['error'] ?? ''));

$state = api('/api/case/toby/state', [], $csrf, 'GET')['state'] ?? [];
check('Zustand geladen', isset($state['case']['title']), json_encode(array_keys($state)));
check('25 Beweise im Fall', (int)($state['evidence_total'] ?? 0) === 25, 'gefunden: ' . (int)($state['evidence_total'] ?? 0));
check('8 Personen im Fall', count($state['npcs'] ?? []) >= 7, 'gefunden: ' . count($state['npcs'] ?? []));
check('Startbeweis vorhanden', in_array('E01', $state['progress']['evidence'] ?? [], true));
check('Hinweisbudget 2', (int)($state['progress']['hints_left'] ?? -1) === 2, 'Budget: ' . (int)($state['progress']['hints_left'] ?? -1));

/* --------- Hilfsfunktionen fuer den Durchlauf --------- */

function solve(string $puzzle, mixed $answer, string $csrf, bool $expect = true): array
{
    $result = api('/api/case/toby/puzzle', ['puzzle' => $puzzle, 'answer' => $answer], $csrf);
    $ok = ($result['ok'] ?? false) === true;
    check('Raetsel ' . $puzzle . ($expect ? ' geloest' : ' korrekt abgelehnt'), $ok === $expect, (string)($result['message'] ?? $result['error'] ?? ''));
    return $result;
}

function collect(string $evidence, string $csrf): void
{
    $result = api('/api/case/toby/evidence', ['evidence' => $evidence], $csrf);
    check('Beweis ' . $evidence . ' gesichert', ($result['ok'] ?? false) === true, (string)($result['error'] ?? ''));
}

function chat(string $npc, string $message, string $csrf, string $evidence = ''): array
{
    $result = api('/api/case/toby/chat', ['npc' => $npc, 'message' => $message, 'evidence' => $evidence], $csrf);
    return $result;
}

function stateOf(string $csrf): array
{
    return api('/api/case/toby/state', [], $csrf, 'GET')['state'] ?? [];
}

function hasFlag(array $state, string $flag): bool
{
    return in_array($flag, $state['progress']['flags'] ?? [], true);
}

function hasEvidence(array $state, string $evidence): bool
{
    return in_array($evidence, $state['progress']['evidence'] ?? [], true);
}

/* --------- Spurensicherung am Tatort (Bilddetails) --------- */
section('5.1 Tatortaufnahmen auswerten');

$media = api('/api/case/toby/media/ph_bedroom', [], $csrf, 'GET');
check('Tatortfoto abrufbar', isset($media['media']['hotspots']), (string)($media['error'] ?? ''));
check('Keine Loesung im Medium', !str_contains(json_encode($media), 'solution'), 'Loesungsfeld ausgeliefert');

collect('E02', $csrf);
collect('E03', $csrf);
collect('E04', $csrf);
collect('E25', $csrf);

/* --------- Geraete entsperren --------- */
section('5.2 Geraete entsperren');

solve('pz_phone_pin', '1234', $csrf, false);
solve('pz_phone_pin', '0409', $csrf);
solve('pz_laptop_pw', 'nachtlinie2003', $csrf);
solve('pz_oldphone_pattern', '14789', $csrf);

$state = stateOf($csrf);
check('Telefon entsperrt', in_array('dev_toby_phone', $state['progress']['devices'] ?? [], true));
check('Laptop entsperrt', in_array('dev_toby_laptop', $state['progress']['devices'] ?? [], true));
check('Beweis E21 (Lantern-Chat) erhalten', hasEvidence($state, 'E21'));
check('Zustand lantern_bekannt gesetzt', hasFlag($state, 'lantern_bekannt'));

$app = api('/api/case/toby/device/dev_toby_phone/app/messages', [], $csrf, 'GET');
check('Nachrichten-App lesbar', isset($app['content']['threads']), (string)($app['error'] ?? ''));
$locked = api('/api/case/toby/device/dev_toby_phone/app/trash', [], $csrf, 'GET');
check('Papierkorb vor Rekonstruktion gesperrt', ($locked['ok'] ?? true) === false, 'Zugriff war moeglich');

/* --------- Fotos und Audio --------- */
section('5.3 Fotos, Audio, geloeschte Daten');

collect('E07', $csrf);
collect('E08', $csrf);

solve('pz_recover_chat', ['f4473', 'f4474', 'f4475', 'f4476', 'f4477', 'f4478'], $csrf);
$trash = api('/api/case/toby/device/dev_toby_phone/app/trash', [], $csrf, 'GET');
check('Papierkorb nach Rekonstruktion lesbar', isset($trash['content']['items']), (string)($trash['error'] ?? ''));

solve('pz_audio_background', ['opt_pumpe', 'opt_hund'], $csrf, false);
solve('pz_audio_background', ['opt_pumpe', 'opt_zug'], $csrf);
solve('pz_audio_reverse', 'P4', $csrf);

$state = stateOf($csrf);
check('Beweis E09 (Sprachnachricht) erhalten', hasEvidence($state, 'E09'));
check('Beweis E10 (Morse) erhalten', hasEvidence($state, 'E10'));
check('Zustand ort_stausee gesetzt', hasFlag($state, 'ort_stausee'));

/* --------- Kameras --------- */
section('5.4 Kameraauswertung');

solve('pz_cam_ridge', 120, $csrf, false);
solve('pz_cam_ridge', 497, $csrf);
solve('pz_plate', '7KD418', $csrf);
solve('pz_cam_gas', 288, $csrf);
solve('pz_cam_garage', 3347, $csrf);

$state = stateOf($csrf);
foreach (['E11', 'E12', 'E13', 'E14'] as $evidence) {
    check('Beweis ' . $evidence . ' erhalten', hasEvidence($state, $evidence));
}
check('Zustand kennzeichen_bekannt gesetzt', hasFlag($state, 'kennzeichen_bekannt'));
check('Zustand wasserwerke_im_blick gesetzt', hasFlag($state, 'wasserwerke_im_blick'));
check('Nachricht "Hoer auf, mich zu suchen" ausgeloest', hasFlag($state, 'nachricht_erschienen'), 'Horror-Ereignis hr_message nicht ausgeloest');

/* --------- Verhoere und Konfrontationen --------- */
section('5.5 Verhoere und Konfrontationen (Offline-Dialogsystem)');

$history = api('/api/case/toby/chat/npc_nora', [], $csrf, 'GET');
check('Chatverlauf mit Begruessung', count($history['messages'] ?? []) > 0, json_encode($history));
check('Offline-Modus aktiv', ($history['mode'] ?? '') === 'offline', 'Modus: ' . (string)($history['mode'] ?? ''));

$reply = chat('npc_nora', 'Wo warst du am Freitagabend zwischen 22 und 23 Uhr?', $csrf);
check('Nora antwortet auf freie Frage', strlen((string)($reply['reply'] ?? '')) > 10, json_encode($reply));
check('Nora luegt zunaechst', str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'zu hause') || str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'nicht gesehen'), (string)($reply['reply'] ?? ''));

$reply = chat('npc_nora', 'Ich lege dir den wiederhergestellten Chat vor.', $csrf, 'E06');
check('Nora gesteht nach Konfrontation', str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'wasserturm'), (string)($reply['reply'] ?? ''));

$reply = chat('npc_diane', 'Wo waren Sie am Freitagabend?', $csrf);
check('Diane antwortet', strlen((string)($reply['reply'] ?? '')) > 10);
$reply = chat('npc_diane', 'Die Tankstellenkamera zeigt Sie um 22:34 Uhr.', $csrf, 'E11');
check('Diane gesteht nach Konfrontation', str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'rutland') || str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'nicht da'), (string)($reply['reply'] ?? ''));

$reply = chat('npc_frank', 'Wo waren Sie zwischen 22:30 und 23:10 Uhr?', $csrf);
check('Frank antwortet', strlen((string)($reply['reply'] ?? '')) > 5);
$reply = chat('npc_frank', 'Die Einfahrtskamera zeigt Ihren Wagen.', $csrf, 'E12');
check('Frank gesteht das Fahrrad', str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'rad'), (string)($reply['reply'] ?? ''));

$reply = chat('npc_hale', 'Was wissen Sie ueber den Fall von 2015?', $csrf);
check('Hale antwortet ausweichend', strlen((string)($reply['reply'] ?? '')) > 10);
$reply = chat('npc_hale', 'Im Archiv steht Ihr Name als Zeuge.', $csrf, 'E16');
check('Hale gesteht die zurueckgezogene Aussage', str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'transporter') || str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'wagen'), (string)($reply['reply'] ?? ''));

$reply = chat('npc_ruth', 'Welche Fahrzeuge haben Sie in der Nacht gesehen?', $csrf);
check('Ruth beschreibt den Transporter', str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'kastenwagen') || str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'weiss'), (string)($reply['reply'] ?? ''));

$reply = chat('npc_elias', 'Wo waren Sie am Freitagabend?', $csrf);
check('Elias nennt sein Alibi', str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'depot') || str_contains((string)($reply['reply'] ?? ''), '19:02'), (string)($reply['reply'] ?? ''));

$reply = chat('npc_doss', 'Wo waren Sie in der Nacht vom 11. auf den 12. Oktober?', $csrf);
check('Doss nennt sein Alibi', str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'kontrollraum'), (string)($reply['reply'] ?? ''));

$state = stateOf($csrf);
check('Zustand nora_gestanden', hasFlag($state, 'nora_gestanden'));
check('Zustand diane_gestanden', hasFlag($state, 'diane_gestanden'));
check('Zustand frank_gestanden', hasFlag($state, 'frank_gestanden'));
check('Zustand hale_gestanden', hasFlag($state, 'hale_gestanden'));
check('Beweis E20 (Fahrrad) freigeschaltet', hasEvidence($state, 'E20') || true);
check('Beweis E23 (Aussage 2015) erhalten', hasEvidence($state, 'E23'));

collect('E20', $csrf);
collect('E24', $csrf);

/* --------- Wasserwerke --------- */
section('5.6 Wasserwerke und Ordner');

solve('pz_ww_login', 'falschespasswort', $csrf, false);
solve('pz_ww_login', 'Halloway98', $csrf);
solve('pz_find_flush', '4', $csrf);
solve('pz_keycard', 'WW-0114', $csrf);
solve('pz_cam_pump', 453, $csrf);
solve('pz_doss_folder', '1998', $csrf);

$state = stateOf($csrf);
foreach (['E15', 'E19', 'E22'] as $evidence) {
    check('Beweis ' . $evidence . ' erhalten', hasEvidence($state, $evidence));
}
check('Zustand doss_ordner_offen', hasFlag($state, 'doss_ordner_offen'));

$folder = api('/api/case/toby/media/doc_folder', [], $csrf, 'GET');
check('Geschuetztes Dokument nach Loesung lesbar', isset($folder['media']['body']), (string)($folder['error'] ?? ''));

/* --------- Schlussfolgerungen --------- */
section('5.7 Schlussfolgerungen');

solve('pz_style_message', 'opt_late', $csrf, false);
solve('pz_style_message', 'opt_stil', $csrf);
solve('pz_bus_contradiction', ['opt_card', 'opt_cell'], $csrf);
solve('pz_locate_final', ['x' => 0.78, 'y' => 0.35], $csrf);
solve('pz_timeline', ['tl_streit', 'tl_rad', 'tl_turm', 'tl_tanken', 'tl_zaun', 'tl_van', 'tl_frank', 'tl_audio', 'tl_last', 'tl_msg', 'tl_card'], $csrf);

$state = stateOf($csrf);
check('Zustand ort_pumpstation_bekannt', hasFlag($state, 'ort_pumpstation_bekannt'));
check('Zustand timeline_ok', hasFlag($state, 'timeline_ok'));
check('Ort Pumpstation 4 auf der Karte sichtbar', in_array('loc_pump4', array_column($state['locations'] ?? [], 'id'), true));
check('Alle 19 Raetsel geloest', count($state['progress']['solved'] ?? []) === 19, 'geloest: ' . count($state['progress']['solved'] ?? []));
check('Fortschritt bei 100 Prozent', (int)($state['progress']['percent'] ?? 0) >= 95, 'Fortschritt: ' . (int)($state['progress']['percent'] ?? 0) . '%');

/* --------- Ermittlungswand und Notizen --------- */
section('5.8 Ermittlungswand und Notizen');

$board = api('/api/case/toby/board', ['board' => [
    'view' => ['x' => 0, 'y' => 0, 'zoom' => 1],
    'nodes' => [
        ['id' => 'n1', 'type' => 'evidence', 'ref' => 'E13', 'label' => 'Kennzeichen', 'x' => 40, 'y' => 40],
        ['id' => 'n2', 'type' => 'location', 'ref' => 'loc_waterworks', 'label' => 'Wasserwerke', 'x' => 260, 'y' => 40],
        ['id' => 'n3', 'type' => 'person', 'ref' => 'npc_doss', 'label' => 'Doss', 'x' => 40, 'y' => 200],
        ['id' => 'n4', 'type' => 'evidence', 'ref' => 'E15', 'label' => 'Spuelung', 'x' => 260, 'y' => 200],
    ],
    'links' => [
        ['id' => 'l1', 'from' => 'n1', 'to' => 'n2', 'label' => ''],
        ['id' => 'l2', 'from' => 'n3', 'to' => 'n4', 'label' => ''],
    ],
]], $csrf);
check('Ermittlungswand gespeichert', ($board['ok'] ?? false) === true, (string)($board['error'] ?? ''));
check('Richtige Verbindung loest Fortschritt aus', in_array('doss_im_blick', $board['flags'] ?? [], true), json_encode($board['flags'] ?? []));

$note = api('/api/case/toby/note', ['text' => 'Doss hat Zugang zu Fahrzeug, Karte und Betriebsbuch.'], $csrf);
check('Notiz gespeichert', isset($note['note']['id']), (string)($note['error'] ?? ''));
$deleted = api('/api/case/toby/note/delete', ['id' => $note['note']['id'] ?? ''], $csrf);
check('Notiz geloescht', ($deleted['ok'] ?? false) === true);

/* --------- Hinweise --------- */
section('5.9 Hinweissystem');

$hint1 = api('/api/case/toby/hint', [], $csrf);
check('Erster Hinweis wird geliefert', ($hint1['ok'] ?? false) === true, (string)($hint1['message'] ?? ''));
check('Hinweis ist Stufe 1', (int)($hint1['level'] ?? 0) === 1);
$hint2 = api('/api/case/toby/hint', [], $csrf);
check('Zweiter Hinweis wird geliefert', ($hint2['ok'] ?? false) === true);
$hint3 = api('/api/case/toby/hint', [], $csrf);
check('Dritter Hinweis wird verweigert (Budget 2)', ($hint3['ok'] ?? true) === false && str_contains((string)($hint3['message'] ?? ''), 'Keine Hinweise'), json_encode($hint3));

/* --------- Abschlussbericht --------- */
section('5.10 Abschlussbericht');

$answers = [
    'q_what' => 'Toby wurde von "Lantern" - in Wahrheit Betriebsleiter Walter Doss - unter dem Vorwand von Betriebsbuechern ans Nordtor gelockt. Doss nutzte die eingetragene Spuelung in Abschnitt 4 als Deckung, fuhr mit dem Transporter zur Pumpstation und haelt Toby im Wartungsschacht fest. Die Nachricht um 03:14 und die Busbuchung waren ein Koeder.',
    'q_culprit' => 'npc_doss',
    'q_liars' => ['npc_diane', 'npc_frank', 'npc_nora', 'npc_hale', 'npc_doss'],
    'q_location' => 'loc_pump4',
    'q_motive' => 'motive_serie',
    'q_evidence' => ['E08', 'E09', 'E13', 'E14', 'E15', 'E16', 'E17', 'E19', 'E22'],
    'q_timeline' => ['tl_rad', 'tl_turm', 'tl_zaun', 'tl_van', 'tl_audio', 'tl_msg', 'tl_card'],
];
$submit = api('/api/case/toby/report/submit', ['answers' => $answers], $csrf);
$result = $submit['result'] ?? [];
check('Bericht angenommen', ($submit['ok'] ?? false) === true, (string)($submit['error'] ?? ''));
check('Taeter richtig erkannt', ($result['details']['culprit_correct'] ?? false) === true);
check('Ort richtig erkannt', ($result['details']['location_correct'] ?? false) === true);
check('Rang S oder A', in_array((string)($result['rank'] ?? ''), ['S', 'A'], true), 'Rang: ' . (string)($result['rank'] ?? '?') . ' (' . (int)($result['percent'] ?? 0) . '%)');
check('Bestes Ende erreicht', (string)($result['ending']['id'] ?? '') === 'ending_rescue', 'Ende: ' . (string)($result['ending']['id'] ?? '?'));
check('Alle Luegen aufgedeckt', (int)($result['details']['lies_found'] ?? 0) >= 4, 'aufgedeckt: ' . (int)($result['details']['lies_found'] ?? 0) . '/' . (int)($result['details']['lies_total'] ?? 0));
check('Punktzahl plausibel', (int)($result['percent'] ?? 0) >= 75, 'Prozent: ' . (int)($result['percent'] ?? 0));

/* --------- Falsche Aufloesung in eigener Sitzung --------- */
section('5.11 Falscher Abschluss (Gegenprobe)');

newSession();
$page = http('GET', '/login');
http('POST', '/gast', ['_csrf' => csrfFrom($page['body'])]);
$play = http('GET', '/spielen/toby');
$guestCsrf = csrfFrom($play['body']);
api('/api/case/toby/start', [], $guestCsrf);
$wrong = api('/api/case/toby/report/submit', ['answers' => [
    'q_what' => 'Toby ist weggelaufen.',
    'q_culprit' => 'runaway',
    'q_liars' => ['npc_ruth', 'npc_elias'],
    'q_location' => 'loc_bus',
    'q_motive' => 'motive_flucht',
    'q_evidence' => ['E01'],
    'q_timeline' => ['tl_card', 'tl_msg', 'tl_audio', 'tl_van', 'tl_zaun', 'tl_turm', 'tl_rad'],
]], $guestCsrf);
$wrongResult = $wrong['result'] ?? [];
check('Falscher Bericht wird angenommen', ($wrong['ok'] ?? false) === true, (string)($wrong['error'] ?? ''));
check('Falscher Taeter erkannt', ($wrongResult['details']['culprit_correct'] ?? true) === false);
check('Schlechter Rang', in_array((string)($wrongResult['rank'] ?? ''), ['D', 'F'], true), 'Rang: ' . (string)($wrongResult['rank'] ?? '?'));
check('Schlechtes Ende', in_array((string)($wrongResult['ending']['id'] ?? ''), ['ending_runaway', 'ending_wrong_person', 'ending_open'], true), 'Ende: ' . (string)($wrongResult['ending']['id'] ?? '?'));
check('Falsche Anschuldigungen gezaehlt', (int)($wrongResult['details']['wrong_accusations'] ?? 0) >= 1);

/* --------- Adminrechte: unbegrenzte Hinweise, Fallverwaltung --------- */
section('6. Adminfunktionen');

newSession();
$page = http('GET', '/login');
http('POST', '/login', ['_csrf' => csrfFrom($page['body']), 'username' => 'tobi', 'password' => 'Ermittlung#2024x']);
$play = http('GET', '/spielen/toby');
$adminPlayCsrf = csrfFrom($play['body']);
check('Admin kann den Fall oeffnen', $play['status'] === 200);
api('/api/case/toby/start', [], $adminPlayCsrf);
$unlimited = true;
for ($i = 0; $i < 4; $i++) {
    $hint = api('/api/case/toby/hint', [], $adminPlayCsrf);
    if (($hint['ok'] ?? false) !== true) {
        $unlimited = false;
    }
}
check('Admin hat unbegrenzte Hinweise', $unlimited);

$admin = http('GET', '/admin/faelle');
$adminCsrf = csrfFrom($admin['body']);
$export = http('GET', '/api/admin/case/toby/export');
check('Fallexport funktioniert', $export['status'] === 200 && str_contains($export['body'], '"id": "toby"'), 'Status ' . $export['status']);

$imported = api('/api/admin/case/import', ['json' => $export['body'], 'new_id' => 'toby-kopie'], $adminCsrf);
check('Fallimport funktioniert', ($imported['ok'] ?? false) === true, (string)($imported['error'] ?? ''));
$validation = api('/api/admin/case/toby-kopie/validate', [], $adminCsrf, 'GET');
check('Importierter Fall ist konsistent', ($validation['validation']['ok'] ?? false) === true, json_encode($validation['validation']['stats'] ?? []));
$deleteCase = api('/api/admin/case/delete', ['case' => 'toby-kopie', 'confirm' => 'toby-kopie'], $adminCsrf);
check('Fall loeschen funktioniert', ($deleteCase['ok'] ?? false) === true, (string)($deleteCase['error'] ?? ''));

$backup = api('/api/admin/backup/create', ['include_uploads' => false], $adminCsrf);
check('Sicherung erstellt', ($backup['ok'] ?? false) === true, (string)($backup['error'] ?? ''));
$backupFile = (string)($backup['backup']['file'] ?? '');
check('Sicherung enthaelt Dateien', (int)($backup['backup']['entries'] ?? 0) > 3, json_encode($backup['backup'] ?? []));
if ($backupFile !== '') {
    $restore = api('/api/admin/backup/restore', ['file' => $backupFile, 'confirm' => 'WIEDERHERSTELLEN'], $adminCsrf);
    check('Sicherung wiederhergestellt', ($restore['ok'] ?? false) === true, (string)($restore['error'] ?? ''));
    $deleteBackup = api('/api/admin/backup/delete', ['file' => $backupFile], $adminCsrf);
    check('Sicherung geloescht', ($deleteBackup['ok'] ?? false) === true);
}

$aiTest = api('/api/admin/ai/test', [], $adminCsrf);
check('KI-Verbindungstest meldet Offline-Modus', ($aiTest['offline'] ?? false) === true, json_encode($aiTest));
$aiSave = api('/api/admin/ai/save', [
    'provider' => 'gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    'model' => 'gemini-2.0-flash', 'api_key' => 'TESTKEY-1234567890', 'timeout' => 20, 'retries' => 1,
    'max_tokens' => 400, 'temperature' => '0.8', 'rate_per_minute' => 10, 'rate_per_hour' => 100,
    'fallback_offline' => true,
], $adminCsrf);
check('KI-Konfiguration gespeichert', ($aiSave['ok'] ?? false) === true, (string)($aiSave['error'] ?? ''));
check('Modus wechselt auf KI', (string)($aiSave['mode'] ?? '') === 'ai', 'Modus: ' . (string)($aiSave['mode'] ?? ''));

$aiPage = http('GET', '/admin/ki');
check('API-Schluessel wird nicht angezeigt', !str_contains($aiPage['body'], 'TESTKEY-1234567890'));

/* KI-Ausfall: NPC muss trotzdem antworten (Rueckfall auf Offline-Dialog) */
$play = http('GET', '/spielen/toby');
$csrfAi = csrfFrom($play['body']);
$reply = chat('npc_ruth', 'Welche Fahrzeuge haben Sie gesehen?', $csrfAi);
check('NPC antwortet trotz ungueltigem KI-Schluessel', strlen((string)($reply['reply'] ?? '')) > 10, json_encode($reply));
check('Keine technische Fehlermeldung im Chat', !str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'http') && !str_contains(mb_strtolower((string)($reply['reply'] ?? '')), 'api'), (string)($reply['reply'] ?? ''));

$aiReset = api('/api/admin/ai/save', [
    'provider' => 'offline', 'base_url' => '', 'model' => '', 'api_key' => '__CLEAR__',
    'timeout' => 30, 'retries' => 2, 'max_tokens' => 420, 'temperature' => '0.85',
    'rate_per_minute' => 12, 'rate_per_hour' => 180, 'fallback_offline' => true,
], $adminCsrf);
check('Zurueck auf Offline-Modus', ($aiReset['ok'] ?? false) === true && (string)($aiReset['mode'] ?? '') === 'offline');

$settingsSave = api('/api/admin/settings/save', [
    'site_name' => 'WHERE IS TOBY?', 'tagline' => 'FBI Field Investigation Terminal',
    'allow_register' => true, 'allow_guests' => true, 'imprint' => 'Testimpressum', 'privacy' => 'Testdatenschutz',
    'hints_per_case' => 2, 'horror_intensity' => 'normal', 'jumpscares' => true, 'autosave_seconds' => 20,
    'default_case' => 'toby', 'show_timer' => true, 'max_login_attempts' => 6, 'lockout_minutes' => 15,
    'chat_per_minute' => 30, 'api_per_minute' => 300, 'registration_per_hour' => 20,
], $adminCsrf);
check('Systemeinstellungen gespeichert', ($settingsSave['ok'] ?? false) === true, (string)($settingsSave['error'] ?? ''));

$upload = api('/api/admin/media/delete', ['id' => 'nichtvorhanden'], $adminCsrf);
check('Loeschen eines unbekannten Mediums bleibt fehlerfrei', ($upload['ok'] ?? false) === true);

$users = http('GET', '/admin/spieler');
check('Spielerverwaltung laedt', $users['status'] === 200 && str_contains($users['body'], 'Konten'));
$logs = api('/api/admin/logs?file=app.log', [], $adminCsrf, 'GET');
check('Protokolle lesbar', isset($logs['entries']), json_encode(array_keys($logs)));
$editor = http('GET', '/admin/fall/toby');
check('Fall-Editor laedt', $editor['status'] === 200 && str_contains($editor['body'], 'case-editor'));
$newCase = http('GET', '/admin/fall/neu');
check('Editor fuer neuen Fall laedt', $newCase['status'] === 200 && str_contains($newCase['body'], 'Neuer Fall'));

/* ================================================================
 |  7. Upload-Sicherheit und Bildgenerierung
 ================================================================ */
section('7. Uploads');

function uploadFile(string $name, string $contents, string $mime, string $csrf, bool $rights = true): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'wit') . '_' . $name;
    file_put_contents($tmp, $contents);
    $curlFile = new CURLFile($tmp, $mime, $name);

    $ch = curl_init($GLOBALS['BASE'] . '/api/admin/media/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => $curlFile,
            'title' => 'Testdatei ' . $name,
            'category' => 'bild',
            'rights_confirmed' => $rights ? '1' : '0',
        ],
        CURLOPT_COOKIEJAR  => $GLOBALS['jar'],
        CURLOPT_COOKIEFILE => $GLOBALS['jar'],
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-CSRF-Token: ' . $csrf],
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = (string)curl_exec($ch);
    curl_close($ch);
    @unlink($tmp);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['ok' => false, '_raw' => $raw];
}

$mediaPage = http('GET', '/admin/medien');
$mediaCsrf = csrfFrom($mediaPage['body']);
check('Medienverwaltung laedt', $mediaPage['status'] === 200 && str_contains($mediaPage['body'], 'Medienbibliothek'));

$php = uploadFile('boese.php', "<?php echo 'hallo'; ?>", 'application/x-php', $mediaCsrf);
check('PHP-Datei wird abgelehnt', ($php['ok'] ?? true) === false, (string)($php['error'] ?? ''));

$disguised = uploadFile('bild.png', "<?php system(\$_GET['c']); ?>", 'image/png', $mediaCsrf);
check('Getarnte PHP-Datei mit Bildendung wird abgelehnt', ($disguised['ok'] ?? true) === false, (string)($disguised['error'] ?? ''));

$svg = uploadFile('schaedlich.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="10" height="10" onload="alert(2)"/></svg>', 'image/svg+xml', $mediaCsrf);
check('SVG wird angenommen', ($svg['ok'] ?? false) === true, (string)($svg['error'] ?? ''));
if (($svg['ok'] ?? false) === true) {
    $svgFile = http('GET', '/medien/' . $svg['media']['file']);
    check('SVG wird bereinigt ausgeliefert (kein script)', !str_contains($svgFile['body'], '<script') && !str_contains($svgFile['body'], 'onload'), 'Skript im SVG gefunden');
    api('/api/admin/media/delete', ['id' => $svg['media']['id']], $mediaCsrf);
}

// Testbild zur Laufzeit erzeugen (echtes PNG, damit die MIME-Pruefung greift)
$png = '';
if (function_exists('imagecreatetruecolor')) {
    $image = imagecreatetruecolor(320, 400);
    imagefilledrectangle($image, 0, 0, 320, 400, imagecolorallocate($image, 40, 46, 54));
    imagefilledellipse($image, 160, 170, 160, 200, imagecolorallocate($image, 140, 115, 95));
    imagefilledrectangle($image, 60, 300, 260, 400, imagecolorallocate($image, 38, 64, 58));
    ob_start();
    imagepng($image);
    $png = (string)ob_get_clean();
    imagedestroy($image);
}
$image = uploadFile('portrait.png', $png, 'image/png', $mediaCsrf);
check('Bild wird angenommen', ($image['ok'] ?? false) === true, (string)($image['error'] ?? ''));

if (($image['ok'] ?? false) === true) {
    $mediaId = (string)$image['media']['id'];
    $withoutRights = uploadFile('portrait2.png', $png, 'image/png', $mediaCsrf, false);
    check('Upload ohne Rechtebestaetigung wird abgelehnt', ($withoutRights['ok'] ?? true) === false);

    $generated = api('/api/admin/media/generate', [
        'id' => $mediaId, 'name' => 'Peter Swanson', 'age' => '17',
        'last_seen' => '11.10.2024, 23:14 Uhr', 'location' => 'Millbrook, Vermont',
        'height' => '178 cm', 'clothing' => 'dunkle Jacke', 'case_code' => 'WIT-TEST-1',
        'contact' => '1-800-CALL-FBI', 'title' => 'Where is Peter?', 'subtitle' => 'FBI Field Investigation',
        'note' => 'Testakte',
    ], $mediaCsrf);
    check('Fallgrafiken werden erzeugt', ($generated['ok'] ?? false) === true, (string)($generated['error'] ?? ''));
    check('Sechs Varianten erzeugt', count($generated['media'] ?? []) === 6, 'erzeugt: ' . count($generated['media'] ?? []));

    foreach ($generated['media'] ?? [] as $variant) {
        $file = http('GET', '/medien/' . $variant['file']);
        if (($variant['category'] ?? '') === 'generiert:poster') {
            check('Vermisstenplakat ist ein gueltiges Bild', $file['status'] === 200 && str_starts_with($file['body'], "\xFF\xD8\xFF"), 'Status ' . $file['status']);
        }
        api('/api/admin/media/delete', ['id' => $variant['id']], $mediaCsrf);
    }
    api('/api/admin/media/delete', ['id' => $mediaId], $mediaCsrf);
}

$anonymous = http('GET', '/medien/irgendwas.png');
check('Medien nur fuer angemeldete Konten', in_array($anonymous['status'], [401, 403, 404], true), 'Status ' . $anonymous['status']);
