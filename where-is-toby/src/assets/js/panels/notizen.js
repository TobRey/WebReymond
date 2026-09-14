/* Eigene Notizen des Ermittlers */

import { el, post, toast, sectionTitle, clear } from '../core.js';

export async function render(game, options = {}) {
    const wrap = el('div', { class: 'panel-grid' });
    wrap.append(sectionTitle('Notizen', 'Eigene Aufzeichnungen. Sie erscheinen auch als Karten auf der Ermittlungswand.'));

    const input = el('textarea', {
        placeholder: 'Beobachtung, Widerspruch, Vermutung ...',
        maxlength: '1200',
        rows: '4',
    });

    const list = el('div', { class: 'panel-grid cols-2' });

    const draw = () => {
        clear(list);
        const notes = [...(game.state.notes || [])].reverse();
        if (!notes.length) {
            list.append(el('p', { class: 'hint', text: 'Noch keine Notizen. Halte fest, was nicht zusammenpasst.' }));
            return;
        }
        notes.forEach((note) => {
            list.append(el('article', { class: 'note-card' }, [
                el('div', { text: note.text }),
                el('time', { text: new Date(note.created).toLocaleString('de-DE') }),
                el('div', { class: 'toolbar', style: { margin: '.5rem 0 0' } }, [
                    el('button', {
                        class: 'btn btn--small', text: 'Loeschen',
                        onclick: async () => {
                            try {
                                await post(`/api/case/${game.caseId}/note/delete`, { id: note.id });
                                game.state.notes = (game.state.notes || []).filter((entry) => entry.id !== note.id);
                                draw();
                            } catch (error) { toast(error.message, { kind: 'bad' }); }
                        },
                    }),
                ]),
            ]));
        });
    };

    const save = async () => {
        const text = input.value.trim();
        if (!text) return;
        try {
            const data = await post(`/api/case/${game.caseId}/note`, { text });
            game.state.notes = [...(game.state.notes || []), data.note];
            input.value = '';
            draw();
            toast('Notiz gespeichert.', { kind: 'good' });
        } catch (error) {
            toast(error.message, { kind: 'bad' });
        }
    };

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) save();
    });

    wrap.append(el('section', { class: 'panel-box' }, [
        input,
        el('div', { class: 'toolbar' }, [
            el('button', { class: 'btn btn--primary', text: 'Notiz speichern', onclick: save }),
            el('span', { class: 'hint', text: 'Strg + Enter speichert ebenfalls.' }),
        ]),
    ]));
    wrap.append(list);
    draw();

    if (options.compose) setTimeout(() => input.focus(), 40);
    return wrap;
}
