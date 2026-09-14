/* =============================================================
   Fall-Editor: formularbasierte Bearbeitung ohne Programmierung
   ============================================================= */

import { el, clear, post, get, toast, openOverlay, closeOverlay, escapeHtml } from '../core.js';
import { SECTIONS } from './schema.js';

/* ---------------------- Pfad-Helfer ---------------------- */

function getPath(object, path) {
    return path.split('.').reduce((cursor, key) => (cursor === null || cursor === undefined ? undefined : cursor[key]), object);
}

function setPath(object, path, value) {
    const keys = path.split('.');
    let cursor = object;
    keys.slice(0, -1).forEach((key) => {
        if (typeof cursor[key] !== 'object' || cursor[key] === null) cursor[key] = {};
        cursor = cursor[key];
    });
    cursor[keys[keys.length - 1]] = value;
}

/* ---------------------- Editor ---------------------- */

export function initCaseEditor(container) {
    const caseData = JSON.parse(container.querySelector('[data-role="case-data"]').textContent || '{}');
    const validation = JSON.parse(container.querySelector('[data-role="validation"]').textContent || '{}');
    let versions = JSON.parse(container.querySelector('[data-role="versions"]').textContent || '[]');
    const mediaLibrary = JSON.parse(container.querySelector('[data-role="media-library"]').textContent || '[]');
    const audioTracks = JSON.parse(container.querySelector('[data-role="audio-tracks"]').textContent || '[]');
    const isNew = container.dataset.isNew === '1';
    const aiAvailable = container.dataset.ai === '1';

    const nav = container.querySelector('[data-role="editor-nav"]');
    const body = container.querySelector('[data-role="editor-body"]');
    const statusNode = container.querySelector('[data-role="editor-status"]');
    const validationBox = container.querySelector('[data-role="validation-box"]');

    let activeSection = SECTIONS[0].id;
    let dirty = false;

    const setStatus = (text) => { statusNode.textContent = text; };
    const markDirty = () => { dirty = true; setStatus('Ungespeicherte Aenderungen.'); };

    window.addEventListener('beforeunload', (event) => {
        if (dirty) { event.preventDefault(); event.returnValue = ''; }
    });

    /* ------------------ Navigation ------------------ */
    const drawNav = () => {
        clear(nav);
        SECTIONS.forEach((section) => {
            const count = countFor(section);
            nav.append(el('button', {
                class: section.id === activeSection ? 'is-active' : '',
                onclick: () => { activeSection = section.id; drawNav(); drawSection(); },
            }, [
                `${section.icon || ''} ${section.label}`,
                count !== null ? el('span', { class: 'count', text: String(count) }) : null,
            ]));
        });
    };

    const countFor = (section) => {
        if (section.list) return (getPath(caseData, section.list.path) || []).length;
        if (section.lists) return section.lists.reduce((sum, list) => sum + (getPath(caseData, list.path) || []).length, 0);
        return null;
    };

    /* ------------------ Abschnitt zeichnen ------------------ */
    const drawSection = () => {
        const section = SECTIONS.find((entry) => entry.id === activeSection);
        clear(body);
        if (!section) return;

        if (section.raw) { body.append(rawEditor()); return; }
        if (section.versions) { body.append(versionView()); return; }

        const box = el('section', { class: 'editor-section' }, [el('h2', { text: `${section.icon || ''} ${section.label}` })]);

        if (section.fields) {
            const grid = el('div', { class: 'field-grid' });
            section.fields.forEach((field) => grid.append(renderField(caseData, field, '')));
            box.append(grid);
        }
        if (section.list) {
            box.append(renderList(caseData, section.list));
        }
        if (section.lists) {
            section.lists.forEach((list) => {
                box.append(el('h3', { text: list.label }));
                box.append(renderList(caseData, list));
            });
        }
        body.append(box);
    };

    /* ------------------ Felder ------------------ */
    function renderField(scope, field, prefix) {
        const path = prefix ? `${prefix}.${field.path}` : field.path;
        const value = getPath(scope, field.path);
        const wrapClass = 'field' + (field.full ? ' full' : '');

        if (field.type === 'list') {
            return el('div', { class: 'full' }, [
                el('h4', { text: field.label }),
                renderList(scope, { ...field, path: field.path }),
            ]);
        }

        let control;
        const commit = (newValue) => {
            setPath(scope, field.path, newValue);
            markDirty();
            if (['id', 'title', 'name', 'label'].includes(field.path)) drawNav();
        };

        switch (field.type) {
            case 'textarea':
                control = el('textarea', { rows: String(field.rows || 3), maxlength: '40000' }, [value ?? '']);
                control.addEventListener('input', () => commit(control.value));
                break;
            case 'number':
                control = el('input', { type: 'number', value: value ?? '', step: field.step || '1' });
                control.addEventListener('input', () => commit(control.value === '' ? null : Number(control.value)));
                break;
            case 'checkbox':
                control = el('input', { type: 'checkbox', checked: Boolean(value) });
                control.addEventListener('change', () => commit(control.checked));
                return el('label', { class: 'check' + (field.full ? ' full' : '') }, [control, el('span', { text: field.label })]);
            case 'triple':
                control = el('select', {}, [
                    el('option', { value: '', text: 'egal' }),
                    el('option', { value: '1', text: 'ja' }),
                    el('option', { value: '0', text: 'nein' }),
                ]);
                control.value = value === undefined || value === null ? '' : (value ? '1' : '0');
                control.addEventListener('change', () => commit(control.value === '' ? undefined : control.value === '1'));
                break;
            case 'select':
                control = el('select', {}, (field.options || []).map((option) =>
                    el('option', { value: option, text: option === '' ? '(keine)' : option })));
                control.value = value ?? '';
                control.addEventListener('change', () => commit(control.value));
                break;
            case 'tags':
                control = el('input', { type: 'text', value: Array.isArray(value) ? value.join(', ') : (value ?? '') });
                control.addEventListener('input', () => commit(
                    control.value.split(',').map((part) => part.trim()).filter(Boolean)
                ));
                break;
            case 'lines':
                control = el('textarea', { rows: String(field.rows || 4) }, [Array.isArray(value) ? value.join('\n') : (value ?? '')]);
                control.addEventListener('input', () => commit(
                    control.value.split('\n').map((line) => line.trim()).filter(Boolean)
                ));
                break;
            case 'hints': {
                const hints = Array.isArray(value) ? value : ['', '', ''];
                const wrap = el('div', { class: 'stack' });
                ['Stufe 1 - subtile Andeutung', 'Stufe 2 - konkreter Hinweis', 'Stufe 3 - fast direkte Hilfe'].forEach((label, index) => {
                    const input = el('textarea', { rows: '2' }, [hints[index] || '']);
                    input.addEventListener('input', () => {
                        hints[index] = input.value;
                        commit([...hints]);
                    });
                    const row = el('div', {}, [el('label', {}, [label, input])]);
                    if (aiAvailable) {
                        row.append(el('button', {
                            class: 'btn btn--small', type: 'button', text: 'KI-Vorschlag',
                            onclick: () => suggestHint(scope, index + 1, (text) => {
                                input.value = text;
                                hints[index] = text;
                                commit([...hints]);
                            }),
                        }));
                    }
                    wrap.append(row);
                });
                return el('div', { class: 'full' }, [el('h4', { text: field.label }), wrap]);
            }
            case 'json': {
                control = el('textarea', { rows: '6', class: 'mono' }, [
                    value === undefined || value === null ? '' : JSON.stringify(value, null, 2),
                ]);
                const feedback = el('span', { class: 'field-help', text: field.help || '' });
                control.addEventListener('input', () => {
                    const text = control.value.trim();
                    if (text === '') { commit(undefined); feedback.textContent = field.help || ''; control.style.borderColor = ''; return; }
                    try {
                        commit(JSON.parse(text));
                        feedback.textContent = 'JSON gueltig.';
                        control.style.borderColor = 'var(--good)';
                    } catch (error) {
                        feedback.textContent = 'JSON-Fehler: ' + error.message;
                        control.style.borderColor = 'var(--danger)';
                    }
                });
                return el('label', { class: wrapClass }, [field.label, control, feedback]);
            }
            case 'media': {
                control = el('input', { type: 'text', value: value ?? '', placeholder: 'assets/img/... oder uploads/media/...' });
                control.addEventListener('input', () => commit(control.value));
                const picker = el('select', {}, [
                    el('option', { value: '', text: '-- aus Bibliothek waehlen --' }),
                    ...mediaLibrary.map((item) => el('option', { value: 'uploads/media/' + item.file, text: item.title || item.file })),
                ]);
                picker.addEventListener('change', () => {
                    if (!picker.value) return;
                    control.value = picker.value;
                    commit(picker.value);
                });
                return el('label', { class: wrapClass }, [field.label, control, picker,
                    field.help ? el('span', { class: 'field-help', text: field.help }) : null]);
            }
            case 'audiotrack': {
                control = el('select', {}, [
                    el('option', { value: '', text: '(keine)' }),
                    ...audioTracks.map((track) => el('option', { value: track, text: track })),
                ]);
                control.value = value ?? '';
                control.addEventListener('change', () => commit(control.value));
                break;
            }
            default:
                control = el('input', { type: 'text', value: value ?? '', maxlength: '2000' });
                control.addEventListener('input', () => commit(control.value));
        }

        const label = el('label', { class: wrapClass }, [
            field.label + (field.required ? ' *' : ''),
            control,
            field.help ? el('span', { class: 'field-help', text: field.help }) : null,
        ]);

        if (field.ai && aiAvailable) {
            label.append(el('button', {
                class: 'btn btn--small', type: 'button', text: 'KI-Vorschlag',
                onclick: () => suggest(field.ai, scope, (text) => {
                    control.value = text;
                    commit(text);
                }),
            }));
        }
        return label;
    }

    /* ------------------ Listen ------------------ */
    function renderList(scope, list) {
        const wrap = el('div', { class: 'stack' });
        const items = getPath(scope, list.path) || [];
        if (!Array.isArray(getPath(scope, list.path))) setPath(scope, list.path, items);

        const redraw = () => {
            clear(wrap);
            const current = getPath(scope, list.path) || [];
            current.forEach((item, index) => {
                const title = String(item[list.titleField] ?? '') || `${list.addLabel || 'Eintrag'} ${index + 1}`;
                const itemBox = el('article', { class: 'repeat-item is-collapsed' });
                const head = el('header', { class: 'repeat-item__head' }, [
                    el('strong', { text: `${index + 1}. ${title.slice(0, 70)}` }),
                    el('div', { class: 'toolbar', style: { margin: 0 } }, [
                        el('button', {
                            class: 'btn btn--small', type: 'button', text: '▲', title: 'nach oben',
                            onclick: (event) => {
                                event.stopPropagation();
                                if (index === 0) return;
                                [current[index - 1], current[index]] = [current[index], current[index - 1]];
                                markDirty(); redraw();
                            },
                        }),
                        el('button', {
                            class: 'btn btn--small', type: 'button', text: '▼', title: 'nach unten',
                            onclick: (event) => {
                                event.stopPropagation();
                                if (index >= current.length - 1) return;
                                [current[index + 1], current[index]] = [current[index], current[index + 1]];
                                markDirty(); redraw();
                            },
                        }),
                        el('button', {
                            class: 'btn btn--small', type: 'button', text: 'Kopieren',
                            onclick: (event) => {
                                event.stopPropagation();
                                const copy = JSON.parse(JSON.stringify(current[index]));
                                if (copy.id) copy.id = copy.id + '_kopie';
                                current.splice(index + 1, 0, copy);
                                markDirty(); redraw(); drawNav();
                            },
                        }),
                        el('button', {
                            class: 'btn btn--small btn--danger', type: 'button', text: 'Entfernen',
                            onclick: (event) => {
                                event.stopPropagation();
                                if (!window.confirm('Diesen Eintrag wirklich entfernen?')) return;
                                current.splice(index, 1);
                                markDirty(); redraw(); drawNav();
                            },
                        }),
                    ]),
                ]);
                head.addEventListener('click', (event) => {
                    if (event.target.closest('button')) return;
                    itemBox.classList.toggle('is-collapsed');
                    if (!itemBox.classList.contains('is-collapsed') && !itemBox.dataset.filled) {
                        fillItem(itemBox, item, list);
                    }
                });
                itemBox.append(head, el('div', { class: 'repeat-item__body' }));
                wrap.append(itemBox);
            });

            wrap.append(el('button', {
                class: 'btn btn--primary btn--small', type: 'button', text: '+ ' + (list.addLabel || 'Eintrag'),
                onclick: () => {
                    const fresh = {};
                    (list.fields || []).forEach((field) => {
                        if (field.type === 'list') setPath(fresh, field.path, []);
                    });
                    current.push(fresh);
                    markDirty();
                    redraw();
                    drawNav();
                    const boxes = wrap.querySelectorAll('.repeat-item');
                    const last = boxes[boxes.length - 1];
                    if (last) {
                        last.classList.remove('is-collapsed');
                        fillItem(last, fresh, list);
                    }
                },
            }));
        };

        const fillItem = (itemBox, item, listDefinition) => {
            const target = itemBox.querySelector('.repeat-item__body');
            clear(target);
            const grid = el('div', { class: 'field-grid' });
            (listDefinition.fields || []).forEach((field) => grid.append(renderField(item, field, '')));
            target.append(grid);
            itemBox.dataset.filled = '1';
        };

        redraw();
        return wrap;
    }

    /* ------------------ Rohdaten ------------------ */
    function rawEditor() {
        const area = el('textarea', { class: 'json-editor' }, [JSON.stringify(caseData, null, 2)]);
        const info = el('p', { class: 'hint', text: 'Direkter Zugriff auf die Falldatei. Nach dem Einfuegen "Uebernehmen" klicken.' });
        return el('section', { class: 'editor-section' }, [
            el('h2', { text: '{} Rohdaten' }),
            info,
            area,
            el('div', { class: 'toolbar' }, [
                el('button', {
                    class: 'btn btn--primary', type: 'button', text: 'Uebernehmen',
                    onclick: () => {
                        try {
                            const parsed = JSON.parse(area.value);
                            Object.keys(caseData).forEach((key) => delete caseData[key]);
                            Object.assign(caseData, parsed);
                            markDirty();
                            drawNav();
                            toast('Rohdaten uebernommen. Jetzt speichern.', { kind: 'good' });
                        } catch (error) {
                            toast('JSON-Fehler: ' + error.message, { kind: 'bad' });
                        }
                    },
                }),
                el('button', {
                    class: 'btn', type: 'button', text: 'In die Zwischenablage',
                    onclick: async () => {
                        try {
                            await navigator.clipboard.writeText(JSON.stringify(caseData, null, 2));
                            toast('Kopiert.', { kind: 'good' });
                        } catch { toast('Kopieren nicht moeglich. Text manuell markieren.', { kind: 'bad' }); }
                    },
                }),
                caseData.id ? el('a', {
                    class: 'btn', href: `../../api/admin/case/${caseData.id}/export`, text: 'Als Datei exportieren',
                }) : null,
            ]),
        ]);
    }

    /* ------------------ Versionen ------------------ */
    function versionView() {
        const box = el('section', { class: 'editor-section' }, [el('h2', { text: '🕘 Versionsverlauf' })]);
        if (!versions.length) {
            box.append(el('p', { class: 'hint', text: 'Noch keine Versionen. Bei jedem Speichern wird der vorherige Stand gesichert.' }));
            return box;
        }
        const table = el('table', { class: 'table' }, [
            el('thead', {}, [el('tr', {}, [el('th', { text: 'Version' }), el('th', { text: 'Titel' }), el('th', { text: '' })])]),
        ]);
        const tbody = el('tbody');
        versions.forEach((version) => {
            tbody.append(el('tr', {}, [
                el('td', { class: 'mono', text: version.id }),
                el('td', { text: version.title }),
                el('td', {}, [el('button', {
                    class: 'btn btn--small', type: 'button', text: 'Wiederherstellen',
                    onclick: async () => {
                        if (!window.confirm('Diese Version wiederherstellen? Der aktuelle Stand wird zuvor gesichert.')) return;
                        try {
                            await post('/api/admin/case/version/restore', { case: caseData.id, version: version.id });
                            toast('Version wiederhergestellt. Seite wird neu geladen.', { kind: 'good' });
                            dirty = false;
                            setTimeout(() => window.location.reload(), 700);
                        } catch (error) { toast(error.message, { kind: 'bad' }); }
                    },
                })]),
            ]));
        });
        table.append(tbody);
        box.append(table);
        return box;
    }

    /* ------------------ KI-Vorschlaege ------------------ */
    async function suggest(type, scope, apply) {
        const context = JSON.stringify({
            fall: caseData.title,
            ort: caseData.location,
            vermisst: caseData.missing_person,
            aktueller_eintrag: scope === caseData ? undefined : scope,
        }).slice(0, 3500);

        const status = el('p', { class: 'hint', text: 'Vorschlag wird erzeugt ...' });
        openOverlay('KI-Vorschlag (' + type + ')', status);
        try {
            const data = await post('/api/admin/ai/suggest', { type, context });
            const area = el('textarea', { rows: '10' }, [data.suggestion]);
            const box = el('div', { class: 'suggestion-box' }, [
                el('p', { class: 'draft-flag', text: 'Entwurf - noch nicht uebernommen' }),
                area,
                el('div', { class: 'toolbar' }, [
                    el('button', {
                        class: 'btn btn--primary', type: 'button', text: 'Uebernehmen',
                        onclick: () => { apply(area.value.trim()); closeOverlay(); toast('Vorschlag uebernommen (noch nicht gespeichert).', { kind: 'good' }); },
                    }),
                    el('button', { class: 'btn', type: 'button', text: 'Verwerfen', onclick: () => closeOverlay() }),
                ]),
                el('p', { class: 'hint', text: data.notice || '' }),
            ]);
            openOverlay('KI-Vorschlag (' + type + ')', box);
        } catch (error) {
            openOverlay('KI-Vorschlag', el('div', { class: 'alert alert--error', text: error.message }));
        }
    }

    async function suggestHint(puzzle, level, apply) {
        if (!caseData.id || !puzzle.id) { toast('Bitte Fall und Raetsel zuerst speichern.', { kind: 'bad' }); return; }
        try {
            const data = await post('/api/admin/hint/suggest', { case: caseData.id, puzzle: puzzle.id, level });
            const area = el('textarea', { rows: '3' }, [data.suggestion]);
            openOverlay(`Hinweisvorschlag (Stufe ${level})`, el('div', { class: 'suggestion-box' }, [
                el('p', { class: 'draft-flag', text: 'Entwurf' }),
                area,
                el('div', { class: 'toolbar' }, [
                    el('button', { class: 'btn btn--primary', type: 'button', text: 'Uebernehmen', onclick: () => { apply(area.value.trim()); closeOverlay(); } }),
                    el('button', { class: 'btn', type: 'button', text: 'Verwerfen', onclick: () => closeOverlay() }),
                ]),
            ]));
        } catch (error) {
            toast(error.message, { kind: 'bad' });
        }
    }

    /* ------------------ Speichern und Pruefen ------------------ */
    const showValidation = (result) => {
        if (!result) { validationBox.hidden = true; return; }
        const issues = result.errors || [];
        if (!issues.length) {
            validationBox.hidden = false;
            validationBox.className = 'alert alert--ok';
            validationBox.textContent = 'Konsistenzpruefung: keine Auffaelligkeiten.';
            return;
        }
        validationBox.hidden = false;
        validationBox.className = 'alert ' + (result.ok ? 'alert--warn' : 'alert--error');
        clear(validationBox);
        validationBox.append(el('strong', {
            text: `${result.stats.errors} Fehler, ${result.stats.warnings} Hinweise`,
        }));
        const list = el('ul');
        issues.slice(0, 60).forEach((issue) => {
            list.append(el('li', { text: `[${issue.level === 'error' ? 'Fehler' : 'Hinweis'}] ${issue.area}: ${issue.message}` }));
        });
        validationBox.append(list);
    };
    showValidation(validation);

    const save = async (publish = false) => {
        setStatus('Speichern ...');
        try {
            const data = await post('/api/admin/case/save', { case: caseData, publish });
            dirty = false;
            setStatus(data.message || 'Gespeichert.');
            toast(data.message || 'Gespeichert.', { kind: 'good' });
            showValidation(data.validation);
            if (isNew) {
                window.location.href = window.location.pathname.replace(/\/neu$/, '/' + caseData.id);
            }
        } catch (error) {
            setStatus('Fehler: ' + error.message);
            toast(error.message, { kind: 'bad' });
        }
    };

    container.querySelector('[data-action="editor-save"]')?.addEventListener('click', () => save(false));
    container.querySelector('[data-action="editor-publish"]')?.addEventListener('click', () => save(true));
    container.querySelector('[data-action="editor-validate"]')?.addEventListener('click', async () => {
        if (!caseData.id || isNew) { toast('Bitte zuerst speichern.', { kind: 'bad' }); return; }
        try {
            const data = await get(`/api/admin/case/${caseData.id}/validate`);
            showValidation(data.validation);
            toast('Pruefung abgeschlossen.', { kind: data.validation.ok ? 'good' : 'bad' });
        } catch (error) { toast(error.message, { kind: 'bad' }); }
    });
    container.querySelector('[data-action="editor-preview"]')?.addEventListener('click', () => {
        if (!caseData.id) { toast('Bitte zuerst speichern.', { kind: 'bad' }); return; }
        const base = window.location.pathname.split('/admin/')[0];
        window.open(base + '/admin/test/' + caseData.id, '_blank');
    });
    container.querySelector('[data-action="editor-reset-test"]')?.addEventListener('click', async () => {
        if (!caseData.id) return;
        try {
            const data = await post('/api/admin/case/reset-test', { case: caseData.id });
            toast(data.message, { kind: 'good' });
        } catch (error) { toast(error.message, { kind: 'bad' }); }
    });

    drawNav();
    drawSection();
}
