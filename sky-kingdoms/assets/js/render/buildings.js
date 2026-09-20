/**
 * Die Gebäude – vollständig selbst gezeichnet.
 *
 * Jeder Typ hat eine eigene Zeichenvorschrift. Die Ausbaustufe (tier 0–6)
 * verändert die Optik in Meilensteinen: mehr Stockwerke, Zinnen, Fahnen,
 * Goldverzierungen. Höhere Stufen ohne eigene Grafik behalten die höchste
 * Optik, während die Werte weiter steigen.
 */

import {
    alpha, battlements, block, bush, coneRoof, door, ellipse, fillPoly,
    flag, gableRoof, rock, roundRect, shade, shadow, tree, windowLight
} from './draw.js';

const STONE = '#e8e0cd';
const WOOD  = '#b98a56';
const ROOF_RED = '#d9552f';
const ROOF_BLUE = '#3f7fbf';
const ROOF_GREEN = '#4f9b52';
const ROOF_PURPLE = '#8266bd';

/** Gemeinsamer Sockel: Plattform mit Schatten. */
function base(ctx, w, d, color = '#8a6f4e') {
    shadow(ctx, 0, 0, w * 0.55, d * 0.42, 0.26);
    ellipse(ctx, 0, 0, w * 0.5, d * 0.36, color);
    ellipse(ctx, 0, -d * 0.05, w * 0.47, d * 0.32, shade(color, 0.12));
}

/** Goldverzierung ab hoher Ausbaustufe. */
function trim(ctx, tier, x, y, w) {
    if (tier < 4) { return; }
    ctx.fillStyle = '#ffc94a';
    ctx.fillRect(x - w / 2, y, w, Math.max(1, w * 0.06));
}

