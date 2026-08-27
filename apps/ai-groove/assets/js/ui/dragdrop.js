/**
 * AI Groove – Drag & Drop für Maus UND Touch.
 *
 * HTML5-Drag-&-Drop funktioniert auf iOS nicht zuverlässig. Deshalb ein
 * eigenes System auf Basis von Pointer-Events: identisches Verhalten auf
 * iPhone, iPad, Android und Desktop.
 */

import { h } from '../core/dom.js';
import { haptic } from '../core/util.js';

/** Aktuell laufender Vorgang. */
let session = null;

/** Registrierte Ablageziele. */
const targets = new Set();

const DRAG_THRESHOLD = 6;

function createGhost(label) {
  const ghost = h('div.drag-ghost', { text: label });
  document.body.appendChild(ghost);
  return ghost;
}

function moveGhost(ghost, x, y) {
  ghost.style.left = `${x}px`;
  ghost.style.top = `${y}px`;
}

function findTarget(x, y) {
  const el = document.elementFromPoint(x, y);
  if (!el) return null;
  for (const target of targets) {
    if (target.element.contains(el) || target.element === el) {
      if (!target.accepts || target.accepts(session.payload)) return target;
    }
  }
  return null;
}

function updateHover(x, y) {
  const target = findTarget(x, y);
  if (target === session.hover) {
    session.hover?.onOver?.(session.payload, x, y);
    return;
  }
  if (session.hover) {
    session.hover.element.classList.remove('drop-target--active');
    session.hover.onLeave?.(session.payload);
  }
  session.hover = target;
  if (target) {
    target.element.classList.add('drop-target--active');
    target.onEnter?.(session.payload, x, y);
    haptic(6);
  }
}

function endSession(x, y, cancelled) {
  if (!session) return;
  const { ghost, payload, onEnd } = session;
  const hover = session.hover;

  ghost?.remove();
  document.removeEventListener('pointermove', onPointerMove);
  document.removeEventListener('pointerup', onPointerUp);
  document.removeEventListener('pointercancel', onPointerCancel);
  document.body.style.removeProperty('user-select');

  if (hover) hover.element.classList.remove('drop-target--active');
  const s = session;
  session = null;

  if (!cancelled && hover) {
    try {
      hover.onDrop?.(payload, x, y);
      haptic([8, 30, 8]);
    } catch (err) {
      console.error('[dragdrop] Fehler beim Ablegen', err);
    }
  }
  onEnd?.(!cancelled && !!hover);
  s.source?.classList.remove('sitem--dragging');
}

function onPointerMove(event) {
  if (!session) return;
  if (!session.active) {
    const dx = Math.abs(event.clientX - session.startX);
    const dy = Math.abs(event.clientY - session.startY);
    if (dx < DRAG_THRESHOLD && dy < DRAG_THRESHOLD) return;
    session.active = true;
    session.ghost = createGhost(session.label);
    session.source?.classList.add('sitem--dragging');
    document.body.style.userSelect = 'none';
    haptic(12);
  }
  moveGhost(session.ghost, event.clientX, event.clientY);
  updateHover(event.clientX, event.clientY);
  event.preventDefault();
}

function onPointerUp(event) {
  if (!session) return;
  if (!session.active) {
    // Kein Ziehen, sondern ein Klick: Aufraeumen und Klick durchlassen.
    endSession(event.clientX, event.clientY, true);
    return;
  }
  endSession(event.clientX, event.clientY, false);
}

function onPointerCancel() {
  endSession(0, 0, true);
}

/**
 * Macht ein Element ziehbar.
 *
 * @param {HTMLElement} element
 * @param {object} options
 * @param {() => object} options.payload  Daten, die beim Ablegen uebergeben werden
 * @param {() => string} options.label    Beschriftung des Ziehbildes
 * @param {HTMLElement} [options.handle]  Nur dieser Bereich startet das Ziehen
 * @param {Function} [options.onEnd]
 */
export function makeDraggable(element, options) {
  const handle = options.handle || element;

  const start = (event) => {
    if (session) return;
    // Nur primäre Taste bzw. ein Finger.
    if (event.button != null && event.button !== 0) return;
    session = {
      startX: event.clientX,
      startY: event.clientY,
      payload: options.payload(),
      label: options.label(),
      active: false,
      ghost: null,
      hover: null,
      source: element,
      onEnd: options.onEnd,
    };
    document.addEventListener('pointermove', onPointerMove, { passive: false });
    document.addEventListener('pointerup', onPointerUp);
    document.addEventListener('pointercancel', onPointerCancel);
  };

  handle.addEventListener('pointerdown', start);
  handle.style.touchAction = 'none';

  return () => handle.removeEventListener('pointerdown', start);
}

/**
 * Registriert ein Ablageziel.
 *
 * @param {HTMLElement} element
 * @param {object} options
 * @param {(payload:object) => boolean} [options.accepts]
 * @param {(payload:object, x:number, y:number) => void} options.onDrop
 */
export function makeDropTarget(element, options) {
  const target = { element, ...options };
  targets.add(target);
  element.classList.add('drop-target');
  return () => {
    targets.delete(target);
    element.classList.remove('drop-target', 'drop-target--active');
  };
}

/** Laeuft gerade ein Ziehvorgang? */
export function isDragging() {
  return !!session && session.active;
}

export function currentPayload() {
  return session?.payload || null;
}

/**
 * Dateien per Drag & Drop aus dem Betriebssystem entgegennehmen
 * (funktioniert nur auf dem Desktop – das ist erwartungsgemäss).
 */
export function acceptFileDrop(element, onFiles) {
  const stop = (event) => {
    event.preventDefault();
    event.stopPropagation();
  };

  const onDragOver = (event) => {
    stop(event);
    element.classList.add('drop-target--active');
  };
  const onDragLeave = (event) => {
    stop(event);
    if (!element.contains(event.relatedTarget)) element.classList.remove('drop-target--active');
  };
  const onDrop = (event) => {
    stop(event);
    element.classList.remove('drop-target--active');
    const files = Array.from(event.dataTransfer?.files || []);
    if (files.length) onFiles(files);
  };

  element.addEventListener('dragover', onDragOver);
  element.addEventListener('dragenter', onDragOver);
  element.addEventListener('dragleave', onDragLeave);
  element.addEventListener('drop', onDrop);

  return () => {
    element.removeEventListener('dragover', onDragOver);
    element.removeEventListener('dragenter', onDragOver);
    element.removeEventListener('dragleave', onDragLeave);
    element.removeEventListener('drop', onDrop);
  };
}
