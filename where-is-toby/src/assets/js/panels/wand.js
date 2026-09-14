/* Ermittlungswand: Karten ziehen, verbinden, Notizen, Zoom und Verschieben */

import { el, clear, post, toast, sectionTitle, debounce, openOverlay, closeOverlay, sound } from '../core.js';

const NODE_TYPES = {
    person:   { label: 'Person',  color: 'person' },
    evidence: { label: 'Beweis',  color: 'evidence' },
    location: { label: 'Ort',     color: 'location' },
    event:    { label: 'Ereignis',color: 'event' },
    note:     { label: 'Notiz',   color: 'note' },
};

let boardRef = null;

export async function render(game) {
    const wrap = el('div', { class: 'panel-grid' });
    wrap.append(sectionTitle(
        'Ermittlungswand',
        'Karten ziehen, verbinden, ordnen. Richtige Zusammenhaenge koennen neue Wege oeffnen.',
    ));

    const board = createBoard(game);
    boardRef = board;
    wrap.append(board.toolbar, board.frame, board.help);
    setTimeout(() => board.draw(), 20);
    return wrap;
}

/** Von anderen Bereichen aus eine Beweiskarte anheften. */
export function addNodeForEvidence(game, evidence) {
    const board = game.state.board || { nodes: [], links: [], view: { x: 0, y: 0, zoom: 1 } };
    if (board.nodes.some((node) => node.ref === evidence.id)) return;
    board.nodes.push({
        id: 'n' + Math.random().toString(36).slice(2, 9),
        type: 'evidence',
        ref: evidence.id,
        label: `${evidence.code} ${evidence.title}`,
        text: evidence.summary || '',
        x: 60 + (board.nodes.length % 5) * 190,
        y: 60 + Math.floor(board.nodes.length / 5) * 130,
        color: 'default',
    });
    game.state.board = board;
    saveBoard(game, board);
    if (boardRef) boardRef.draw();
}

function saveBoardNow(game, board) {
    return post(`/api/case/${game.caseId}/board`, { board })
        .then((data) => {
            if (data.state) game.setState(data.state);
            if ((data.flags || []).length) {
                sound.play('ui_alert', { gain: 0.35 });
                toast('Ein Zusammenhang ergibt Sinn. Die Zentrale schickt neue Unterlagen.', {
                    title: 'Neue Erkenntnis', kind: 'good', timeout: 9000,
                });
                game.markPanelDot('beweise');
            }
            game.handleHorror(data.horror);
        })
        .catch((error) => toast(error.message, { kind: 'bad' }));
}

const saveBoard = debounce((game, board) => saveBoardNow(game, board), 900);

