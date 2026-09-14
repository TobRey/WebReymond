/* FBI-Abschlussbericht und Bewertung */

import { el, clear, post, toast, sectionTitle, debounce, openOverlay, closeOverlay, sound } from '../core.js';

export async function render(game) {
    const wrap = el('div', { class: 'panel-grid' });
    const report = game.state.report || {};

    if (report.submitted && report.result) {
        wrap.append(sectionTitle('Fallabschluss', 'Der Bericht liegt bei der Zentrale.'));
        wrap.append(renderResult(game, report.result));
        wrap.append(el('div', { class: 'toolbar' }, [
            el('button', { class: 'btn', text: 'Fall neu ermitteln', onclick: () => game.confirmReset() }),
            el('button', {
                class: 'btn', text: 'Zur Fallauswahl',
                onclick: () => {
                    const base = window.location.pathname.split('/spielen/')[0] || '';
                    window.location.href = base + '/faelle';
                },
            }),
        ]));
        return wrap;
    }

    wrap.append(sectionTitle(
        'Abschlussbericht',
        'Alles, was du behauptest, sollte durch Beweise gestuetzt sein. Ein falscher Bericht hat Folgen.',
    ));

    const answers = { ...(report.answers || {}) };
    const form = el('div', { class: 'report-form' });

    const save = debounce(() => {
        post(`/api/case/${game.caseId}/report`, { answers }).catch(() => {});
    }, 900);

    (report.questions || []).forEach((question) => {
        const box = el('section', { class: 'report-question' }, [
            el('h3', { text: question.label }),
            question.help ? el('p', { class: 'help', text: question.help }) : null,
        ]);

        if (question.type === 'text') {
            const area = el('textarea', {
                rows: '4',
                maxlength: String(question.max || 1500),
                placeholder: 'Freitext ...',
            }, [answers[question.id] || '']);
            area.addEventListener('input', () => { answers[question.id] = area.value; save(); });
            box.append(area);
        } else if (question.type === 'choice') {
            const list = el('div', { class: 'option-list' });
            (question.options || []).forEach((option) => {
                const input = el('input', { type: 'radio', name: question.id, value: option.id });
                if (answers[question.id] === option.id) input.checked = true;
                const label = el('label', { class: 'option' + (input.checked ? ' is-selected' : '') }, [
                    input,
                    el('span', {}, [el('strong', { text: option.label }), option.note ? el('small', { class: 'hint', text: ' ' + option.note }) : null]),
                ]);
                input.addEventListener('change', () => {
                    answers[question.id] = option.id;
                    list.querySelectorAll('.option').forEach((node) => node.classList.remove('is-selected'));
                    label.classList.add('is-selected');
                    save();
                });
                list.append(label);
            });
            box.append(list);
        } else if (question.type === 'multi' || question.type === 'evidence') {
            const options = question.type === 'evidence'
                ? (game.state.evidence || []).map((item) => ({ id: item.id, label: `${item.code} ${item.title}`, note: item.summary }))
                : (question.options || []);
            const current = new Set(answers[question.id] || []);
            const list = el('div', { class: 'option-list' });
            if (!options.length) {
                list.append(el('p', { class: 'hint', text: 'Noch keine Auswahl moeglich - sichere zuerst Beweise.' }));
            }
            options.forEach((option) => {
                const input = el('input', { type: 'checkbox', value: option.id });
                if (current.has(option.id)) input.checked = true;
                const label = el('label', { class: 'option' + (input.checked ? ' is-selected' : '') }, [
                    input,
                    el('span', {}, [el('strong', { text: option.label }), option.note ? el('small', { class: 'hint', text: ' ' + String(option.note).slice(0, 110) }) : null]),
                ]);
                input.addEventListener('change', () => {
                    input.checked ? current.add(option.id) : current.delete(option.id);
                    label.classList.toggle('is-selected', input.checked);
                    answers[question.id] = Array.from(current);
                    save();
                });
                list.append(label);
            });
            box.append(list);
        } else if (question.type === 'sequence' || question.type === 'timeline') {
            const order = answers[question.id] && answers[question.id].length
                ? question.options.slice().sort((a, b) => answers[question.id].indexOf(a.id) - answers[question.id].indexOf(b.id))
                : [...(question.options || [])];
            const list = el('div', { class: 'timeline-sort' });
            const redraw = () => {
                clear(list);
                order.forEach((item, index) => {
                    list.append(el('div', { class: 'sortable-item' }, [
                        el('span', { class: 'handle', text: String(index + 1).padStart(2, '0') }),
                        el('span', {}, [el('strong', { text: item.label }), item.note ? el('small', { class: 'hint', text: ' ' + item.note }) : null]),
                        el('span', { class: 'toolbar', style: { margin: 0, gap: '.2rem' } }, [
                            el('button', {
                                class: 'btn btn--small', text: '▲',
                                onclick: () => { if (index > 0) { [order[index - 1], order[index]] = [order[index], order[index - 1]]; commit(); } },
                            }),
                            el('button', {
                                class: 'btn btn--small', text: '▼',
                                onclick: () => { if (index < order.length - 1) { [order[index + 1], order[index]] = [order[index], order[index + 1]]; commit(); } },
                            }),
                        ]),
                    ]));
                });
            };
            const commit = () => { answers[question.id] = order.map((item) => item.id); save(); redraw(); };
            redraw();
            answers[question.id] = answers[question.id] || order.map((item) => item.id);
            box.append(list);
        }
        form.append(box);
    });

    wrap.append(form);

    wrap.append(el('div', { class: 'toolbar' }, [
        el('button', {
            class: 'btn', text: 'Zwischenspeichern',
            onclick: async () => {
                try {
                    await post(`/api/case/${game.caseId}/report`, { answers });
                    toast('Bericht gespeichert.', { kind: 'good' });
                } catch (error) { toast(error.message, { kind: 'bad' }); }
            },
        }),
        el('button', {
            class: 'btn btn--danger', text: 'Bericht einreichen und Fall abschliessen',
            onclick: () => confirmSubmit(game, answers),
        }),
    ]));

    wrap.append(el('p', {
        class: 'hint',
        text: 'Nach dem Einreichen ist der Fall abgeschlossen. Du kannst ihn danach nur komplett neu beginnen.',
    }));

    return wrap;
}

