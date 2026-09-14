/* Geraete: Handys, Laptops, Ueberwachungsrechner - entsperren und durchsuchen */

import { el, clear, get, sectionTitle, assetUrl, toast, openOverlay, sound, post } from '../core.js';
import { renderPuzzle } from '../puzzle.js';
import { renderPhoto, openMedia, fetchMedia, renderDocument } from '../viewer.js';
import { renderVideo } from '../cctv.js';
import { renderAudio } from '../audioplayer.js';

export async function render(game, options = {}) {
    if (options.device) return deviceView(game, options.device, options.app || null);
    return shelfView(game);
}

/* ---------------------------- Uebersicht ---------------------------- */

function shelfView(game) {
    const wrap = el('div', { class: 'panel-grid' });
    wrap.append(sectionTitle('Asservate und Geraete', 'Sichergestellte Geraete. Gesperrte Geraete brauchen einen Code aus deinen Funden.'));

    const devices = game.state.devices || [];
    if (!devices.length) {
        wrap.append(el('p', { class: 'hint', text: 'Noch keine Geraete sichergestellt.' }));
        return wrap;
    }

    const shelf = el('div', { class: 'device-shelf' });
    devices.forEach((device) => {
        shelf.append(el('button', {
            class: 'device-tile' + (device.unlocked ? '' : ' is-locked'),
            onclick: () => game.showPanel('geraete', { device: device.id }),
        }, [
            el('div', { class: 'device-tile__screen' }, [
                device.unlocked ? el('span', { text: deviceLabel(device.type) }) : el('span', { text: '🔒 GESPERRT' }),
            ]),
            el('h3', { text: device.name }),
            el('p', { text: `${device.owner ? 'Halter: ' + device.owner : ''}${device.evidence_tag ? ' · ' + device.evidence_tag : ''}` }),
            device.note ? el('p', { class: 'hint', text: device.note }) : null,
        ]));
    });
    wrap.append(shelf);
    return wrap;
}

function deviceLabel(type) {
    return { phone: 'SMARTPHONE', laptop: 'LAPTOP', pc: 'ARBEITSPLATZ', tablet: 'TABLET', camera: 'KAMERASYSTEM' }[type] || 'GERAET';
}

/* ---------------------------- Geraeteansicht ---------------------------- */

async function deviceView(game, deviceId, appId) {
    const device = (game.state.devices || []).find((item) => item.id === deviceId);
    if (!device) return el('p', { class: 'alert alert--error', text: 'Geraet nicht gefunden.' });

    const wrap = el('div', { class: 'panel-grid' });
    wrap.append(el('div', { class: 'toolbar' }, [
        el('button', { class: 'btn btn--small', text: '← Geraete', onclick: () => game.showPanel('geraete') }),
        el('strong', { text: device.name }),
        el('span', { class: 'hint', text: device.owner ? 'Halter: ' + device.owner : '' }),
    ]));

    if (!device.unlocked) {
        wrap.append(lockScreen(game, device));
        return wrap;
    }

    const screenBody = el('div', { class: device.type === 'phone' ? 'phone__content' : 'os-body' });
    const shell = device.type === 'phone' ? phoneShell(device, screenBody) : laptopShell(device, screenBody);

    const showApps = () => {
        clear(screenBody);
        const grid = el('div', { class: device.type === 'phone' ? 'app-grid' : 'panel-grid cols-3' });
        (device.apps || []).forEach((app) => {
            grid.append(el('button', {
                class: 'app-icon' + (app.locked ? ' is-locked' : ''),
                onclick: () => {
                    if (app.locked) {
                        const puzzle = game.puzzleById(app.puzzle);
                        if (puzzle && puzzle.available && !puzzle.solved) {
                            openOverlay(puzzle.title || app.label, renderPuzzle(game, puzzle, {
                                onSuccess: async () => { await game.refresh(); game.showPanel('geraete', { device: deviceId }); },
                            }));
                        } else {
                            toast(app.locked_hint || 'Zugriff verweigert.', { kind: 'bad' });
                        }
                        return;
                    }
                    openApp(game, device, app, screenBody, showApps);
                },
            }, [
                el('span', { class: 'app-icon__tile', text: appGlyph(app.type) }),
                el('span', { class: 'app-icon__label', text: app.label }),
                app.badge ? el('span', { class: 'app-icon__badge', text: String(app.badge) }) : null,
            ]));
        });
        screenBody.append(grid);
    };

    showApps();
    wrap.append(shell);

    if (appId) {
        const app = (device.apps || []).find((item) => item.id === appId);
        if (app && !app.locked) openApp(game, device, app, screenBody, showApps);
    }
    return wrap;
}

