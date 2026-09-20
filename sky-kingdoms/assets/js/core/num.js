/**
 * Zahlenformatierung – exakt wie app/Core/Num.php.
 * Deutsche Schreibweise: Komma als Dezimaltrennzeichen, Punkt als Tausendertrenner.
 */

const UNITS = ['', 'K', 'M', 'B', 'T', 'Qa', 'Qi', 'Sx', 'Sp', 'Oc', 'No', 'Dc'];

function german(value, decimals) {
    return value.toLocaleString('de-DE', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
    });
}

/** Kompakt: 942 · 12,4K · 3,2M · 1,1B */
export function compact(value, decimals = 1) {
    const negative = value < 0;
    value = Math.abs(Number(value) || 0);

    if (value < 1000) {
        const rounded = value >= 100 || value === Math.floor(value)
            ? german(Math.round(value), 0)
            : german(value, 1);
        return (negative ? '-' : '') + rounded;
    }

    let index = Math.floor(Math.log(value) / Math.log(1000));
    index = Math.max(1, Math.min(index, UNITS.length - 1));
    const scaled = value / Math.pow(1000, index);

    if (index >= UNITS.length - 1 && scaled >= 1000) {
        return (negative ? '-' : '') + value.toExponential(2).replace('.', ',');
    }

    const dec = scaled >= 100 ? 0 : (scaled >= 10 ? Math.min(decimals, 1) : decimals);
    return (negative ? '-' : '') + german(scaled, dec) + UNITS[index];
}

/** Vollständig mit Tausenderpunkten. */
export function full(value, decimals = 0) {
    return german(Number(value) || 0, decimals);
}

/** Prozent mit Vorzeichen: +1,5 % */
export function percent(ratio, decimals = 1, sign = true) {
    const value = (Number(ratio) || 0) * 100;
    const prefix = sign && value > 0 ? '+' : '';
    return prefix + german(value, decimals) + ' %';
}

/** Zeitspanne: „3 Std. 12 Min." */
export function duration(seconds) {
    seconds = Math.max(0, Math.round(seconds));
    if (seconds < 60) { return seconds + ' Sek.'; }
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) { return minutes + ' Min.'; }
    const hours = Math.floor(minutes / 60);
    const restMinutes = minutes % 60;
    if (hours < 24) {
        return restMinutes > 0 ? hours + ' Std. ' + restMinutes + ' Min.' : hours + ' Std.';
    }
    const days = Math.floor(hours / 24);
    const restHours = hours % 24;
    return restHours > 0 ? days + ' Tage ' + restHours + ' Std.' : days + ' Tage';
}

/** Rate je Minute: „12,4 /Min." */
export function rate(perMinute) {
    return compact(perMinute, 1) + ' /Min.';
}