const SPECS = {
    // ---------------------------------------------------------------
    // Hauptinsel
    // ---------------------------------------------------------------
    castle(ctx, w, d, tier, t) {
        base(ctx, w, d, '#9a8b6f');
        const towerH = w * (0.62 + tier * 0.045);
        const mainW = w * 0.46;

        block(ctx, -w * 0.26, 0, mainW * 0.62, d * 0.5, towerH * 0.8, STONE);
        battlements(ctx, -w * 0.26, -towerH * 0.8, mainW * 0.62, shade(STONE, -0.1), 3);

        block(ctx, w * 0.2, 0, mainW * 0.72, d * 0.55, towerH, STONE);
        coneRoof(ctx, w * 0.2, -towerH, mainW * 0.86, towerH * 0.52, ROOF_RED);

        block(ctx, -w * 0.02, 0, mainW * 0.5, d * 0.45, towerH * 0.55, shade(STONE, -0.06));
        door(ctx, -w * 0.02, 0, w * 0.13, w * 0.2);

        windowLight(ctx, w * 0.2, -towerH * 0.62, w * 0.07, w * 0.1, true);
        windowLight(ctx, -w * 0.26, -towerH * 0.55, w * 0.06, w * 0.09, tier > 1);
        trim(ctx, tier, w * 0.2, -towerH * 0.04, mainW * 0.72);

        flag(ctx, w * 0.2, -towerH - towerH * 0.5, w * 0.11, '#ffc94a', Math.sin(t * 2.2));
        if (tier >= 2) { flag(ctx, -w * 0.26, -towerH * 0.8 - w * 0.02, w * 0.08, '#3f8cff', Math.sin(t * 2.4 + 1)); }
        if (tier >= 5) {
            block(ctx, -w * 0.42, 0, mainW * 0.4, d * 0.4, towerH * 0.62, STONE);
            coneRoof(ctx, -w * 0.42, -towerH * 0.62, mainW * 0.5, towerH * 0.34, ROOF_BLUE);
        }
    },

    house(ctx, w, d, tier, t) {
        base(ctx, w, d, '#7fa869');
        const h = w * (0.38 + tier * 0.03);
        block(ctx, 0, 0, w * 0.62, d * 0.5, h, '#f3e8d2');
        gableRoof(ctx, 0, -h, w * 0.78, w * 0.3, ROOF_RED);
        door(ctx, -w * 0.12, 0, w * 0.13, w * 0.18);
        windowLight(ctx, w * 0.12, -h * 0.6, w * 0.1, w * 0.1, true);
        if (tier >= 2) { windowLight(ctx, -w * 0.16, -h * 0.6, w * 0.09, w * 0.09, false); }
        if (tier >= 3) {
            ctx.fillStyle = '#9a7b5a';
            ctx.fillRect(w * 0.2, -h - w * 0.2, w * 0.08, w * 0.22);
            smoke(ctx, w * 0.24, -h - w * 0.2, w * 0.1, t);
        }
        bush(ctx, -w * 0.3, d * 0.06, w * 0.2);
    },

    workshop(ctx, w, d, tier, t) {
        base(ctx, w, d, '#9c8a6a');
        const h = w * 0.34;
        block(ctx, 0, 0, w * 0.72, d * 0.5, h, '#cbb48e');
        gableRoof(ctx, 0, -h, w * 0.86, w * 0.2, '#7c5f3e');
        door(ctx, 0, 0, w * 0.18, w * 0.2, '#6d4f34');
        ctx.fillStyle = '#8fb2c9';
        ctx.fillRect(-w * 0.3, -h * 0.72, w * 0.12, w * 0.1);
        gear(ctx, w * 0.26, -h * 0.5, w * 0.13, t * (1 + tier * 0.25));
        if (tier >= 3) { gear(ctx, w * 0.12, -h * 0.75, w * 0.09, -t * 1.6); }
    },

    smithy(ctx, w, d, tier, t) {
        base(ctx, w, d, '#8d7a62');
        const h = w * 0.34;
        block(ctx, 0, 0, w * 0.7, d * 0.5, h, '#9a8878');
        gableRoof(ctx, 0, -h, w * 0.84, w * 0.2, '#4a4a55');
        ctx.fillStyle = '#5a4436';
        ctx.fillRect(w * 0.18, -h - w * 0.26, w * 0.1, w * 0.28);
        smoke(ctx, w * 0.23, -h - w * 0.26, w * 0.13, t);
        // Glut im Inneren
        ellipse(ctx, -w * 0.12, -h * 0.28, w * 0.1, w * 0.06, '#ff8b3d');
        ellipse(ctx, -w * 0.12, -h * 0.3, w * 0.06, w * 0.04, '#ffd980');
        if (tier >= 2) { anvil(ctx, w * 0.3, 0, w * 0.16); }
        trim(ctx, tier, 0, -h * 0.08, w * 0.7);
    },

    armory(ctx, w, d, tier, t) {
        base(ctx, w, d, '#8d7a62');
        const h = w * 0.36;
        block(ctx, 0, 0, w * 0.68, d * 0.5, h, '#8f8496');
        gableRoof(ctx, 0, -h, w * 0.82, w * 0.22, '#5c4b6b');
        sword(ctx, -w * 0.18, -h * 0.5, w * 0.24, -0.4);
        sword(ctx, w * 0.18, -h * 0.5, w * 0.24, 0.4);
        if (tier >= 3) { shield(ctx, 0, -h * 0.45, w * 0.18); }
    },

    research_hall(ctx, w, d, tier, t) {
        base(ctx, w, d, '#8f9bb0');
        const h = w * 0.5;
        block(ctx, 0, 0, w * 0.6, d * 0.5, h, '#e6ecf6');
        coneRoof(ctx, 0, -h, w * 0.74, w * 0.34, ROOF_PURPLE);
        windowLight(ctx, 0, -h * 0.62, w * 0.12, w * 0.14, true);
        // Kristall auf der Spitze
        const glow = 0.5 + Math.sin(t * 2) * 0.5;
        ctx.globalAlpha = 0.35 + glow * 0.4;
        ellipse(ctx, 0, -h - w * 0.4, w * 0.16, w * 0.16, '#6fd8ff');
        ctx.globalAlpha = 1;
        fillPoly(ctx, [[0, -h - w * 0.52], [w * 0.07, -h - w * 0.36], [0, -h - w * 0.26], [-w * 0.07, -h - w * 0.36]], '#6fd8ff');
        if (tier >= 3) {
            block(ctx, -w * 0.32, 0, w * 0.22, d * 0.35, h * 0.6, '#dfe6f2');
            coneRoof(ctx, -w * 0.32, -h * 0.6, w * 0.3, w * 0.18, ROOF_PURPLE);
        }
    },

    barracks(ctx, w, d, tier, t) {
        base(ctx, w, d, '#91856c');
        const h = w * 0.38;
        block(ctx, 0, 0, w * 0.78, d * 0.55, h, '#c2a888');
        gableRoof(ctx, 0, -h, w * 0.9, w * 0.2, '#6b4f35');
        door(ctx, 0, 0, w * 0.16, w * 0.2, '#523a26');
        for (let i = -1; i <= 1; i += 2) {
            windowLight(ctx, i * w * 0.26, -h * 0.6, w * 0.09, w * 0.09, tier > 0);
        }
        flag(ctx, w * 0.36, -h * 0.1, w * 0.1, '#ff5c6c', Math.sin(t * 2.6));
        if (tier >= 2) { shield(ctx, -w * 0.34, -h * 0.4, w * 0.16); }
    },

    market(ctx, w, d, tier, t) {
        base(ctx, w, d, '#a89a76');
        const h = w * 0.26;
        block(ctx, 0, 0, w * 0.66, d * 0.45, h, '#e5d7bb');
        // Markise
        const stripes = 5;
        for (let i = 0; i < stripes; i++) {
            ctx.fillStyle = i % 2 === 0 ? '#e2584f' : '#f5ece0';
            const sw = w * 0.86 / stripes;
            fillPoly(ctx, [
                [-w * 0.43 + i * sw, -h],
                [-w * 0.43 + (i + 1) * sw, -h],
                [-w * 0.43 + (i + 1) * sw - w * 0.03, -h - w * 0.16],
                [-w * 0.43 + i * sw - w * 0.03, -h - w * 0.16]
            ], ctx.fillStyle);
        }
        ellipse(ctx, -w * 0.18, -h * 0.35, w * 0.08, w * 0.05, '#ffc94a');
        ellipse(ctx, w * 0.06, -h * 0.35, w * 0.07, w * 0.05, '#6fbf5e');
        if (tier >= 2) { ellipse(ctx, w * 0.26, -h * 0.35, w * 0.07, w * 0.05, '#e2604f'); }
    },

    // ---------------------------------------------------------------
    // Verteidigung
    // ---------------------------------------------------------------
    wall(ctx, w, d, tier) {
        base(ctx, w, d, '#8e8e94');
        const h = w * (0.34 + tier * 0.03);
        block(ctx, 0, 0, w * 0.86, d * 0.45, h, '#bdbcb4');
        battlements(ctx, 0, -h, w * 0.86, '#a9a89f', 4 + Math.min(2, tier));
        ctx.strokeStyle = alpha('#7d7c74', 0.6);
        ctx.lineWidth = Math.max(1, w * 0.012);
        for (let i = 1; i < 3; i++) {
            ctx.beginPath();
            ctx.moveTo(-w * 0.43, -h * (i / 3));
            ctx.lineTo(w * 0.43, -h * (i / 3));
            ctx.stroke();
        }
    },

    tower(ctx, w, d, tier, t) {
        base(ctx, w, d, '#8e8e94');
        const h = w * (0.64 + tier * 0.05);
        block(ctx, 0, 0, w * 0.44, d * 0.4, h, '#cdc9bd');
        battlements(ctx, 0, -h, w * 0.5, '#b4b0a4', 3);
        windowLight(ctx, 0, -h * 0.55, w * 0.08, w * 0.12, true);
        if (tier >= 1) { coneRoof(ctx, 0, -h - w * 0.06, w * 0.56, w * 0.26, ROOF_BLUE); }
        if (tier >= 3) { flag(ctx, 0, -h - w * 0.3, w * 0.08, '#3f8cff', Math.sin(t * 3)); }
    },

    patrol_post(ctx, w, d, tier, t) {
        base(ctx, w, d, '#8f9a72');
        const h = w * 0.32;
        block(ctx, 0, 0, w * 0.4, d * 0.35, h, WOOD);
        gableRoof(ctx, 0, -h, w * 0.56, w * 0.16, '#6b4f35');
        // Wache
        const bob = Math.sin(t * 1.5) * w * 0.012;
        ellipse(ctx, w * 0.26, -w * 0.12 + bob, w * 0.05, w * 0.05, '#f0c9a0');
        ctx.fillStyle = '#3f6fbf';
        ctx.fillRect(w * 0.22, -w * 0.09 + bob, w * 0.08, w * 0.12);
        ctx.strokeStyle = '#8a6a48';
        ctx.lineWidth = Math.max(1, w * 0.02);
        ctx.beginPath();
        ctx.moveTo(w * 0.32, 0);
        ctx.lineTo(w * 0.32, -w * 0.3);
        ctx.stroke();
    },

    // ---------------------------------------------------------------
    // Bauerninsel
    // ---------------------------------------------------------------
    grain_farm(ctx, w, d, tier, t) {
        base(ctx, w, d, '#a58b52');
        const rows = 4;
        for (let r = 0; r < rows; r++) {
            const y = -d * 0.14 + r * d * 0.1;
            for (let i = 0; i < 7; i++) {
                const x = -w * 0.38 + i * w * 0.13;
                const sway = Math.sin(t * 1.6 + i * 0.7 + r) * w * 0.012;
                ctx.strokeStyle = '#c9a63f';
                ctx.lineWidth = Math.max(1, w * 0.016);
                ctx.beginPath();
                ctx.moveTo(x, y);
                ctx.lineTo(x + sway, y - w * 0.16);
                ctx.stroke();
                ellipse(ctx, x + sway, y - w * 0.19, w * 0.026, w * 0.045, '#e0b551');
            }
        }
        if (tier >= 2) {
            ctx.fillStyle = '#8a6a48';
            ctx.fillRect(-w * 0.46, -w * 0.1, w * 0.04, w * 0.12);
            ctx.fillRect(w * 0.42, -w * 0.1, w * 0.04, w * 0.12);
        }
    },

    vegetable_farm(ctx, w, d, tier, t) {
        base(ctx, w, d, '#8b7a4f');
        for (let r = 0; r < 3; r++) {
            for (let i = 0; i < 6; i++) {
                const x = -w * 0.36 + i * w * 0.145;
                const y = -d * 0.1 + r * d * 0.11;
                bush(ctx, x, y, w * 0.13, r % 2 ? '#6fbf5e' : '#57ad4c');
            }
        }
    },

    orchard(ctx, w, d, tier, t) {
        base(ctx, w, d, '#7fa869');
        const positions = [[-0.28, -0.1], [0.05, -0.16], [0.3, -0.02], [-0.08, 0.08]];
        positions.forEach(([px, py], index) => {
            const sway = Math.sin(t * 1.2 + index) * w * 0.008;
            tree(ctx, w * px + sway, d * py, w * (0.42 + tier * 0.02), index % 2 ? '#4fae57' : '#62bd63');
            if (tier >= 1) {
                ellipse(ctx, w * px + sway + w * 0.06, d * py - w * 0.3, w * 0.03, w * 0.03, '#e2604f');
            }
        });
    },

    pasture(ctx, w, d, tier, t) {
        base(ctx, w, d, '#7fb765');
        // Zaun
        ctx.strokeStyle = '#a5824f';
        ctx.lineWidth = Math.max(1, w * 0.018);
        for (let i = 0; i <= 6; i++) {
            const x = -w * 0.42 + i * w * 0.14;
            ctx.beginPath();
            ctx.moveTo(x, d * 0.12);
            ctx.lineTo(x, d * 0.12 - w * 0.12);
            ctx.stroke();
        }
        ctx.beginPath();
        ctx.moveTo(-w * 0.42, d * 0.12 - w * 0.08);
        ctx.lineTo(w * 0.42, d * 0.12 - w * 0.08);
        ctx.stroke();

        const walk = Math.sin(t * 0.7) * w * 0.12;
        cow(ctx, -w * 0.1 + walk, -d * 0.04, w * 0.22);
        if (tier >= 2) { cow(ctx, w * 0.22 - walk * 0.6, -d * 0.12, w * 0.18); }
    },

    horse_ranch(ctx, w, d, tier, t) {
        base(ctx, w, d, '#8fae6a');
        const h = w * 0.3;
        block(ctx, -w * 0.24, -d * 0.06, w * 0.4, d * 0.4, h, WOOD);
        gableRoof(ctx, -w * 0.24, -d * 0.06 - h, w * 0.52, w * 0.18, '#7c5f3e');
        const walk = Math.sin(t * 0.9) * w * 0.14;
        horse(ctx, w * 0.18 + walk, d * 0.04, w * 0.26);
        if (tier >= 2) { horse(ctx, w * 0.3 - walk * 0.7, -d * 0.1, w * 0.2); }
    },

    mill(ctx, w, d, tier, t) {
        base(ctx, w, d, '#9c8a6a');
        const h = w * (0.5 + tier * 0.03);
        // Turm
        fillPoly(ctx, [
            [-w * 0.2, 0], [w * 0.2, 0], [w * 0.14, -h], [-w * 0.14, -h]
        ], '#e5d7bb');
        fillPoly(ctx, [[w * 0.2, 0], [w * 0.14, -h], [w * 0.04, -h], [w * 0.08, 0]], shade('#e5d7bb', -0.16));
        coneRoof(ctx, 0, -h, w * 0.36, w * 0.18, '#7c5f3e');
        door(ctx, 0, 0, w * 0.1, w * 0.14);

        // Flügel drehen sich
        const angle = t * (0.9 + tier * 0.12);
        const cx = 0;
        const cy = -h - w * 0.02;
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(angle);
        for (let i = 0; i < 4; i++) {
            ctx.rotate(Math.PI / 2);
            ctx.fillStyle = '#f2e6cd';
            ctx.fillRect(w * 0.03, -w * 0.02, w * 0.3, w * 0.07);
            ctx.strokeStyle = '#8a6a48';
            ctx.lineWidth = Math.max(1, w * 0.012);
            ctx.strokeRect(w * 0.03, -w * 0.02, w * 0.3, w * 0.07);
        }
        ctx.restore();
        ellipse(ctx, cx, cy, w * 0.035, w * 0.035, '#6b5744');
    },

    bakery(ctx, w, d, tier, t) {
        base(ctx, w, d, '#9c8a6a');
        const h = w * 0.34;
        block(ctx, 0, 0, w * 0.6, d * 0.45, h, '#f0dcc0');
        gableRoof(ctx, 0, -h, w * 0.74, w * 0.2, '#c07a3f');
        door(ctx, -w * 0.1, 0, w * 0.13, w * 0.18);
        ellipse(ctx, w * 0.14, -h * 0.5, w * 0.1, w * 0.07, '#d8a05a');
        ctx.fillStyle = '#9a7b5a';
        ctx.fillRect(w * 0.2, -h - w * 0.18, w * 0.07, w * 0.2);
        smoke(ctx, w * 0.235, -h - w * 0.18, w * 0.1, t);
    },

    // ---------------------------------------------------------------
    // Rohstoffinsel
    // ---------------------------------------------------------------
    lumberjack(ctx, w, d, tier, t) {
        base(ctx, w, d, '#8a7b55');
        const h = w * 0.28;
        block(ctx, -w * 0.18, 0, w * 0.38, d * 0.4, h, WOOD);
        gableRoof(ctx, -w * 0.18, -h, w * 0.5, w * 0.16, '#6b4f35');
        // Stapel
        for (let i = 0; i < 3; i++) {
            ellipse(ctx, w * 0.26, -w * 0.05 - i * w * 0.07, w * 0.11, w * 0.05, '#c89055');
            ellipse(ctx, w * 0.26, -w * 0.05 - i * w * 0.07, w * 0.05, w * 0.025, '#8a5a2c');
        }
        tree(ctx, w * 0.42, d * 0.1, w * 0.36);
        // Axtschlag
        const swing = Math.sin(t * 2.4) * 0.5;
        ctx.save();
        ctx.translate(w * 0.06, -w * 0.02);
        ctx.rotate(swing);
        ctx.strokeStyle = '#8a6a48';
        ctx.lineWidth = Math.max(1, w * 0.02);
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.lineTo(w * 0.12, -w * 0.1);
        ctx.stroke();
        ctx.fillStyle = '#9aa6b3';
        ctx.fillRect(w * 0.1, -w * 0.14, w * 0.07, w * 0.05);
        ctx.restore();
    },

    quarry(ctx, w, d, tier, t) {
        base(ctx, w, d, '#9a9a90');
        ellipse(ctx, 0, -d * 0.02, w * 0.36, d * 0.26, '#7e7e75');
        ellipse(ctx, 0, -d * 0.02, w * 0.28, d * 0.2, '#6c6c64');
        rock(ctx, -w * 0.3, d * 0.08, w * 0.3);
        rock(ctx, w * 0.3, d * 0.04, w * 0.34);
        rock(ctx, w * 0.06, -d * 0.16, w * 0.24, '#b3bcc6');
        // Hebel
        const swing = Math.sin(t * 1.8) * 0.3;
        ctx.save();
        ctx.translate(-w * 0.06, -w * 0.02);
        ctx.rotate(swing);
        ctx.strokeStyle = '#8a6a48';
        ctx.lineWidth = Math.max(1, w * 0.022);
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.lineTo(w * 0.16, -w * 0.12);
        ctx.stroke();
        ctx.restore();
    },

    iron_mine(ctx, w, d, tier, t)   { mine(ctx, w, d, tier, t, '#7d8894', '#5c6672'); },
    copper_mine(ctx, w, d, tier, t) { mine(ctx, w, d, tier, t, '#c87f4a', '#a9622f'); },
    coal_mine(ctx, w, d, tier, t)   { mine(ctx, w, d, tier, t, '#4a4a55', '#33333d'); },

    crystal_mine(ctx, w, d, tier, t) {
        base(ctx, w, d, '#8a92a8');
        ellipse(ctx, 0, -d * 0.04, w * 0.34, d * 0.24, '#5f6880');
        const glow = 0.45 + Math.sin(t * 1.8) * 0.3;
        [[-0.2, 0.02, 0.3], [0.08, -0.08, 0.42], [0.26, 0.06, 0.26]].forEach(([px, py, size], index) => {
            const pulse = 0.4 + Math.sin(t * 2 + index) * 0.3;
            ctx.globalAlpha = 0.25 * pulse + 0.1;
            ellipse(ctx, w * px, d * py - w * size * 0.4, w * size * 0.6, w * size * 0.6, '#6fd8ff');
            ctx.globalAlpha = 1;
            fillPoly(ctx, [
                [w * px, d * py - w * size], [w * px + w * size * 0.22, d * py - w * size * 0.4],
                [w * px, d * py], [w * px - w * size * 0.22, d * py - w * size * 0.4]
            ], '#6fd8ff');
            fillPoly(ctx, [
                [w * px, d * py - w * size], [w * px + w * size * 0.22, d * py - w * size * 0.4],
                [w * px, d * py]
            ], '#3eb6e6');
        });
        ctx.globalAlpha = glow * 0.2;
        ellipse(ctx, 0, -w * 0.2, w * 0.5, w * 0.3, '#6fd8ff');
        ctx.globalAlpha = 1;
    },

    aether_well(ctx, w, d, tier, t) {
        base(ctx, w, d, '#7f7192');
        ellipse(ctx, 0, 0, w * 0.3, d * 0.22, '#4a3f5e');
        for (let i = 0; i < 5; i++) {
            const phase = (t * 0.5 + i / 5) % 1;
            ctx.globalAlpha = (1 - phase) * 0.8;
            ellipse(ctx, Math.sin(t + i) * w * 0.08, -phase * w * 0.6, w * 0.05 * (1 - phase * 0.4), w * 0.05 * (1 - phase * 0.4), '#c79bff');
        }
        ctx.globalAlpha = 1;
        for (let i = 0; i < 4; i++) {
            const angle = (i / 4) * Math.PI * 2;
            fillPoly(ctx, [
                [Math.cos(angle) * w * 0.26, Math.sin(angle) * d * 0.18 - w * 0.02],
                [Math.cos(angle) * w * 0.3, Math.sin(angle) * d * 0.2 - w * 0.24],
                [Math.cos(angle) * w * 0.22, Math.sin(angle) * d * 0.16 - w * 0.22]
            ], '#8f7fb0');
        }
    },

    // ---------------------------------------------------------------
    // Lagerinsel
    // ---------------------------------------------------------------
    warehouse(ctx, w, d, tier, t)   { storeHouse(ctx, w, d, tier, '#c2a888', '#7c5f3e', '#a9743f'); },
    granary(ctx, w, d, tier, t)     { storeHouse(ctx, w, d, tier, '#e5d7bb', '#c07a3f', '#e0b551'); },
    goods_store(ctx, w, d, tier, t) { storeHouse(ctx, w, d, tier, '#b8c6d4', '#5a7089', '#8fb2c9'); },

    vault(ctx, w, d, tier, t) {
        base(ctx, w, d, '#8e8e94');
        const h = w * 0.42;
        block(ctx, 0, 0, w * 0.5, d * 0.42, h, '#b6b0a0');
        battlements(ctx, 0, -h, w * 0.54, '#9e9888', 3);
        ellipse(ctx, 0, -h * 0.45, w * 0.12, w * 0.12, '#ffc94a');
        ellipse(ctx, 0, -h * 0.45, w * 0.07, w * 0.07, '#d79f27');
        if (tier >= 2) { trim(ctx, 4, 0, -h * 0.02, w * 0.5); }
    },

    stable(ctx, w, d, tier, t) {
        base(ctx, w, d, '#8fae6a');
        const h = w * 0.3;
        block(ctx, 0, 0, w * 0.66, d * 0.45, h, WOOD);
        gableRoof(ctx, 0, -h, w * 0.8, w * 0.18, '#7c5f3e');
        for (let i = -1; i <= 1; i++) {
            ctx.fillStyle = '#5b4433';
            ctx.fillRect(i * w * 0.2 - w * 0.06, -h * 0.55, w * 0.12, h * 0.55);
        }
        horse(ctx, w * 0.36, d * 0.06, w * 0.2);
    },

    transport_office(ctx, w, d, tier, t) {
        base(ctx, w, d, '#a2937a');
        const h = w * 0.34;
        block(ctx, -w * 0.08, 0, w * 0.44, d * 0.4, h, '#ddd0b4');
        gableRoof(ctx, -w * 0.08, -h, w * 0.56, w * 0.18, ROOF_BLUE);
        windowLight(ctx, -w * 0.08, -h * 0.6, w * 0.1, w * 0.1, true);
        cart(ctx, w * 0.28, d * 0.06, w * 0.28, t);
        if (tier >= 3) { flag(ctx, -w * 0.32, -w * 0.02, w * 0.08, '#3f8cff', Math.sin(t * 2.6)); }
    },

    crane(ctx, w, d, tier, t) {
        base(ctx, w, d, '#98917f');
        const h = w * (0.6 + tier * 0.04);
        ctx.strokeStyle = '#8a6a48';
        ctx.lineWidth = Math.max(1.5, w * 0.035);
        ctx.beginPath();
        ctx.moveTo(-w * 0.1, 0);
        ctx.lineTo(-w * 0.1, -h);
        ctx.stroke();
        ctx.beginPath();
        ctx.moveTo(-w * 0.14, -h);
        ctx.lineTo(w * 0.34, -h * 0.86);
        ctx.stroke();

        const swing = Math.sin(t * 1.1) * w * 0.05;
        ctx.strokeStyle = '#6b5744';
        ctx.lineWidth = Math.max(1, w * 0.014);
        ctx.beginPath();
        ctx.moveTo(w * 0.3 + swing, -h * 0.86);
        ctx.lineTo(w * 0.3 + swing, -h * 0.42);
        ctx.stroke();
        ctx.fillStyle = '#c89055';
        ctx.fillRect(w * 0.22 + swing, -h * 0.42, w * 0.16, w * 0.13);
    }
};