function appGlyph(type) {
    return {
        messages: '💬', gallery: '🖼', files: '📁', mail: '✉', browser: '🌐', notes: '📝',
        calls: '📞', audio: '🎧', cctv: '📹', deleted: '🗑', logs: '📊', login: '🔑', apps: '⚙',
    }[type] || '▦';
}

function phoneShell(device, body) {
    return el('div', { class: 'phone' }, [
        el('div', { class: 'phone__screen' }, [
            el('div', { class: 'phone__status' }, [
                el('span', { text: device.carrier || 'NO SERVICE' }),
                el('span', { text: device.clock || '23:14' }),
                el('span', { text: '🔋 12 %' }),
            ]),
            body,
        ]),
    ]);
}

function laptopShell(device, body) {
    return el('div', { class: 'laptop' }, [
        el('div', { class: 'laptop__screen' }, [
            el('div', { class: 'os-bar' }, [
                el('span', { class: 'dot' }), el('span', { class: 'dot' }), el('span', { class: 'dot' }),
                el('span', { text: device.os || 'FBI FORENSIC IMAGE · read only' }),
            ]),
            body,
        ]),
        el('div', { class: 'laptop__base' }),
    ]);
}

/* ---------------------------- Sperrbildschirm ---------------------------- */

function lockScreen(game, device) {
    const lock = device.lock || {};
    const puzzle = game.puzzleById(lock.puzzle);
    const box = el('section', { class: 'panel-box' }, [
        el('div', { class: 'lockscreen' }, [
            el('h3', { text: lock.label || 'Geraet gesperrt' }),
            lock.user ? el('p', { class: 'mono', text: 'Konto: ' + lock.user }) : null,
            el('p', { text: lock.hint || 'Zugangscode erforderlich.' }),
        ]),
    ]);
    if (!puzzle) {
        box.append(el('p', { class: 'hint', text: 'Fuer dieses Geraet ist kein Zugang hinterlegt.' }));
        return box;
    }
    box.append(renderPuzzle(game, puzzle, {
        compact: true,
        onSuccess: async () => {
            sound.play('ui_success', { gain: 0.5 });
            await game.refresh();
            game.showPanel('geraete', { device: device.id });
        },
    }));
    return box;
}

/* ---------------------------- Apps ---------------------------- */

async function openApp(game, device, app, host, backToApps) {
    clear(host);
    host.append(el('div', { class: 'stage__loading', text: 'Oeffne ' + app.label + ' ...' }));
    try {
        const data = await get(`/api/case/${game.caseId}/device/${device.id}/app/${app.id}`);
        clear(host);
        const bar = el('div', { class: 'toolbar', style: { marginBottom: '.6rem' } }, [
            el('button', { class: 'btn btn--small', text: '← Zurueck', onclick: backToApps }),
            el('strong', { text: app.label }),
        ]);
        host.append(bar);
        host.append(renderAppContent(game, app, data.content || {}, host, backToApps));
        game.handleHorror(data.horror);
        sound.play('ui_open', { gain: 0.25 });
    } catch (error) {
        clear(host);
        host.append(el('div', { class: 'alert alert--error', text: error.message }));
        host.append(el('button', { class: 'btn btn--small', text: '← Zurueck', onclick: backToApps }));
    }
}

