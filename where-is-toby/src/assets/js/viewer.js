/* =============================================================
   Bildbetrachter: Zoom, Verschieben, Bilddetails (Hotspots), EXIF
   ============================================================= */

import { el, clear, assetUrl, post, toast, sound, openOverlay } from './core.js';
import { renderPuzzle } from './puzzle.js';

export function renderPhoto(game, photo, options = {}) {
    const wrap = el('div', { class: 'viewer' });
    const frame = el('div', { class: 'viewer__frame' });
    const image = el('img', { src: assetUrl(photo.src), alt: photo.title || 'Beweisfoto', draggable: 'false' });

    let zoom = 1;
    let offsetX = 0;
    let offsetY = 0;
    let dragging = false;
    let lastX = 0;
    let lastY = 0;

    const apply = () => {
        image.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${zoom})`;
        hotspots.forEach(({ node, spot }) => {
            node.style.transform = `translate(${offsetX}px, ${offsetY}px) scale(${zoom})`;
            node.style.transformOrigin = 'center center';
            node.style.opacity = zoom >= (spot.zoom_min || 1) ? '1' : '0';
            node.style.pointerEvents = zoom >= (spot.zoom_min || 1) ? 'auto' : 'none';
        });
    };

    const hotspots = [];
    frame.append(image);

    (photo.hotspots || []).forEach((spot) => {
        const node = el('button', {
            class: 'viewer__hot',
            type: 'button',
            title: spot.label || 'Detail',
            'aria-label': spot.label || 'Bilddetail untersuchen',
            style: {
                left: `${(spot.x * 100).toFixed(2)}%`,
                top: `${(spot.y * 100).toFixed(2)}%`,
                width: `${((spot.r || 0.05) * 200).toFixed(2)}%`,
                height: `${((spot.r || 0.05) * 200 * (photo.aspect || 0.75)).toFixed(2)}%`,
                marginLeft: `-${((spot.r || 0.05) * 100).toFixed(2)}%`,
                marginTop: `-${((spot.r || 0.05) * 100 * (photo.aspect || 0.75)).toFixed(2)}%`,
            },
            onclick: () => inspectHotspot(game, photo, spot, node, options),
        });
        hotspots.push({ node, spot });
        frame.append(node);
    });

    frame.addEventListener('wheel', (event) => {
        event.preventDefault();
        zoom = Math.max(1, Math.min(5, zoom + (event.deltaY < 0 ? 0.25 : -0.25)));
        if (zoom === 1) { offsetX = 0; offsetY = 0; }
        apply();
    }, { passive: false });

    frame.addEventListener('pointerdown', (event) => {
        if (event.target.classList.contains('viewer__hot')) return;
        dragging = true;
        lastX = event.clientX;
        lastY = event.clientY;
        frame.setPointerCapture(event.pointerId);
    });
    frame.addEventListener('pointermove', (event) => {
        if (!dragging || zoom === 1) return;
        offsetX += event.clientX - lastX;
        offsetY += event.clientY - lastY;
        lastX = event.clientX;
        lastY = event.clientY;
        apply();
    });
    frame.addEventListener('pointerup', () => { dragging = false; });
    frame.addEventListener('pointercancel', () => { dragging = false; });

    const tools = el('div', { class: 'viewer__tools' }, [
        el('button', { class: 'btn btn--small', text: '+ Zoom', onclick: () => { zoom = Math.min(5, zoom + 0.5); apply(); } }),
        el('button', { class: 'btn btn--small', text: '− Zoom', onclick: () => { zoom = Math.max(1, zoom - 0.5); if (zoom === 1) { offsetX = 0; offsetY = 0; } apply(); } }),
        el('button', { class: 'btn btn--small', text: 'Zuruecksetzen', onclick: () => { zoom = 1; offsetX = 0; offsetY = 0; apply(); } }),
        photo.hotspots?.length ? el('span', { class: 'hint', text: 'Details werden erst ab Zoomstufe sichtbar. Verdaechtige Stellen anklicken.' }) : null,
    ]);

    wrap.append(frame, tools);

    if (photo.exif && Object.keys(photo.exif).length) {
        const exif = el('div', { class: 'exif' });
        Object.entries(photo.exif).forEach(([key, value]) => {
            exif.append(el('span', { class: 'muted', text: key }), el('span', { text: String(value) }));
        });
        wrap.append(el('details', { open: Boolean(options.exifOpen) }, [
            el('summary', { text: 'Metadaten (EXIF)' }),
            exif,
        ]));
    }
    if (photo.caption) wrap.append(el('p', { class: 'hint', text: photo.caption }));
    if (photo.puzzle) {
        const puzzle = game.puzzleById(photo.puzzle);
        if (puzzle && !puzzle.solved) {
            wrap.append(el('div', { class: 'panel-box', style: { marginTop: '.6rem' } }, [
                el('h3', { text: puzzle.title }),
                renderPuzzle(game, puzzle, { onSuccess: options.onPuzzleSolved }),
            ]));
        }
    }
    return wrap;
}

async function inspectHotspot(game, photo, spot, node, options) {
    sound.play('ui_click', { gain: 0.3 });
    if (spot.evidence) {
        try {
            const data = await post(`/api/case/${game.caseId}/evidence`, { evidence: spot.evidence });
            if (data.state) game.setState(data.state);
            node.classList.add('is-found');
            if (data.already) {
                toast(spot.text || 'Dieses Detail ist bereits gesichert.', { title: spot.label || 'Detail' });
            } else {
                toast(`${data.evidence.title}`, { title: 'Beweis gesichert', kind: 'evidence', timeout: 7000 });
                game.markPanelDot('beweise');
            }
            game.handleHorror(data.horror);
        } catch (error) {
            toast(error.message, { kind: 'bad' });
        }
    }
    if (spot.puzzle) {
        const puzzle = game.puzzleById(spot.puzzle);
        if (puzzle) {
            openOverlay(puzzle.title || spot.label || 'Detail', el('div', { class: 'stack' }, [
                spot.text ? el('p', { text: spot.text }) : null,
                renderPuzzle(game, puzzle, { onSuccess: options.onPuzzleSolved }),
            ]));
            return;
        }
    }
    if (spot.text && !spot.evidence) {
        openOverlay(spot.label || 'Detail', el('p', { text: spot.text }));
    }
}

/** Oeffnet ein Medium anhand seiner ID (laedt es vom Server). */
export async function openMedia(game, mediaId, options = {}) {
    try {
        const data = await fetchMedia(game, mediaId);
        const media = data.media;
        const { renderVideo } = await import('./cctv.js');
        const { renderAudio } = await import('./audioplayer.js');

        let content;
        if (media.group === 'videos') content = renderVideo(game, media, options);
        else if (media.group === 'audios') content = renderAudio(game, media, options);
        else if (media.group === 'documents') content = renderDocument(game, media);
        else content = renderPhoto(game, media, options);

        openOverlay(media.title || 'Medium', content, { wide: media.group === 'videos' });
        return media;
    } catch (error) {
        toast(error.message, { title: 'Medium', kind: 'bad' });
        return null;
    }
}

const mediaCache = new Map();

export async function fetchMedia(game, mediaId) {
    const key = game.caseId + ':' + mediaId;
    if (mediaCache.has(key)) return mediaCache.get(key);
    const { get } = await import('./core.js');
    const data = await get(`/api/case/${game.caseId}/media/${encodeURIComponent(mediaId)}`);
    mediaCache.set(key, data);
    return data;
}

export function renderDocument(game, document_) {
    const wrap = el('div', { class: 'stack' });
    if (document_.src) {
        wrap.append(el('img', { src: assetUrl(document_.src), alt: document_.title || 'Dokument', style: { width: '100%' } }));
    }
    if (document_.body) {
        wrap.append(el('div', { class: 'paper' }, [
            document_.heading ? el('h3', { text: document_.heading }) : null,
            el('div', { class: 'typed', text: document_.body }),
        ]));
    }
    (document_.pages || []).forEach((page) => {
        wrap.append(el('div', { class: 'paper' }, [
            page.heading ? el('h3', { text: page.heading }) : null,
            el('div', { class: 'typed', text: page.text || '' }),
        ]));
    });
    if (document_.puzzle) {
        const puzzle = game.puzzleById(document_.puzzle);
        if (puzzle && !puzzle.solved) {
            wrap.append(el('div', { class: 'panel-box' }, [el('h3', { text: puzzle.title }), renderPuzzle(game, puzzle)]));
        }
    }
    return wrap;
}