// -------------------------------------------------------------------
// Bausteine, die mehrere Gebäude verwenden
// -------------------------------------------------------------------

function mine(ctx, w, d, tier, t, oreLight, oreDark) {
    base(ctx, w, d, '#8d8578');
    // Hang
    fillPoly(ctx, [[-w * 0.44, d * 0.06], [-w * 0.1, -w * 0.34], [w * 0.2, -w * 0.3], [w * 0.44, d * 0.06]], '#7e7669');
    // Stolleneingang
    ctx.fillStyle = '#2f2a24';
    ctx.beginPath();
    ctx.moveTo(-w * 0.14, d * 0.02);
    ctx.lineTo(-w * 0.14, -w * 0.14);
    ctx.arc(0, -w * 0.14, w * 0.14, Math.PI, 0);
    ctx.lineTo(w * 0.14, d * 0.02);
    ctx.closePath();
    ctx.fill();
    // Stützbalken
    ctx.fillStyle = '#8a6a48';
    ctx.fillRect(-w * 0.19, -w * 0.18, w * 0.05, w * 0.2);
    ctx.fillRect(w * 0.14, -w * 0.18, w * 0.05, w * 0.2);
    ctx.fillRect(-w * 0.2, -w * 0.22, w * 0.4, w * 0.05);
    // Loren
    const roll = (Math.sin(t * 0.9) * 0.5 + 0.5) * w * 0.3;
    ctx.fillStyle = oreDark;
    ctx.fillRect(w * 0.06 + roll, -w * 0.06, w * 0.14, w * 0.09);
    ellipse(ctx, w * 0.1 + roll, w * 0.035, w * 0.02, w * 0.02, '#4a4a55');
    ellipse(ctx, w * 0.17 + roll, w * 0.035, w * 0.02, w * 0.02, '#4a4a55');
    ellipse(ctx, w * 0.13 + roll, -w * 0.07, w * 0.05, w * 0.025, oreLight);
    if (tier >= 2) { rock(ctx, -w * 0.34, d * 0.08, w * 0.26, oreLight); }
}