function renderAppContent(game, app, content, host, back) {
    switch (app.type) {
        case 'messages': return renderMessages(game, content);
        case 'gallery':  return renderGallery(game, content);
        case 'files':    return renderFiles(game, content);
        case 'mail':     return renderMail(game, content);
        case 'browser':  return renderBrowser(game, content);
        case 'notes':    return renderNotes(game, content);
        case 'calls':    return renderCalls(game, content);
        case 'audio':    return renderAudioList(game, content);
        case 'cctv':     return renderVideoList(game, content);
        case 'deleted':  return renderDeleted(game, content);
        case 'logs':     return renderLogs(game, content);
        case 'login':    return renderLogin(game, content);
        default:         return renderGeneric(game, content);
    }
}

function renderMessages(game, content) {
    const wrap = el('div', { class: 'stack' });
    const threads = content.threads || [];
    if (!threads.length) return el('p', { class: 'hint', text: 'Keine Nachrichten vorhanden.' });

    const view = el('div', { class: 'threadlist' });
    const list = el('div', { class: 'stack' });

    const openThread = (thread) => {
        clear(list);
        list.append(el('div', { class: 'toolbar' }, [
            el('button', { class: 'btn btn--small', text: '← Chats', onclick: draw }),
            el('strong', { text: thread.contact }),
            thread.number ? el('span', { class: 'hint', text: thread.number }) : null,
        ]));
        const stream = el('div', { class: 'threadlist' });
        (thread.messages || []).forEach((message) => {
            const bubble = el('div', {
                class: 'thread-bubble ' + (message.from === 'me' ? 'thread-bubble--out' : 'thread-bubble--in') + (message.deleted ? ' is-deleted' : ''),
            }, [
                el('span', { text: message.text || '' }),
                message.attachment ? el('button', {
                    class: 'btn btn--small', text: '📎 Anhang oeffnen',
                    onclick: () => openMedia(game, message.attachment),
                }) : null,
                el('time', { text: (message.deleted ? 'geloescht · ' : '') + (message.time || '') }),
            ]);
            stream.append(bubble);
        });
        list.append(stream);
    };

    const draw = () => {
        clear(list);
        threads.forEach((thread) => {
            const last = (thread.messages || [])[thread.messages.length - 1];
            list.append(el('button', {
                class: 'mailrow',
                onclick: () => openThread(thread),
            }, [
                el('div', {}, [
                    el('strong', { text: thread.contact }),
                    el('div', { class: 'hint', text: last ? String(last.text).slice(0, 70) : '' }),
                ]),
                el('small', { text: last?.time || '' }),
            ]));
        });
    };
    draw();
    wrap.append(list);
    return wrap;
}

function renderGallery(game, content) {
    const wrap = el('div', { class: 'stack' });
    const gallery = el('div', { class: 'gallery' });
    (content.photos || []).forEach((photo) => {
        gallery.append(el('button', {
            onclick: async () => {
                const data = await fetchMedia(game, photo.media);
                openOverlay(data.media.title || photo.title || 'Foto', renderPhoto(game, data.media, {
                    onPuzzleSolved: () => game.refresh(),
                }));
            },
        }, [
            el('img', { src: assetUrl(photo.thumb || photo.preview || ''), alt: photo.title || '', loading: 'lazy' }),
            el('figcaption', { text: photo.title || '' }),
        ]));
    });
    wrap.append(gallery);
    if (content.note) wrap.append(el('p', { class: 'hint', text: content.note }));
    return wrap;
}

