/* =============================================================
   Audioanalyse: Wellenform, Rueckwaertswiedergabe, Tempo, Transkript
   ============================================================= */

import { el, assetUrl, toast, sound, clear } from './core.js';
import { renderPuzzle } from './puzzle.js';

export function renderAudio(game, track, options = {}) {
    const wrap = el('div', { class: 'audio-player' });
    const canvas = el('canvas', { class: 'wave', width: '900', height: '76' });
    const status = el('span', { class: 'timecode', text: '00:00' });
    const transcript = el('div', { class: 'transcript' });

    let context = null;
    let buffer = null;
    let source = null;
    let playing = false;
    let reversed = false;
    let rate = 1;
    let startedAt = 0;
    let offset = 0;
    let raf = 0;

    const url = track.src ? assetUrl(track.src) : `${(window.witGame?.base) || ''}`;
    const soundUrl = track.track ? sound.url(track.track) : url;

    const ensureContext = () => {
        if (!context) {
            const Ctor = window.AudioContext || window.webkitAudioContext;
            context = Ctor ? new Ctor() : null;
        }
        if (context && context.state === 'suspended') context.resume().catch(() => {});
        return context;
    };

    const load = async () => {
        const ctx = ensureContext();
        if (!ctx) { toast('Dieser Browser kann die Audioanalyse nicht darstellen.', { kind: 'bad' }); return null; }
        if (buffer) return buffer;
        try {
            const response = await fetch(soundUrl, { credentials: 'same-origin' });
            const raw = await response.arrayBuffer();
            buffer = await ctx.decodeAudioData(raw);
            drawWave();
            return buffer;
        } catch {
            toast('Audiodatei konnte nicht geladen werden.', { kind: 'bad' });
            return null;
        }
    };

    const drawWave = (progress = 0) => {
        const ctx2d = canvas.getContext('2d');
        if (!ctx2d || !buffer) return;
        const width = canvas.width;
        const height = canvas.height;
        ctx2d.clearRect(0, 0, width, height);
        ctx2d.fillStyle = '#06080c';
        ctx2d.fillRect(0, 0, width, height);

        const data = buffer.getChannelData(0);
        const step = Math.max(1, Math.floor(data.length / width));
        ctx2d.strokeStyle = '#3d6484';
        ctx2d.beginPath();
        for (let x = 0; x < width; x++) {
            let min = 1;
            let max = -1;
            for (let i = 0; i < step; i++) {
                const value = data[(x * step) + i] || 0;
                if (value < min) min = value;
                if (value > max) max = value;
            }
            ctx2d.moveTo(x + 0.5, ((1 + min) * height) / 2);
            ctx2d.lineTo(x + 0.5, ((1 + max) * height) / 2);
        }
        ctx2d.stroke();

        // Marker aus der Falldefinition (z. B. auffaellige Stellen)
        (track.markers || []).forEach((marker) => {
            const x = (Number(marker.t || 0) / buffer.duration) * width;
            ctx2d.strokeStyle = marker.color || '#b98a35';
            ctx2d.beginPath();
            ctx2d.moveTo(x, 0);
            ctx2d.lineTo(x, height);
            ctx2d.stroke();
        });

        if (progress > 0) {
            ctx2d.strokeStyle = '#a02a2f';
            ctx2d.beginPath();
            ctx2d.moveTo(progress * width, 0);
            ctx2d.lineTo(progress * width, height);
            ctx2d.stroke();
        }
    };

    const stop = () => {
        if (source) { try { source.stop(); } catch { /* egal */ } source = null; }
        playing = false;
        playButton.textContent = '▶ Abspielen';
        cancelAnimationFrame(raf);
    };

    const tick = () => {
        if (!playing || !context || !buffer) return;
        const elapsed = (context.currentTime - startedAt) * rate + offset;
        const progress = Math.min(1, elapsed / buffer.duration);
        status.textContent = formatSeconds(elapsed);
        drawWave(progress);
        highlightTranscript(elapsed);
        if (progress >= 1) { stop(); return; }
        raf = requestAnimationFrame(tick);
    };

    const start = async () => {
        if (playing) { stop(); return; }
        const ctx = ensureContext();
        const data = await load();
        if (!ctx || !data) return;

        source = ctx.createBufferSource();
        let playBuffer = data;
        if (reversed) {
            playBuffer = ctx.createBuffer(data.numberOfChannels, data.length, data.sampleRate);
            for (let channel = 0; channel < data.numberOfChannels; channel++) {
                const input = data.getChannelData(channel);
                const output = playBuffer.getChannelData(channel);
                for (let i = 0; i < input.length; i++) output[i] = input[input.length - 1 - i];
            }
        }
        source.buffer = playBuffer;
        source.playbackRate.value = rate;
        const gain = ctx.createGain();
        gain.gain.value = Math.max(0.05, game.settings.volume ?? 0.7);
        source.connect(gain).connect(ctx.destination);
        source.start();
        startedAt = ctx.currentTime;
        offset = 0;
        playing = true;
        playButton.textContent = '❚❚ Pause';
        tick();
        source.onended = () => { if (playing) stop(); };
    };

    const playButton = el('button', { class: 'btn btn--primary btn--small', text: '▶ Abspielen', onclick: start });
    const reverseButton = el('button', {
        class: 'btn btn--small', text: 'Rueckwaerts: aus',
        title: 'Spur rueckwaerts abspielen',
        onclick: () => {
            reversed = !reversed;
            reverseButton.textContent = 'Rueckwaerts: ' + (reversed ? 'an' : 'aus');
            reverseButton.classList.toggle('btn--primary', reversed);
            if (playing) { stop(); start(); }
        },
    });
    const rateButton = el('button', {
        class: 'btn btn--small', text: 'Tempo 1.0x',
        onclick: () => {
            rate = rate === 1 ? 0.5 : (rate === 0.5 ? 2 : 1);
            rateButton.textContent = `Tempo ${rate.toFixed(1)}x`;
            if (playing) { stop(); start(); }
        },
    });

    const speakButton = el('button', {
        class: 'btn btn--small', text: 'Stimme vorlesen',
        title: 'Transkript ueber die Sprachausgabe des Browsers hoerbar machen',
        onclick: () => {
            if (!('speechSynthesis' in window)) { toast('Dieser Browser hat keine Sprachausgabe.', { kind: 'bad' }); return; }
            const text = (track.transcript || []).map((line) => line.text).join(' ');
            if (!text) { toast('Kein Transkript vorhanden.'); return; }
            window.speechSynthesis.cancel();
            const utterance = new SpeechSynthesisUtterance(text);
            utterance.lang = 'de-DE';
            utterance.rate = 0.96;
            utterance.pitch = track.voice_pitch || 1;
            utterance.volume = Math.max(0.1, game.settings.volume ?? 0.7);
            window.speechSynthesis.speak(utterance);
        },
    });

    const lines = [];
    (track.transcript || []).forEach((line) => {
        const node = el('p', { 'data-t': String(line.t || 0) }, [
            el('span', { class: 'muted mono', text: formatSeconds(line.t || 0) + ' ' }),
            el('span', { text: line.text || '' }),
        ]);
        lines.push({ node, t: Number(line.t || 0) });
        transcript.append(node);
    });

    function highlightTranscript(seconds) {
        lines.forEach(({ node, t }, index) => {
            const next = lines[index + 1]?.t ?? Infinity;
            node.classList.toggle('is-active', seconds >= t && seconds < next);
        });
    }

    function formatSeconds(value) {
        const total = Math.max(0, Math.floor(value));
        return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
    }

    load();

    wrap.append(
        el('div', { class: 'toolbar', style: { margin: 0 } }, [
            el('strong', { text: track.title || 'Audio' }),
            el('span', { class: 'hint', text: track.meta || '' }),
        ]),
        canvas,
        el('div', { class: 'audio-tools' }, [playButton, reverseButton, rateButton, speakButton, status]),
    );

    if (game.settings.subtitles && lines.length) {
        wrap.append(el('details', { open: true }, [el('summary', { text: 'Transkript' }), transcript]));
    }
    if (track.note) wrap.append(el('p', { class: 'hint', text: track.note }));

    if (track.puzzle) {
        const puzzle = game.puzzleById(track.puzzle);
        if (puzzle && !puzzle.solved) {
            wrap.append(el('div', { class: 'panel-box' }, [
                el('h3', { text: puzzle.title }),
                renderPuzzle(game, puzzle, { onSuccess: options.onPuzzleSolved }),
            ]));
        }
    }

    const observer = new MutationObserver(() => {
        if (!document.body.contains(wrap)) {
            stop();
            if ('speechSynthesis' in window) window.speechSynthesis.cancel();
            observer.disconnect();
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });

    return wrap;
}
