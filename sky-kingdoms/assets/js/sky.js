/**
 * Sky Kingdoms – animierter Himmel für Anmeldung, Installer und Adminbereich.
 * Bewusst klein und sparsam: ein Canvas, wenige Wolken, keine Abhängigkeiten.
 * Bei „Bewegung reduzieren" wird nur ein ruhiges Bild gezeichnet.
 */
(function () {
    'use strict';

    var canvas = document.getElementById('sk-sky-canvas');
    if (!canvas || !canvas.getContext) { return; }

    var ctx = canvas.getContext('2d');
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    var width = 0, height = 0;
    var clouds = [];
    var islands = [];
    var time = 0;

    function style(name, fallback) {
        var value = getComputedStyle(document.documentElement).getPropertyValue(name);
        return (value || '').trim() || fallback;
    }

    function resize() {
        width = canvas.clientWidth || window.innerWidth;
        height = canvas.clientHeight || window.innerHeight;
        canvas.width = Math.floor(width * dpr);
        canvas.height = Math.floor(height * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        build();
    }

    function build() {
        clouds = [];
        var count = Math.max(5, Math.round(width / 150));
        for (var i = 0; i < count; i++) {
            clouds.push({
                x: Math.random() * width,
                y: Math.random() * height * 0.85,
                s: 0.5 + Math.random() * 1.1,
                v: 0.08 + Math.random() * 0.22,
                a: 0.30 + Math.random() * 0.4
            });
        }

        islands = [];
        var many = width > 720 ? 4 : 3;
        for (var j = 0; j < many; j++) {
            islands.push({
                x: (j + 0.5) * (width / many) + (Math.random() - 0.5) * 60,
                y: height * (0.30 + Math.random() * 0.45),
                s: 0.45 + Math.random() * 0.75,
                p: Math.random() * Math.PI * 2
            });
        }
    }

    function cloud(c) {
        ctx.globalAlpha = c.a;
        ctx.fillStyle = '#ffffff';
        var r = 22 * c.s;
        ctx.beginPath();
        ctx.arc(c.x, c.y, r, 0, Math.PI * 2);
        ctx.arc(c.x + r * 0.85, c.y + r * 0.18, r * 0.75, 0, Math.PI * 2);
        ctx.arc(c.x - r * 0.85, c.y + r * 0.22, r * 0.65, 0, Math.PI * 2);
        ctx.arc(c.x + r * 0.15, c.y - r * 0.55, r * 0.6, 0, Math.PI * 2);
        ctx.fill();
        ctx.globalAlpha = 1;
    }

    function island(o) {
        var lift = reduced ? 0 : Math.sin(time * 0.0006 + o.p) * 7;
        var x = o.x, y = o.y + lift, s = o.s;

        ctx.globalAlpha = 0.85;
        // Fels
        ctx.fillStyle = '#6f5842';
        ctx.beginPath();
        ctx.moveTo(x - 54 * s, y);
        ctx.lineTo(x + 54 * s, y);
        ctx.lineTo(x + 26 * s, y + 40 * s);
        ctx.lineTo(x, y + 62 * s);
        ctx.lineTo(x - 28 * s, y + 38 * s);
        ctx.closePath();
        ctx.fill();

        // Wiese
        ctx.fillStyle = '#5bb75f';
        ctx.beginPath();
        ctx.ellipse(x, y, 54 * s, 13 * s, 0, 0, Math.PI * 2);
        ctx.fill();

        // Kleines Haus
        ctx.fillStyle = '#f2ead6';
        ctx.fillRect(x - 11 * s, y - 20 * s, 22 * s, 20 * s);
        ctx.fillStyle = '#e2582f';
        ctx.beginPath();
        ctx.moveTo(x - 15 * s, y - 20 * s);
        ctx.lineTo(x + 15 * s, y - 20 * s);
        ctx.lineTo(x, y - 34 * s);
        ctx.closePath();
        ctx.fill();
        ctx.globalAlpha = 1;
    }

    function frame(now) {
        time = now || 0;
        ctx.clearRect(0, 0, width, height);

        var grad = ctx.createLinearGradient(0, 0, 0, height);
        grad.addColorStop(0, style('--sk-sky-top', '#5ec6ff'));
        grad.addColorStop(1, style('--sk-sky-bottom', '#b9e9ff'));
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, width, height);

        for (var i = 0; i < islands.length; i++) { island(islands[i]); }

        for (var j = 0; j < clouds.length; j++) {
            var c = clouds[j];
            if (!reduced) {
                c.x += c.v;
                if (c.x - 80 > width) { c.x = -80; c.y = Math.random() * height * 0.85; }
            }
            cloud(c);
        }

        if (!reduced) { requestAnimationFrame(frame); }
    }

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () { resize(); if (reduced) { frame(0); } }, 180);
    });

    resize();
    if (reduced) { frame(0); } else { requestAnimationFrame(frame); }
})();
