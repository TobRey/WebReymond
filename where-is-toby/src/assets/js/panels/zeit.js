/* Zeitleiste: gesicherte Ereignisse und Rekonstruktion */

import { el, sectionTitle } from '../core.js';
import { renderPuzzle } from '../puzzle.js';

export async function render(game) {
    const wrap = el('div', { class: 'panel-grid' });
    wrap.append(sectionTitle('Zeitleiste', 'Alles, was du zeitlich belegen kannst. Luecken sind Arbeit.'));

    const evidence = game.state.evidence || [];
    const entries = evidence
        .filter((item) => item.timestamp)
        .map((item) => ({
            time: item.timestamp,
            title: item.title,
            text: item.summary,
            key: item.importance === 'kern',
            code: item.code,
        }))
        .sort((a, b) => String(a.time).localeCompare(String(b.time)));

    if (!entries.length) {
        wrap.append(el('p', { class: 'hint', text: 'Noch keine Beweise mit Zeitstempel gesichert.' }));
    } else {
        const timeline = el('div', { class: 'timeline' });
        entries.forEach((entry) => {
            timeline.append(el('div', { class: 'timeline__item' + (entry.key ? ' is-key' : '') }, [
                el('div', { class: 'timeline__time', text: entry.time }),
                el('div', { class: 'timeline__text' }, [
                    el('strong', { text: entry.title + ' ' }),
                    el('span', { class: 'hint', text: entry.code || '' }),
                    el('div', { text: entry.text || '' }),
                ]),
            ]));
        });
        wrap.append(el('section', { class: 'panel-box' }, [timeline]));
    }

    // Rekonstruktionsaufgabe (Typ sequence/timeline)
    const puzzles = (game.state.puzzles || []).filter((puzzle) => ['timeline', 'sequence'].includes(puzzle.type) && puzzle.panel !== 'bericht');
    puzzles.forEach((puzzle) => {
        wrap.append(el('section', { class: 'panel-box' }, [
            el('h3', { text: puzzle.title }),
            puzzle.prompt ? el('p', { text: puzzle.prompt }) : null,
            renderPuzzle(game, puzzle, { compact: true }),
        ]));
    });

    return wrap;
}
