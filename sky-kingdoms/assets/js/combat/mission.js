/**
 * Die Kampfsimulation im Browser – nach denselben Regeln wie
 * app/Game/Combat/Mission.php.
 *
 * Wichtig: Massgeblich ist immer das Ergebnis des Servers. Diese Fassung dient
 * der flüssigen Darstellung und der Vorschau; abgeschickt werden ausschliesslich
 * die Entscheidungen des Spielers (Spurwechsel und Fähigkeiten).
 */

export const LANES = [12, 30, 48];
export const OBJECTIVE_X = 92;
export const FIELD_WIDTH = 100;
export const FIELD_HEIGHT = 60;

export class MissionSim {
    constructor(setup, units, abilities) {
        this.setup = setup;
        this.abilities = abilities || {};
        this.tickRate = 10;
        this.maxTicks = (setup.duration || 80) * this.tickRate;

        this.units = units.map((unit) => ({ ...unit, hp: unit.maxHp !== undefined ? unit.maxHp : unit.hp }));
        this.walls = (setup.walls || []).map((wall) => ({ ...wall }));
        this.towers = (setup.towers || []).map((tower) => ({ ...tower, hp: tower.hp || 300 }));
        this.patrols = (setup.patrols || []).map((patrol) => ({ ...patrol, home: patrol.x }));

        this.tick = 0;
        this.lane = 1;
        this.x = 2;
        this.active = {};
        this.cooldowns = {};
        this.actions = [];
        this.events = [];
        this.finished = false;
        this.result = null;
        this.lastActionTick = -999;
        this.shots = [];
    }

    /** Entscheidung des Spielers aufzeichnen und sofort anwenden. */
    act(kind, value) {
        if (this.finished) { return false; }

        if (kind === 'lane') {
            if (this.x >= 70) { return false; }
            this.lane = Number(value);
            this.actions.push({ t: this.tick, a: 'lane', v: String(value) });
            return true;
        }

        if (kind === 'ability') {
            const def = this.abilities[value];
            if (!def) { return false; }
            if ((this.cooldowns[value] || 0) > this.tick) { return false; }
            if (this.tick - this.lastActionTick < 6) { return false; }

            this.active[value] = def.duration || 40;
            this.cooldowns[value] = this.tick + (def.cooldown || 120);
            this.lastActionTick = this.tick;
            this.actions.push({ t: this.tick, a: 'ability', v: String(value) });
            return true;
        }

        return false;
    }

    abilityReady(key) {
        return (this.cooldowns[key] || 0) <= this.tick && this.tick - this.lastActionTick >= 6;
    }

    abilityProgress(key) {
        const ready = this.cooldowns[key] || 0;
        if (ready <= this.tick) { return 0; }
        const def = this.abilities[key] || {};
        const total = def.cooldown || 120;
        return Math.max(0, Math.min(1, (ready - this.tick) / total));
    }

    aliveCount() {
        return this.units.filter((unit) => unit.hp > 0).length;
    }

    weakestIndex() {
        let best = -1;
        let value = 0;
        this.units.forEach((unit, index) => {
            if (unit.hp <= 0) { return; }
            if (best === -1 || unit.hp < value) { best = index; value = unit.hp; }
        });
        return best;
    }

    /** Ein Simulationsschritt (1/10 Sekunde). */
    step() {
        if (this.finished) { return; }
        if (this.tick >= this.maxTicks) { return this.finish('timeout'); }

        // Wirkung der Fähigkeiten
        let speedFactor = 1;
        let damageTaken = 1;
        let towerHit = 1;
        let structFactor = 1;

        Object.keys(this.active).forEach((key) => {
            if (this.active[key] <= 0) { delete this.active[key]; return; }
            const def = this.abilities[key] || {};
            speedFactor *= def.speed || 1;
            damageTaken *= def.damage_taken || 1;
            towerHit *= def.tower_accuracy || 1;
            structFactor *= def.structure_damage || 1;
            this.active[key] -= 1;
        });

        if (this.aliveCount() === 0) { return this.finish('defeated'); }

        // Mauer im Weg?
        let blocking = -1;
        this.walls.forEach((wall, index) => {
            if (blocking >= 0 || wall.lane !== this.lane || wall.hp <= 0) { return; }
            if (this.x + 1.2 >= wall.x && this.x <= wall.x + 1.2) { blocking = index; }
        });

        // Patrouillen bewegen sich
        let fighting = -1;
        this.patrols.forEach((patrol, index) => {
            if (patrol.hp <= 0) { return; }
            patrol.x += patrol.dir * patrol.speed;
            if (patrol.x > patrol.home + patrol.range) { patrol.dir = -1; }
            if (patrol.x < patrol.home - patrol.range) { patrol.dir = 1; }
            if (patrol.lane === this.lane && fighting < 0) {
                const dx = patrol.x - this.x;
                if (dx < 1.5 && dx > -1.5) { fighting = index; }
            }
        });

        // Schaden der eigenen Truppe
        let groupDamage = 0;
        let structDamage = 0;
        this.units.forEach((unit) => {
            if (unit.hp <= 0) { return; }
            groupDamage += unit.damage / 10;
            structDamage += (unit.damage * (unit.struct || 1)) / 10;
        });

        if (blocking >= 0) {
            this.walls[blocking].hp -= structDamage * structFactor;
            if (this.walls[blocking].hp <= 0) { this.events.push({ t: this.tick, e: 'wall', v: blocking }); }
        } else if (fighting >= 0) {
            this.patrols[fighting].hp -= groupDamage;
            if (this.patrols[fighting].hp <= 0) { this.events.push({ t: this.tick, e: 'patrol', v: fighting }); }
        } else {
            let speed = 0;
            let count = 0;
            this.units.forEach((unit) => {
                if (unit.hp > 0) { speed += unit.speed; count++; }
            });
            this.x += (speed / count) * speedFactor / 10;
        }

        // Patrouille schlägt zurück
        const bonus = this.setup.bonus || 1.15;
        if (fighting >= 0) {
            const target = this.weakestIndex();
            if (target >= 0) {
                this.units[target].hp -= this.patrols[fighting].damage * damageTaken * bonus / 10;
            }
        }

        // Türme feuern
        this.shots = this.shots.filter((shot) => this.tick - shot.t < 3);
        this.towers.forEach((tower, index) => {
            if (tower.hp <= 0) { return; }
            if (this.tick % tower.reload !== index % tower.reload) { return; }
            const dx = tower.x - this.x;
            const laneGap = tower.lane === this.lane ? 0 : 14;
            const distance = Math.sqrt(dx * dx + laneGap * laneGap);
            if (distance > tower.range) { return; }

            const target = this.weakestIndex();
            if (target < 0) { return; }
            this.units[target].hp -= (tower.damage || 12) * towerHit * damageTaken * bonus / 10 * tower.reload;
            this.shots.push({ t: this.tick, x: tower.x, lane: tower.lane });
        });

        if (this.x >= OBJECTIVE_X) {
            this.tick++;
            return this.finish('success');
        }

        this.tick++;
    }

    finish(reason) {
        this.finished = true;
        const survivors = this.aliveCount();
        let carry = 0;
        this.units.forEach((unit) => { if (unit.hp > 0) { carry += unit.carry || 0; } });

        this.result = {
            success: reason === 'success',
            reason,
            progress: Math.max(0, Math.min(1, (this.x - 2) / (OBJECTIVE_X - 2))),
            survivors,
            carry,
            ticks: this.tick
        };
    }

    get timeLeft() {
        return Math.max(0, Math.ceil((this.maxTicks - this.tick) / this.tickRate));
    }
}
