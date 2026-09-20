/**
 * Symbole für Rohstoffe und Oberfläche – als SVG direkt im Code.
 * Vorteil: keine zusätzlichen Ladevorgänge, beliebig skalierbar, eigene Optik.
 */

const R = {
    wood: `<path d="M4 16c0-3 3-4 5-4h9a3 3 0 0 1 0 6H9c-2 0-5-1-5-2z" fill="#a9743f"/>
           <ellipse cx="18" cy="15" rx="2.6" ry="3" fill="#c89055"/>
           <ellipse cx="18" cy="15" rx="1.2" ry="1.5" fill="#8a5a2c"/>
           <path d="M6 9c0-2.4 2.4-3.4 4-3.4h8a2.6 2.6 0 0 1 0 5.2H10C8.4 10.8 6 10.2 6 9z" fill="#c08650"/>
           <ellipse cx="18" cy="8.2" rx="2.2" ry="2.6" fill="#dda76a"/>`,
    stone: `<path d="M5 17l2.5-7L13 6l6 4 1 7z" fill="#9aa3ad"/>
            <path d="M13 6l6 4 1 7-7-3z" fill="#7c8691"/>
            <path d="M7.5 10L13 13l-1 4-4.5-1z" fill="#b3bcc6"/>`,
    iron: `<path d="M4 14l4-7h8l4 7-4 5H8z" fill="#7d8894"/>
           <path d="M12 7l4 7-4 5-4-5z" fill="#9aa6b3"/>
           <path d="M8 7l4 7-4 5-4-5z" fill="#68727d"/>`,
    copper: `<circle cx="12" cy="12" r="7" fill="#c87f4a"/>
             <circle cx="12" cy="12" r="4.6" fill="#e09a63"/>
             <path d="M9.6 10.4l2.4-1.6 2.4 1.6v3.2L12 15.2l-2.4-1.6z" fill="#a9622f"/>`,
    coal: `<path d="M5 16l3-6 5-3 6 4 .5 6-7 2z" fill="#4a4a55"/>
           <path d="M13 7l6 4 .5 6-6-4z" fill="#33333d"/>
           <path d="M8.5 11.5l3.5 2-1 3-3-1z" fill="#61616e"/>`,
    grain: `<path d="M12 21V8" stroke="#b58b2e" stroke-width="1.8" stroke-linecap="round"/>
            <g fill="#e0b551"><ellipse cx="9.4" cy="9" rx="2.1" ry="3.2" transform="rotate(-24 9.4 9)"/>
            <ellipse cx="14.6" cy="9" rx="2.1" ry="3.2" transform="rotate(24 14.6 9)"/>
            <ellipse cx="12" cy="5.6" rx="2.1" ry="3.4"/></g>`,
    vegetables: `<path d="M12 21c-4 0-6-3.4-6-6.6C6 11 8.6 9 12 9s6 2 6 5.4C18 17.6 16 21 12 21z" fill="#6fbf5e"/>
                 <path d="M12 9c0-2.4 1.6-4.4 4-5-.4 2.6-1.6 4.2-4 5z" fill="#4c9a44"/>
                 <path d="M12 9C11.4 7 9.6 5.6 7.4 5.4 8 7.6 9.6 8.8 12 9z" fill="#57ad4c"/>`,
    fruit: `<circle cx="12" cy="14" r="6" fill="#e2604f"/>
            <path d="M12 8c1.6-2 3.4-2.6 5-2.4-.6 2.2-2.2 3.2-5 2.4z" fill="#5da64f"/>
            <path d="M11.6 8V5.6" stroke="#7a4a2a" stroke-width="1.6" stroke-linecap="round"/>
            <ellipse cx="9.6" cy="12" rx="1.6" ry="2.2" fill="#f08a76" opacity=".7"/>`,
    meat: `<path d="M6 15c-1.6-2.6-.6-6 2.4-7.4 3-1.4 6.6-.2 8.2 2.4 1.6 2.6.6 6-2.4 7.4-3 1.4-6.6.2-8.2-2.4z" fill="#c0574f"/>
           <path d="M14 17.4c3-1.4 4-4.8 2.4-7.4l-2 1c1 2-.2 4.4-2.6 5.6z" fill="#9c4440"/>
           <rect x="15" y="14.6" width="5.4" height="2.6" rx="1.3" transform="rotate(-24 15 14.6)" fill="#efe3c7"/>`,
    flour: `<path d="M7 9h10l1 10H6z" fill="#efe3c7"/>
            <path d="M12 9h5l1 10h-6z" fill="#dccfae"/>
            <path d="M7 9c0-2 2-3.4 5-3.4S17 7 17 9z" fill="#cfc09a"/>`,
    bread: `<path d="M4.4 14.6c0-3.4 3.4-6 7.6-6s7.6 2.6 7.6 6c0 2-1.6 3.4-3.6 3.4H8c-2 0-3.6-1.4-3.6-3.4z" fill="#d8a05a"/>
            <path d="M12 8.6c4.2 0 7.6 2.6 7.6 6 0 2-1.6 3.4-3.6 3.4h-3z" fill="#bb8443"/>
            <path d="M8.4 11.6l1.6 2M11.6 11l1.6 2M14.8 11.6l1.6 2" stroke="#a86c35" stroke-width="1.4" stroke-linecap="round"/>`,
    tools: `<path d="M14.6 5.4a4 4 0 0 0 5.2 5.2l-9.4 9.4-5.2-5.2z" fill="#8fb2c9"/>
            <path d="M5.2 14.8l5.2 5.2-1.6 1.6a2.6 2.6 0 0 1-3.6-3.6z" fill="#6a8ba1"/>
            <circle cx="17.4" cy="7.4" r="2" fill="#c5dced"/>`,
    parts: `<path d="M12 4l2 2.2 3-.6.6 3L20 11l-2.2 2 .6 3-3 .6L12 20l-2-2.2-3 .6-.6-3L4 11l2.2-2-.6-3 3-.6z" fill="#b09a6b"/>
            <circle cx="12" cy="12" r="3.4" fill="#e8dcb8"/>
            <circle cx="12" cy="12" r="1.6" fill="#8e7a4f"/>`,
    weapons: `<path d="M12 3l2.2 10.6-2.2 2-2.2-2z" fill="#c9d6e2"/>
              <path d="M12 3l2.2 10.6-2.2 2z" fill="#9aacc0"/>
              <rect x="9.4" y="14.6" width="5.2" height="1.8" rx=".8" fill="#9c6b8f"/>
              <rect x="11.2" y="16.2" width="1.6" height="4.4" rx=".8" fill="#6d4a63"/>`,
    horse: `<path d="M6 19c0-4 1.4-6.4 4-7.6L8.6 8c-.4-1 .2-2 1.2-2.2l2.4-.4 2 2.6c2.6.6 4.4 2.8 4.4 5.6V19h-2.6v-3.4l-2 1V19h-2.6v-3.2l-2.4-.8V19z" fill="#a8763f"/>
            <path d="M10.8 6.4l1.4-.2 1.6 2.2-1.6.4z" fill="#8a5c2c"/>
            <circle cx="11.4" cy="7.6" r=".7" fill="#33241a"/>`,
    crystal: `<path d="M12 3l5 6-5 12-5-12z" fill="#6fd8ff"/>
              <path d="M12 3l5 6-5 12z" fill="#3eb6e6"/>
              <path d="M7 9h10" stroke="#bdf0ff" stroke-width="1.2"/>
              <path d="M12 3v18" stroke="#bdf0ff" stroke-width="1" opacity=".7"/>`,
    aether: `<circle cx="12" cy="12" r="5" fill="#c79bff" opacity=".9"/>
             <circle cx="12" cy="12" r="2.4" fill="#f0e2ff"/>
             <path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"
                   stroke="#c79bff" stroke-width="1.6" stroke-linecap="round"/>`,
    gold: `<ellipse cx="12" cy="17" rx="7" ry="3" fill="#d79f27"/>
           <ellipse cx="12" cy="15" rx="7" ry="3" fill="#ffc94a"/>
           <ellipse cx="12" cy="11" rx="5.4" ry="2.4" fill="#d79f27"/>
           <ellipse cx="12" cy="9.6" rx="5.4" ry="2.4" fill="#ffd970"/>
           <ellipse cx="12" cy="9.2" rx="2.4" ry="1" fill="#fff0b8" opacity=".8"/>`
};

