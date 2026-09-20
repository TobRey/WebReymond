/** Zeigt beim Tippen an, wie stark ein Passwort ist. Reine Anzeige – geprüft wird auf dem Server. */
(function () {
    'use strict';
    var input = document.querySelector('[data-strength]');
    var meter = document.querySelector('.sk-strength');
    if (!input || !meter) { return; }

    input.addEventListener('input', function () {
        var value = input.value || '';
        var score = 0;
        if (value.length >= 10) { score++; }
        if (value.length >= 14) { score++; }
        if (/\d/.test(value) && /[a-zäöüß]/.test(value) && /[A-ZÄÖÜ]/.test(value)) { score++; }
        if (/[^\p{L}\p{N}]/u.test(value)) { score++; }
        meter.setAttribute('data-score', String(Math.min(4, score)));
    });
})();
