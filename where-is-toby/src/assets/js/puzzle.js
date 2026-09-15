/* =============================================================
   Raetsel-Oberflaeche: Eingabemasken fuer alle Raetseltypen
   ============================================================= */

import { el, post, toast, sound, clear, closeOverlay, openOverlay } from './core.js';

/** Sendet eine Antwort und verarbeitet das Ergebnis zentral. */
export async function solve(game, puzzleId, answer, options = {}) {
    try {
        const data = await post(`/api/case/${game.caseId}/puzzle`, { puzzle: puzzleId, answer });
        if (data.state) game.setState(data.state);

        if (data.ok) {
            sound.play('ui_success', { gain: 0.4 });
            toast(data.message || 'Richtig.', { title: 'Gesichert', kind: 'good' });
            (data.unlocked?.evidence || []).forEach((id) => {
                const evidence = game.evidenceById(id);
                if (evidence) {
                    toast(`${evidence.code} ${evidence.title}`, { title: 'Neuer Beweis', kind: 'evidence', timeout: 7000 });
                }
            });
            (data.unlocked?.devices || []).forEach(() => game.markPanelDot('geraete'));
            if (data.explanation) {
                toast(data.explanation, { title: 'Auswertung', timeout: 11000 });
            }
            game.handleHorror(data.horror);
            if (typeof options.onSuccess === 'function') options.onSuccess(data);
        } else {
            sound.play('ui_error', { gain: 0.35 });
            toast(data.message || 'Das passt nicht.', { title: data.close ? 'Fast' : 'Falsch', kind: 'bad' });
            game.handleHorror(data.horror);
            if (typeof options.onFail === 'function') options.onFail(data);
        }
        return data;
    } catch (error) {
        toast(error.message, { title: 'Fehler', kind: 'bad' });
        return { ok: false };
    }
}

/**
 * Baut die Eingabemaske fuer ein Raetsel.
 * @param {object} game
 * @param {object} puzzle  Oeffentliche Raetseldaten aus dem Spielzustand
 * @param {object} options { onSuccess, compact }
 */
export function renderPuzzle(game, puzzle, options = {}) {
    if (!puzzle) return el('p', { class: 'hint', text: 'Dieses Raetsel ist nicht verfuegbar.' });

    if (puzzle.solved) {
        return el('div', { class: 'alert alert--ok' }, [
            el('strong', { text: 'Geloest. ' }),
            el('span', { text: puzzle.explanation || 'Diese Aufgabe ist abgeschlossen.' }),
        ]);
    }
    if (!puzzle.available) {
        return el('div', { class: 'alert alert--warn', text: 'Dafuer fehlen noch Informationen. Suche weiter.' });
    }

    const wrap = el('div', { class: 'stack' });
    if (puzzle.prompt && !options.compact) wrap.append(el('p', { text: puzzle.prompt }));
    if (puzzle.context) wrap.append(el('p', { class: 'hint', text: puzzle.context }));

    const finish = (data) => {
        if (data?.ok && typeof options.onSuccess === 'function') options.onSuccess(data);
    };

    switch (puzzle.type) {
        case 'pin':
            wrap.append(pinPad(game, puzzle, finish));
            break;
        case 'pattern':
            wrap.append(patternLock(game, puzzle, finish));
            break;
        case 'choice':
            wrap.append(choiceList(game, puzzle, finish, false));
            break;
        case 'multi':
        case 'contradiction':
        case 'pairs':
            wrap.append(choiceList(game, puzzle, finish, true));
            break;
        case 'sequence':
        case 'timeline':
            wrap.append(sortableList(game, puzzle, finish));
            break;
        case 'mark':
            wrap.append(markableText(game, puzzle, finish));
            break;
        case 'record':
            wrap.append(recordTable(game, puzzle, finish));
            break;
        case 'link':
            wrap.append(linkColumns(game, puzzle, finish));
            break;
        default:
            wrap.append(textInput(game, puzzle, finish));
    }

    if (puzzle.attempts > 0) {
        wrap.append(el('p', { class: 'hint', text: `Versuche: ${puzzle.attempts}${puzzle.max_attempts ? ' / ' + puzzle.max_attempts : ''}` }));
    }
    return wrap;
}

/* ------------------------- Einzelne Typen ------------------------- */

