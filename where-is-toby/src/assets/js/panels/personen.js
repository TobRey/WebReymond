/* Personenregister und freie KI-Verhoere */

import { el, clear, get, post, toast, sectionTitle, assetUrl, sound, openOverlay, closeOverlay, clockFromIso } from '../core.js';
import { speechAvailable, createDictation } from '../speech.js';

export async function render(game, options = {}) {
    if (options.npc) return chatView(game, options.npc, options);
    return listView(game);
}

/** Chat aus anderen Bereichen heraus oeffnen. */
export async function openChat(game, npcId, options = {}) {
    closeOverlay();
    await game.showPanel('personen', { npc: npcId, ...options });
}

/* ---------------------------- Liste ---------------------------- */

function listView(game) {
    const wrap = el('div', { class: 'panel-grid' });
    wrap.append(sectionTitle('Personen', 'Frei formulierte Fragen. Die Aussagen sind nicht automatisch wahr.'));

    const npcs = game.state.npcs || [];
    if (!npcs.length) {
        wrap.append(el('p', { class: 'hint', text: 'Noch keine Kontakte freigeschaltet.' }));
        return wrap;
    }

    const list = el('div', { class: 'panel-grid cols-2' });
    npcs.forEach((npc) => {
        const locked = !npc.available;
        const leftUntil = Number(npc.left_until || 0) * 1000;
        const away = leftUntil > Date.now();
        list.append(el('button', {
            class: 'person-card' + (locked ? ' is-locked' : ''),
            disabled: locked ? 'disabled' : null,
            onclick: () => {
                if (locked) { toast(npc.locked_hint || 'Noch kein Kontakt moeglich.', { kind: 'bad' }); return; }
                game.showPanel('personen', { npc: npc.id });
            },
        }, [
            el('img', { src: assetUrl(npc.avatar), alt: '' }),
            el('div', {}, [
                el('h3', {}, [
                    npc.name,
                    npc.unread ? el('span', { class: 'badge-new', text: '  ● neue Nachricht' }) : null,
                ]),
                el('p', { text: `${npc.role}${npc.age ? ', ' + npc.age : ''}${npc.relation ? ' · ' + npc.relation : ''}` }),
                el('p', { class: 'hint', text: locked ? (npc.locked_hint || '') : (away ? 'Gespraech gerade abgebrochen.' : (npc.short || '')) }),
            ]),
        ]));
    });
    wrap.append(list);

    wrap.append(el('p', {
        class: 'hint',
        text: game.chatMode === 'offline'
            ? 'Offline-Modus: Die Figuren antworten ueber das regelbasierte Dialogsystem. Frage konkret nach Zeiten, Orten und Personen.'
            : 'KI-Modus aktiv: Die Figuren antworten frei. Sie koennen luegen, ausweichen und das Gespraech abbrechen.',
    }));
    return wrap;
}

/* ---------------------------- Chat ---------------------------- */

