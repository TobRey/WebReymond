/**
 * AI Groove – schlanker Hash-Router.
 *
 * Kein Framework nötig: die App hat wenige Seiten, und der Studio-Bereich
 * bleibt beim Wechsel im Speicher erhalten (damit die Audioengine weiterläuft).
 */

import { bus, EV } from '../core/bus.js';
import { clear } from '../core/dom.js';

const routes = new Map();
let currentPath = '';
let currentView = null;
let container = null;

export function registerRoute(path, factory, options = {}) {
  routes.set(path, { factory, keepAlive: !!options.keepAlive, instance: null });
}

export function navigate(path, replace = false) {
  const target = path.startsWith('#') ? path : `#${path}`;
  if (location.hash === target) {
    render();
    return;
  }
  if (replace) history.replaceState(null, '', target);
  else location.hash = target;
}

export function currentRoute() {
  return currentPath;
}

/** Zerlegt "#/studio?new=1" in Pfad und Parameter. */
function parseHash() {
  const raw = location.hash.replace(/^#/, '') || '/';
  const [path, query] = raw.split('?');
  const params = new URLSearchParams(query || '');
  return { path: path || '/', params };
}

function render() {
  const { path, params } = parseHash();
  const route = routes.get(path) || routes.get('/');
  if (!route) return;

  if (currentView && currentView.route === route && currentPath === path) {
    currentView.instance?.onShow?.(params);
    return;
  }

  // Alte Ansicht abmelden.
  if (currentView) {
    if (currentView.route.keepAlive) {
      currentView.instance?.onHide?.();
      currentView.element.remove();
    } else {
      currentView.instance?.destroy?.();
      currentView.element.remove();
      currentView.route.instance = null;
    }
  }

  let instance = route.keepAlive ? route.instance : null;
  if (!instance) {
    instance = route.factory();
    if (route.keepAlive) route.instance = instance;
  }

  container.appendChild(instance.element);
  instance.onShow?.(params);

  currentView = { route, instance, element: instance.element };
  currentPath = path;
  bus.emit(EV.VIEW_CHANGED, path);
}

export function startRouter(mountEl) {
  container = mountEl;
  clear(container);
  window.addEventListener('hashchange', render);
  render();
}
