/* =============================================================
   Adminbereich - Seitenlogik
   ============================================================= */

import { el, $, clear, api, post, get, toast, openOverlay, closeOverlay, initOverlay } from './core.js';
import { initCaseEditor } from './admin/editor.js';

initOverlay();

const page = document.querySelector('[data-admin-page]');
const pageName = page?.dataset.adminPage || '';

/* Overlay-Grundgeruest fuer den Adminbereich bereitstellen */
if (!document.getElementById('overlay')) {
    document.body.append(el('div', { class: 'overlay', id: 'overlay', hidden: true }, [
        el('div', { class: 'overlay__box' }, [
            el('header', { class: 'overlay__head' }, [
                el('h2', { id: 'overlay-title' }),
                el('button', { class: 'overlay__close', id: 'overlay-close', text: '×' }),
            ]),
            el('div', { class: 'overlay__body', id: 'overlay-body' }),
        ]),
    ]));
    document.body.append(el('div', { class: 'toasts', id: 'toasts' }));
    initOverlay();
}

const formData = (form) => {
    const data = {};
    new FormData(form).forEach((value, key) => {
        if (data[key] !== undefined) {
            data[key] = [].concat(data[key], value);
        } else {
            data[key] = value;
        }
    });
    form.querySelectorAll('input[type="checkbox"]').forEach((input) => {
        if (!input.name) return;
        data[input.name] = input.checked;
    });
    return data;
};

const status = (selector, text) => {
    const node = document.querySelector(selector);
    if (node) node.textContent = text;
};

/* ------------------------- Faelle ------------------------- */

if (pageName === 'cases') {
    document.querySelectorAll('[data-action="case-status"]').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                const data = await post('/api/admin/case/status', { case: button.dataset.case, status: button.dataset.status });
                toast(data.message, { kind: 'good' });
                setTimeout(() => window.location.reload(), 600);
            } catch (error) { toast(error.message, { kind: 'bad' }); }
        });
    });

    document.querySelectorAll('[data-action="case-delete"]').forEach((button) => {
        button.addEventListener('click', () => {
            const caseId = button.dataset.case;
            const input = el('input', { type: 'text', placeholder: caseId });
            openOverlay('Fall loeschen', el('div', { class: 'stack' }, [
                el('p', { text: `Der Fall "${caseId}" wird geloescht. Eine Version wird zuvor gesichert. Spielstaende bleiben bestehen.` }),
                el('label', {}, ['Zur Bestaetigung die Fall-ID eingeben', input]),
                el('div', { class: 'toolbar' }, [
                    el('button', {
                        class: 'btn btn--danger', text: 'Endgueltig loeschen',
                        onclick: async () => {
                            try {
                                const data = await post('/api/admin/case/delete', { case: caseId, confirm: input.value.trim() });
                                toast(data.message, { kind: 'good' });
                                closeOverlay();
                                setTimeout(() => window.location.reload(), 600);
                            } catch (error) { toast(error.message, { kind: 'bad' }); }
                        },
                    }),
                    el('button', { class: 'btn', text: 'Abbrechen', onclick: () => closeOverlay() }),
                ]),
            ]));
        });
    });

    document.querySelectorAll('[data-action="case-duplicate"]').forEach((button) => {
        button.addEventListener('click', () => {
            const source = button.dataset.case;
            const idInput = el('input', { type: 'text', value: source + '-kopie', maxlength: '64' });
            const titleInput = el('input', { type: 'text', value: 'Kopie von ' + source, maxlength: '120' });
            openOverlay('Fall duplizieren', el('div', { class: 'stack' }, [
                el('label', {}, ['Neue Fall-ID', idInput]),
                el('label', {}, ['Neuer Titel', titleInput]),
                el('div', { class: 'toolbar' }, [
                    el('button', {
                        class: 'btn btn--primary', text: 'Duplizieren',
                        onclick: async () => {
                            try {
                                const data = await post('/api/admin/case/duplicate', {
                                    case: source, new_id: idInput.value.trim(), new_title: titleInput.value.trim(),
                                });
                                toast(data.message, { kind: 'good' });
                                closeOverlay();
                                setTimeout(() => window.location.reload(), 600);
                            } catch (error) { toast(error.message, { kind: 'bad' }); }
                        },
                    }),
                    el('button', { class: 'btn', text: 'Abbrechen', onclick: () => closeOverlay() }),
                ]),
            ]));
        });
    });

    document.querySelector('[data-action="import-case"]')?.addEventListener('click', () => {
        const fileInput = el('input', { type: 'file', accept: '.json' });
        const idInput = el('input', { type: 'text', placeholder: 'optional: neue Fall-ID', maxlength: '64' });
        const area = el('textarea', { rows: '6', placeholder: 'oder JSON hier einfuegen' });
        openOverlay('Fall importieren', el('div', { class: 'stack' }, [
            el('label', {}, ['JSON-Datei', fileInput]),
            el('label', {}, ['Neue Fall-ID (optional)', idInput]),
            el('label', {}, ['JSON-Inhalt', area]),
            el('div', { class: 'toolbar' }, [
                el('button', {
                    class: 'btn btn--primary', text: 'Importieren',
                    onclick: async () => {
                        try {
                            let json = area.value.trim();
                            if (!json && fileInput.files?.[0]) {
                                json = await fileInput.files[0].text();
                            }
                            if (!json) { toast('Bitte Datei waehlen oder JSON einfuegen.', { kind: 'bad' }); return; }
                            const data = await post('/api/admin/case/import', { json, new_id: idInput.value.trim() });
                            toast(data.message, { kind: 'good' });
                            closeOverlay();
                            setTimeout(() => window.location.reload(), 700);
                        } catch (error) { toast(error.message, { kind: 'bad' }); }
                    },
                }),
                el('button', { class: 'btn', text: 'Abbrechen', onclick: () => closeOverlay() }),
            ]),
        ]));
    });
}

