/**
 * Zeichenwerkzeuge für die Spielwelt.
 * Alles wird selbst gezeichnet – keine fremden Grafiken, keine Ladezeiten.
 */

/** Farbe aufhellen (positiv) oder abdunkeln (negativ), Wert -1 … 1 */
export function shade(hex, amount) {
    const value = parseInt(hex.slice(1), 16);
    const mix = (channel) => {
        const target = amount > 0 ? 255 : 0;
        return Math.round(channel + (target - channel) * Math.abs(amount));
    };
    const r = mix((value >> 16) & 255);
    const g = mix((value >> 8) & 255);
    const b = mix(value & 255);
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
}

export function alpha(hex, a) {
    const value = parseInt(hex.slice(1), 16);
    return `rgba(${(value >> 16) & 255},${(value >> 8) & 255},${value & 255},${a})`;
}

export function roundRect(ctx, x, y, w, h, r) {
    const radius = Math.min(r, Math.abs(w) / 2, Math.abs(h) / 2);
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.lineTo(x + w - radius, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + radius);
    ctx.lineTo(x + w, y + h - radius);
    ctx.quadraticCurveTo(x + w, y + h, x + w - radius, y + h);
    ctx.lineTo(x + radius, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - radius);
    ctx.lineTo(x, y + radius);
    ctx.quadraticCurveTo(x, y, x + radius, y);
    ctx.closePath();
}

export function poly(ctx, points) {
    ctx.beginPath();
    ctx.moveTo(points[0][0], points[0][1]);
    for (let i = 1; i < points.length; i++) { ctx.lineTo(points[i][0], points[i][1]); }
    ctx.closePath();
}

export function fillPoly(ctx, points, color) {
    poly(ctx, points);
    ctx.fillStyle = color;
    ctx.fill();
}

export function ellipse(ctx, x, y, rx, ry, color) {
    ctx.beginPath();
    ctx.ellipse(x, y, rx, ry, 0, 0, Math.PI * 2);
    ctx.fillStyle = color;
    ctx.fill();
}

/** Weicher Schatten unter einem Objekt. */
export function shadow(ctx, x, y, rx, ry, strength = 0.22) {
    ctx.save();
    ctx.globalAlpha = strength;
    ellipse(ctx, x, y, rx, ry, '#0d1526');
    ctx.restore();
}

/**
 * Quader mit Vorderseite, Seitenfläche und Deckel – der Grundbaustein
 * aller Gebäude. (x|y) ist die Mitte der Grundfläche.
 */
export function block(ctx, x, y, w, d, h, color, options = {}) {
    const top = y - h;
    const light = shade(color, 0.16);
    const dark = shade(color, -0.26);
    const side = options.sideWidth !== undefined ? options.sideWidth : w * 0.18;

    // Seitenfläche rechts
    fillPoly(ctx, [
        [x + w / 2, top],
        [x + w / 2 + side, top - d * 0.2],
        [x + w / 2 + side, y - d * 0.2],
        [x + w / 2, y]
    ], dark);

    // Deckel
    fillPoly(ctx, [
        [x - w / 2, top],
        [x + w / 2, top],
        [x + w / 2 + side, top - d * 0.2],
        [x - w / 2 + side, top - d * 0.2]
    ], light);

    // Vorderseite
    ctx.fillStyle = color;
    ctx.fillRect(x - w / 2, top, w, h);

    if (options.outline !== false) {
        ctx.strokeStyle = alpha(shade(color, -0.55), 0.55);
        ctx.lineWidth = Math.max(1, w * 0.02);
        ctx.strokeRect(x - w / 2, top, w, h);
    }
}

/** Giebeldach. */
export function gableRoof(ctx, x, y, w, h, color) {
    const light = shade(color, 0.14);
    fillPoly(ctx, [[x - w / 2, y], [x + w / 2, y], [x, y - h]], color);
    fillPoly(ctx, [[x, y - h], [x + w / 2, y], [x + w * 0.12, y]], shade(color, -0.2));
    ctx.fillStyle = light;
    ctx.globalAlpha = 0.5;
    poly(ctx, [[x - w / 2, y], [x, y - h], [x - w * 0.1, y]]);
    ctx.fill();
    ctx.globalAlpha = 1;
}