function confirmSubmit(game, answers) {
    const missing = (game.state.report?.questions || [])
        .filter((question) => question.required)
        .filter((question) => {
            const value = answers[question.id];
            return value === undefined || value === '' || (Array.isArray(value) && !value.length);
        });

    const body = el('div', { class: 'stack' }, [
        el('p', { text: 'Der Bericht geht an die Zentrale. Die Bewertung erfolgt sofort.' }),
        missing.length
            ? el('div', { class: 'alert alert--warn' }, [
                el('strong', { text: 'Unvollstaendig: ' }),
                el('span', { text: missing.map((question) => question.label).join(', ') }),
            ])
            : el('div', { class: 'alert alert--ok', text: 'Alle Pflichtfragen sind beantwortet.' }),
        el('div', { class: 'toolbar' }, [
            el('button', {
                class: 'btn btn--danger', text: 'Einreichen',
                onclick: async () => {
                    try {
                        const data = await post(`/api/case/${game.caseId}/report/submit`, { answers });
                        if (data.state) game.setState(data.state);
                        closeOverlay();
                        await game.showPanel('bericht');
                        sound.play('sting_low', { gain: 0.5 });
                        setTimeout(() => game.handleHorror(data.horror), 900);
                    } catch (error) { toast(error.message, { kind: 'bad' }); }
                },
            }),
            el('button', { class: 'btn', text: 'Zurueck', onclick: () => closeOverlay() }),
        ]),
    ]);
    openOverlay('Bericht einreichen', body);
}

