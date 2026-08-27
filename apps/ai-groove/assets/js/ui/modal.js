/**
 * AI Groove – Dialoge.
 *
 * Fokusfalle, Escape zum Schliessen, Safe-Area-taugliche Positionierung
 * und saubere Aufraeumroutine (wichtig, damit keine Audioknoten liegenbleiben).
 */

import { h, icon, iconButton, clear } from '../core/dom.js';

const overlayRoot = () => document.getElementById('overlay-root');

let openCount = 0;

/**
 * Oeffnet einen Dialog.
 *
 * @param {object} options
 * @param {string} options.title
 * @param {Node|Node[]} options.body
 * @param {Array<{label:string, variant?:string, value?:any, onClick?:Function, keepOpen?:boolean}>} [options.actions]
 * @param {'narrow'|'default'|'wide'} [options.size]
 * @param {Function} [options.onClose]
 * @param {boolean} [options.dismissable]
 * @returns {{close:Function, element:HTMLElement, setBusy:Function}}
 */
export function openModal(options) {
  const {
    title,
    body,
    actions = [],
    size = 'default',
    onClose = null,
    dismissable = true,
  } = options;

  const backdrop = h('div.modal-backdrop', { role: 'presentation' });
  const modal = h(
    `div.modal${size === 'wide' ? '.modal--wide' : size === 'narrow' ? '.modal--narrow' : ''}`,
    { role: 'dialog', 'aria-modal': 'true', 'aria-label': title || 'Dialog' },
  );

  const head = h('div.modal__head');
  head.appendChild(h('div.modal__title', { text: title || '' }));
  if (dismissable) {
    const closeBtn = iconButton('close', 'Schliessen', { class: 'btn--ghost btn--sm' });
    closeBtn.addEventListener('click', () => close(null));
    head.appendChild(closeBtn);
  }

  const bodyEl = h('div.modal__body');
  if (Array.isArray(body)) for (const b of body) b && bodyEl.appendChild(b);
  else if (body) bodyEl.appendChild(body);

  modal.appendChild(head);
  modal.appendChild(bodyEl);

  let footEl = null;
  if (actions.length) {
    footEl = h('div.modal__foot');
    for (const action of actions) {
      const btn = h(`button.btn${action.variant ? `.btn--${action.variant}` : ''}`, {
        type: 'button',
        text: action.label,
        disabled: !!action.disabled,
      });
      btn.addEventListener('click', async () => {
        if (action.onClick) {
          const result = await action.onClick({ close, setBusy, body: bodyEl });
          if (result === false) return;
        }
        if (!action.keepOpen) close(action.value ?? action.label);
      });
      action.ref?.(btn);
      footEl.appendChild(btn);
    }
    modal.appendChild(footEl);
  }

  backdrop.appendChild(modal);
  overlayRoot().appendChild(backdrop);
  openCount++;

  let closed = false;
  let resolveFn = null;
  const promise = new Promise((resolve) => {
    resolveFn = resolve;
  });

  function close(value) {
    if (closed) return;
    closed = true;
    openCount--;
    document.removeEventListener('keydown', onKey, true);
    backdrop.remove();
    onClose?.(value);
    resolveFn?.(value);
  }

  function setBusy(busy, label) {
    modal.querySelectorAll('button').forEach((b) => {
      b.disabled = !!busy;
    });
    const existing = modal.querySelector('.modal__busy');
    if (!busy) {
      existing?.remove();
      return;
    }
    if (!label) return;
    const bar = existing || h('div.modal__busy.empty');
    if (!existing) bodyEl.appendChild(bar);
    clear(bar);
    bar.appendChild(h('div.spinner'));
    bar.appendChild(h('span', { text: label }));
  }

  function onKey(event) {
    if (event.key === 'Escape' && dismissable) {
      event.stopPropagation();
      event.preventDefault();
      close(null);
      return;
    }
    // Fokus im Dialog halten.
    if (event.key === 'Tab') {
      const focusables = modal.querySelectorAll(
        'button:not([disabled]), [href], input:not([disabled]), select, textarea, [tabindex]:not([tabindex="-1"])',
      );
      if (!focusables.length) return;
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  }

  document.addEventListener('keydown', onKey, true);

  backdrop.addEventListener('pointerdown', (event) => {
    if (event.target === backdrop && dismissable) close(null);
  });

  // Ersten sinnvollen Fokus setzen (nicht auf Mobilgeraeten, sonst springt die Tastatur auf).
  requestAnimationFrame(() => {
    const target = modal.querySelector('[data-autofocus]') || (window.matchMedia('(pointer: fine)').matches ? modal.querySelector('input, textarea, button.btn--primary') : null);
    target?.focus();
  });

  return { close, element: modal, body: bodyEl, foot: footEl, setBusy, promise };
}

/** Einfache Ja/Nein-Rueckfrage. */
export function confirmModal({ title, message, confirmLabel = 'OK', cancelLabel = 'Abbrechen', danger = false }) {
  return new Promise((resolve) => {
    openModal({
      title,
      size: 'narrow',
      body: h('p.prose', { text: message }),
      actions: [
        { label: cancelLabel, variant: 'ghost', onClick: () => resolve(false) },
        { label: confirmLabel, variant: danger ? 'danger' : 'primary', onClick: () => resolve(true) },
      ],
      onClose: (value) => {
        if (value == null) resolve(false);
      },
    });
  });
}

/** Einzeiliges Eingabefeld als Dialog. */
export function promptModal({ title, label, value = '', placeholder = '', confirmLabel = 'Übernehmen', maxLength = 120 }) {
  return new Promise((resolve) => {
    const input = h('input.input', {
      type: 'text',
      value,
      placeholder,
      maxLength,
      'data-autofocus': '',
    });
    const wrap = h('div.field', null, [label ? h('label.field__label', { text: label }) : null, input]);

    const modal = openModal({
      title,
      size: 'narrow',
      body: wrap,
      actions: [
        { label: 'Abbrechen', variant: 'ghost', onClick: () => resolve(null) },
        { label: confirmLabel, variant: 'primary', onClick: () => resolve(input.value.trim() || null) },
      ],
      onClose: (v) => {
        if (v == null) resolve(null);
      },
    });

    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        resolve(input.value.trim() || null);
        modal.close('ok');
      }
    });
  });
}

export function anyModalOpen() {
  return openCount > 0;
}

export { icon };
