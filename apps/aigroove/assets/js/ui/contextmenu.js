/**
 * AI Groove – Kontextmenü.
 *
 * Funktioniert mit Rechtsklick (Desktop) und langem Antippen (Touch).
 * Positioniert sich immer innerhalb des sichtbaren Bereichs.
 */

import { h, icon } from '../core/dom.js';
import { haptic } from '../core/util.js';

let current = null;

export function closeContextMenu() {
  if (!current) return;
  current.el.remove();
  document.removeEventListener('pointerdown', current.outside, true);
  document.removeEventListener('keydown', current.onKey, true);
  window.removeEventListener('resize', closeContextMenu);
  window.removeEventListener('scroll', closeContextMenu, true);
  current = null;
}

/**
 * @param {{x:number, y:number}} position
 * @param {Array<{label?:string, icon?:string, kbd?:string, danger?:boolean,
 *                disabled?:boolean, separator?:boolean, heading?:string, onClick?:Function}>} items
 */
export function openContextMenu(position, items) {
  closeContextMenu();
  if (!items || !items.length) return;

  const el = h('div.ctxmenu', { role: 'menu' });

  for (const item of items) {
    if (!item) continue;
    if (item.separator) {
      el.appendChild(h('div.ctxmenu__sep'));
      continue;
    }
    if (item.heading) {
      el.appendChild(h('div.ctxmenu__label', { text: item.heading }));
      continue;
    }
    const btn = h(`button.ctxmenu__item${item.danger ? '.ctxmenu__item--danger' : ''}`, {
      type: 'button',
      role: 'menuitem',
      disabled: !!item.disabled,
    });
    if (item.icon) btn.appendChild(icon(item.icon, 16));
    btn.appendChild(h('span', { text: item.label }));
    if (item.kbd) btn.appendChild(h('span.ctxmenu__kbd', { text: item.kbd }));
    btn.addEventListener('click', () => {
      closeContextMenu();
      item.onClick?.();
    });
    el.appendChild(btn);
  }

  document.body.appendChild(el);

  // Positionieren, ohne aus dem Bild zu rutschen (inkl. Safe Areas).
  const rect = el.getBoundingClientRect();
  const pad = 10;
  let x = position.x;
  let y = position.y;
  if (x + rect.width + pad > window.innerWidth) x = window.innerWidth - rect.width - pad;
  if (y + rect.height + pad > window.innerHeight) y = window.innerHeight - rect.height - pad;
  el.style.left = `${Math.max(pad, x)}px`;
  el.style.top = `${Math.max(pad, y)}px`;

  const outside = (event) => {
    if (!el.contains(event.target)) closeContextMenu();
  };
  const onKey = (event) => {
    if (event.key === 'Escape') {
      event.stopPropagation();
      closeContextMenu();
    }
  };

  // Erst im naechsten Frame lauschen, sonst schliesst das oeffnende Ereignis sofort wieder.
  requestAnimationFrame(() => {
    document.addEventListener('pointerdown', outside, true);
  });
  document.addEventListener('keydown', onKey, true);
  window.addEventListener('resize', closeContextMenu);
  window.addEventListener('scroll', closeContextMenu, true);

  current = { el, outside, onKey };
  haptic(10);
  return { close: closeContextMenu };
}
