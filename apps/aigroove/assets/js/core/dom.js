/**
 * AI Groove – DOM-Hilfsfunktionen.
 *
 * Alle Texte werden ueber textContent gesetzt. innerHTML wird bewusst nirgends
 * mit Nutzerdaten verwendet (XSS-Schutz).
 */

import { colorIndexFor } from './util.js';

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Erzeugt ein Element.
 * @param {string} tag  z.B. 'div', 'button.btn.btn--primary', 'span#id'
 * @param {object} [props] Attribute/Eigenschaften
 * @param {Array|Node|string} [children]
 */
export function h(tag, props = null, children = null) {
  let name = tag;
  const classes = [];
  let id = '';

  const hashIdx = name.indexOf('#');
  if (hashIdx >= 0) {
    const rest = name.slice(hashIdx + 1);
    const dot = rest.indexOf('.');
    id = dot >= 0 ? rest.slice(0, dot) : rest;
    name = name.slice(0, hashIdx) + (dot >= 0 ? rest.slice(dot) : '');
  }
  const parts = name.split('.');
  name = parts.shift() || 'div';
  classes.push(...parts.filter(Boolean));

  const el = document.createElement(name);
  if (id) el.id = id;
  if (classes.length) el.classList.add(...classes);

  if (props) applyProps(el, props);
  if (children != null) append(el, children);
  return el;
}

/** SVG-Element mit Attributen. */
export function svg(tag, attrs = null, children = null) {
  const el = document.createElementNS(SVG_NS, tag);
  if (attrs) {
    for (const [k, v] of Object.entries(attrs)) {
      if (v == null || v === false) continue;
      el.setAttribute(k, String(v));
    }
  }
  if (children != null) append(el, children);
  return el;
}

/**
 * Fertiges Icon aus dem internen Set.
 * Icons sind bewusst als Pfad-Strings hinterlegt: kein externer Request, kein innerHTML.
 */