/** Kegeldach (Türme). */
export function coneRoof(ctx, x, y, w, h, color) {
    fillPoly(ctx, [[x - w / 2, y], [x + w / 2, y], [x, y - h]], color);
    fillPoly(ctx, [[x, y - h], [x + w / 2, y], [x + w * 0.05, y]], shade(color, -0.22));
}

/** Zinnenkranz auf einer Mauer. */
export function battlements(ctx, x, y, w, color, count = 4) {
    const step = w / (count * 2 - 1);
    ctx.fillStyle = color;
    for (let i = 0; i < count; i++) {
        ctx.fillRect(x - w / 2 + i * step * 2, y - step * 0.9, step, step * 0.9);
    }
}

export function windowLight(ctx, x, y, w, h, lit) {
    ctx.fillStyle = lit ? '#ffd980' : '#3a4a66';
    roundRect(ctx, x - w / 2, y, w, h, Math.min(w, h) * 0.35);
    ctx.fill();
}

export function door(ctx, x, y, w, h, color = '#5b4433') {
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.moveTo(x - w / 2, y);
    ctx.lineTo(x - w / 2, y - h + w / 2);
    ctx.arc(x, y - h + w / 2, w / 2, Math.PI, 0);
    ctx.lineTo(x + w / 2, y);
    ctx.closePath();
    ctx.fill();
}

/** Fahne auf einer Stange – der Winkel kommt von der Zeit (Animation). */
export function flag(ctx, x, y, size, color, wave) {
    ctx.strokeStyle = '#6b5744';
    ctx.lineWidth = Math.max(1, size * 0.12);
    ctx.beginPath();
    ctx.moveTo(x, y);
    ctx.lineTo(x, y - size * 1.7);
    ctx.stroke();

    const w = size * 1.15;
    const top = y - size * 1.7;
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.moveTo(x, top);
    ctx.quadraticCurveTo(x + w * 0.5, top - size * 0.12 + wave * size * 0.22, x + w, top + size * 0.28);
    ctx.quadraticCurveTo(x + w * 0.5, top + size * 0.5 + wave * size * 0.18, x, top + size * 0.62);
    ctx.closePath();
    ctx.fill();
}

/** Baum. */
export function tree(ctx, x, y, size, hue = '#4fae57') {
    ctx.fillStyle = '#6b4f35';
    ctx.fillRect(x - size * 0.09, y - size * 0.45, size * 0.18, size * 0.45);
    ellipse(ctx, x, y - size * 0.72, size * 0.42, size * 0.4, hue);
    ellipse(ctx, x - size * 0.22, y - size * 0.52, size * 0.3, size * 0.28, shade(hue, -0.1));
    ellipse(ctx, x + size * 0.2, y - size * 0.56, size * 0.28, size * 0.26, shade(hue, 0.1));
}

export function bush(ctx, x, y, size, hue = '#5bb75f') {
    ellipse(ctx, x, y - size * 0.18, size * 0.36, size * 0.26, hue);
    ellipse(ctx, x - size * 0.2, y - size * 0.1, size * 0.24, size * 0.18, shade(hue, -0.12));
    ellipse(ctx, x + size * 0.18, y - size * 0.12, size * 0.22, size * 0.17, shade(hue, 0.12));
}

export function rock(ctx, x, y, size, hue = '#9aa3ad') {
    fillPoly(ctx, [
        [x - size * 0.4, y], [x - size * 0.18, y - size * 0.38],
        [x + size * 0.14, y - size * 0.42], [x + size * 0.4, y]
    ], hue);
    fillPoly(ctx, [
        [x + size * 0.14, y - size * 0.42], [x + size * 0.4, y], [x, y]
    ], shade(hue, -0.18));
}