function storeHouse(ctx, w, d, tier, wallColor, roofColor, crateColor) {
    base(ctx, w, d, '#a2937a');
    const h = w * (0.34 + tier * 0.025);
    block(ctx, 0, 0, w * 0.76, d * 0.52, h, wallColor);
    gableRoof(ctx, 0, -h, w * 0.9, w * 0.22, roofColor);
    ctx.fillStyle = shade(wallColor, -0.2);
    ctx.fillRect(-w * 0.14, -h * 0.62, w * 0.28, h * 0.62);
    ctx.strokeStyle = alpha('#000000', 0.2);
    ctx.lineWidth = Math.max(1, w * 0.012);
    ctx.strokeRect(-w * 0.14, -h * 0.62, w * 0.28, h * 0.62);
    // Kisten davor
    ctx.fillStyle = crateColor;
    ctx.fillRect(w * 0.26, -w * 0.12, w * 0.13, w * 0.12);
    ctx.fillRect(w * 0.3, -w * 0.24, w * 0.1, w * 0.1);
    ctx.strokeStyle = alpha(shade(crateColor, -0.4), 0.6);
    ctx.strokeRect(w * 0.26, -w * 0.12, w * 0.13, w * 0.12);
    if (tier >= 3) {
        ctx.fillStyle = crateColor;
        ctx.fillRect(-w * 0.4, -w * 0.1, w * 0.11, w * 0.1);
    }
}

