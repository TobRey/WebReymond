/**
 * Der Weltzeichner: schwebende Inseln, Brücken, Gebäude und sichtbare Transporte.
 *
 * Aufbau in Ebenen: Himmel → Wolken → Inseln → Brücken → Gebäude → Figuren →
 * Auswahl und Bauraster. Inselkörper werden einmal vorgezeichnet und dann als
 * Bild gesetzt; das hält die Bildrate auch auf schwächeren Handys hoch.
 */

import { alpha, ellipse, fillPoly, shade, shadow, tree, bush, rock } from './draw.js';
import { drawBuilding } from './buildings.js';
import { state, buildingsOn } from '../core/state.js';

export const CELL = 46;          // Weltpixel je Rasterfeld
export const SQUASH = 0.58;      // Stauchung in der Höhe (leichte Perspektive)
export const SPACING = 620;      // Abstand der Inselplätze

const QUALITY = { high: 1, medium: 2, low: 3 };

export class WorldRenderer {
    constructor(canvas, camera) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d', { alpha: false });
        this.camera = camera;
        this.time = 0;
        this.quality = 1;
        this.animations = true;
        this.islandCache = new Map();
        this.clouds = [];
        this.particles = [];
        this.agents = new Map();
        this.selected = null;
        this.buildGhost = null;
        this.highlightRoute = null;
        this.dayNight = 0;
        this.buildMode = false;
    }

    setQuality(level) {
        this.quality = QUALITY[level] || 1;
        this.islandCache.clear();
    }

    setAnimations(on) {
        this.animations = !!on;
    }

    // ---------------------------------------------------------------
    // Inselgeometrie
    // ---------------------------------------------------------------

    /** Mittelpunkt einer Insel in Weltkoordinaten. */
    islandCenter(island) {
        const slots = (state.statics && state.statics.slots) || [[0, 0]];
        const slot = slots[island.slot % slots.length] || [0, 0];
        return { x: slot[0] * SPACING, y: slot[1] * SPACING };
    }

    /** Weltposition eines Rasterfelds. */
    cellToWorld(island, cx, cy) {
        const def = state.statics.islandTypes[island.type];
        const [cols, rows] = def.grid;
        const center = this.islandCenter(island);
        return {
            x: center.x + (cx - cols / 2 + 0.5) * CELL,
            y: center.y + (cy - rows / 2 + 0.5) * CELL * SQUASH
        };
    }

    /** Rasterfeld unter einem Weltpunkt (oder null). */
    worldToCell(island, wx, wy) {
        const def = state.statics.islandTypes[island.type];
        const [cols, rows] = def.grid;
        const center = this.islandCenter(island);
        const cx = Math.floor((wx - center.x) / CELL + cols / 2);
        const cy = Math.floor((wy - center.y) / (CELL * SQUASH) + rows / 2);
        if (cx < 0 || cy < 0 || cx >= cols || cy >= rows) { return null; }
        return { x: cx, y: cy };
    }

    isBuildable(island, cx, cy) {
        const def = state.statics.islandTypes[island.type];
        const row = def.mask[cy];
        return !!row && row[cx] === '#';
    }

    /** Umriss der Insel aus der Maske (oben herum, unten zurück). */
    outline(def) {
        const [cols, rows] = def.grid;
        const top = [];
        const bottom = [];
        for (let x = 0; x < cols; x++) {
            let first = -1;
            let last = -1;
            for (let y = 0; y < rows; y++) {
                if (def.mask[y] && def.mask[y][x] === '#') {
                    if (first < 0) { first = y; }
                    last = y;
                }
            }
            if (first < 0) { continue; }
            const px = (x - cols / 2 + 0.5) * CELL;
            top.push([px, (first - rows / 2) * CELL * SQUASH]);
            bottom.push([px, (last - rows / 2 + 1) * CELL * SQUASH]);
        }
        return { top, bottom };
    }

    // ---------------------------------------------------------------
    // Inselkörper vorzeichnen
    // ---------------------------------------------------------------

    islandSprite(island) {
        const key = island.id + '|' + island.type + '|' + this.quality;
        if (this.islandCache.has(key)) { return this.islandCache.get(key); }

        const def = state.statics.islandTypes[island.type];
        const [cols, rows] = def.grid;
        const w = cols * CELL;
        const h = rows * CELL * SQUASH;
        const depth = 150;
        const pad = 40;

        const canvas = document.createElement('canvas');
        const scale = this.quality === 1 ? 1 : (this.quality === 2 ? 0.75 : 0.55);
        canvas.width = Math.ceil((w + pad * 2) * scale);
        canvas.height = Math.ceil((h + depth + pad * 2) * scale);

        const g = canvas.getContext('2d');
        g.scale(scale, scale);
        g.translate(w / 2 + pad, h / 2 + pad);

        const { top, bottom } = this.outline(def);
        const tint = def.tint || '#7ec97a';

        // --- Felskörper -------------------------------------------------
        const rockPath = [];
        top.forEach((p) => rockPath.push([p[0], p[1] + 10]));
        for (let i = bottom.length - 1; i >= 0; i--) { rockPath.push(bottom[i]); }

        const lowest = Math.max(...bottom.map((p) => p[1]));
        const centerX = 0;

        g.save();
        const rockGradient = g.createLinearGradient(0, 0, 0, lowest + depth);
        rockGradient.addColorStop(0, '#9a7f63');
        rockGradient.addColorStop(0.45, '#6f5842');
        rockGradient.addColorStop(1, '#43321f');
        g.fillStyle = rockGradient;
        g.beginPath();
        g.moveTo(rockPath[0][0], rockPath[0][1]);
        for (let i = 1; i < rockPath.length; i++) {
            const mid = [(rockPath[i - 1][0] + rockPath[i][0]) / 2, (rockPath[i - 1][1] + rockPath[i][1]) / 2];
            g.quadraticCurveTo(rockPath[i - 1][0], rockPath[i - 1][1], mid[0], mid[1]);
        }
        // Spitze nach unten
        g.lineTo(centerX + CELL * 0.6, lowest + depth * 0.55);
        g.lineTo(centerX, lowest + depth);
        g.lineTo(centerX - CELL * 0.8, lowest + depth * 0.5);
        g.closePath();
        g.fill();

        // Felsstruktur
        g.globalAlpha = 0.22;
        g.strokeStyle = '#2e2214';
        g.lineWidth = 3;
        for (let i = 0; i < 6; i++) {
            const sx = -w * 0.32 + i * w * 0.13;
            g.beginPath();
            g.moveTo(sx, lowest * 0.55);
            g.quadraticCurveTo(sx + 12, lowest + depth * 0.3, centerX * 0.4, lowest + depth * 0.75);
            g.stroke();
        }
        g.globalAlpha = 1;
        g.restore();

        // --- Wiese -------------------------------------------------------
        const grassPath = [];
        top.forEach((p) => grassPath.push(p));
        for (let i = bottom.length - 1; i >= 0; i--) { grassPath.push([bottom[i][0], bottom[i][1] + 6]); }

        const grassGradient = g.createLinearGradient(0, -h / 2, 0, h / 2);
        grassGradient.addColorStop(0, shade(tint, 0.18));
        grassGradient.addColorStop(1, shade(tint, -0.16));
        g.fillStyle = grassGradient;
        smoothPath(g, grassPath);
        g.fill();

        // Heller Rand oben
        g.strokeStyle = alpha(shade(tint, 0.4), 0.7);
        g.lineWidth = 4;
        g.beginPath();
        g.moveTo(top[0][0], top[0][1]);
        for (let i = 1; i < top.length; i++) {
            const mid = [(top[i - 1][0] + top[i][0]) / 2, (top[i - 1][1] + top[i][1]) / 2];
            g.quadraticCurveTo(top[i - 1][0], top[i - 1][1], mid[0], mid[1]);
        }
        g.stroke();

        // --- Dekoration (feste Verteilung je Insel) ----------------------
        if (this.quality < 3) {
            let seed = 0;
            for (let i = 0; i < island.id.length; i++) { seed += island.id.charCodeAt(i) * (i + 7); }
            const rnd = () => {
                seed = (seed * 1103515245 + 12345) & 0x7fffffff;
                return seed / 0x7fffffff;
            };
            for (let y = 0; y < rows; y++) {
                for (let x = 0; x < cols; x++) {
                    if (!def.mask[y] || def.mask[y][x] !== '#') { continue; }
                    const edge = x === 0 || y === 0 || x === cols - 1 || y === rows - 1
                        || !def.mask[y - 1] || def.mask[y - 1][x] !== '#'
                        || !def.mask[y + 1] || def.mask[y + 1][x] !== '#';
                    if (!edge || rnd() > 0.28) { continue; }
                    const px = (x - cols / 2 + 0.5) * CELL + (rnd() - 0.5) * CELL * 0.5;
                    const py = (y - rows / 2 + 0.5) * CELL * SQUASH + (rnd() - 0.5) * CELL * 0.3;
                    const pick = rnd();
                    if (pick < 0.45) { tree(g, px, py, CELL * (0.7 + rnd() * 0.5), shade(tint, -0.18)); }
                    else if (pick < 0.8) { bush(g, px, py, CELL * (0.5 + rnd() * 0.4), shade(tint, 0.05)); }
                    else { rock(g, px, py, CELL * (0.5 + rnd() * 0.3)); }
                }
            }
        }

        const sprite = {
            canvas,
            scale,
            offsetX: -(w / 2 + pad),
            offsetY: -(h / 2 + pad),
            width: canvas.width / scale,
            height: canvas.height / scale
        };
        this.islandCache.set(key, sprite);
        return sprite;
    }

    // ---------------------------------------------------------------
    // Hauptzeichnung
    // ---------------------------------------------------------------

    render(dt) {
        const ctx = this.ctx;
        if (this.animations) { this.time += dt; }

        const w = this.camera.width;
        const h = this.camera.height;

        this.drawSky(ctx, w, h);
        this.drawClouds(ctx, w, h, dt, -0.35);

        if (!state.world) { return; }

        const islands = Object.values(state.world.islands);

        // Brücken liegen hinter den Inseln
        this.drawBridges(ctx);

        // Inseln von hinten nach vorne
        islands.sort((a, b) => this.islandCenter(a).y - this.islandCenter(b).y);
        islands.forEach((island) => this.drawIsland(ctx, island));

        this.drawAgents(ctx, dt);
        this.drawClouds(ctx, w, h, dt, 0.9);
        this.drawParticles(ctx, dt);
    }

    drawSky(ctx, w, h) {
        const hour = new Date().getHours();
        const night = hour < 6 || hour >= 21;
        const dusk = (hour >= 19 && hour < 21) || (hour >= 6 && hour < 8);
        this.dayNight = night ? 1 : (dusk ? 0.5 : 0);

        const gradient = ctx.createLinearGradient(0, 0, 0, h);
        if (night) {
            gradient.addColorStop(0, '#132041');
            gradient.addColorStop(0.55, '#1d2f55');
            gradient.addColorStop(1, '#2c4470');
        } else if (dusk) {
            gradient.addColorStop(0, '#4a72b8');
            gradient.addColorStop(0.5, '#b57fa0');
            gradient.addColorStop(1, '#f0b878');
        } else {
            gradient.addColorStop(0, '#4fb8f5');
            gradient.addColorStop(0.55, '#8fd6fb');
            gradient.addColorStop(1, '#cdeeff');
        }
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, w, h);

        if (night) {
            ctx.fillStyle = '#ffffff';
            for (let i = 0; i < 60; i++) {
                const x = ((i * 8123) % 1000) / 1000 * w;
                const y = ((i * 4271) % 1000) / 1000 * h * 0.7;
                const twinkle = 0.3 + Math.sin(this.time * 1.5 + i) * 0.3;
                ctx.globalAlpha = Math.max(0.08, twinkle * 0.7);
                ctx.fillRect(x, y, 2, 2);
            }
            ctx.globalAlpha = 1;
        }
    }

    drawClouds(ctx, w, h, dt, depth) {
        if (this.clouds.length === 0) {
            const count = this.quality === 3 ? 4 : (this.quality === 2 ? 7 : 11);
            for (let i = 0; i < count; i++) {
                this.clouds.push({
                    x: Math.random() * 1600 - 800,
                    y: Math.random() * 1200 - 600,
                    s: 0.6 + Math.random() * 1.6,
                    v: 4 + Math.random() * 12,
                    d: Math.random() < 0.5 ? -0.35 : 0.9
                });
            }
        }

        ctx.save();
        this.clouds.forEach((cloud) => {
            if (Math.abs(cloud.d - depth) > 0.1) { return; }
            if (this.animations) { cloud.x += cloud.v * dt; }
            if (cloud.x > 1000) { cloud.x = -1000; cloud.y = Math.random() * 1200 - 600; }

            const parallax = depth < 0 ? 0.45 : 1.25;
            const screen = this.camera.worldToScreen(cloud.x, cloud.y);
            const px = (screen.x - w / 2) * parallax + w / 2;
            const py = (screen.y - h / 2) * parallax + h / 2;
            const radius = 40 * cloud.s * this.camera.zoom;
            if (px < -radius * 4 || px > w + radius * 4 || py < -radius * 4 || py > h + radius * 4) { return; }

            ctx.globalAlpha = depth < 0 ? 0.5 : 0.28;
            ctx.fillStyle = this.dayNight > 0.7 ? '#9fb4d8' : '#ffffff';
            ctx.beginPath();
            ctx.arc(px, py, radius, 0, Math.PI * 2);
            ctx.arc(px + radius * 0.85, py + radius * 0.18, radius * 0.72, 0, Math.PI * 2);
            ctx.arc(px - radius * 0.85, py + radius * 0.22, radius * 0.62, 0, Math.PI * 2);
            ctx.arc(px + radius * 0.12, py - radius * 0.5, radius * 0.6, 0, Math.PI * 2);
            ctx.fill();
        });
        ctx.restore();
        ctx.globalAlpha = 1;
    }

    drawIsland(ctx, island) {
        const center = this.islandCenter(island);
        const sprite = this.islandSprite(island);
        const screen = this.camera.worldToScreen(center.x + sprite.offsetX, center.y + sprite.offsetY);
        const zoom = this.camera.zoom;

        // Ausserhalb des Bildes? Nicht zeichnen.
        if (screen.x > this.camera.width || screen.y > this.camera.height
            || screen.x + sprite.width * zoom < 0 || screen.y + sprite.height * zoom < 0) {
            return;
        }

        ctx.drawImage(sprite.canvas, screen.x, screen.y, sprite.width * zoom, sprite.height * zoom);

        if (this.buildMode && island.id === state.island) {
            this.drawGrid(ctx, island);
        }

        // Gebäude: von hinten nach vorne
        const buildings = buildingsOn(island.id).slice().sort((a, b) => (a.y - b.y) || (a.x - b.x));
        buildings.forEach((building) => this.drawBuildingAt(ctx, island, building));

        // Inselname bei weiter Sicht
        if (zoom < 0.62) {
            const label = this.camera.worldToScreen(center.x, center.y - 30);
            ctx.font = '600 15px system-ui, sans-serif';
            ctx.textAlign = 'center';
            ctx.fillStyle = 'rgba(12,22,40,.75)';
            const textWidth = ctx.measureText(island.name).width + 18;
            ctx.beginPath();
            ctx.roundRect(label.x - textWidth / 2, label.y - 12, textWidth, 24, 12);
            ctx.fill();
            ctx.fillStyle = '#ffffff';
            ctx.fillText(island.name, label.x, label.y + 5);
            ctx.textAlign = 'left';
        }
    }

    drawGrid(ctx, island) {
        const def = state.statics.islandTypes[island.type];
        const [cols, rows] = def.grid;
        const zoom = this.camera.zoom;

        ctx.save();
        ctx.lineWidth = 1;
        for (let y = 0; y < rows; y++) {
            for (let x = 0; x < cols; x++) {
                if (!this.isBuildable(island, x, y)) { continue; }
                const world = this.cellToWorld(island, x, y);
                const p = this.camera.worldToScreen(world.x - CELL / 2, world.y - CELL * SQUASH / 2);
                const free = !this.occupied(island, x, y);
                ctx.strokeStyle = free ? 'rgba(255,255,255,.35)' : 'rgba(255,90,90,.35)';
                ctx.fillStyle = free ? 'rgba(255,255,255,.08)' : 'rgba(255,90,90,.10)';
                ctx.beginPath();
                ctx.rect(p.x, p.y, CELL * zoom, CELL * SQUASH * zoom);
                ctx.fill();
                ctx.stroke();
            }
        }
        ctx.restore();
    }

    occupied(island, cx, cy) {
        return buildingsOn(island.id).some((building) => {
            const def = state.statics.buildings[building.type];
            const [bw, bh] = def ? def.size : [1, 1];
            return cx >= building.x && cx < building.x + bw && cy >= building.y && cy < building.y + bh;
        });
    }

    drawBuildingAt(ctx, island, building) {
        const def = state.statics.buildings[building.type];
        if (!def) { return; }

        const [bw, bh] = def.size;
        const world = this.cellToWorld(island, building.x + (bw - 1) / 2, building.y + (bh - 1) / 2);
        if (!this.camera.isVisible(world.x, world.y, 160)) { return; }

        const screen = this.camera.worldToScreen(world.x, world.y + (bh * CELL * SQUASH) / 2);
        const zoom = this.camera.zoom;
        const width = bw * CELL * zoom;
        const depth = bh * CELL * SQUASH * zoom;

        const tier = milestoneTier(building.level);

        ctx.save();
        ctx.translate(screen.x, screen.y);
        drawBuilding(ctx, building.type, width, depth, tier, this.animations ? this.time : 0);

        // Schaden sichtbar machen
        if (building.damage > 0.01) {
            ctx.globalAlpha = Math.min(0.55, building.damage * 0.8);
            ctx.fillStyle = '#4a1f1f';
            ctx.beginPath();
            ctx.ellipse(0, -width * 0.2, width * 0.5, width * 0.4, 0, 0, Math.PI * 2);
            ctx.fill();
            ctx.globalAlpha = 1;
            ctx.fillStyle = '#ff5c6c';
            ctx.font = `700 ${Math.max(10, 12 * zoom)}px system-ui, sans-serif`;
            ctx.textAlign = 'center';
            ctx.fillText('beschädigt', 0, -width * 0.75);
            ctx.textAlign = 'left';
        }
        ctx.restore();

        // Auswahlring
        if (this.selected === building.id) {
            ctx.save();
            ctx.strokeStyle = '#ffc94a';
            ctx.lineWidth = 3;
            ctx.setLineDash([8, 6]);
            ctx.lineDashOffset = -this.time * 26;
            ctx.beginPath();
            ctx.ellipse(screen.x, screen.y, width * 0.56, depth * 0.62, 0, 0, Math.PI * 2);
            ctx.stroke();
            ctx.restore();
        }

        // Stufenanzeige
        if (zoom > 0.55) {
            const label = 'St. ' + building.level;
            ctx.font = `700 ${Math.max(9, 11 * Math.min(zoom, 1.4))}px system-ui, sans-serif`;
            const textWidth = ctx.measureText(label).width + 12;
            ctx.fillStyle = 'rgba(12,22,40,.78)';
            ctx.beginPath();
            ctx.roundRect(screen.x - textWidth / 2, screen.y + 4, textWidth, 17, 9);
            ctx.fill();
            ctx.fillStyle = tier >= 4 ? '#ffc94a' : '#ffffff';
            ctx.textAlign = 'center';
            ctx.fillText(label, screen.x, screen.y + 16);
            ctx.textAlign = 'left';
        }
    }

    // ---------------------------------------------------------------
    // Brücken
    // ---------------------------------------------------------------

    /** Halbmesser einer Insel in Richtung (dx|dy). */
    islandRadius(island, dx, dy) {
        const def = state.statics.islandTypes[island.type];
        const [cols, rows] = def.grid;
        const rx = (cols * CELL) / 2;
        const ry = (rows * CELL * SQUASH) / 2;
        const length = Math.hypot(dx, dy) || 1;
        const ux = dx / length;
        const uy = dy / length;
        const denominator = Math.sqrt((ux / rx) * (ux / rx) + (uy / ry) * (uy / ry)) || 1;
        return 1 / denominator;
    }

    /**
     * Anfangs- und Endpunkt einer Brücke – jeweils am Inselrand, damit die
     * Brücke die Insel nicht durchschneidet.
     */
    bridgeEnds(bridge) {
        const a = state.world.islands[bridge.a];
        const b = state.world.islands[bridge.b];
        if (!a || !b) { return null; }

        const ca = this.islandCenter(a);
        const cb = this.islandCenter(b);
        const dx = cb.x - ca.x;
        const dy = cb.y - ca.y;
        const length = Math.hypot(dx, dy) || 1;
        const ux = dx / length;
        const uy = dy / length;

        const ra = this.islandRadius(a, dx, dy) * 0.92;
        const rb = this.islandRadius(b, -dx, -dy) * 0.92;

        return {
            from: { x: ca.x + ux * ra, y: ca.y + uy * ra },
            to:   { x: cb.x - ux * rb, y: cb.y - uy * rb },
            center: { x: (ca.x + cb.x) / 2, y: (ca.y + cb.y) / 2 }
        };
    }

    drawBridges(ctx) {
        const rates = state.rates || {};
        Object.values(state.world.bridges).forEach((bridge) => {
            const ends = this.bridgeEnds(bridge);
            if (!ends) { return; }

            const p1 = this.camera.worldToScreen(ends.from.x, ends.from.y);
            const p2 = this.camera.worldToScreen(ends.to.x, ends.to.y);
            const zoom = this.camera.zoom;
            const info = (rates.bridges && rates.bridges[bridge.id]) || { factor: 1 };
            const jam = info.factor < 0.95;

            const width = Math.max(6, (10 + Math.min(bridge.level, 20) * 0.6) * zoom);

            ctx.save();
            ctx.lineCap = 'round';

            // Unterbau
            ctx.strokeStyle = 'rgba(58,42,26,.85)';
            ctx.lineWidth = width + 5 * zoom;
            ctx.beginPath();
            ctx.moveTo(p1.x, p1.y);
            ctx.lineTo(p2.x, p2.y);
            ctx.stroke();

            // Belag
            ctx.strokeStyle = bridge.damage > 0.01 ? '#8a5a4a' : '#b98a56';
            ctx.lineWidth = width;
            ctx.beginPath();
            ctx.moveTo(p1.x, p1.y);
            ctx.lineTo(p2.x, p2.y);
            ctx.stroke();

            // Planken
            const length = Math.hypot(p2.x - p1.x, p2.y - p1.y);
            const steps = Math.max(3, Math.floor(length / (14 * zoom)));
            ctx.strokeStyle = 'rgba(90,64,38,.55)';
            ctx.lineWidth = Math.max(1, 1.6 * zoom);
            for (let i = 1; i < steps; i++) {
                const t = i / steps;
                const x = p1.x + (p2.x - p1.x) * t;
                const y = p1.y + (p2.y - p1.y) * t;
                const nx = -(p2.y - p1.y) / length;
                const ny = (p2.x - p1.x) / length;
                ctx.beginPath();
                ctx.moveTo(x + nx * width / 2, y + ny * width / 2);
                ctx.lineTo(x - nx * width / 2, y - ny * width / 2);
                ctx.stroke();
            }

            // Stau sichtbar machen
            if (jam) {
                const pulse = 0.4 + Math.sin(this.time * 4) * 0.3;
                ctx.strokeStyle = `rgba(255,92,108,${pulse})`;
                ctx.lineWidth = width + 6 * zoom;
                ctx.beginPath();
                ctx.moveTo(p1.x, p1.y);
                ctx.lineTo(p2.x, p2.y);
                ctx.stroke();

                const mid = { x: (p1.x + p2.x) / 2, y: (p1.y + p2.y) / 2 };
                ctx.font = `700 ${Math.max(10, 12 * zoom)}px system-ui, sans-serif`;
                ctx.textAlign = 'center';
                ctx.fillStyle = 'rgba(120,20,32,.9)';
                const text = 'Stau ' + Math.round(info.factor * 100) + ' %';
                const tw = ctx.measureText(text).width + 14;
                ctx.beginPath();
                ctx.roundRect(mid.x - tw / 2, mid.y - 26, tw, 20, 10);
                ctx.fill();
                ctx.fillStyle = '#ffffff';
                ctx.fillText(text, mid.x, mid.y - 12);
                ctx.textAlign = 'left';
            }
            ctx.restore();
        });
    }

    // ---------------------------------------------------------------
    // Sichtbare Transporte
    // ---------------------------------------------------------------

    /** Weg einer Route als Punktliste in Weltkoordinaten. */
    routePath(route) {
        const world = state.world;
        const endpoint = (ref) => {
            if (ref && ref[0] === 'b') {
                const building = world.buildings[ref[1]];
                if (!building) { return null; }
                const island = world.islands[building.island];
                if (!island) { return null; }
                const def = state.statics.buildings[building.type];
                const [bw, bh] = def ? def.size : [1, 1];
                return this.cellToWorld(island, building.x + (bw - 1) / 2, building.y + (bh - 1) / 2);
            }
            // Lager: Mittelpunkt der Lagerinsel
            const storageIsland = Object.values(world.islands).find((i) => i.type === 'storage')
                || Object.values(world.islands)[0];
            return storageIsland ? this.islandCenter(storageIsland) : null;
        };

        const from = endpoint(route.src);
        const to = endpoint(route.dst);
        if (!from || !to) { return null; }

        const rates = (state.rates && state.rates.routes && state.rates.routes[route.id]) || null;
        const points = [from];
        if (rates && rates.path) {
            rates.path.forEach((bridgeId) => {
                const bridge = world.bridges[bridgeId];
                if (!bridge) { return; }
                const ends = this.bridgeEnds(bridge);
                if (ends) {
                    points.push({ x: (ends.from.x + ends.to.x) / 2, y: (ends.from.y + ends.to.y) / 2 });
                }
            });
        }
        points.push(to);
        return points;
    }

    drawAgents(ctx, dt) {
        const rates = (state.rates && state.rates.routes) || {};
        const zoom = this.camera.zoom;
        const maxAgents = this.quality === 3 ? 12 : (this.quality === 2 ? 26 : 48);
        let drawn = 0;

        Object.values(state.world.routes).forEach((route) => {
            const info = rates[route.id];
            if (!info || !info.ok || info.flow <= 0) { return; }

            const path = this.routePath(route);
            if (!path || path.length < 2) { return; }

            let entry = this.agents.get(route.id);
            const count = Math.max(1, Math.min(route.carriers || 1, 6));
            if (!entry || entry.count !== count) {
                entry = { count, offsets: [] };
                for (let i = 0; i < count; i++) { entry.offsets.push(i / count); }
                this.agents.set(route.id, entry);
            }

            // Geschwindigkeit aus der echten Rundfahrtzeit
            const tripTime = Math.max(3, info.trip || 30);
            const speed = (1 / tripTime) * (info.jam || 1);

            entry.offsets.forEach((offset, index) => {
                if (this.animations) {
                    entry.offsets[index] = (offset + speed * dt) % 1;
                }
                if (drawn >= maxAgents) { return; }

                // Hin- und Rückweg
                const raw = entry.offsets[index];
                const forward = raw < 0.5;
                const t = forward ? raw * 2 : (1 - raw) * 2;
                const point = pointOnPath(path, t);
                if (!point || !this.camera.isVisible(point.x, point.y, 80)) { return; }

                const screen = this.camera.worldToScreen(point.x, point.y);
                drawn++;
                drawAgent(ctx, screen.x, screen.y, zoom, route.mode, forward, this.time + index, info.jam < 0.95);
            });
        });
    }

    // ---------------------------------------------------------------
    // Partikel (Bau, Ernte, Beute)
    // ---------------------------------------------------------------

    burst(worldX, worldY, color, count = 12) {
        if (!this.animations) { return; }
        for (let i = 0; i < count; i++) {
            const angle = (i / count) * Math.PI * 2 + Math.random();
            const speed = 40 + Math.random() * 90;
            this.particles.push({
                x: worldX, y: worldY,
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed - 40,
                life: 0.8 + Math.random() * 0.5,
                age: 0,
                color,
                size: 2 + Math.random() * 4
            });
        }
    }

    drawParticles(ctx, dt) {
        if (this.particles.length === 0) { return; }
        const zoom = this.camera.zoom;

        this.particles = this.particles.filter((p) => {
            p.age += dt;
            if (p.age >= p.life) { return false; }
            p.x += p.vx * dt;
            p.y += p.vy * dt;
            p.vy += 150 * dt;

            const screen = this.camera.worldToScreen(p.x, p.y);
            ctx.globalAlpha = 1 - (p.age / p.life);
            ctx.fillStyle = p.color;
            ctx.beginPath();
            ctx.arc(screen.x, screen.y, p.size * zoom, 0, Math.PI * 2);
            ctx.fill();
            return true;
        });
        ctx.globalAlpha = 1;
    }
}