function renderFiles(game, content) {
    const wrap = el('div', { class: 'stack' });
    const crumbs = el('div', { class: 'breadcrumbs' });
    const list = el('div', { class: 'filelist' });
    let path = [];

    const nodeAt = () => {
        let nodes = content.tree || [];
        path.forEach((name) => {
            const found = nodes.find((node) => node.name === name && node.type === 'folder');
            nodes = found ? (found.children || []) : [];
        });
        return nodes;
    };

    const draw = () => {
        clear(crumbs);
        crumbs.append(el('button', { text: content.root || 'C:\\', onclick: () => { path = []; draw(); } }));
        path.forEach((name, index) => {
            crumbs.append(el('span', { text: ' \\ ' }));
            crumbs.append(el('button', { text: name, onclick: () => { path = path.slice(0, index + 1); draw(); } }));
        });

        clear(list);
        const nodes = nodeAt();
        if (path.length) {
            list.append(el('button', {
                class: 'filelist__row',
                onclick: () => { path = path.slice(0, -1); draw(); },
            }, [el('span', { text: '↩' }), el('span', { text: '.. (zurueck)' }), el('span'), el('span')]));
        }
        if (!nodes.length) {
            list.append(el('p', { class: 'hint', text: 'Leerer Ordner.' }));
        }
        nodes.forEach((node) => {
            list.append(el('button', {
                class: 'filelist__row' + (node.deleted ? ' is-deleted' : ''),
                onclick: () => {
                    if (node.type === 'folder') { path = [...path, node.name]; draw(); return; }
                    openFile(game, node);
                },
            }, [
                el('span', { text: node.type === 'folder' ? '📁' : fileGlyph(node.name) }),
                el('span', { text: node.name }),
                el('span', { class: 'size', text: node.size || '' }),
                el('span', { class: 'size', text: node.modified || '' }),
            ]));
        });
    };
    draw();

    wrap.append(crumbs, list);
    if (content.note) wrap.append(el('p', { class: 'hint', text: content.note }));
    return wrap;
}

function fileGlyph(name) {
    const ext = String(name).split('.').pop().toLowerCase();
    if (['jpg', 'jpeg', 'png', 'gif', 'svg'].includes(ext)) return '🖼';
    if (['mp4', 'avi', 'mov'].includes(ext)) return '🎞';
    if (['wav', 'mp3', 'm4a'].includes(ext)) return '🎧';
    if (['zip', 'rar'].includes(ext)) return '🗜';
    return '📄';
}

function openFile(game, node) {
    if (node.media) { openMedia(game, node.media); return; }
    if (node.puzzle) {
        const puzzle = game.puzzleById(node.puzzle);
        if (puzzle) {
            openOverlay(node.name, el('div', { class: 'stack' }, [
                node.body ? el('div', { class: 'typed', text: node.body }) : null,
                renderPuzzle(game, puzzle, { onSuccess: () => game.refresh() }),
            ]));
            return;
        }
    }
    openOverlay(node.name, el('div', { class: 'stack' }, [
        el('p', { class: 'hint', text: `${node.size || ''} ${node.modified ? '· geaendert ' + node.modified : ''}` }),
        node.body ? el('div', { class: 'paper' }, [el('div', { class: 'typed', text: node.body })]) : el('p', { class: 'hint', text: 'Kein lesbarer Inhalt.' }),
    ]));
}

function renderMail(game, content) {
    const wrap = el('div', { class: 'stack' });
    const list = el('div', { class: 'maillist' });
    const view = el('div', {});

    (content.messages || []).forEach((mail) => {
        list.append(el('button', {
            class: 'mailrow',
            onclick: () => {
                clear(view);
                view.append(el('div', { class: 'mailview' }, [
                    el('div', { class: 'mailview__head' }, [
                        el('div', { text: 'Von: ' + (mail.from || '') }),
                        el('div', { text: 'An: ' + (mail.to || '') }),
                        el('div', { text: 'Datum: ' + (mail.date || '') }),
                        el('div', { text: 'Betreff: ' + (mail.subject || '') }),
                    ]),
                    el('div', { class: 'typed', text: mail.body || '' }),
                    ...(mail.attachments || []).map((attachment) => el('button', {
                        class: 'btn btn--small', text: '📎 ' + (attachment.name || 'Anhang'),
                        onclick: () => openMedia(game, attachment.media),
                    })),
                ]));
                view.scrollIntoView({ block: 'nearest' });
            },
        }, [
            el('div', {}, [
                el('strong', { text: mail.subject || '(kein Betreff)' }),
                el('div', { class: 'hint', text: mail.from || '' }),
            ]),
            el('small', { text: mail.date || '' }),
        ]));
    });

    wrap.append(content.account ? el('p', { class: 'hint', text: 'Postfach: ' + content.account }) : null, list, view);
    return wrap;
}

