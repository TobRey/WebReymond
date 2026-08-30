/**
 * Bedienelemente: Kopfzeile, Menü, Hell-/Dunkelmodus, Formulare.
 */

import { env, clamp } from './env.js';

/* ------------------------------------------------------------------ */
/* Kopfzeile: weicht beim Herunterscrollen aus                         */
/* ------------------------------------------------------------------ */

export function initHeader() {
  const header = document.querySelector('.wa-header');
  if (!header) return;

  const setHeight = () => {
    document.documentElement.style.setProperty(
      '--wa-header-h',
      `${header.offsetHeight}px`
    );
  };
  setHeight();
  window.addEventListener('resize', setHeight, { passive: true });

  let lastY = window.scrollY;
  let ticking = false;

  const update = () => {
    ticking = false;
    const y = window.scrollY;

    header.classList.toggle('is-stuck', y > 12);

    // Erst ab einer gewissen Tiefe ausweichen, sonst zappelt es oben.
    if (y > 260) {
      const goingDown = y > lastY + 4;
      const goingUp = y < lastY - 4;
      if (goingDown) header.classList.add('is-hidden');
      if (goingUp) header.classList.remove('is-hidden');
    } else {
      header.classList.remove('is-hidden');
    }

    lastY = y;
  };

  window.addEventListener(
    'scroll',
    () => {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(update);
    },
    { passive: true }
  );

  update();
}

/* ------------------------------------------------------------------ */
/* Menü auf schmalen Bildschirmen                                      */
/* ------------------------------------------------------------------ */