/* ------------------------- Fall-Editor ------------------------- */

if (pageName === 'case-editor') {
    initCaseEditor(page);
}

/* ------------------------- Medien ------------------------- */

if (pageName === 'media') {
    const uploadForm = document.querySelector('[data-role="upload-form"]');
    uploadForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = new FormData(uploadForm);
        status('[data-role="upload-status"]', 'Laedt hoch ...');
        try {
            const data = await api('/api/admin/media/upload', { method: 'POST', body });
            status('[data-role="upload-status"]', data.message);
            toast(data.message, { kind: 'good' });
            setTimeout(() => window.location.reload(), 800);
        } catch (error) {
            status('[data-role="upload-status"]', error.message);
            toast(error.message, { kind: 'bad' });
        }
    });

    const generateForm = document.querySelector('[data-role="generate-form"]');
    generateForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        status('[data-role="generate-status"]', 'Erzeuge Grafiken ...');
        try {
            const data = await post('/api/admin/media/generate', formData(generateForm));
            status('[data-role="generate-status"]', data.message);
            toast(data.message, { kind: 'good' });
            setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            status('[data-role="generate-status"]', error.message);
            toast(error.message, { kind: 'bad' });
        }
    });

    document.querySelectorAll('[data-action="media-delete"]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!window.confirm('Datei wirklich loeschen? Faelle, die sie verwenden, zeigen dann ein fehlendes Bild.')) return;
            try {
                const data = await post('/api/admin/media/delete', { id: button.dataset.id });
                toast(data.message, { kind: 'good' });
                button.closest('.media-item')?.remove();
            } catch (error) { toast(error.message, { kind: 'bad' }); }
        });
    });

    document.querySelectorAll('[data-action="copy-path"]').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(button.dataset.path);
                toast('Pfad kopiert: ' + button.dataset.path, { kind: 'good' });
            } catch {
                toast(button.dataset.path, { title: 'Pfad (manuell kopieren)' });
            }
        });
    });
}

/* ------------------------- Spieler ------------------------- */

if (pageName === 'players') {
    const createForm = document.querySelector('[data-role="user-create"]');
    createForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const data = await post('/api/admin/user/save', formData(createForm));
            toast(data.message, { kind: 'good' });
            setTimeout(() => window.location.reload(), 700);
        } catch (error) {
            status('[data-role="user-status"]', error.message);
            toast(error.message, { kind: 'bad' });
        }
    });

    const editBox = document.querySelector('[data-role="user-edit-box"]');
    const editForm = document.querySelector('[data-role="user-edit-form"]');

    document.querySelectorAll('[data-action="user-edit"]').forEach((button) => {
        button.addEventListener('click', () => {
            editBox.hidden = false;
            document.querySelector('[data-role="edit-name"]').textContent = button.dataset.username;
            editForm.elements.id.value = button.dataset.id;
            editForm.elements.role.value = button.dataset.role;
            editForm.elements.email.value = button.dataset.email || '';
            editForm.elements.agent_name.value = button.dataset.agent || '';
            editForm.elements.password.value = '';
            editBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
    document.querySelector('[data-action="user-edit-cancel"]')?.addEventListener('click', () => { editBox.hidden = true; });

    editForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        try {
            const data = await post('/api/admin/user/save', formData(editForm));
            toast(data.message, { kind: 'good' });
            setTimeout(() => window.location.reload(), 700);
        } catch (error) { toast(error.message, { kind: 'bad' }); }
    });

    document.querySelectorAll('[data-action="user-reset"]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!window.confirm('Alle Spielstaende dieses Kontos loeschen?')) return;
            try {
                const data = await post('/api/admin/user/reset-progress', { id: button.dataset.id });
                toast(data.message, { kind: 'good' });
            } catch (error) { toast(error.message, { kind: 'bad' }); }
        });
    });

    document.querySelectorAll('[data-action="user-delete"]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!window.confirm(`Konto "${button.dataset.username}" samt Spielstaenden endgueltig loeschen?`)) return;
            try {
                const data = await post('/api/admin/user/delete', { id: button.dataset.id });
                toast(data.message, { kind: 'good' });
                setTimeout(() => window.location.reload(), 700);
            } catch (error) { toast(error.message, { kind: 'bad' }); }
        });
    });
}