function renderBrowser(game, content) {
    const wrap = el('div', { class: 'stack' });
    const table = el('table', { class: 'table' }, [
        el('thead', {}, [el('tr', {}, [
            el('th', { text: 'Zeit' }), el('th', { text: 'Seite' }), el('th', { text: 'Adresse' }),
        ])]),
    ]);
    const body = el('tbody');
    (content.entries || []).forEach((entry) => {
        const row = el('tr', {}, [
            el('td', { class: 'mono', text: entry.time || '' }),
            el('td', { text: entry.title || '' }),
            el('td', { class: 'mono hint', text: entry.url || '' }),
        ]);
        if (entry.note || entry.body) {
            row.style.cursor = 'pointer';
            row.addEventListener('click', () => openOverlay(entry.title || 'Seite', el('div', { class: 'stack' }, [
                el('p', { class: 'mono hint', text: entry.url || '' }),
                el('div', { class: 'typed', text: entry.body || entry.note || '' }),
            ])));
        }
        body.append(row);
    });
    table.append(body);
    wrap.append(table);
    if (content.note) wrap.append(el('p', { class: 'hint', text: content.note }));
    return wrap;
}

function renderNotes(game, content) {
    const wrap = el('div', { class: 'panel-grid cols-2' });
    (content.notes || []).forEach((note) => {
        wrap.append(el('article', { class: 'paper' }, [
            el('h3', { text: note.title || 'Notiz' }),
            el('div', { class: 'typed', text: note.text || '' }),
            el('p', { class: 'hint', text: note.date || '' }),
        ]));
    });
    if (!(content.notes || []).length) wrap.append(el('p', { class: 'hint', text: 'Keine Notizen.' }));
    return wrap;
}

function renderCalls(game, content) {
    const table = el('table', { class: 'table' }, [
        el('thead', {}, [el('tr', {}, [
            el('th', { text: 'Zeit' }), el('th', { text: 'Kontakt' }), el('th', { text: 'Nummer' }),
            el('th', { text: 'Richtung' }), el('th', { text: 'Dauer' }),
        ])]),
    ]);
    const body = el('tbody');
    (content.entries || []).forEach((entry) => {
        body.append(el('tr', {}, [
            el('td', { class: 'mono', text: entry.time || '' }),
            el('td', { text: entry.name || 'Unbekannt' }),
            el('td', { class: 'mono', text: entry.number || '' }),
            el('td', { text: entry.direction || '' }),
            el('td', { class: 'mono', text: entry.duration || '' }),
        ]));
    });
    table.append(body);
    return el('div', { class: 'stack' }, [table, content.note ? el('p', { class: 'hint', text: content.note }) : null]);
}

function renderAudioList(game, content) {
    const wrap = el('div', { class: 'stack' });
    (content.clips || []).forEach((clip) => {
        wrap.append(el('button', {
            class: 'evidence-card',
            onclick: async () => {
                const data = await fetchMedia(game, clip.media);
                openOverlay(data.media.title || clip.title, renderAudio(game, data.media, { onPuzzleSolved: () => game.refresh() }));
            },
        }, [
            el('span', { class: 'code', text: 'AUDIO' }),
            el('h3', { text: clip.title || '' }),
            el('p', { text: clip.meta || '' }),
        ]));
    });
    if (!(content.clips || []).length) wrap.append(el('p', { class: 'hint', text: 'Keine Aufnahmen.' }));
    return wrap;
}

function renderVideoList(game, content) {
    const wrap = el('div', { class: 'stack' });
    (content.videos || []).forEach((video) => {
        wrap.append(el('button', {
            class: 'evidence-card',
            onclick: async () => {
                const data = await fetchMedia(game, video.media);
                openOverlay(data.media.title || video.title, renderVideo(game, data.media, { onPuzzleSolved: () => game.refresh() }), { wide: true });
            },
        }, [
            el('span', { class: 'code', text: video.camera || 'KAMERA' }),
            el('h3', { text: video.title || '' }),
            el('p', { text: video.meta || '' }),
        ]));
    });
    if (!(content.videos || []).length) wrap.append(el('p', { class: 'hint', text: 'Keine Aufzeichnungen verfuegbar.' }));
    return wrap;
}