function gear(ctx, x, y, r, angle) {
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(angle);
    ctx.fillStyle = '#9a8a6a';
    for (let i = 0; i < 8; i++) {
        ctx.rotate(Math.PI / 4);
        ctx.fillRect(-r * 0.16, -r * 1.25, r * 0.32, r * 0.42);
    }
    ellipse(ctx, 0, 0, r, r, '#b3a284');
    ellipse(ctx, 0, 0, r * 0.4, r * 0.4, '#7c6e54');
    ctx.restore();
}

function smoke(ctx, x, y, size, t) {
    for (let i = 0; i < 3; i++) {
        const phase = (t * 0.45 + i / 3) % 1;
        ctx.globalAlpha = (1 - phase) * 0.5;
        ellipse(ctx, x + Math.sin(phase * 4 + i) * size * 0.5, y - phase * size * 3,
            size * (0.4 + phase * 0.8), size * (0.35 + phase * 0.7), '#e8ecf2');
    }
    ctx.globalAlpha = 1;
}

function anvil(ctx, x, y, size) {
    ctx.fillStyle = '#5c6672';
    ctx.fillRect(x - size * 0.2, y - size * 0.22, size * 0.4, size * 0.12);
    fillPoly(ctx, [
        [x - size * 0.45, y - size * 0.5], [x + size * 0.45, y - size * 0.5],
        [x + size * 0.25, y - size * 0.3], [x - size * 0.25, y - size * 0.3]
    ], '#7d8894');
}