/* ------------------------- Einstellungen ------------------------- */

if (pageName === 'settings') {
    const form = document.querySelector('[data-role="settings-form"]');
    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        status('[data-role="settings-status"]', 'Speichern ...');
        try {
            const data = await post('/api/admin/settings/save', formData(form));
            status('[data-role="settings-status"]', data.message);
            toast(data.message, { kind: 'good' });
        } catch (error) {
            status('[data-role="settings-status"]', error.message);
            toast(error.message, { kind: 'bad' });
        }
    });
}

/* ------------------------- KI ------------------------- */

if (pageName === 'ai') {
    const form = document.querySelector('[data-role="ai-form"]');

    document.querySelectorAll('[data-action="ai-preset"]').forEach((button) => {
        button.addEventListener('click', () => {
            form.elements.provider.value = button.dataset.provider;
            form.elements.base_url.value = button.dataset.base;
            form.elements.model.value = button.dataset.model;
            toast('Vorlage eingetragen. Schluessel ergaenzen und speichern.', { kind: 'good' });
        });
    });

    document.querySelector('[data-action="ai-clear-key"]')?.addEventListener('click', () => {
        form.elements.api_key.value = '__CLEAR__';
        toast('Beim Speichern wird der Schluessel entfernt.', { kind: '' });
    });

    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        status('[data-role="ai-status"]', 'Speichern ...');
        try {
            const data = await post('/api/admin/ai/save', formData(form));
            status('[data-role="ai-status"]', `${data.message} Modus: ${data.mode}`);
            toast(data.message, { kind: 'good' });
        } catch (error) {
            status('[data-role="ai-status"]', error.message);
            toast(error.message, { kind: 'bad' });
        }
    });

    document.querySelector('[data-action="ai-test"]')?.addEventListener('click', async () => {
        status('[data-role="ai-status"]', 'Teste Verbindung ...');
        try {
            const payload = formData(form);
            payload.use_form = true;
            const data = await post('/api/admin/ai/test', payload);
            status('[data-role="ai-status"]', data.message);
            toast(data.message, { kind: data.ok ? 'good' : 'bad', timeout: 9000 });
        } catch (error) {
            status('[data-role="ai-status"]', error.message);
            toast(error.message, { kind: 'bad' });
        }
    });
}

/* ------------------------- Sicherungen ------------------------- */

if (pageName === 'backups') {
    const form = document.querySelector('[data-role="backup-form"]');
    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        status('[data-role="backup-status"]', 'Erstelle Sicherung ...');
        try {
            const data = await post('/api/admin/backup/create', formData(form));
            status('[data-role="backup-status"]', data.message);
            toast(data.message, { kind: 'good' });
            setTimeout(() => window.location.reload(), 900);
        } catch (error) {
            status('[data-role="backup-status"]', error.message);
            toast(error.message, { kind: 'bad' });
        }
    });

    document.querySelectorAll('[data-action="backup-restore"]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = el('input', { type: 'text', placeholder: 'WIEDERHERSTELLEN' });
            openOverlay('Sicherung wiederherstellen', el('div', { class: 'stack' }, [
                el('p', { text: `Die Sicherung "${button.dataset.file}" wird eingespielt. Der aktuelle Stand wird zuvor automatisch gesichert.` }),
                el('label', {}, ['Bestaetigung eingeben', input]),
                el('div', { class: 'toolbar' }, [
                    el('button', {
                        class: 'btn btn--danger', text: 'Wiederherstellen',
                        onclick: async () => {
                            try {
                                const data = await post('/api/admin/backup/restore', { file: button.dataset.file, confirm: input.value.trim() });
                                toast(data.message, { kind: 'good', timeout: 9000 });
                                closeOverlay();
                                setTimeout(() => window.location.reload(), 1200);
                            } catch (error) { toast(error.message, { kind: 'bad' }); }
                        },
                    }),
                    el('button', { class: 'btn', text: 'Abbrechen', onclick: () => closeOverlay() }),
                ]),
            ]));
        });
    });

    document.querySelectorAll('[data-action="backup-delete"]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!window.confirm('Sicherung loeschen?')) return;
            try {
                const data = await post('/api/admin/backup/delete', { file: button.dataset.file });
                toast(data.message, { kind: 'good' });
                setTimeout(() => window.location.reload(), 600);
            } catch (error) { toast(error.message, { kind: 'bad' }); }
        });
    });
}

