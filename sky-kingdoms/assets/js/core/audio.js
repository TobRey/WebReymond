/**
 * Dezente Klänge – vollständig im Browser erzeugt (WebAudio).
 * Dadurch sind keine Tondateien nötig und es entstehen keine Lizenzfragen.
 */

let ctx = null;
let master = null;
let enabled = true;
let musicOn = false;
let musicTimer = null;

function context() {
    if (ctx) { return ctx; }
    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (!Ctor) { return null; }
    ctx = new Ctor();
    master = ctx.createGain();
    master.gain.value = 0.22;
    master.connect(ctx.destination);
    return ctx;
}

export function setEnabled(value) { enabled = !!value; }
export function setMusic(value) {
    musicOn = !!value;
    if (musicOn) { startMusic(); } else { stopMusic(); }
}

/** Wird beim ersten Antippen aufgerufen (Browser verlangen eine Geste). */
export function unlock() {
    const audio = context();
    if (audio && audio.state === 'suspended') { audio.resume(); }
}

function tone(frequency, duration, type = 'sine', volume = 0.5, delay = 0, slideTo = null) {
    if (!enabled) { return; }
    const audio = context();
    if (!audio) { return; }

    const start = audio.currentTime + delay;
    const osc = audio.createOscillator();
    const gain = audio.createGain();

    osc.type = type;
    osc.frequency.setValueAtTime(frequency, start);
    if (slideTo) { osc.frequency.exponentialRampToValueAtTime(slideTo, start + duration); }

    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(volume, start + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);

    osc.connect(gain);
    gain.connect(master);
    osc.start(start);
    osc.stop(start + duration + 0.05);
}

export const sfx = {
    tap()      { tone(520, 0.06, 'sine', 0.30); },
    open()     { tone(380, 0.10, 'sine', 0.30); tone(560, 0.12, 'sine', 0.22, 0.05); },
    close()    { tone(420, 0.09, 'sine', 0.22, 0, 300); },
    build()    { tone(180, 0.10, 'square', 0.22); tone(300, 0.14, 'triangle', 0.26, 0.06); tone(450, 0.18, 'sine', 0.22, 0.13); },
    upgrade()  { tone(440, 0.09, 'triangle', 0.26); tone(660, 0.11, 'triangle', 0.24, 0.07); tone(880, 0.16, 'sine', 0.26, 0.15); },
    coins()    { tone(880, 0.06, 'square', 0.16); tone(1180, 0.07, 'square', 0.14, 0.05); tone(1480, 0.09, 'square', 0.12, 0.1); },
    error()    { tone(220, 0.14, 'sawtooth', 0.18, 0, 150); },
    success()  { tone(523, 0.11, 'sine', 0.26); tone(659, 0.11, 'sine', 0.26, 0.09); tone(784, 0.22, 'sine', 0.28, 0.18); },
    battle()   { tone(140, 0.22, 'sawtooth', 0.20, 0, 90); tone(300, 0.14, 'square', 0.14, 0.1); },
    hit()      { tone(160, 0.07, 'square', 0.18, 0, 110); },
    quest()    { tone(700, 0.09, 'triangle', 0.24); tone(1050, 0.16, 'triangle', 0.22, 0.08); }
};

/** Sehr ruhige Begleitmusik aus wenigen Tönen. */
function startMusic() {
    stopMusic();
    const scale = [196.0, 220.0, 261.6, 293.7, 329.6, 392.0];
    let step = 0;
    musicTimer = setInterval(() => {
        if (!musicOn || !enabled) { return; }
        const note = scale[(step * 3 + Math.floor(Math.random() * 2)) % scale.length];
        tone(note, 2.2, 'sine', 0.05);
        if (step % 4 === 0) { tone(note / 2, 3.4, 'sine', 0.04, 0.1); }
        step++;
    }, 2600);
}

function stopMusic() {
    if (musicTimer) { clearInterval(musicTimer); musicTimer = null; }
}