// -------------------------------------------------------------------
// Hilfen
// -------------------------------------------------------------------

/** Optische Ausbaustufe – gleiche Meilensteine wie auf dem Server. */
export function milestoneTier(level) {
    const milestones = (state.statics && state.statics.milestones) || [10, 25, 50, 100, 250, 500];
    let tier = 0;
    milestones.forEach((m) => { if (level >= m) { tier++; } });
    return tier;
}

/** Geschlossener Weg mit weichen Ecken. */
function smoothPath(ctx, points) {
    ctx.beginPath();
    if (points.length < 3) {
        ctx.moveTo(points[0][0], points[0][1]);
        points.forEach((p) => ctx.lineTo(p[0], p[1]));
        ctx.closePath();
        return;
    }

    const first = [(points[points.length - 1][0] + points[0][0]) / 2,
                   (points[points.length - 1][1] + points[0][1]) / 2];
    ctx.moveTo(first[0], first[1]);
    for (let i = 0; i < points.length; i++) {
        const current = points[i];
        const next = points[(i + 1) % points.length];
        const mid = [(current[0] + next[0]) / 2, (current[1] + next[1]) / 2];
        ctx.quadraticCurveTo(current[0], current[1], mid[0], mid[1]);
    }
    ctx.closePath();
}

function pointOnPath(points, t) {
    if (points.length < 2) { return null; }
    let total = 0;
    const lengths = [];
    for (let i = 1; i < points.length; i++) {
        const l = Math.hypot(points[i].x - points[i - 1].x, points[i].y - points[i - 1].y);
        lengths.push(l);
        total += l;
    }
    if (total <= 0) { return points[0]; }

    let target = t * total;
    for (let i = 0; i < lengths.length; i++) {
        if (target <= lengths[i]) {
            const f = lengths[i] > 0 ? target / lengths[i] : 0;
            return {
                x: points[i].x + (points[i + 1].x - points[i].x) * f,
                y: points[i].y + (points[i + 1].y - points[i].y) * f
            };
        }
        target -= lengths[i];
    }
    return points[points.length - 1];
}