/** SVG-Symbol eines Rohstoffs. */
export function resourceIcon(key, size = 24) {
    const body = R[key] || `<circle cx="12" cy="12" r="7" fill="#9aa3ad"/>`;
    return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" aria-hidden="true" focusable="false">${body}</svg>`;
}

export function hasIcon(key) {
    return Object.prototype.hasOwnProperty.call(R, key);
}

/** Kleine Oberflächensymbole. */
const UI = {
    arrow: `<path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`,
    plus: `<path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/>`,
    lock: `<rect x="5" y="11" width="14" height="9" rx="2" fill="currentColor"/><path d="M8 11V8a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="2" fill="none"/>`,
    warn: `<path d="M12 4l9 16H3z" fill="currentColor"/><path d="M12 10v4M12 16.5v.5" stroke="#fff" stroke-width="2" stroke-linecap="round"/>`,
    time: `<circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 8v4l3 2" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round"/>`,
    worker: `<circle cx="12" cy="7" r="3.2" fill="currentColor"/><path d="M5 20c0-4 3.2-6.4 7-6.4s7 2.4 7 6.4z" fill="currentColor"/>`,
    truck: `<rect x="2" y="7" width="12" height="9" rx="1.5" fill="currentColor"/><path d="M14 10h4l4 3.4V16h-8z" fill="currentColor"/><circle cx="6" cy="18" r="2" fill="currentColor"/><circle cx="18" cy="18" r="2" fill="currentColor"/>`
};

export function uiIcon(key, size = 20) {
    const body = UI[key] || '';
    return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" aria-hidden="true" focusable="false">${body}</svg>`;
}
