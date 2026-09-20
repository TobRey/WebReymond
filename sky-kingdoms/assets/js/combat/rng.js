/**
 * Zufallsgenerator mit festem Startwert – Zeichen für Zeichen identisch zu
 * app/Game/Combat/Rng.php. Gleicher Startwert = gleiche Folge in Browser und
 * auf dem Server.
 */
export class Rng {
    constructor(seed) {
        seed = seed >>> 0;
        this.state = seed === 0 ? 0x9E3779B9 : seed;
    }

    next() {
        let x = this.state;
        x ^= (x << 13) >>> 0;
        x >>>= 0;
        x ^= x >>> 17;
        x ^= (x << 5) >>> 0;
        this.state = x >>> 0;
        return this.state;
    }

    float() {
        return this.next() / 4294967296;
    }

    int(min, max) {
        if (max <= min) { return min; }
        return min + Math.floor(this.float() * (max - min + 1));
    }
}
