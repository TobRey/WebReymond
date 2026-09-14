/* Beweisarchiv */

import { el, sectionTitle, openOverlay, clear, toast, assetUrl } from '../core.js';
import { openMedia } from '../viewer.js';

const CATEGORIES = {
    alle: 'Alle',
    dokument: 'Dokumente',
    digital: 'Digital',
    foto: 'Fotos',
    video: 'Video',
    audio: 'Audio',
    aussage: 'Aussagen',
    objekt: 'Objekte',
};

export async function render(game) {
    const wrap = el('div', { class: 'panel-grid' });
    const evidence = game.state.evidence || [];

    wrap.append(sectionTitle(
        'Beweisarchiv',
        `${evidence.length} von ${game.state.evidence_total || 0} Beweisen gesichert. Kernbeweise sind rot markiert.`,
    ));

    let filter = 'alle';
    const filters = el('div', { class: 'filters' });
    const list = el('div', { class: 'panel-grid cols-2' });

    const draw = () => {
        clear(list);
        const visible = evidence.filter((item) => filter === 'alle' || item.category === filter);
        if (!visible.length) {
            list.append(el('p', { class: 'hint', text: 'In dieser Kategorie ist noch nichts gesichert.' }));
            return;
        }
        visible.forEach((item) => {
            list.append(el('button', {
                class: 'evidence-card',
                'data-importance': item.importance,
                onclick: () => openEvidence(game, item),
            }, [
                el('span', { class: 'code', text: item.code }),
                el('h3', { text: item.title }),
                el('p', { text: item.summary }),
                item.timestamp ? el('p', { class: 'hint', text: 'Zeitbezug: ' + item.timestamp }) : null,
            ]));
        });
    };

    Object.entries(CATEGORIES).forEach(([key, label]) => {
        const count = key === 'alle' ? evidence.length : evidence.filter((item) => item.category === key).length;
        if (key !== 'alle' && !count) return;
        const button = el('button', {
            class: key === filter ? 'is-active' : '',
            text: `${label} (${count})`,
            onclick: () => {
                filter = key;
                filters.querySelectorAll('button').forEach((node) => node.classList.remove('is-active'));
                button.classList.add('is-active');
                draw();
            },
        });
        filters.append(button);
    });

    wrap.append(filters, list);
    draw();
    return wrap;
}

export function openEvidence(game, item) {
    const body = el('div', { class: 'stack' });
    body.append(el('p', { class: 'eyebrow', text: `${item.code} · ${item.category}${item.timestamp ? ' · ' + item.timestamp : ''}` }));
    body.append(el('p', { class: 'lead', text: item.summary }));
    if (item.detail) body.append(el('div', { class: 'typed', text: item.detail }));
    if (item.source) body.append(el('p', { class: 'hint', text: 'Herkunft: ' + item.source }));

    const tools = el('div', { class: 'toolbar' });

    if (item.media && item.media.id) {
        tools.append(el('button', {
            class: 'btn btn--small btn--primary',
            text: 'Medium oeffnen',
            onclick: () => openMedia(game, item.media.id),
        }));
    }

    tools.append(el('button', {
        class: 'btn btn--small',
        text: 'Person konfrontieren',
        onclick: () => confrontDialog(game, item),
    }));

    tools.append(el('button', {
        class: 'btn btn--small',
        text: 'Auf die Wand heften',
        onclick: async () => {
            const board = await import('./wand.js');
            board.addNodeForEvidence(game, item);
            toast('Karte auf der Ermittlungswand abgelegt.', { kind: 'good' });
        },
    }));

    body.append(tools);

    if (item.related?.length) {
        body.append(el('p', { class: 'hint', text: 'Verweise: ' + item.related.join(', ') }));
    }
    openOverlay(item.title, body);
}

function confrontDialog(game, item) {
    const npcs = (game.state.npcs || []).filter((npc) => npc.available);
    if (!npcs.length) {
        toast('Derzeit ist niemand erreichbar.', { kind: 'bad' });
        return;
    }
    const list = el('div', { class: 'panel-grid cols-2' });
    npcs.forEach((npc) => {
        list.append(el('button', {
            class: 'person-card',
            onclick: async () => {
                const personen = await import('./personen.js');
                await personen.openChat(game, npc.id, { presentEvidence: item.id });
            },
        }, [
            el('img', { src: assetUrl(npc.avatar), alt: '' }),
            el('div', {}, [
                el('h3', { text: npc.name }),
                el('p', { text: npc.role }),
            ]),
        ]));
    });
    openOverlay(`${item.code} vorlegen`, el('div', { class: 'stack' }, [
        el('p', { text: 'Wem legst du diesen Beweis vor? Eine Konfrontation kann Aussagen kippen - oder Vertrauen kosten.' }),
        list,
    ]));
}