async function chatView(game, npcId, options = {}) {
    const data = await get(`/api/case/${game.caseId}/chat/${encodeURIComponent(npcId)}`);
    const npc = data.npc;

    const wrap = el('div', { class: 'panel-grid' });
    const log = el('div', { class: 'chat__log' });
    const input = el('textarea', {
        placeholder: 'Frage stellen ... (Enter sendet, Shift+Enter neue Zeile)',
        rows: '2',
        maxlength: '900',
    });

    const head = el('header', { class: 'chat__head' }, [
        el('button', { class: 'btn btn--small', text: '← Personen', onclick: () => game.showPanel('personen') }),
        el('img', { src: assetUrl(npc.avatar), alt: '' }),
        el('div', {}, [
            el('h3', { text: npc.name }),
            el('p', { text: `${npc.role}${npc.age ? ', ' + npc.age : ''}` }),
        ]),
        el('span', { class: 'chat__mode', text: data.mode === 'offline' ? 'LEITUNG: LOKAL' : 'LEITUNG: EXTERN' }),
    ]);

    const appendMessage = (message) => {
        let cls = 'bubble bubble--npc';
        if (message.from === 'player') cls = message.evidence ? 'bubble bubble--evidence' : 'bubble bubble--me';
        if (message.from === 'system') cls = 'bubble bubble--system';
        const node = el('div', { class: cls }, [
            el('span', { text: message.text }),
            message.time ? el('time', { text: clockFromIso(message.time) }) : null,
        ]);
        log.append(node);
        log.scrollTop = log.scrollHeight;
        return node;
    };

    (data.messages || []).forEach(appendMessage);

    const typing = () => {
        const node = el('div', { class: 'bubble bubble--npc typing' }, [el('span'), el('span'), el('span')]);
        log.append(node);
        log.scrollTop = log.scrollHeight;
        return node;
    };

    let busy = false;
    const send = async (text, evidenceId = '') => {
        if (busy) return;
        const message = (text ?? input.value).trim();
        if (!message && !evidenceId) return;
        busy = true;
        input.value = '';

        if (evidenceId) {
            const evidence = game.evidenceById(evidenceId);
            appendMessage({
                from: 'player',
                evidence: evidenceId,
                text: `[Beweis vorgelegt: ${evidence ? evidence.code + ' ' + evidence.title : evidenceId}]${message ? '\n' + message : ''}`,
                time: new Date().toISOString(),
            });
        } else {
            appendMessage({ from: 'player', text: message, time: new Date().toISOString() });
        }

        const indicator = typing();
        try {
            const response = await post(`/api/case/${game.caseId}/chat`, {
                npc: npcId,
                message,
                evidence: evidenceId,
            });
            indicator.remove();

            if (response.unavailable) {
                appendMessage({ from: 'system', text: response.message || 'Die Person antwortet gerade nicht.' });
            } else {
                appendMessage({ from: 'npc', text: response.reply, time: new Date().toISOString() });
                sound.play('ui_click', { gain: 0.18 });
            }

            (response.new_evidence || []).forEach((evidence) => {
                toast(`${evidence.code} ${evidence.title}`, { title: 'Neuer Beweis', kind: 'evidence', timeout: 8000 });
                game.markPanelDot('beweise');
            });
            if (response.state) game.setState(response.state);
            game.handleHorror(response.horror);

            const leftUntil = Number(response.left_until || 0) * 1000;
            if (leftUntil > Date.now()) {
                const seconds = Math.ceil((leftUntil - Date.now()) / 1000);
                appendMessage({ from: 'system', text: `Das Gespraech wurde beendet. Erneuter Kontakt in etwa ${seconds} Sekunden moeglich.` });
            }
        } catch (error) {
            indicator.remove();
            appendMessage({ from: 'system', text: 'Die Verbindung ist abgerissen. ' + error.message });
        } finally {
            busy = false;
            input.focus();
        }
    };

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            send();
        }
    });

    const micButton = el('button', {
        class: 'btn mic', title: 'Diktieren', 'aria-label': 'Spracheingabe',
        text: '🎙',
    });
    if (speechAvailable()) {
        const dictation = createDictation(input, micButton);
        micButton.addEventListener('click', () => dictation.toggle());
    } else {
        micButton.disabled = true;
        micButton.title = 'Dieser Browser unterstuetzt keine Spracherkennung.';
    }

    const evidenceButton = el('button', {
        class: 'btn', text: 'Beweis vorlegen', title: 'Person mit einem Beweis konfrontieren',
        onclick: () => {
            const evidence = game.state.evidence || [];
            if (!evidence.length) { toast('Du hast noch keine Beweise.', { kind: 'bad' }); return; }
            const list = el('div', { class: 'panel-grid cols-2' });
            evidence.forEach((item) => {
                list.append(el('button', {
                    class: 'evidence-card',
                    'data-importance': item.importance,
                    onclick: () => { closeOverlay(); send(input.value.trim(), item.id); },
                }, [
                    el('span', { class: 'code', text: item.code }),
                    el('h3', { text: item.title }),
                    el('p', { text: item.summary }),
                ]));
            });
            openOverlay('Beweis vorlegen', el('div', { class: 'stack' }, [
                el('p', { class: 'hint', text: 'Der ausgewaehlte Beweis wird der Person direkt vorgehalten.' }),
                list,
            ]));
        },
    });

    const sendButton = el('button', { class: 'btn btn--primary', text: 'Senden', onclick: () => send() });

    const suggestions = el('div', { class: 'chat__suggestions' });
    (npc.topics || []).forEach((topic) => {
        suggestions.append(el('button', {
            text: topic.label,
            onclick: () => { input.value = topic.text || topic.label; input.focus(); },
        }));
    });

    const chat = el('section', { class: 'chat panel-box' }, [
        head,
        log,
        el('div', {}, [
            suggestions,
            el('div', { class: 'chat__input' }, [input, micButton, evidenceButton, sendButton]),
        ]),
    ]);

    wrap.append(chat);
    setTimeout(() => { log.scrollTop = log.scrollHeight; input.focus(); }, 30);

    if (options.presentEvidence) {
        setTimeout(() => send('', options.presentEvidence), 200);
    }
    return wrap;
}