function renderDeleted(game, content) {
    const wrap = el('div', { class: 'stack' });
    wrap.append(el('p', { class: 'hint', text: content.note || 'Wiederhergestellte Fragmente aus dem nicht zugewiesenen Speicherbereich.' }));
    const list = el('div', { class: 'filelist' });
    (content.items || []).forEach((item) => {
        list.append(el('button', {
            class: 'filelist__row is-deleted',
            onclick: () => {
                if (item.media) { openMedia(game, item.media); return; }
                openOverlay(item.name, el('div', { class: 'stack' }, [
                    el('p', { class: 'hint', text: item.meta || '' }),
                    el('div', { class: 'paper' }, [el('div', { class: 'typed', text: item.body || 'Datei beschaedigt.' })]),
                ]));
            },
        }, [
            el('span', { text: '🗑' }),
            el('span', { text: item.name }),
            el('span', { class: 'size', text: item.size || '' }),
            el('span', { class: 'size', text: item.deleted_at || '' }),
        ]));
    });
    wrap.append(list);
    return wrap;
}

function renderLogs(game, content) {
    const wrap = el('div', { class: 'stack' });
    const search = el('input', { type: 'search', placeholder: 'Filtern (Name, Zeit, Karte, Abschnitt) ...' });
    const table = el('table', { class: 'table' });
    const head = el('thead', {}, [el('tr', {}, (content.columns || []).map((column) => el('th', { text: column })))]);
    const body = el('tbody');
    table.append(head, body);

    const draw = () => {
        clear(body);
        const needle = search.value.trim().toLowerCase();
        (content.rows || [])
            .filter((row) => !needle || row.join(' ').toLowerCase().includes(needle))
            .forEach((row) => {
                const tr = el('tr', {}, row.map((cell) => el('td', { class: 'mono', text: String(cell) })));
                body.append(tr);
            });
        if (!body.childElementCount) body.append(el('tr', {}, [el('td', { colspan: String((content.columns || []).length || 1), text: 'Keine Treffer.' })]));
    };
    search.addEventListener('input', draw);
    draw();

    wrap.append(el('div', { class: 'toolbar' }, [search]), table);
    if (content.note) wrap.append(el('p', { class: 'hint', text: content.note }));
    if (content.puzzle) {
        const puzzle = game.puzzleById(content.puzzle);
        if (puzzle && !puzzle.solved) {
            wrap.append(el('div', { class: 'panel-box' }, [el('h3', { text: puzzle.title }), renderPuzzle(game, puzzle, { onSuccess: () => game.refresh() })]));
        }
    }
    return wrap;
}

function renderLogin(game, content) {
    const puzzle = game.puzzleById(content.puzzle);
    const wrap = el('div', { class: 'stack' });
    wrap.append(el('div', { class: 'lockscreen' }, [
        el('h3', { text: content.title || 'Anmeldung' }),
        el('p', { class: 'mono', text: content.system || '' }),
        content.user ? el('p', { class: 'mono hint', text: 'Benutzer: ' + content.user }) : null,
        el('p', { class: 'hint', text: content.note || 'Simulierte Anmeldemaske. Reines Spielelement.' }),
    ]));
    if (puzzle) {
        wrap.append(renderPuzzle(game, puzzle, {
            compact: true,
            onSuccess: async () => { await game.refresh(); toast('Zugang gewaehrt.', { kind: 'good' }); game.showPanel('geraete'); },
        }));
    }
    return wrap;
}

function renderGeneric(game, content) {
    if (content.body || content.pages) return renderDocument(game, content);
    return el('pre', { class: 'typed', text: JSON.stringify(content, null, 2) });
}