function sword(ctx, x, y, size, tilt) {
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(tilt);
    fillPoly(ctx, [[0, -size * 0.7], [size * 0.09, -size * 0.2], [-size * 0.09, -size * 0.2]], '#c9d6e2');
    ctx.fillStyle = '#9c6b8f';
    ctx.fillRect(-size * 0.16, -size * 0.2, size * 0.32, size * 0.07);
    ctx.fillStyle = '#6d4a63';
    ctx.fillRect(-size * 0.05, -size * 0.13, size * 0.1, size * 0.2);
    ctx.restore();
}

function shield(ctx, x, y, size) {
    ctx.fillStyle = '#bfc8d4';
    ctx.beginPath();
    ctx.moveTo(x - size * 0.5, y - size * 0.6);
    ctx.lineTo(x + size * 0.5, y - size * 0.6);
    ctx.lineTo(x + size * 0.5, y - size * 0.1);
    ctx.quadraticCurveTo(x, y + size * 0.4, x - size * 0.5, y - size * 0.1);
    ctx.closePath();
    ctx.fill();
    ctx.fillStyle = '#3f8cff';
    ctx.fillRect(x - size * 0.1, y - size * 0.6, size * 0.2, size * 0.7);
}

function cow(ctx, x, y, size) {
    ellipse(ctx, x, y - size * 0.3, size * 0.42, size * 0.28, '#f4efe6');
    ellipse(ctx, x - size * 0.12, y - size * 0.34, size * 0.14, size * 0.1, '#4a4a55');
    ellipse(ctx, x + size * 0.34, y - size * 0.42, size * 0.18, size * 0.16, '#f4efe6');
    ctx.fillStyle = '#c9c2b6';
    ctx.fillRect(x - size * 0.28, y - size * 0.1, size * 0.08, size * 0.12);
    ctx.fillRect(x + size * 0.16, y - size * 0.1, size * 0.08, size * 0.12);
}

