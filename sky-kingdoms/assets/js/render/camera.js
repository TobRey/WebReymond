/**
 * Kamera: Verschieben, Zoomen, Antippen – für Touch und Maus.
 *
 * Besonderheiten für Handys: Pinch-Zoom um den Fingermittelpunkt, Nachlauf
 * beim Wischen, klare Trennung zwischen Tippen und Ziehen, grosse Toleranz.
 */

export class Camera {
    constructor(canvas) {
        this.canvas = canvas;
        this.x = 0;
        this.y = 0;
        this.zoom = 1;
        this.minZoom = 0.25;
        this.maxZoom = 2.6;
        this.vx = 0;
        this.vy = 0;
        this.width = 1;
        this.height = 1;
        this.dpr = 1;

        this.pointers = new Map();
        this.dragging = false;
        this.pinchStart = null;
        this.moved = 0;
        this.downAt = 0;
        this.longPressTimer = null;

        this.onTap = null;
        this.onLongPress = null;
        this.onDragMove = null;
    }

    resize(width, height, dpr) {
        this.width = width;
        this.height = height;
        this.dpr = dpr;
    }

    worldToScreen(wx, wy) {
        return {
            x: (wx - this.x) * this.zoom + this.width / 2,
            y: (wy - this.y) * this.zoom + this.height / 2
        };
    }

    screenToWorld(sx, sy) {
        return {
            x: (sx - this.width / 2) / this.zoom + this.x,
            y: (sy - this.height / 2) / this.zoom + this.y
        };
    }

    /** Ist ein Weltpunkt (mit Rand) sichtbar? */
    isVisible(wx, wy, margin = 200) {
        const p = this.worldToScreen(wx, wy);
        return p.x > -margin && p.x < this.width + margin && p.y > -margin && p.y < this.height + margin;
    }

    moveTo(x, y, zoom) {
        this.x = x;
        this.y = y;
        if (zoom) { this.zoom = this.clampZoom(zoom); }
        this.vx = 0;
        this.vy = 0;
    }

    /** Weich auf ein Ziel zufahren. */
    glideTo(x, y, zoom) {
        this.target = { x, y, zoom: zoom ? this.clampZoom(zoom) : this.zoom };
    }

    clampZoom(value) {
        return Math.max(this.minZoom, Math.min(this.maxZoom, value));
    }

    update(dt) {
        if (this.target) {
            const speed = Math.min(1, dt * 6);
            this.x += (this.target.x - this.x) * speed;
            this.y += (this.target.y - this.y) * speed;
            this.zoom += (this.target.zoom - this.zoom) * speed;
            if (Math.abs(this.target.x - this.x) < 0.6 && Math.abs(this.target.y - this.y) < 0.6
                && Math.abs(this.target.zoom - this.zoom) < 0.004) {
                this.x = this.target.x;
                this.y = this.target.y;
                this.zoom = this.target.zoom;
                this.target = null;
            }
            return;
        }

        if (!this.dragging && (Math.abs(this.vx) > 0.02 || Math.abs(this.vy) > 0.02)) {
            this.x += this.vx * dt;
            this.y += this.vy * dt;
            const damping = Math.pow(0.0016, dt);
            this.vx *= damping;
            this.vy *= damping;
        }
    }