function textInput(game, puzzle, finish) {
    const input = el('input', {
        type: puzzle.type === 'password' ? 'password' : 'text',
        placeholder: puzzle.placeholder || 'Antwort eingeben',
        maxlength: '120',
        autocomplete: 'off',
        autocapitalize: 'off',
        spellcheck: 'false',
    });
    const submit = async () => {
        const value = input.value.trim();
        if (!value) return;
        const data = await solve(game, puzzle.id, value);
        if (data.ok) { input.value = ''; finish(data); } else { input.select(); }
    };
    input.addEventListener('keydown', (event) => { if (event.key === 'Enter') submit(); });
    return el('div', { class: 'toolbar' }, [
        el('div', { style: { flex: '1 1 220px' } }, [input]),
        el('button', { class: 'btn btn--primary', text: 'Pruefen', onclick: submit }),
    ]);
}

/**
 * Typ "mark": Stellen direkt im Text anklicken.
 * Ersetzt Aufgaben, bei denen man sonst aus vier Saetzen den richtigen waehlt -
 * hier zeigt der Spieler im Dokument selbst, was ihm auffaellt.
 */
function markableText(game, puzzle, finish) {
    const picked = new Set();
    const sheet = el('div', { class: 'markdoc' });

    (puzzle.tokens || []).forEach((token) => {
        if (token.break) { sheet.append(el('br')); return; }
        if (!token.markable) { sheet.append(el('span', { text: token.text })); return; }
        const chip = el('button', { type: 'button', class: 'markdoc__tok', text: token.text });
        chip.addEventListener('click', () => {
            if (picked.has(token.id)) { picked.delete(token.id); } else { picked.add(token.id); }
            chip.classList.toggle('is-marked', picked.has(token.id));
            sound.play('ui_click', { gain: 0.2 });
            count.textContent = `${picked.size} markiert`;
        });
        sheet.append(chip);
    });

    const count = el('span', { class: 'hint', text: '0 markiert' });
    const submit = async () => {
        if (!picked.size) return;
        const data = await solve(game, puzzle.id, [...picked]);
        if (data.ok) { finish(data); return; }
        sheet.querySelectorAll('.is-marked').forEach((node) => {
            node.classList.remove('is-marked');
            node.classList.add('is-wrong');
            setTimeout(() => node.classList.remove('is-wrong'), 700);
        });
        picked.clear();
        count.textContent = '0 markiert';
    };

    return el('div', { class: 'stack' }, [
        sheet,
        el('div', { class: 'toolbar' }, [
            count,
            el('button', { class: 'btn btn--primary', text: 'Markierung pruefen', onclick: submit }),
        ]),
    ]);
}

/**
 * Typ "record": ein filterbares Protokoll, aus dem die richtige Zeile angeklickt wird.
 * Die Arbeit besteht im Eingrenzen, nicht im Abtippen.
 */
function recordTable(game, puzzle, finish) {
    const columns = puzzle.columns || [];
    const rows = puzzle.records || [];
    const body = el('div', { class: 'reclist__body' });
    const status = el('span', { class: 'hint' });

    let active = '';
    const draw = () => {
        clear(body);
        const visible = rows.filter((row) => !active || (row.tags || []).includes(active));
        visible.forEach((row) => {
            const line = el('button', { type: 'button', class: 'reclist__row' },
                (row.cells || []).map((cell) => el('span', { text: cell })));
            line.style.gridTemplateColumns = `repeat(${columns.length || row.cells.length}, minmax(0, 1fr))`;
            line.addEventListener('click', async () => {
                const data = await solve(game, puzzle.id, row.id);
                if (data.ok) { finish(data); return; }
                line.classList.add('is-wrong');
                setTimeout(() => line.classList.remove('is-wrong'), 700);
            });
            body.append(line);
        });
        status.textContent = `${visible.length} von ${rows.length} Eintraegen`;
    };

    const bar = el('div', { class: 'toolbar' });
    (puzzle.filters || []).forEach((filter) => {
        const button = el('button', { type: 'button', class: 'btn btn--small', text: filter.label || filter.tag });
        button.addEventListener('click', () => {
            active = active === filter.tag ? '' : filter.tag;
            bar.querySelectorAll('.btn').forEach((b) => b.classList.remove('is-active'));
            if (active) button.classList.add('is-active');
            draw();
        });
        bar.append(button);
    });
    bar.append(status);

    const head = el('div', { class: 'reclist__head' }, columns.map((c) => el('span', { text: c })));
    head.style.gridTemplateColumns = `repeat(${columns.length}, minmax(0, 1fr))`;

    draw();
    return el('div', { class: 'stack' }, [bar, el('div', { class: 'reclist' }, [head, body])]);
}

