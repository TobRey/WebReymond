/**
 * Spielzustand im Browser.
 *
 * Der Server schickt nach jeder Aktion nur die Änderungen („patch"). Hier
 * werden sie eingepflegt, damit nie die ganze Welt neu geladen werden muss.
 */

const listeners = new Set();

export const state = {
    world: null,
    effects: null,
    rates: null,
    profile: null,
    quests: [],
    statics: null,
    unread: 0,
    island: null,
    ready: false,
    serverNow: 0,
    localNow: 0
};

/** Auf Änderungen horchen. Gibt eine Funktion zum Abmelden zurück. */
export function subscribe(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

export function emit(reason = 'update') {
    listeners.forEach((fn) => {
        try { fn(reason); } catch (error) { console.error(error); }
    });
}

/** Vollständigen Zustand setzen (beim Laden). */
export function setState(payload) {
    if (payload.world) { state.world = payload.world; }
    if (payload.effects) { state.effects = payload.effects; }
    if (payload.rates) { state.rates = payload.rates; }
    if (payload.profile) { state.profile = payload.profile; }
    if (payload.quests) { state.quests = payload.quests; }
    if (typeof payload.unread === 'number') { state.unread = payload.unread; }
    if (payload.now) {
        state.serverNow = payload.now;
        state.localNow = Date.now() / 1000;
    }

    if (state.world && !state.island) {
        const first = Object.keys(state.world.islands || {})[0];
        state.island = first || null;
    }

    state.ready = true;
    emit('state');
}

/** Änderungen einpflegen. */
export function applyPatch(patch) {
    if (!patch || !state.world) { return; }

    if (patch.store) { state.world.store = patch.store; }

    ['buildings', 'routes', 'bridges', 'islands'].forEach((collection) => {
        if (patch[collection]) {
            Object.keys(patch[collection]).forEach((id) => {
                state.world[collection][id] = patch[collection][id];
            });
        }
        const removed = patch.removed && patch.removed[collection];
        if (removed) {
            removed.forEach((id) => { delete state.world[collection][id]; });
        }
    });

    ['units', 'unit_levels', 'research', 'modes', 'quests', 'achievements', 'flags', 'name']
        .forEach((key) => {
            if (Object.prototype.hasOwnProperty.call(patch, key)) {
                state.world[key] = patch[key];
            }
        });
}

/** Antwort einer Aktion verarbeiten. */
export function applyResult(result) {
    if (!result) { return; }
    applyPatch(result.patch);
    if (result.effects) { state.effects = result.effects; }
    if (result.rates) { state.rates = result.rates; }
    if (result.score && state.profile) { state.profile.score = result.score; }
    emit('action');
}

// -------------------------------------------------------------------
// Bequeme Abfragen
// -------------------------------------------------------------------

export function island(id) {
    return state.world && state.world.islands ? state.world.islands[id] : null;
}

export function buildingsOn(islandId) {
    if (!state.world) { return []; }
    return Object.values(state.world.buildings).filter((b) => b.island === islandId);
}

export function buildingDef(type) {
    return state.statics && state.statics.buildings ? state.statics.buildings[type] : null;
}

export function islandDef(type) {
    return state.statics && state.statics.islandTypes ? state.statics.islandTypes[type] : null;
}

export function resourceDef(key) {
    return state.statics && state.statics.resources ? state.statics.resources[key] : null;
}

export function resourceName(key) {
    const def = resourceDef(key);
    return def ? def.name : key;
}

export function stored(key) {
    return state.world && state.world.store ? (state.world.store[key] || 0) : 0;
}

/** Routen, die ein Gebäude berühren. */
export function routesFor(buildingId) {
    if (!state.world) { return []; }
    return Object.values(state.world.routes).filter((route) => {
        const src = route.src || [];
        const dst = route.dst || [];
        return (src[0] === 'b' && src[1] === buildingId) || (dst[0] === 'b' && dst[1] === buildingId);
    });
}

/** Geschätzte Serverzeit. */
export function now() {
    if (!state.serverNow) { return Math.floor(Date.now() / 1000); }
    return Math.floor(state.serverNow + (Date.now() / 1000 - state.localNow));
}