    /** Kamera an das Zeigegerät binden. */
    attach() {
        const canvas = this.canvas;
        const rect = () => canvas.getBoundingClientRect();

        const down = (event) => {
            canvas.setPointerCapture(event.pointerId);
            const box = rect();
            this.pointers.set(event.pointerId, {
                x: event.clientX - box.left,
                y: event.clientY - box.top,
                startX: event.clientX - box.left,
                startY: event.clientY - box.top
            });

            this.target = null;
            this.moved = 0;
            this.downAt = performance.now();
            this.vx = 0;
            this.vy = 0;

            if (this.pointers.size === 1) {
                this.dragging = true;
                clearTimeout(this.longPressTimer);
                this.longPressTimer = setTimeout(() => {
                    if (this.moved < 14 && this.pointers.size === 1 && this.onLongPress) {
                        const p = this.pointers.get(event.pointerId);
                        if (p) { this.onLongPress(this.screenToWorld(p.x, p.y), p); }
                        this.moved = 9999; // kein Tippen mehr auslösen
                    }
                }, 480);
            } else if (this.pointers.size === 2) {
                clearTimeout(this.longPressTimer);
                const [a, b] = [...this.pointers.values()];
                this.pinchStart = {
                    distance: Math.hypot(a.x - b.x, a.y - b.y) || 1,
                    zoom: this.zoom,
                    centerWorld: this.screenToWorld((a.x + b.x) / 2, (a.y + b.y) / 2)
                };
            }
        };

        const move = (event) => {
            const pointer = this.pointers.get(event.pointerId);
            if (!pointer) { return; }

            const box = rect();
            const nx = event.clientX - box.left;
            const ny = event.clientY - box.top;
            const dx = nx - pointer.x;
            const dy = ny - pointer.y;
            pointer.x = nx;
            pointer.y = ny;
            this.moved += Math.abs(dx) + Math.abs(dy);

            if (this.pointers.size === 2 && this.pinchStart) {
                const [a, b] = [...this.pointers.values()];
                const distance = Math.hypot(a.x - b.x, a.y - b.y) || 1;
                this.zoom = this.clampZoom(this.pinchStart.zoom * (distance / this.pinchStart.distance));

                // Zoom um den Mittelpunkt der Finger
                const centerX = (a.x + b.x) / 2;
                const centerY = (a.y + b.y) / 2;
                const world = this.pinchStart.centerWorld;
                this.x = world.x - (centerX - this.width / 2) / this.zoom;
                this.y = world.y - (centerY - this.height / 2) / this.zoom;
                return;
            }

            if (this.pointers.size === 1 && this.dragging) {
                this.x -= dx / this.zoom;
                this.y -= dy / this.zoom;
                this.vx = -dx / this.zoom * 14;
                this.vy = -dy / this.zoom * 14;
                if (this.onDragMove) { this.onDragMove(this.screenToWorld(nx, ny)); }
            }
        };

        const up = (event) => {
            const pointer = this.pointers.get(event.pointerId);
            this.pointers.delete(event.pointerId);
            clearTimeout(this.longPressTimer);

            if (this.pointers.size < 2) { this.pinchStart = null; }
            if (this.pointers.size === 0) {
                this.dragging = false;
                const quick = performance.now() - this.downAt < 420;
                if (pointer && this.moved < 14 && quick && this.onTap) {
                    this.vx = 0;
                    this.vy = 0;
                    this.onTap(this.screenToWorld(pointer.x, pointer.y), pointer);
                }
            }
        };

        canvas.addEventListener('pointerdown', down);
        canvas.addEventListener('pointermove', move);
        canvas.addEventListener('pointerup', up);
        canvas.addEventListener('pointercancel', up);
        canvas.addEventListener('pointerleave', up);

        canvas.addEventListener('wheel', (event) => {
            event.preventDefault();
            const box = rect();
            const sx = event.clientX - box.left;
            const sy = event.clientY - box.top;
            const before = this.screenToWorld(sx, sy);
            this.target = null;
            this.zoom = this.clampZoom(this.zoom * (event.deltaY < 0 ? 1.12 : 0.89));
            const after = this.screenToWorld(sx, sy);
            this.x += before.x - after.x;
            this.y += before.y - after.y;
        }, { passive: false });

        // Doppeltippen zoomt heran
        let lastTap = 0;
        canvas.addEventListener('pointerup', (event) => {
            const now = performance.now();
            if (now - lastTap < 320 && this.moved < 14) {
                const box = rect();
                const world = this.screenToWorld(event.clientX - box.left, event.clientY - box.top);
                this.glideTo(world.x, world.y, this.zoom < 1.2 ? 1.6 : 0.7);
            }
            lastTap = now;
        });
    }
}
