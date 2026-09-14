/* Digitale Karte mit Fallorten und Standortzuordnung */

import { el, assetUrl, sectionTitle, openOverlay, toast, clear } from '../core.js';
import { solve, renderPuzzle } from '../puzzle.js';

export async function render(game) {
    const wrap = el('div', { class: 'panel-grid' });
    const map = game.state.case?.map || {};
    const locations = game.state.locations || [];

    const locatePuzzles = (game.state.puzzles || []).filter((puzzle) => puzzle.type === 'locate' && !puzzle.solved && puzzle.available);
    const activePuzzle = locatePuzzles[0] || null;

    wrap.append(sectionTitle(
        'Karte',
        activePuzzle
            ? `Aufgabe: ${activePuzzle.prompt || activePuzzle.title}`
            : 'Bekannte Orte des Falls. Klicke einen Ort fuer Details.',
    ));

    const frame = el('div', { class: 'map-wrap' });
    const image = el('img', { src: assetUrl(map.image || 'assets/img/scenes/map-millbrook.svg'), alt: 'Karte ' + (map.title || '') });
    frame.append(image);

    locations.forEach((location) => {
        const pin = el('button', {
            class: 'map-pin' + (location.visited ? ' is-visited' : '') + (location.type === 'tatort' ? ' is-key' : ''),
            style: { left: `${(location.x * 100).toFixed(2)}%`, top: `${(location.y * 100).toFixed(2)}%` },
            title: location.name,
            onclick: (event) => {
                event.stopPropagation();
                openOverlay(location.name, el('div', { class: 'stack' }, [
                    el('p', { class: 'eyebrow', text: location.type + (location.address ? ' · ' + location.address : '') }),
                    el('p', { text: location.description || '' }),
                    location.notes ? el('p', { class: 'hint', text: location.notes }) : null,
                    location.evidence?.length
                        ? el('p', { class: 'hint', text: 'Zugeordnete Beweise: ' + location.evidence.join(', ') })
                        : null,
                ]));
            },
        }, [
            el('span', { class: 'dot' }),
            el('span', { text: location.name }),
        ]);
        frame.append(pin);
    });

    if (activePuzzle) {
        frame.style.cursor = 'crosshair';
        frame.addEventListener('click', async (event) => {
            if (event.target.closest('.map-pin')) return;
            const rect = image.getBoundingClientRect();
            const x = (event.clientX - rect.left) / rect.width;
            const y = (event.clientY - rect.top) / rect.height;
            if (x < 0 || x > 1 || y < 0 || y > 1) return;
            const data = await solve(game, activePuzzle.id, { x: Number(x.toFixed(4)), y: Number(y.toFixed(4)) });
            if (data.ok) {
                const fresh = await render(game);
                clear(game.root);
                game.root.append(fresh);
            }
        });
    }

    wrap.append(frame);

    if (map.legend) {
        wrap.append(el('p', { class: 'hint', text: map.legend }));
    }

    // Ortsliste (mobile Alternative und Uebersicht)
    const list = el('div', { class: 'panel-grid cols-3' });
    locations.forEach((location) => {
        list.append(el('button', {
            class: 'evidence-card',
            onclick: async () => {
                if (activePuzzle) {
                    const data = await solve(game, activePuzzle.id, { location: location.id });
                    if (data.ok) {
                        const fresh = await render(game);
                        clear(game.root);
                        game.root.append(fresh);
                        return;
                    }
                }
                openOverlay(location.name, el('div', { class: 'stack' }, [
                    el('p', { class: 'eyebrow', text: location.type }),
                    el('p', { text: location.description || '' }),
                ]));
            },
        }, [
            el('span', { class: 'code', text: location.type }),
            el('h3', { text: location.name }),
            el('p', { text: location.address || location.description || '' }),
        ]));
    });
    wrap.append(el('section', {}, [
        el('h3', { text: activePuzzle ? 'Ort auswaehlen' : 'Orte' }),
        activePuzzle ? el('p', { class: 'hint', text: 'Tippe auf die Karte oder waehle einen Ort aus der Liste.' }) : null,
        list,
    ]));

    if (activePuzzle) {
        wrap.append(el('section', { class: 'panel-box' }, [
            el('h3', { text: activePuzzle.title }),
            renderPuzzle(game, activePuzzle, { compact: true }),
        ]));
    }

    return wrap;
}