/* ------------------------- Protokolle ------------------------- */

if (pageName === 'logs') {
    const select = document.querySelector('[data-role="log-file"]');
    const bodyNode = document.querySelector('[data-role="log-body"]');
    const filter = document.querySelector('[data-role="log-filter"]');
    let entries = [];

    const draw = () => {
        const needle = (filter?.value || '').toLowerCase();
        clear(bodyNode);
        const visible = entries.filter((entry) => !needle || JSON.stringify(entry).toLowerCase().includes(needle));
        if (!visible.length) {
            bodyNode.append(el('tr', {}, [el('td', { class: 'hint', text: 'Keine Eintraege.' })]));
            return;
        }
        visible.forEach((entry) => {
            bodyNode.append(el('tr', {}, [
                el('td', { class: 'hint', text: String(entry.ts || '').slice(0, 19) }),
                el('td', { class: 'log-level ' + (entry.level || ''), text: entry.level || '' }),
                el('td', { text: entry.msg || '' }),
                el('td', { class: 'hint', text: JSON.stringify(entry.context || {}) }),
            ]));
        });
    };

    const load = async () => {
        try {
            const data = await get('/api/admin/logs?file=' + encodeURIComponent(select.value) + '&limit=300');
            entries = data.entries || [];
            draw();
        } catch (error) { toast(error.message, { kind: 'bad' }); }
    };

    select?.addEventListener('change', load);
    filter?.addEventListener('input', draw);
    document.querySelector('[data-action="log-reload"]')?.addEventListener('click', load);
    document.querySelector('[data-action="log-clear"]')?.addEventListener('click', async () => {
        if (!window.confirm('Diese Protokolldatei leeren?')) return;
        try {
            await post('/api/admin/logs/clear', { file: select.value });
            toast('Protokoll geleert.', { kind: 'good' });
            load();
        } catch (error) { toast(error.message, { kind: 'bad' }); }
    });
}

/* ------------------------- Diagnose ------------------------- */

if (pageName === 'diagnostics') {
    const result = document.querySelector('[data-role="diag-result"]');

    const run = async (deep) => {
        status('[data-role="diag-status"]', deep ? 'Belastungstest laeuft ...' : 'Pruefung laeuft ...');
        try {
            const data = await get('/api/admin/diagnostics' + (deep ? '?deep=1' : ''));
            clear(result);
            (data.result.groups || []).forEach((group) => {
                const box = el('section', { class: 'panel-box' }, [el('h2', { text: group.title })]);
                const list = el('div', { class: 'check-list' });
                group.checks.forEach((check) => {
                    list.append(el('div', { class: 'check-row' }, [
                        el('span', { class: 'status-dot status-' + check.status }),
                        el('span', { text: check.name }),
                        el('span', {}, [
                            check.message,
                            check.fix && check.status !== 'ok' ? el('span', { class: 'fix', text: ' Loesung: ' + check.fix }) : null,
                        ]),
                    ]));
                });
                box.append(list);
                result.append(box);
            });
            const summary = data.result.summary || {};
            status('[data-role="diag-status"]', `${summary.ok || 0} in Ordnung, ${summary.warn || 0} Hinweise, ${summary.fail || 0} Fehler`);
        } catch (error) {
            status('[data-role="diag-status"]', error.message);
            toast(error.message, { kind: 'bad' });
        }
    };

    document.querySelector('[data-action="diag-run"]')?.addEventListener('click', () => run(false));
    document.querySelector('[data-action="diag-deep"]')?.addEventListener('click', () => run(true));
}

/* ------------------------- Konto ------------------------- */

if (pageName === 'account') {
    const form = document.querySelector('[data-role="password-form"]');
    form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        status('[data-role="password-status"]', 'Speichern ...');
        try {
            const data = await post('/api/admin/password', formData(form));
            status('[data-role="password-status"]', data.message);
            toast(data.message, { kind: 'good' });
            form.reset();
        } catch (error) {
            status('[data-role="password-status"]', error.message);
            toast(error.message, { kind: 'bad' });
        }
    });
}