/**
 * Typ "link": zwei Spalten verbinden - links eine Behauptung, rechts ein Fakt.
 * Erst wenn beide Seiten gewaehlt sind, wird geprueft.
 */
function linkColumns(game, puzzle, finish) {
    const choice = { left: '', right: '' };
    const columns = el('div', { class: 'linkcols' });

    const column = (side, items, title) => {
        const list = el('div', { class: 'linkcols__col' }, [el('p', { class: 'eyebrow', text: title })]);
        items.forEach((item) => {
            const card = el('button', { type: 'button', class: 'linkcols__item' }, [
                el('strong', { text: item.label }),
                item.note ? el('small', { text: item.note }) : null,
            ].filter(Boolean));
            card.addEventListener('click', async () => {
                choice[side] = choice[side] === item.id ? '' : item.id;
                list.querySelectorAll('.linkcols__item').forEach((n) => n.classList.remove('is-picked'));
                if (choice[side]) card.classList.add('is-picked');
                sound.play('ui_click', { gain: 0.2 });
                if (!choice.left || !choice.right) return;

                const data = await solve(game, puzzle.id, [choice.left, choice.right]);
                if (data.ok) { finish(data); return; }
                columns.classList.add('is-wrong');
                setTimeout(() => columns.classList.remove('is-wrong'), 700);
                choice.left = '';
                choice.right = '';
                columns.querySelectorAll('.linkcols__item').forEach((n) => n.classList.remove('is-picked'));
            });
            list.append(card);
        });
        return list;
    };

    columns.append(column('left', puzzle.left || [], puzzle.left_title || 'Behauptung'));
    columns.append(column('right', puzzle.right || [], puzzle.right_title || 'Fakten'));
    return el('div', { class: 'stack' }, [
        columns,
        el('p', { class: 'hint', text: 'Waehle links und rechts je einen Eintrag, der zusammengehoert.' }),
    ]);
}

function pinPad(game, puzzle, finish) {
    const length = puzzle.length || 4;
    let value = '';
    const display = el('div', { class: 'pin-display', text: '' });
    const update = () => { display.textContent = '•'.repeat(value.length).padEnd(length, '·'); };
    update();

    const press = async (digit) => {
        if (value.length >= length) return;
        value += digit;
        sound.play('ui_click', { gain: 0.25 });
        update();
        if (value.length === length) {
            const data = await solve(game, puzzle.id, value);
            value = '';
            update();
            if (data.ok) finish(data);
        }
    };

    const pad = el('div', { class: 'pinpad' });
    ['1', '2', '3', '4', '5', '6', '7', '8', '9'].forEach((digit) => {
        pad.append(el('button', { type: 'button', text: digit, onclick: () => press(digit) }));
    });
    pad.append(el('button', {
        type: 'button', text: '←',
        onclick: () => { value = value.slice(0, -1); update(); },
    }));
    pad.append(el('button', { type: 'button', text: '0', onclick: () => press('0') }));
    pad.append(el('button', {
        type: 'button', text: 'C',
        onclick: () => { value = ''; update(); },
    }));

    return el('div', { class: 'lockscreen' }, [display, pad]);
}

function patternLock(game, puzzle, finish) {
    const selected = [];
    const grid = el('div', { class: 'pattern-grid' });
    const buttons = [];

    for (let i = 1; i <= 9; i++) {
        const button = el('button', { type: 'button', 'data-node': String(i), text: '' });
        const toggle = () => {
            if (selected.includes(i)) return;
            selected.push(i);
            button.classList.add('is-on');
            sound.play('ui_click', { gain: 0.18 });
        };
        button.addEventListener('pointerdown', toggle);
        button.addEventListener('pointerenter', (event) => { if (event.buttons === 1) toggle(); });
        buttons.push(button);
        grid.append(button);
    }

    const reset = () => {
        selected.length = 0;
        buttons.forEach((button) => button.classList.remove('is-on'));
    };

    const check = async () => {
        if (selected.length < 3) return;
        const data = await solve(game, puzzle.id, selected.join(''));
        reset();
        if (data.ok) finish(data);
    };

    return el('div', { class: 'lockscreen' }, [
        el('p', { class: 'hint', text: 'Muster ziehen oder Punkte nacheinander antippen, dann bestaetigen.' }),
        grid,
        el('div', { class: 'toolbar' }, [
            el('button', { class: 'btn btn--primary', text: 'Entsperren', onclick: check }),
            el('button', { class: 'btn', text: 'Zuruecksetzen', onclick: reset }),
        ]),
    ]);
}