export function initMobileNav() {
  const button = document.querySelector('[data-nav-toggle]');
  const panel = document.querySelector('[data-nav-panel]');
  if (!button || !panel) return;

  const links = [...panel.querySelectorAll('a')];
  links.forEach((link, index) => link.style.setProperty('--i', String(index)));

  let lastFocused = null;

  const open = () => {
    lastFocused = document.activeElement;
    panel.classList.add('is-open');
    button.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
    links[0]?.focus();
  };

  const close = () => {
    panel.classList.remove('is-open');
    button.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    if (lastFocused instanceof HTMLElement) lastFocused.focus();
  };

  const toggle = () => {
    button.getAttribute('aria-expanded') === 'true' ? close() : open();
  };

  button.addEventListener('click', toggle);
  links.forEach((link) => link.addEventListener('click', close));

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (button.getAttribute('aria-expanded') === 'true') close();
  });

  // Solange das Menü offen ist, bleibt der Tastaturfokus darin gefangen.
  panel.addEventListener('keydown', (event) => {
    if (event.key !== 'Tab' || links.length === 0) return;
    const first = links[0];
    const last = links[links.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
}

/* ------------------------------------------------------------------ */
/* Hell- und Dunkelmodus                                               */
/*                                                                     */
/* Die Wahl bleibt nur in diesem Browser gespeichert. Sie verlässt das  */
/* Gerät nicht und wird nirgends ausgewertet.                          */
/* ------------------------------------------------------------------ */

const THEME_KEY = 'webatze-theme';

export function initTheme() {
  const root = document.documentElement;
  const button = document.querySelector('[data-theme-toggle]');

  const apply = (theme) => {
    if (theme === 'light') {
      root.setAttribute('data-theme', 'light');
    } else {
      root.removeAttribute('data-theme');
    }
    button?.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
    // Die Adressleiste des Handys soll mitfärben.
    document
      .querySelector('meta[name="theme-color"]')
      ?.setAttribute('content', theme === 'light' ? '#ffffff' : '#06060f');
  };

  let stored = null;
  try {
    stored = localStorage.getItem(THEME_KEY);
  } catch {
    // Privates Fenster oder gesperrter Speicher – dann eben ohne Merken.
  }

  apply(stored === 'light' ? 'light' : 'dark');

  button?.addEventListener('click', () => {
    const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    apply(next);
    try {
      localStorage.setItem(THEME_KEY, next);
    } catch {
      /* nicht schlimm */
    }
    document.dispatchEvent(new CustomEvent('wa:theme', { detail: { theme: next } }));
  });
}

/**
 * Wird vor dem ersten Zeichnen ausgeführt (im <head>), damit es beim Laden
 * nicht kurz hell aufblitzt, wenn eigentlich hell gewählt wurde.
 */
export function themeBootScript() {
  return `try{var t=localStorage.getItem('${THEME_KEY}');if(t==='light'){document.documentElement.setAttribute('data-theme','light')}}catch(e){}`;
}

/* ------------------------------------------------------------------ */
/* Nur eine FAQ-Antwort gleichzeitig offen                             */
/* ------------------------------------------------------------------ */

export function initAccordion() {
  const items = [...document.querySelectorAll('.wa-faq__item')];
  if (items.length === 0) return;

  items.forEach((item) => {
    item.addEventListener('toggle', () => {
      if (!item.open) return;
      items.forEach((other) => {
        if (other !== item) other.open = false;
      });
    });
  });
}

/* ------------------------------------------------------------------ */
/* Kontaktformular ohne Neuladen absenden                              */
/* ------------------------------------------------------------------ */

export function initContactForm() {
  const form = document.querySelector('[data-contact-form]');
  if (!form) return;

  const status = form.querySelector('[data-form-status]');
  const submit = form.querySelector('button[type="submit"]');
  const originalLabel = submit?.textContent ?? '';

  // Zeitfalle: ein Formular, das in unter zwei Sekunden ankommt,
  // wurde nicht von einem Menschen ausgefüllt.
  const startedAt = Date.now();
  const startedField = form.querySelector('input[name="started_at"]');
  if (startedField) startedField.value = String(startedAt);

  form.addEventListener('submit', async (event) => {
    event.preventDefault();

    if (submit) {
      submit.disabled = true;
      submit.innerHTML = `<span class="wa-spinner"></span> ${form.dataset.sendingLabel ?? '...'}`;
    }
    if (status) status.textContent = '';

    // Vorherige Fehlermarkierungen entfernen
    form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
    form.querySelectorAll('.wa-error').forEach((el) => el.remove());

    try {
      const body = new FormData(form);
      body.set('elapsed', String(Date.now() - startedAt));

      const response = await fetch(form.action, {
        method: 'POST',
        body,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      });

      const data = await response.json().catch(() => ({}));

      if (response.ok && data.ok) {
        form.innerHTML = `
          <div class="wa-note wa-note--success">
            <div>
              <strong>${escapeHtml(data.title ?? '')}</strong>
              ${escapeHtml(data.message ?? '')}
            </div>
          </div>`;
        return;
      }

      // Fehler den einzelnen Feldern zuordnen
      if (data.errors && typeof data.errors === 'object') {
        for (const [field, message] of Object.entries(data.errors)) {
          const input = form.querySelector(`[name="${CSS.escape(field)}"]`);
          if (!input) continue;
          input.setAttribute('aria-invalid', 'true');
          const error = document.createElement('p');
          error.className = 'wa-error';
          error.textContent = String(message);
          input.insertAdjacentElement('afterend', error);
        }
      }

      if (status) {
        status.innerHTML = `<div class="wa-note wa-note--danger">${escapeHtml(
          data.error ?? 'Bitte versuche es noch einmal.'
        )}</div>`;
      }
    } catch {
      if (status) {
        status.innerHTML =
          '<div class="wa-note wa-note--danger">Die Verbindung ist unterbrochen. Bitte versuche es noch einmal.</div>';
      }
    } finally {
      if (submit) {
        submit.disabled = false;
        submit.textContent = originalLabel;
      }
    }
  });
}

function escapeHtml(value) {
  const div = document.createElement('div');
  div.textContent = String(value);
  return div.innerHTML;
}

/* ------------------------------------------------------------------ */
/* Sanftes Springen zu Ankern, ohne die Adresse zu verhunzen           */
/* ------------------------------------------------------------------ */

export function initAnchors() {
  document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('a[href^="#"]') : null;
    if (!link) return;

    const id = link.getAttribute('href')?.slice(1);
    if (!id) return;

    const target = document.getElementById(id);
    if (!target) return;

    event.preventDefault();
    target.scrollIntoView({
      behavior: env.reducedMotion ? 'auto' : 'smooth',
      block: 'start',
    });

    // Damit die Tastatur nach dem Sprung am richtigen Ort weitermacht.
    target.setAttribute('tabindex', '-1');
    target.focus({ preventScroll: true });
  });
}

/* ------------------------------------------------------------------ */
/* Fortschrittsbalken ganz oben                                        */
/* ------------------------------------------------------------------ */

export function initReadingProgress() {
  const bar = document.querySelector('[data-reading-progress]');
  if (!bar) return;

  const update = () => {
    const max = document.documentElement.scrollHeight - window.innerHeight;
    const value = max > 0 ? clamp(window.scrollY / max, 0, 1) : 0;
    bar.style.transform = `scaleX(${value.toFixed(4)})`;
  };

  window.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update, { passive: true });
  update();
}
