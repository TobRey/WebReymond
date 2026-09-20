/** Winziger Ereignisverteiler, damit Oberfläche und Spielwelt sich nicht gegenseitig einbinden müssen. */

const handlers = new Map();

export function on(event, fn) {
    if (!handlers.has(event)) { handlers.set(event, new Set()); }
    handlers.get(event).add(fn);
    return () => handlers.get(event).delete(fn);
}

export function emit(event, payload) {
    (handlers.get(event) || []).forEach((fn) => {
        try { fn(payload); } catch (error) { console.error(error); }
    });
}