const ICONS = {
  play: 'M8 5.2v13.6a1 1 0 0 0 1.53.85l10.8-6.8a1 1 0 0 0 0-1.7L9.53 4.35A1 1 0 0 0 8 5.2Z',
  pause: 'M7 4h3.2v16H7zM13.8 4H17v16h-3.2z',
  stop: 'M6.5 6.5h11v11h-11z',
  record: 'M12 6.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z',
  plus: 'M12 5v14M5 12h14',
  minus: 'M5 12h14',
  close: 'M6 6l12 12M18 6L6 18',
  chevron: 'M9 5l7 7-7 7',
  chevronDown: 'M5 9l7 7 7-7',
  dots: 'M12 6.6a1.3 1.3 0 1 0 0-2.6 1.3 1.3 0 0 0 0 2.6Zm0 6.7a1.3 1.3 0 1 0 0-2.6 1.3 1.3 0 0 0 0 2.6Zm0 6.7a1.3 1.3 0 1 0 0-2.6 1.3 1.3 0 0 0 0 2.6Z',
  grip: 'M9 6h.01M9 12h.01M9 18h.01M15 6h.01M15 12h.01M15 18h.01',
  trash: 'M4 7h16M9 7V5h6v2M6.5 7l.8 12.2A1.8 1.8 0 0 0 9.1 21h5.8a1.8 1.8 0 0 0 1.8-1.8L17.5 7',
  copy: 'M8.5 8.5h9.3a1.7 1.7 0 0 1 1.7 1.7v9.3a1.7 1.7 0 0 1-1.7 1.7H8.5a1.7 1.7 0 0 1-1.7-1.7v-9.3a1.7 1.7 0 0 1 1.7-1.7ZM5.5 15.5A1.7 1.7 0 0 1 3.8 13.8V4.5A1.7 1.7 0 0 1 5.5 2.8h9.3a1.7 1.7 0 0 1 1.7 1.7',
  edit: 'M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17v3Z',
  wand: 'M4 20l9-9M14.5 4.5l1 2.5 2.5 1-2.5 1-1 2.5-1-2.5-2.5-1 2.5-1 1-2.5ZM19 13l.7 1.8 1.8.7-1.8.7-.7 1.8-.7-1.8-1.8-.7 1.8-.7.7-1.8Z',
  mic: 'M12 15.5a3.5 3.5 0 0 0 3.5-3.5V6a3.5 3.5 0 1 0-7 0v6a3.5 3.5 0 0 0 3.5 3.5ZM5.5 11.5A6.5 6.5 0 0 0 12 18m0 0a6.5 6.5 0 0 0 6.5-6.5M12 18v3.5',
  upload: 'M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M4.5 15v3.2A2.3 2.3 0 0 0 6.8 20.5h10.4a2.3 2.3 0 0 0 2.3-2.3V15',
  download: 'M12 4v12m0 0l4.5-4.5M12 16l-4.5-4.5M4.5 15v3.2A2.3 2.3 0 0 0 6.8 20.5h10.4a2.3 2.3 0 0 0 2.3-2.3V15',
  settings:
    'M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Zm8.4-2.1.1-1.1-.1-1.1 2-1.5-2-3.4-2.3.9a8 8 0 0 0-1.9-1.1L15.8 3h-3.9l-.4 2.8a8 8 0 0 0-1.9 1.1l-2.3-.9-2 3.4 2 1.5-.1 1.1.1 1.1-2 1.5 2 3.4 2.3-.9a8 8 0 0 0 1.9 1.1l.4 2.8h3.9l.4-2.8a8 8 0 0 0 1.9-1.1l2.3.9 2-3.4-2-1.5Z',
  pads: 'M4.5 4.5h6v6h-6zM13.5 4.5h6v6h-6zM4.5 13.5h6v6h-6zM13.5 13.5h6v6h-6z',
  grid: 'M3.5 8.5h17M3.5 15.5h17M8.5 3.5v17M15.5 3.5v17M3.5 3.5h17v17h-17z',
  piano: 'M3.5 4.5h17v15h-17zM8.2 4.5v9.5M12 4.5v9.5M15.8 4.5v9.5',
  timeline: 'M3.5 6.5h17M3.5 12h11M3.5 17.5h14M6 4v5M14 9.5v5M10 15v5',
  mixer: 'M6 3.5v6M6 14.5v6M12 3.5v10M12 18.5v2M18 3.5v3M18 11.5v9M3.5 11h5M9.5 15h5M15.5 8h5',
  sound: 'M11 5.5 6.5 9.5H3.5v5h3L11 18.5zM15.5 9.2a4 4 0 0 1 0 5.6M18.3 6.4a8 8 0 0 1 0 11.2',
  help: 'M12 17.5h.01M9.4 9.2a2.7 2.7 0 1 1 3.6 2.5c-.7.3-1 1-1 1.8v.5M12 3.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17Z',
  undo: 'M9 8H5V4M5.2 8.2A8 8 0 1 1 4.5 14',
  redo: 'M15 8h4V4M18.8 8.2A8 8 0 1 0 19.5 14',
  loop: 'M4.5 9.5A4 4 0 0 1 8.5 5.5h9l-2.5-2.5M19.5 14.5a4 4 0 0 1-4 4h-9l2.5 2.5',
  metronome: 'M9.5 3.5h5l3.5 17h-12zM6.5 15.5h11M12 20V8',
  chat: 'M20.5 12c0 4.1-3.8 7.5-8.5 7.5-1.1 0-2.2-.2-3.1-.5L3.5 20.5l1.6-4.1A7 7 0 0 1 3.5 12C3.5 7.9 7.3 4.5 12 4.5s8.5 3.4 8.5 7.5Z',
  key: 'M14.5 3.5a6 6 0 1 0 -4.2 10.2L9 15h-2v2h-2v2H3v-2.6l6.3-6.3A6 6 0 0 0 14.5 3.5Zm2 4.5h.01',
  check: 'M5 12.5l4.5 4.5L19 7.5',
  folder: 'M3.5 6.5A1.5 1.5 0 0 1 5 5h4l2 2.5h8A1.5 1.5 0 0 1 20.5 9v9A1.5 1.5 0 0 1 19 19.5H5A1.5 1.5 0 0 1 3.5 18Z',
  scissors: 'M6.5 3.5 17 17.5M17.5 3.5 7 17.5M5.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Zm13 0a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z',
  power: 'M12 3.5v8M7.5 6.3a7 7 0 1 0 9 0',
};

export function icon(name, size = 20) {
  const path = ICONS[name] || ICONS.dots;
  const el = svg('svg', {
    viewBox: '0 0 24 24',
    width: size,
    height: size,
    fill: 'none',
    stroke: 'currentColor',
    'stroke-width': 1.7,
    'stroke-linecap': 'round',
    'stroke-linejoin': 'round',
    'aria-hidden': 'true',
    focusable: 'false',
  });
  const p = svg('path', { d: path });
  if (name === 'record' || name === 'play' || name === 'stop' || name === 'pause') {
    p.setAttribute('fill', 'currentColor');
    p.setAttribute('stroke', 'none');
  }
  el.appendChild(p);
  return el;
}