function horse(ctx, x, y, size) {
    ctx.fillStyle = '#a8763f';
    ellipse(ctx, x, y - size * 0.45, size * 0.45, size * 0.26, '#a8763f');
    ctx.fillRect(x - size * 0.3, y - size * 0.3, size * 0.1, size * 0.3);
    ctx.fillRect(x + size * 0.18, y - size * 0.3, size * 0.1, size * 0.3);
    fillPoly(ctx, [
        [x + size * 0.3, y - size * 0.55], [x + size * 0.58, y - size * 0.95],
        [x + size * 0.7, y - size * 0.8], [x + size * 0.42, y - size * 0.42]
    ], '#a8763f');
    ctx.fillStyle = '#6b4a28';
    ctx.fillRect(x + size * 0.2, y - size * 0.8, size * 0.1, size * 0.28);
}

function cart(ctx, x, y, size, t) {
    const roll = Math.sin(t * 0.8) * size * 0.06;
    ctx.fillStyle = '#c89055';
    ctx.fillRect(x - size * 0.4 + roll, y - size * 0.45, size * 0.8, size * 0.3);
    ctx.strokeStyle = alpha('#6b4a28', 0.7);
    ctx.lineWidth = Math.max(1, size * 0.04);
    ctx.strokeRect(x - size * 0.4 + roll, y - size * 0.45, size * 0.8, size * 0.3);
    ellipse(ctx, x - size * 0.22 + roll, y - size * 0.1, size * 0.13, size * 0.13, '#6b5744');
    ellipse(ctx, x + size * 0.22 + roll, y - size * 0.1, size * 0.13, size * 0.13, '#6b5744');
}

/**
 * Gebäude zeichnen.
 * (0|0) ist die Mitte der Grundfläche, w/d sind Breite und Tiefe in Pixeln.
 */
export function drawBuilding(ctx, type, w, d, tier, time) {
    const spec = SPECS[type];
    ctx.save();
    if (spec) {
        spec(ctx, w, d, Math.max(0, Math.min(6, tier)), time);
    } else {
        // Unbekannter Typ: schlichtes, aber ansehnliches Haus statt leerem Kasten
        SPECS.house(ctx, w, d, tier, time);
    }
    ctx.restore();
}

export function hasSprite(type) {
    return Object.prototype.hasOwnProperty.call(SPECS, type);
}