function choiceList(game, puzzle, finish, multiple) {
    const chosen = new Set();
    const list = el('div', { class: 'option-list' });

    (puzzle.options || []).forEach((option) => {
        const input = el('input', {
            type: multiple ? 'checkbox' : 'radio',
            name: 'puzzle-' + puzzle.id,
            value: option.id,
        });
        const label = el('label', { class: 'option' }, [
            input,
            el('span', {}, [
                el('strong', { text: option.label }),
                option.note ? el('small', { class: 'hint', text: ' ' + option.note }) : null,
            ]),
        ]);
        input.addEventListener('change', () => {
            if (multiple) {
                input.checked ? chosen.add(option.id) : chosen.delete(option.id);
            } else {
                chosen.clear();
                chosen.add(option.id);
                list.querySelectorAll('.option').forEach((node) => node.classList.remove('is-selected'));
            }
            label.classList.toggle('is-selected', input.checked);
        });
        list.append(label);
    });

    return el('div', { class: 'stack' }, [
        list,
        el('button', {
            class: 'btn btn--primary',
            text: multiple ? 'Auswahl pruefen' : 'Bestaetigen',
            onclick: async () => {
                if (!chosen.size) { toast('Bitte zuerst auswaehlen.'); return; }
                const answer = multiple ? Array.from(chosen) : Array.from(chosen)[0];
                const data = await solve(game, puzzle.id, answer);
                if (data.ok) finish(data);
            },
        }),
    ]);
}

function sortableList(game, puzzle, finish) {
    const items = [...(puzzle.options || [])];
    const list = el('div', { class: 'timeline-sort' });
    let dragged = null;

    const draw = () => {
        clear(list);
        items.forEach((item, index) => {
            const row = el('div', {
                class: 'sortable-item',
                draggable: 'true',
                'data-id': item.id,
            }, [
                el('span', { class: 'handle', text: String(index + 1).padStart(2, '0') }),
                el('span', {}, [
                    el('strong', { text: item.label }),
                    item.note ? el('small', { class: 'hint', text: ' ' + item.note }) : null,
                ]),
                el('span', { class: 'toolbar', style: { margin: 0, gap: '.2rem' } }, [
                    el('button', {
                        class: 'btn btn--small', text: '▲', title: 'nach oben',
                        onclick: () => { if (index > 0) { [items[index - 1], items[index]] = [items[index], items[index - 1]]; draw(); } },
                    }),
                    el('button', {
                        class: 'btn btn--small', text: '▼', title: 'nach unten',
                        onclick: () => { if (index < items.length - 1) { [items[index + 1], items[index]] = [items[index], items[index + 1]]; draw(); } },
                    }),
                ]),
            ]);
            row.addEventListener('dragstart', () => { dragged = item.id; row.classList.add('is-dragging'); });
            row.addEventListener('dragend', () => { dragged = null; row.classList.remove('is-dragging'); });
            row.addEventListener('dragover', (event) => event.preventDefault());
            row.addEventListener('drop', (event) => {
                event.preventDefault();
                if (!dragged || dragged === item.id) return;
                const from = items.findIndex((entry) => entry.id === dragged);
                const to = items.findIndex((entry) => entry.id === item.id);
                items.splice(to, 0, items.splice(from, 1)[0]);
                draw();
            });
            list.append(row);
        });
    };
    draw();

    return el('div', { class: 'stack' }, [
        el('p', { class: 'hint', text: 'Reihenfolge per Ziehen oder mit den Pfeilen sortieren.' }),
        list,
        el('button', {
            class: 'btn btn--primary',
            text: 'Reihenfolge pruefen',
            onclick: async () => {
                const data = await solve(game, puzzle.id, items.map((item) => item.id));
                if (data.ok) finish(data);
            },
        }),
    ]);
}

/** Raetsel in einem Overlay oeffnen. */
export function openPuzzleOverlay(game, puzzle, title = null) {
    const content = renderPuzzle(game, puzzle, {
        onSuccess: () => setTimeout(() => closeOverlay(), 900),
    });
    openOverlay(title || puzzle.title || 'Aufgabe', content);
}
