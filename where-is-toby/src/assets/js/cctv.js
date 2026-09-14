/* =============================================================
   Ueberwachungsvideo: Frame-Player mit Zeitleiste und Zeitcode
   ============================================================= */

import { el, assetUrl, post, toast, sound, clear } from './core.js';
import { renderPuzzle } from './puzzle.js';

function pad(value) {
    return String(Math.floor(value)).padStart(2, '0');
}

function clockAt(video, seconds) {
    const [h, m, s] = String(video.start_clock || '00:00:00').split(':').map((part) => Number(part) || 0);
    const total = h * 3600 + m * 60 + s + Math.floor(seconds);
    return `${pad((total / 3600) % 24)}:${pad((total / 60) % 60)}:${pad(total % 60)}`;
}

export function renderVideo(game, video, options = {}) {
    const frames = [...(video.frames || [])].sort((a, b) => (a.t || 0) - (b.t || 0));
    const duration = Number(video.duration || (frames.length ? frames[frames.length - 1].t + 10 : 60));

    let position = 0;
    let playing = false;
    let timer = null;
    let speed = 1;

    const screen = el('div', { class: 'cctv__screen' });
    const image = el('img', { src: frames.length ? assetUrl(frames[0].src) : '', alt: video.title || 'Kamerabild' });
    const osd = el('div', { class: 'cctv__osd' }, [
        el('span', { text: video.camera || video.title || 'CAM' }),
        el('span', { class: 'cctv__rec', text: '● REC' }),
    ]);
    const clockNode = el('div', { class: 'cctv__osd', style: { top: 'auto', bottom: '.5rem' } }, [
        el('span', { text: video.date || '' }),
        el('span', { id: 'cctv-clock', text: clockAt(video, 0) }),
    ]);
    screen.append(image, osd, clockNode);

    const scrub = el('input', { type: 'range', min: '0', max: String(duration), step: '1', value: '0' });
    const timecode = el('span', { class: 'timecode', text: `00:00 / ${pad(duration / 60)}:${pad(duration % 60)}` });
    const noteNode = el('p', { class: 'hint', text: '' });

    const currentFrame = () => {
        let frame = frames[0];
        for (const candidate of frames) {
            if ((candidate.t || 0) <= position) frame = candidate;
        }
        return frame;
    };

    const draw = () => {
        const frame = currentFrame();
        if (frame && image.getAttribute('src') !== assetUrl(frame.src)) {
            image.src = assetUrl(frame.src);
        }
        noteNode.textContent = frame?.note || '';
        scrub.value = String(Math.floor(position));
        timecode.textContent = `${pad(position / 60)}:${pad(position % 60)} / ${pad(duration / 60)}:${pad(duration % 60)}`;
        const clock = document.getElementById('cctv-clock');
        if (clock) clock.textContent = clockAt(video, position);
    };

    const stop = () => {
        playing = false;
        clearInterval(timer);
        playButton.textContent = '▶';
    };

    const play = () => {
        if (playing) { stop(); return; }
        playing = true;
        playButton.textContent = '❚❚';
        timer = setInterval(() => {
            position += speed;
            if (position >= duration) { position = duration; stop(); }
            draw();
        }, 1000 / 4 / speed > 60 ? 250 : 250);
    };

    const playButton = el('button', { class: 'btn btn--small', text: '▶', onclick: play, title: 'Abspielen' });

    scrub.addEventListener('input', () => {
        position = Number(scrub.value);
        draw();
    });

    const speedButton = el('button', {
        class: 'btn btn--small', text: '1x',
        onclick: () => {
            speed = speed === 1 ? 4 : (speed === 4 ? 16 : 1);
            speedButton.textContent = speed + 'x';
            if (playing) { stop(); play(); }
        },
    });

    const markButton = el('button', {
        class: 'btn btn--small btn--primary',
        text: 'Zeitpunkt melden',
        title: 'Aktuellen Zeitpunkt als Fund melden',
        onclick: async () => {
            if (!video.puzzle) {
                toast('Hier gibt es nichts zu melden.', { kind: '' });
                return;
            }
            const puzzle = game.puzzleById(video.puzzle);
            if (!puzzle) return;
            if (puzzle.solved) { toast('Dieser Zeitpunkt ist bereits ausgewertet.'); return; }
            const { solve } = await import('./puzzle.js');
            await solve(game, video.puzzle, position, { onSuccess: options.onPuzzleSolved });
        },
    });

    // Marker fuer bereits gefundene Stellen
    const markers = el('div', { class: 'hint' });
    (video.markers || []).filter((marker) => !marker.hidden).forEach((marker) => {
        markers.append(el('button', {
            class: 'btn btn--small',
            text: `${pad((marker.t || 0) / 60)}:${pad((marker.t || 0) % 60)} ${marker.label || ''}`.trim(),
            onclick: () => { position = marker.t || 0; draw(); },
        }));
    });

    draw();

    const wrap = el('div', { class: 'cctv' }, [
        screen,
        el('div', { class: 'cctv__controls' }, [
            el('div', { class: 'toolbar', style: { margin: 0 } }, [playButton, speedButton]),
            scrub,
            timecode,
        ]),
        noteNode,
        markers.childElementCount ? el('div', { class: 'toolbar' }, [el('span', { class: 'hint', text: 'Sprungmarken:' }), markers]) : null,
        el('div', { class: 'toolbar' }, [markButton, el('span', { class: 'hint', text: video.help || 'Mit dem Regler durch die Aufzeichnung fahren. Verdaechtige Bilder genau ansehen.' })]),
    ]);

    if (video.puzzle) {
        const puzzle = game.puzzleById(video.puzzle);
        if (puzzle && !puzzle.solved && puzzle.type !== 'timecode') {
            wrap.append(el('div', { class: 'panel-box' }, [el('h3', { text: puzzle.title }), renderPuzzle(game, puzzle, { onSuccess: options.onPuzzleSolved })]));
        }
    }

    // Aufraeumen, wenn das Overlay geschlossen wird
    const observer = new MutationObserver(() => {
        if (!document.body.contains(wrap)) { stop(); observer.disconnect(); }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    return wrap;
}
