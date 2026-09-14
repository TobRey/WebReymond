/* Fallakte: Auftrag, vermisste Person, Aktenstuecke */

import { el, assetUrl, sectionTitle, openOverlay } from '../core.js';
import { openMedia, renderDocument } from '../viewer.js';

export async function render(game) {
    const caseData = game.state.case || {};
    const person = caseData.missing_person || {};
    const progress = game.state.progress || {};

    const wrap = el('div', { class: 'panel-grid' });

    wrap.append(sectionTitle(
        'Fallakte ' + (caseData.code || ''),
        `${caseData.location || ''} · Ereignisdatum ${caseData.incident_date || ''}`,
    ));

    const dossier = el('section', { class: 'dossier' }, [
        el('figure', { class: 'dossier__photo' }, [
            el('img', { src: assetUrl(person.photo || caseData.cover), alt: 'Portraet ' + (person.name || '') }),
            el('figcaption', { text: `${person.name || ''} · ${person.age || '?'} Jahre` }),
        ]),
        el('div', {}, [
            el('p', { class: 'eyebrow', text: 'Vermisstenanzeige' }),
            el('h3', { text: person.name || 'Unbekannt' }),
            el('dl', {}, [
                el('dt', { text: 'Alter' }), el('dd', { text: String(person.age || '-') }),
                el('dt', { text: 'Zuletzt gesehen' }), el('dd', { text: person.last_seen || '-' }),
                el('dt', { text: 'Groesse' }), el('dd', { text: person.height || '-' }),
                el('dt', { text: 'Kleidung' }), el('dd', { text: person.clothing || '-' }),
                el('dt', { text: 'Besonderheiten' }), el('dd', { text: person.traits || '-' }),
                el('dt', { text: 'Status' }), el('dd', { text: progress.completed ? 'Bericht abgegeben' : 'Aktiv' }),
            ]),
            el('p', { text: person.description || '' }),
        ]),
    ]);
    wrap.append(dossier);

    if (caseData.briefing) {
        wrap.append(el('section', { class: 'paper' }, [
            el('span', { class: 'stamp', text: 'VERTRAULICH' }),
            el('h3', { text: 'Einsatzauftrag' }),
            el('div', { class: 'typed', text: caseData.briefing }),
        ]));
    }

    const entries = caseData.file_entries || [];
    if (entries.length) {
        const list = el('div', { class: 'panel-grid cols-2' });
        entries.forEach((entry) => {
            list.append(el('button', {
                class: 'evidence-card',
                onclick: () => {
                    if (entry.media) {
                        openMedia(game, entry.media);
                    } else {
                        openOverlay(entry.title || 'Aktenstueck', renderDocument(game, {
                            heading: entry.title,
                            body: entry.body || '',
                        }));
                    }
                },
            }, [
                el('span', { class: 'code', text: entry.code || 'AKTE' }),
                el('h3', { text: entry.title || '' }),
                el('p', { text: entry.summary || '' }),
            ]));
        });
        wrap.append(el('section', {}, [el('h3', { text: 'Aktenstuecke und Nachschlagewerke' }), list]));
    }

    const reference = caseData.reference || [];
    if (reference.length) {
        const refList = el('div', { class: 'panel-grid cols-3' });
        reference.forEach((item) => {
            refList.append(el('button', {
                class: 'evidence-card',
                onclick: () => openOverlay(item.title || 'Referenz', el('div', { class: 'stack' }, [
                    item.image ? el('img', { src: assetUrl(item.image), alt: item.title || '', style: { width: '100%' } }) : null,
                    el('div', { class: 'typed', text: item.body || '' }),
                ])),
            }, [
                el('span', { class: 'code', text: 'REF' }),
                el('h3', { text: item.title || '' }),
                el('p', { text: item.summary || '' }),
            ]));
        });
        wrap.append(el('section', {}, [el('h3', { text: 'Referenzmaterial' }), refList]));
    }

    wrap.append(el('section', { class: 'panel-grid cols-3' }, [
        statTile('Beweise gesichert', `${(progress.evidence || []).length} / ${game.state.evidence_total || 0}`),
        statTile('Aufgaben geloest', String((progress.solved || []).length)),
        statTile('Hinweise uebrig', progress.hints_left === -1 ? 'unbegrenzt' : String(progress.hints_left ?? 0)),
    ]));

    return wrap;
}

function statTile(label, value) {
    return el('div', { class: 'tile' }, [
        el('h3', { text: label }),
        el('div', { class: 'big', text: value }),
    ]);
}