function renderResult(game, result) {
    const wrap = el('div', { class: 'verdict' });
    const ending = result.ending || {};

    wrap.append(el('section', { class: 'panel-box' }, [
        el('div', { class: 'toolbar', style: { alignItems: 'center' } }, [
            el('div', { class: 'verdict__rank', 'data-rank': result.rank, text: result.rank }),
            el('div', {}, [
                el('p', { class: 'eyebrow', text: 'Bewertung' }),
                el('h2', { text: ending.title || 'Fall abgeschlossen' }),
                el('p', { class: 'hint', text: `${result.percent} % · ${result.score} von ${result.max} Punkten` }),
            ]),
        ]),
        el('div', { class: 'typed', text: ending.text || '' }),
        ending.epilogue ? el('div', { class: 'paper', style: { marginTop: '1rem' } }, [
            el('h3', { text: 'Epilog' }),
            el('div', { class: 'typed', text: ending.epilogue }),
        ]) : null,
    ]));

    const details = result.details || {};
    const breakdown = el('section', { class: 'panel-box' }, [el('h3', { text: 'Punkteverteilung' })]);
    (result.breakdown || []).forEach((row) => {
        breakdown.append(el('div', { class: 'score-row' }, [
            el('span', { text: row.label }),
            el('strong', { text: `${row.value}${row.max ? ' / ' + row.max : ''}` }),
        ]));
    });
    wrap.append(breakdown);

    const questions = el('section', { class: 'panel-box' }, [el('h3', { text: 'Deine Antworten' })]);
    Object.entries(details.questions || {}).forEach(([id, entry]) => {
        questions.append(el('div', { class: 'score-row' }, [
            el('span', {}, [
                el('strong', { text: entry.label }),
                el('div', { class: 'hint', text: entry.feedback || '' }),
            ]),
            el('span', {
                class: 'chip ' + (entry.correct ? 'chip--ok' : (entry.partial ? 'chip--warn' : 'chip--bad')),
                text: entry.correct ? 'richtig' : (entry.partial ? 'teilweise' : 'falsch'),
            }),
        ]));
    });
    wrap.append(questions);

    if ((details.missed_evidence || []).length) {
        const missed = el('section', { class: 'panel-box' }, [
            el('h3', { text: 'Uebersehene Spuren' }),
            el('p', { class: 'hint', text: 'Diese Beweise haettest du finden koennen:' }),
        ]);
        details.missed_evidence.forEach((item) => {
            missed.append(el('div', { class: 'score-row' }, [
                el('span', {}, [
                    el('strong', { text: `${item.code} ${item.title}` }),
                    item.where ? el('div', { class: 'hint', text: item.where }) : null,
                ]),
                el('span', { class: 'chip ' + (item.key ? 'chip--bad' : 'chip--muted'), text: item.key ? 'Kernbeweis' : 'Nebenspur' }),
            ]));
        });
        wrap.append(missed);
    }

    if ((details.missed_lies || []).length) {
        const lies = el('section', { class: 'panel-box' }, [el('h3', { text: 'Nicht aufgedeckte Luegen' })]);
        details.missed_lies.forEach((lie) => {
            lies.append(el('div', { class: 'score-row' }, [
                el('span', {}, [
                    el('strong', { text: lie.npc }),
                    el('div', { class: 'hint', text: `Behauptung: ${lie.claim}` }),
                    el('div', { class: 'hint', text: `Wahrheit: ${lie.truth}` }),
                ]),
                el('span', { class: 'chip chip--warn', text: 'uebersehen' }),
            ]));
        });
        wrap.append(lies);
    }

    wrap.append(el('section', { class: 'panel-box' }, [
        el('h3', { text: 'Statistik' }),
        el('div', { class: 'score-row' }, [el('span', { text: 'Gefundene Beweise' }), el('strong', { text: `${details.evidence_found} / ${details.evidence_total}` })]),
        el('div', { class: 'score-row' }, [el('span', { text: 'Aufgedeckte Luegen' }), el('strong', { text: `${details.lies_found} / ${details.lies_total}` })]),
        el('div', { class: 'score-row' }, [el('span', { text: 'Verwendete Hinweise' }), el('strong', { text: String(details.hints_used) })]),
        el('div', { class: 'score-row' }, [el('span', { text: 'Bearbeitungszeit' }), el('strong', { text: formatDuration(details.playtime) })]),
        el('div', { class: 'score-row' }, [el('span', { text: 'Falsche Anschuldigungen' }), el('strong', { text: String(details.wrong_accusations) })]),
    ]));

    return wrap;
}

function formatDuration(seconds) {
    const total = Math.max(0, Math.floor(seconds || 0));
    const minutes = Math.floor(total / 60);
    return `${minutes} Min. ${String(total % 60).padStart(2, '0')} Sek.`;
}
