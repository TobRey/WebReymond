/* =============================================================
   Spracheingabe ueber die Web-Speech-API (optional, mit Vorschau)
   ============================================================= */

import { toast } from './core.js';

export function speechAvailable() {
    return Boolean(window.SpeechRecognition || window.webkitSpeechRecognition);
}

/**
 * Startet die Diktierfunktion. Der erkannte Text wird NICHT automatisch
 * abgeschickt, sondern in das Eingabefeld geschrieben und kann korrigiert werden.
 */
export function createDictation(targetInput, button) {
    const Ctor = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!Ctor) return null;

    const recognition = new Ctor();
    recognition.lang = 'de-DE';
    recognition.interimResults = true;
    recognition.continuous = false;
    recognition.maxAlternatives = 1;

    let active = false;
    let baseText = '';

    recognition.addEventListener('result', (event) => {
        let interim = '';
        let final = '';
        for (let i = event.resultIndex; i < event.results.length; i++) {
            const transcript = event.results[i][0].transcript;
            if (event.results[i].isFinal) final += transcript;
            else interim += transcript;
        }
        targetInput.value = (baseText + ' ' + final + ' ' + interim).replace(/\s+/g, ' ').trim();
        targetInput.dispatchEvent(new Event('input'));
    });

    recognition.addEventListener('end', () => {
        active = false;
        button.classList.remove('is-recording');
        button.title = 'Diktieren';
        targetInput.focus();
    });

    recognition.addEventListener('error', (event) => {
        active = false;
        button.classList.remove('is-recording');
        const messages = {
            'not-allowed': 'Das Mikrofon wurde nicht freigegeben.',
            'no-speech': 'Nichts verstanden. Bitte erneut versuchen.',
            'audio-capture': 'Kein Mikrofon gefunden.',
            'network': 'Die Spracherkennung ist offline nicht verfuegbar.',
        };
        toast(messages[event.error] || 'Spracherkennung nicht moeglich.', { title: 'Mikrofon', kind: 'bad' });
    });

    return {
        toggle() {
            if (active) {
                recognition.stop();
                return;
            }
            baseText = targetInput.value.trim();
            try {
                recognition.start();
                active = true;
                button.classList.add('is-recording');
                button.title = 'Aufnahme laeuft - erneut klicken zum Beenden';
            } catch {
                toast('Die Spracherkennung laeuft bereits.', { kind: '' });
            }
        },
        stop() { if (active) recognition.stop(); },
    };
}