function createBoard(game) {
    const board = game.state.board || { nodes: [], links: [], view: { x: 0, y: 0, zoom: 1 } };
    board.nodes = board.nodes || [];
    board.links = board.links || [];
    board.view = board.view || { x: 0, y: 0, zoom: 1 };

    const frame = el('div', { class: 'board-wrap' });
    const canvas = el('div', { class: 'board-canvas' });
    const svg = el('div', { class: 'board-svg' });
    svg.innerHTML = '<svg width="100%" height="100%" style="position:absolute;inset:0"><g id="board-links"></g></svg>';
    canvas.append(svg);
    frame.append(canvas);

    let linkMode = false;
    let linkFrom = null;
    let selected = null;

    const draw = () => {
        // Karten
        Array.from(canvas.querySelectorAll('.board-node')).forEach((node) => node.remove());
        board.nodes.forEach((node) => {
            const card = el('div', {
                class: 'board-node' + (selected === node.id ? ' is-selected' : ''),
                'data-type': node.type,
                'data-id': node.id,
                style: { left: node.x + 'px', top: node.y + 'px' },
            }, [
                el('span', { class: 'pin' }),
                el('strong', { text: node.label || NODE_TYPES[node.type]?.label || 'Karte' }),
                node.text ? el('span', { text: node.text.slice(0, 120) }) : null,
            ]);

            let dragging = false;
            let startX = 0;
            let startY = 0;
            let originX = 0;
            let originY = 0;

            card.addEventListener('pointerdown', (event) => {
                event.stopPropagation();
                if (linkMode) {
                    handleLinkClick(node);
                    return;
                }
                dragging = true;
                selected = node.id;
                startX = event.clientX;
                startY = event.clientY;
                originX = node.x;
                originY = node.y;
                card.setPointerCapture(event.pointerId);
                card.style.cursor = 'grabbing';
            });
            card.addEventListener('pointermove', (event) => {
                if (!dragging) return;
                const zoom = board.view.zoom || 1;
                node.x = Math.max(0, originX + (event.clientX - startX) / zoom);
                node.y = Math.max(0, originY + (event.clientY - startY) / zoom);
                card.style.left = node.x + 'px';
                card.style.top = node.y + 'px';
                drawLinks();
            });
            card.addEventListener('pointerup', () => {
                if (!dragging) return;
                dragging = false;
                card.style.cursor = 'grab';
                saveBoard(game, board);
            });
            card.addEventListener('dblclick', () => editNode(node));
            canvas.append(card);
        });
        applyView();
        drawLinks();
    };

    const drawLinks = () => {
        const group = svg.querySelector('#board-links');
        if (!group) return;
        group.innerHTML = '';
        board.links.forEach((link) => {
            const from = board.nodes.find((node) => node.id === link.from);
            const to = board.nodes.find((node) => node.id === link.to);
            if (!from || !to) return;
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', String(from.x + 84));
            line.setAttribute('y1', String(from.y + 26));
            line.setAttribute('x2', String(to.x + 84));
            line.setAttribute('y2', String(to.y + 26));
            line.setAttribute('stroke', '#a0342f');
            line.setAttribute('stroke-width', '1.6');
            line.setAttribute('opacity', '.8');
            group.append(line);
            if (link.label) {
                const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                text.setAttribute('x', String((from.x + to.x) / 2 + 84));
                text.setAttribute('y', String((from.y + to.y) / 2 + 20));
                text.setAttribute('fill', '#9aa4b1');
                text.setAttribute('font-size', '11');
                text.textContent = link.label;
                group.append(text);
            }
        });
    };

    const applyView = () => {
        canvas.style.transform = `translate(${board.view.x}px, ${board.view.y}px) scale(${board.view.zoom})`;
        canvas.style.transformOrigin = '0 0';
    };

    const handleLinkClick = (node) => {
        if (!linkFrom) {
            linkFrom = node.id;
            toast('Startkarte gewaehlt. Jetzt die zweite Karte antippen.', { timeout: 3500 });
            return;
        }
        if (linkFrom === node.id) { linkFrom = null; return; }
        const exists = board.links.some((link) =>
            (link.from === linkFrom && link.to === node.id) || (link.from === node.id && link.to === linkFrom));
        if (!exists) {
            board.links.push({ id: 'l' + Math.random().toString(36).slice(2, 9), from: linkFrom, to: node.id, label: '' });
            sound.play('ui_click', { gain: 0.25 });
            saveBoardNow(game, board);
        }
        linkFrom = null;
        drawLinks();
    };

    // Verschieben des Hintergrunds
    let panning = false;
    let panStart = { x: 0, y: 0, viewX: 0, viewY: 0 };
    canvas.addEventListener('pointerdown', (event) => {
        if (event.target !== canvas && !event.target.closest('.board-svg')) return;
        panning = true;
        canvas.classList.add('is-panning');
        panStart = { x: event.clientX, y: event.clientY, viewX: board.view.x, viewY: board.view.y };
    });
    window.addEventListener('pointermove', (event) => {
        if (!panning) return;
        board.view.x = panStart.viewX + (event.clientX - panStart.x);
        board.view.y = panStart.viewY + (event.clientY - panStart.y);
        applyView();
    });
    window.addEventListener('pointerup', () => {
        if (!panning) return;
        panning = false;
        canvas.classList.remove('is-panning');
        saveBoard(game, board);
    });
    frame.addEventListener('wheel', (event) => {
        event.preventDefault();
        board.view.zoom = Math.max(0.5, Math.min(2.2, (board.view.zoom || 1) + (event.deltaY < 0 ? 0.1 : -0.1)));
        applyView();
        saveBoard(game, board);
    }, { passive: false });

    const editNode = (node) => {
        const label = el('input', { type: 'text', value: node.label || '', maxlength: '140' });
        const text = el('textarea', { rows: '3', maxlength: '600' }, [node.text || '']);
        openOverlay('Karte bearbeiten', el('div', { class: 'stack' }, [
            el('label', {}, ['Titel', label]),
            el('label', {}, ['Text', text]),
            el('div', { class: 'toolbar' }, [
                el('button', {
                    class: 'btn btn--primary', text: 'Speichern',
                    onclick: () => {
                        node.label = label.value.trim().slice(0, 140);
                        node.text = text.value.trim().slice(0, 600);
                        saveBoardNow(game, board);
                        draw();
                        closeOverlay();
                    },
                }),
                el('button', {
                    class: 'btn btn--danger', text: 'Karte entfernen',
                    onclick: () => {
                        board.nodes = board.nodes.filter((entry) => entry.id !== node.id);
                        board.links = board.links.filter((link) => link.from !== node.id && link.to !== node.id);
                        saveBoardNow(game, board);
                        draw();
                        closeOverlay();
                    },
                }),
                el('button', { class: 'btn', text: 'Abbrechen', onclick: () => closeOverlay() }),
            ]),
        ]));
    };

    const addNode = (type, ref, label, text) => {
        const count = board.nodes.length;
        board.nodes.push({
            id: 'n' + Math.random().toString(36).slice(2, 9),
            type, ref: ref || '', label, text: text || '',
            x: 40 + (count % 5) * 190,
            y: 40 + Math.floor(count / 5) * 130,
            color: 'default',
        });
        saveBoardNow(game, board);
        draw();
    };

    const pickDialog = () => {
        const tabs = el('div', { class: 'stack' });
        const section = (title, items, type) => {
            if (!items.length) return null;
            const list = el('div', { class: 'panel-grid cols-3' });
            items.forEach((item) => {
                list.append(el('button', {
                    class: 'evidence-card',
                    onclick: () => { addNode(type, item.ref, item.label, item.text); closeOverlay(); },
                }, [
                    el('span', { class: 'code', text: NODE_TYPES[type].label }),
                    el('h3', { text: item.label }),
                    item.text ? el('p', { text: String(item.text).slice(0, 90) }) : null,
                ]));
            });
            return el('section', {}, [el('h3', { text: title }), list]);
        };

        const onBoard = new Set(board.nodes.map((node) => node.ref));
        tabs.append(
            section('Personen', (game.state.npcs || [])
                .filter((npc) => !onBoard.has(npc.id))
                .map((npc) => ({ ref: npc.id, label: npc.name, text: npc.role })), 'person'),
            section('Beweise', (game.state.evidence || [])
                .filter((item) => !onBoard.has(item.id))
                .map((item) => ({ ref: item.id, label: `${item.code} ${item.title}`, text: item.summary })), 'evidence'),
            section('Orte', (game.state.locations || [])
                .filter((location) => !onBoard.has(location.id))
                .map((location) => ({ ref: location.id, label: location.name, text: location.description })), 'location'),
            section('Notizen', (game.state.notes || [])
                .filter((note) => !onBoard.has(note.id))
                .map((note) => ({ ref: note.id, label: note.text.slice(0, 40), text: note.text })), 'note'),
        );
        openOverlay('Karte hinzufuegen', tabs);
    };

    const linkButton = el('button', {
        class: 'btn btn--small', text: 'Verbinden: aus',
        onclick: () => {
            linkMode = !linkMode;
            linkFrom = null;
            linkButton.textContent = 'Verbinden: ' + (linkMode ? 'an' : 'aus');
            linkButton.classList.toggle('btn--primary', linkMode);
            frame.style.outline = linkMode ? '1px solid var(--accent)' : '';
        },
    });

    const toolbar = el('div', { class: 'board-toolbar' }, [
        el('button', { class: 'btn btn--small btn--primary', text: '+ Karte', onclick: pickDialog }),
        el('button', {
            class: 'btn btn--small', text: '+ Freie Notiz',
            onclick: () => addNode('note', '', 'Notiz', 'Doppelklick zum Bearbeiten'),
        }),
        linkButton,
        el('button', {
            class: 'btn btn--small', text: 'Verbindungen loesen',
            onclick: () => {
                if (!board.links.length) { toast('Es gibt keine Verbindungen.'); return; }
                board.links = [];
                saveBoardNow(game, board);
                drawLinks();
            },
        }),
        el('button', {
            class: 'btn btn--small', text: 'Ansicht zentrieren',
            onclick: () => { board.view = { x: 0, y: 0, zoom: 1 }; applyView(); saveBoard(game, board); },
        }),
        el('span', { class: 'hint', text: 'Ziehen: Karten bewegen · Doppelklick: bearbeiten · Mausrad: Zoom' }),
    ]);

    const help = el('p', {
        class: 'board-hint',
        text: 'Auf Touchgeraeten: "Verbinden" einschalten und zwei Karten nacheinander antippen. Karten lassen sich mit dem Finger ziehen.',
    });

    return { frame, toolbar, help, draw };
}