/** Träger, Pferd oder Wagen unterwegs. */
function drawAgent(ctx, x, y, zoom, mode, forward, time, jammed) {
    const size = Math.max(5, 12 * zoom);
    const bob = Math.sin(time * 9) * size * 0.1;
    const dir = forward ? 1 : -1;

    ctx.save();
    ctx.translate(x, y + bob);
    ctx.scale(dir, 1);

    // Schatten
    ctx.globalAlpha = 0.2;
    ctx.fillStyle = '#0d1526';
    ctx.beginPath();
    ctx.ellipse(0, size * 0.5, size * 0.42, size * 0.16, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.globalAlpha = 1;

    if (mode === 'horse' || mode === 'wagon') {
        ctx.fillStyle = '#a8763f';
        ctx.beginPath();
        ctx.ellipse(0, 0, size * 0.42, size * 0.24, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#8a5c2c';
        ctx.fillRect(size * 0.2, -size * 0.45, size * 0.12, size * 0.3);
        if (mode === 'wagon') {
            ctx.fillStyle = '#c89055';
            ctx.fillRect(-size * 0.85, -size * 0.3, size * 0.5, size * 0.34);
            ctx.fillStyle = '#6b5744';
            ctx.beginPath();
            ctx.arc(-size * 0.62, size * 0.16, size * 0.14, 0, Math.PI * 2);
            ctx.fill();
        }
    } else if (mode === 'airship' || mode === 'cableway' || mode === 'lift') {
        ctx.fillStyle = '#d8e4f0';
        ctx.beginPath();
        ctx.ellipse(0, -size * 0.3, size * 0.55, size * 0.28, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = '#8a6a48';
        ctx.fillRect(-size * 0.2, -size * 0.05, size * 0.4, size * 0.24);
    } else {
        // Träger zu Fuss (oder mit Karren)
        const step = Math.sin(time * 11) * size * 0.2;
        ctx.fillStyle = '#3f6fbf';
        ctx.fillRect(-size * 0.14, -size * 0.28, size * 0.28, size * 0.38);
        ctx.fillStyle = '#f0c9a0';
        ctx.beginPath();
        ctx.arc(0, -size * 0.4, size * 0.16, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = '#2e4f8a';
        ctx.lineWidth = Math.max(1, size * 0.09);
        ctx.beginPath();
        ctx.moveTo(-size * 0.06, size * 0.1);
        ctx.lineTo(-size * 0.06 + step, size * 0.42);
        ctx.moveTo(size * 0.06, size * 0.1);
        ctx.lineTo(size * 0.06 - step, size * 0.42);
        ctx.stroke();

        // Last auf dem Rücken
        ctx.fillStyle = mode === 'handcart' ? '#c89055' : '#a9743f';
        if (mode === 'handcart') {
            ctx.fillRect(-size * 0.6, -size * 0.2, size * 0.36, size * 0.26);
            ctx.fillStyle = '#6b5744';
            ctx.beginPath();
            ctx.arc(-size * 0.42, size * 0.12, size * 0.11, 0, Math.PI * 2);
            ctx.fill();
        } else {
            ctx.fillRect(-size * 0.3, -size * 0.34, size * 0.22, size * 0.24);
        }
    }

    if (jammed) {
        ctx.fillStyle = '#ff5c6c';
        ctx.beginPath();
        ctx.arc(0, -size * 0.8, size * 0.12, 0, Math.PI * 2);
        ctx.fill();
    }

    ctx.restore();
}