export function applyProps(el, props) {
  for (const [key, value] of Object.entries(props)) {
    if (value == null || value === false) continue;
    if (key === 'class' || key === 'className') {
      el.classList.add(...String(value).split(/\s+/).filter(Boolean));
    } else if (key === 'text') {
      el.textContent = String(value);
    } else if (key === 'html') {
      // Nur fuer statische, im Code definierte Markierungen verwenden.
      el.innerHTML = String(value);
    } else if (key === 'style' && typeof value === 'object') {
      for (const [p, v] of Object.entries(value)) {
        if (p.startsWith('--')) el.style.setProperty(p, String(v));
        else el.style[p] = v;
      }
    } else if (key === 'dataset') {
      for (const [p, v] of Object.entries(value)) el.dataset[p] = String(v);
    } else if (key.startsWith('on') && typeof value === 'function') {
      el.addEventListener(key.slice(2).toLowerCase(), value);
    } else if (key in el && key !== 'list' && key !== 'type' && key !== 'form') {
      try {
        el[key] = value;
      } catch (_) {
        el.setAttribute(key, String(value));
      }
    } else {
      el.setAttribute(key, value === true ? '' : String(value));
    }
  }
  return el;
}

export function append(parent, children) {
  const list = Array.isArray(children) ? children : [children];
  for (const child of list) {
    if (child == null || child === false) continue;
    parent.appendChild(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return parent;
}

export function clear(el) {
  while (el.firstChild) el.removeChild(el.firstChild);
  return el;
}

export const qs = (sel, root = document) => root.querySelector(sel);
export const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/** Ereignis registrieren und Abmeldefunktion zurueckgeben. */
export function on(target, type, handler, options) {
  target.addEventListener(type, handler, options);
  return () => target.removeEventListener(type, handler, options);
}

/** Button mit Icon + Beschriftung. */
export function iconButton(iconName, label, props = {}) {
  const btn = h('button.btn.btn--icon', {
    type: 'button',
    title: label,
    'aria-label': label,
    ...props,
  });
  btn.appendChild(icon(iconName, props.iconSize || 18));
  return btn;
}

export function labeledButton(iconName, label, props = {}) {
  const btn = h('button.btn', { type: 'button', ...props });
  if (iconName) btn.appendChild(icon(iconName, props.iconSize || 17));
  btn.appendChild(h('span', { text: label }));
  return btn;
}

/** Beschriftetes Eingabefeld. */
export function field(label, control, hint) {
  const wrap = h('div.field');
  if (label) wrap.appendChild(h('label.field__label', { text: label, htmlFor: control.id || null }));
  wrap.appendChild(control);
  if (hint) wrap.appendChild(h('p.field__hint', { text: hint }));
  return wrap;
}

/** Ein/Aus-Schalter. */
export function toggle(label, checked, onChange) {
  const input = h('input', { type: 'checkbox', checked: !!checked });
  input.addEventListener('change', () => onChange(input.checked));
  const el = h('label.switch', null, [input, h('span.switch__track'), label ? h('span', { text: label }) : null]);
  el.setChecked = (v) => {
    input.checked = !!v;
  };
  return el;
}

/** Schieberegler mit Live-Wertanzeige; fuellt den Track visuell. */
export function slider(min, max, step, value, onInput) {
  const input = h('input.slider', { type: 'range', min, max, step, value });
  const paint = () => {
    const pct = ((input.valueAsNumber - min) / (max - min)) * 100;
    input.style.setProperty('--fill', `${pct}%`);
  };
  input.addEventListener('input', () => {
    paint();
    onInput(input.valueAsNumber);
  });
  paint();
  input.setValue = (v) => {
    input.value = String(v);
    paint();
  };
  return input;
}

/** Segmentierte Auswahl. */
export function segmented(options, value, onChange) {
  const wrap = h('div.segmented', { role: 'group' });
  const buttons = options.map((opt) => {
    const btn = h('button.segmented__btn', {
      type: 'button',
      text: opt.label,
      'aria-pressed': String(opt.value === value),
    });
    btn.addEventListener('click', () => {
      wrap.setValue(opt.value);
      onChange(opt.value);
    });
    wrap.appendChild(btn);
    return { btn, value: opt.value };
  });
  wrap.setValue = (v) => {
    for (const b of buttons) b.btn.setAttribute('aria-pressed', String(b.value === v));
  };
  return wrap;
}

/**
 * Macht ein Element per Ziehen (Maus + Touch) veraenderbar.
 * Liefert dy/dx relativ zum Startpunkt.
 */
export function makeDraggableValue(el, { onStart, onMove, onEnd, axis = 'y' } = {}) {
  let active = false;
  let startX = 0;
  let startY = 0;
  let pointerId = null;

  const down = (e) => {
    if (active) return;
    active = true;
    pointerId = e.pointerId;
    startX = e.clientX;
    startY = e.clientY;
    try {
      el.setPointerCapture(pointerId);
    } catch (_) {
      /* egal */
    }
    onStart?.(e);
    e.preventDefault();
  };
  const move = (e) => {
    if (!active || e.pointerId !== pointerId) return;
    const dx = e.clientX - startX;
    const dy = startY - e.clientY; // nach oben = positiv
    onMove?.(axis === 'x' ? dx : dy, { dx, dy, event: e });
    e.preventDefault();
  };
  const up = (e) => {
    if (!active || e.pointerId !== pointerId) return;
    active = false;
    try {
      el.releasePointerCapture(pointerId);
    } catch (_) {
      /* egal */
    }
    pointerId = null;
    onEnd?.(e);
  };

  el.addEventListener('pointerdown', down);
  el.addEventListener('pointermove', move);
  el.addEventListener('pointerup', up);
  el.addEventListener('pointercancel', up);
  el.style.touchAction = 'none';

  return () => {
    el.removeEventListener('pointerdown', down);
    el.removeEventListener('pointermove', move);
    el.removeEventListener('pointerup', up);
    el.removeEventListener('pointercancel', up);
  };
}

/**
 * Erkennt ein langes Antippen (Touch) bzw. Rechtsklick und ruft denselben Handler auf.
 * Wird fuer Kontextmenues auf dem Handy verwendet.
 */
export function onLongPress(el, handler, delay = 480) {
  let timer = 0;
  let sx = 0;
  let sy = 0;
  let fired = false;

  const cancel = () => {
    clearTimeout(timer);
    timer = 0;
  };

  el.addEventListener(
    'pointerdown',
    (e) => {
      if (e.pointerType === 'mouse') return;
      fired = false;
      sx = e.clientX;
      sy = e.clientY;
      cancel();
      timer = setTimeout(() => {
        fired = true;
        handler({ x: sx, y: sy, target: e.target, originalEvent: e });
      }, delay);
    },
    { passive: true },
  );

  el.addEventListener(
    'pointermove',
    (e) => {
      if (!timer) return;
      if (Math.abs(e.clientX - sx) > 10 || Math.abs(e.clientY - sy) > 10) cancel();
    },
    { passive: true },
  );

  el.addEventListener('pointerup', cancel, { passive: true });
  el.addEventListener('pointercancel', cancel, { passive: true });

  el.addEventListener('contextmenu', (e) => {
    e.preventDefault();
    if (fired) {
      fired = false;
      return;
    }
    handler({ x: e.clientX, y: e.clientY, target: e.target, originalEvent: e });
  });
}

/**
 * Richtet ein Canvas fuer die Pixeldichte des Geraets ein.
 * Auf iPhones wird die Aufloesung begrenzt, damit das Zeichnen guenstig bleibt.
 */
export function setupCanvas(canvas, maxDpr = 2) {
  const rect = canvas.getBoundingClientRect();
  const dpr = Math.min(window.devicePixelRatio || 1, maxDpr);
  const w = Math.max(1, Math.round(rect.width * dpr));
  const hgt = Math.max(1, Math.round(rect.height * dpr));
  if (canvas.width !== w || canvas.height !== hgt) {
    canvas.width = w;
    canvas.height = hgt;
  }
  const ctx = canvas.getContext('2d');
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  return { ctx, width: rect.width, height: rect.height, dpr };
}

/** Liest einen CSS-Custom-Property-Wert als konkrete Farbe aus. */
const cssVarCache = new Map();
export function cssVar(name) {
  if (cssVarCache.has(name)) return cssVarCache.get(name);
  const val = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  cssVarCache.set(name, val);
  return val;
}

export function clearCssVarCache() {
  cssVarCache.clear();
}

/**
 * Liefert die Spurfarbe als konkreten Farbwert.
 * Canvas kann kein var(--x) aufloesen, deshalb wird hier ausgerechnet.
 */
export function trackColorValue(id) {
  return cssVar(`--t${colorIndexFor(id)}`) || '#7d5cff';
}

/** Farbe mit Deckkraft versehen (erwartet #rrggbb oder rgb()). */
export function withAlpha(color, alpha) {
  const c = String(color).trim();
  if (c.startsWith('#')) {
    const hex = c.length === 4
      ? c.slice(1).split('').map((ch) => ch + ch).join('')
      : c.slice(1, 7);
    const num = parseInt(hex, 16);
    const r = (num >> 16) & 255;
    const g = (num >> 8) & 255;
    const b = num & 255;
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }
  if (c.startsWith('rgb(')) return c.replace('rgb(', 'rgba(').replace(')', `, ${alpha})`);
  if (c.startsWith('rgba(')) return c.replace(/[\d.]+\)$/, `${alpha})`);
  return c;
}
