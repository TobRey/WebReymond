/**
 * Alle Bewegungen der Seite ausser der 3D-Szene.
 *
 * Grundsätze:
 *   - Ein einziger Beobachter für alle Einblendungen statt einer pro Element.
 *   - Parallax läuft über CSS-Variablen; JavaScript setzt nur Zahlen,
 *     das Rechnen macht der Browser auf der Grafikkarte.
 *   - Alles hier ist Zugabe. Ohne JavaScript ist die Seite vollständig lesbar.
 */

import { env, clamp, lerp, onFrame } from './env.js';

/* ------------------------------------------------------------------ */
/* Einblenden beim Scrollen                                            */
/* ------------------------------------------------------------------ */

export function initReveals() {
  const targets = document.querySelectorAll('[data-reveal], [data-reveal-stagger]');
  if (targets.length === 0) return;

  // Kein IntersectionObserver: alles sofort zeigen statt gar nichts.
  if (!('IntersectionObserver' in window)) {
    targets.forEach((el) => el.classList.add('is-revealed'));
    return;
  }

  // Kinder durchnummerieren, damit sie versetzt erscheinen.
  document.querySelectorAll('[data-reveal-stagger]').forEach((group) => {
    [...group.children].forEach((child, index) => {
      child.setAttribute('data-reveal-child', '');
      child.style.setProperty('--i', String(index));
    });
  });

  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        entry.target.classList.add('is-revealed');
        // Einmal gezeigt ist gezeigt – nicht wieder ausblenden beim Zurückscrollen.
        observer.unobserve(entry.target);
      }
    },
    { rootMargin: '0px 0px -12% 0px', threshold: 0.12 }
  );

  const viewportHeight = window.innerHeight;

  targets.forEach((el) => {
    // Was beim Laden schon im Bild steht, wird sofort gezeigt.
    //
    // Der Beobachter oben zieht seinen unteren Rand um 12 Prozent nach
    // innen. Ein Element knapp über der Bildschirmkante fiele dadurch
    // durch und bliebe unsichtbar, bis jemand scrollt – bei einem
    // Besucher, der gar nicht scrollt, für immer.
    if (el.getBoundingClientRect().top < viewportHeight) {
      el.classList.add('is-revealed');
      return;
    }
    observer.observe(el);
  });
}

/* ------------------------------------------------------------------ */
/* Text Wort für Wort enthüllen                                        */
/* ------------------------------------------------------------------ */

export function initWordReveal() {
  document.querySelectorAll('[data-words]').forEach((el) => {
    if (el.dataset.wordsDone === '1') return;

    const text = el.textContent ?? '';
    const words = text.split(/(\s+)/);

    const fragment = document.createDocumentFragment();
    let index = 0;

    for (const word of words) {
      if (word.trim() === '') {
        fragment.append(word);
        continue;
      }
      const outer = document.createElement('span');
      outer.className = 'wa-word';
      outer.style.setProperty('--i', String(index++));
      const inner = document.createElement('span');
      inner.textContent = word;
      outer.append(inner);
      fragment.append(outer);
    }

    el.textContent = '';
    el.append(fragment);
    el.dataset.wordsDone = '1';
  });
}

/* ------------------------------------------------------------------ */
/* Parallax                                                            */
/* ------------------------------------------------------------------ */

export function initParallax() {
  const items = [...document.querySelectorAll('[data-parallax]')];
  if (items.length === 0 || env.reducedMotion) return;

  let ticking = false;

  const update = () => {
    ticking = false;
    const viewport = window.innerHeight;

    for (const el of items) {
      const rect = el.getBoundingClientRect();

      // Ausserhalb des Sichtfelds nicht rechnen.
      if (rect.bottom < -viewport * 0.5 || rect.top > viewport * 1.5) continue;

      // 0 = Element betritt den Bildschirm unten, 1 = verlässt ihn oben.
      const progress = clamp(
        (viewport - rect.top) / (viewport + rect.height),
        0,
        1
      );
      el.style.setProperty('--p', progress.toFixed(4));
    }
  };

  const onScroll = () => {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(update);
  };

  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });
  update();
}

/* ------------------------------------------------------------------ */
/* Scrollfortschritt als CSS-Variable                                  */
/* ------------------------------------------------------------------ */

export function initScrollProgress() {
  const root = document.documentElement;
  let ticking = false;

  const update = () => {
    ticking = false;
    const max = root.scrollHeight - window.innerHeight;
    const value = max > 0 ? clamp(window.scrollY / max, 0, 1) : 0;
    root.style.setProperty('--wa-scroll', value.toFixed(4));
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
/* Karten, die sich unter dem Mauszeiger neigen                        */
/* ------------------------------------------------------------------ */

export function initTilt() {
  if (!env.hasPointer || env.reducedMotion) return;

  const cards = document.querySelectorAll('.wa-card--tilt, [data-tilt]');

  cards.forEach((card) => {
    const strength = Number(card.dataset.tiltStrength ?? 7);
    let raf = 0;
    let targetX = 0;
    let targetY = 0;
    let currentX = 0;
    let currentY = 0;

    const animate = () => {
      currentX = lerp(currentX, targetX, 0.14);
      currentY = lerp(currentY, targetY, 0.14);

      card.style.setProperty('--rx', `${currentY.toFixed(2)}deg`);
      card.style.setProperty('--ry', `${currentX.toFixed(2)}deg`);

      if (Math.abs(currentX - targetX) > 0.01 || Math.abs(currentY - targetY) > 0.01) {
        raf = requestAnimationFrame(animate);
      } else {
        raf = 0;
      }
    };

    const start = () => {
      if (raf === 0) raf = requestAnimationFrame(animate);
    };

    card.addEventListener('pointermove', (event) => {
      const rect = card.getBoundingClientRect();
      const px = (event.clientX - rect.left) / rect.width;
      const py = (event.clientY - rect.top) / rect.height;

      targetX = (px - 0.5) * strength * 2;
      targetY = (0.5 - py) * strength * 2;

      // Für den Glanzfleck im CSS
      card.style.setProperty('--px', `${(px * 100).toFixed(1)}%`);
      card.style.setProperty('--py', `${(py * 100).toFixed(1)}%`);
      card.style.setProperty('--lift', '-6px');

      start();
    });

    card.addEventListener('pointerleave', () => {
      targetX = 0;
      targetY = 0;
      card.style.setProperty('--lift', '0px');
      start();
    });
  });
}

/* ------------------------------------------------------------------ */
/* Magnetische Schaltflächen                                           */
/*                                                                     */
/* Die Schaltfläche folgt dem Zeiger ein Stück weit. Zwei Dinge waren  */
/* daran vorher falsch:                                                */
/*                                                                     */
/* 1. Die Verschiebung war ein Anteil des Abstands in Pixeln. Bei      */
/*    einer breiten Schaltfläche sind das am Rand schnell 40 Pixel -   */
/*    sie schob sich dann über den Rand ihres Behälters und wurde dort */
/*    abgeschnitten. Jetzt zählt der Abstand relativ (−1 bis 1), und   */
/*    ein fester Höchstwert in Pixeln begrenzt ihn. Eine grosse und    */
/*    eine kleine Schaltfläche bewegen sich damit gleich viel.         */
/*                                                                     */
/* 2. getBoundingClientRect() liefert die bereits verschobene Lage.    */
/*    Der gemessene Abstand wurde dadurch von der eigenen Bewegung     */
/*    verfälscht. Die aktuelle Verschiebung wird jetzt herausgerechnet.*/
/* ------------------------------------------------------------------ */

/** So weit darf eine Schaltfläche höchstens wandern. */
const MAGNET_MAX_X = 10;
const MAGNET_MAX_Y = 6;

const klemme = (wert, grenze) => Math.max(-grenze, Math.min(grenze, wert));

export function initMagnets() {
  if (!env.hasPointer || env.reducedMotion) return;

  document.querySelectorAll('[data-magnet]').forEach((el) => {
    // Der Wert im Attribut ist die Stärke: 1 heisst "bis zum
    // Höchstwert", 0.2 heisst "ein Fünftel davon".
    const pull = Math.min(1, Math.max(0, Number(el.dataset.magnet || 0.32) * 3));

    let mx = 0;
    let my = 0;

    el.addEventListener('pointermove', (event) => {
      const rect = el.getBoundingClientRect();

      // Die Mitte, wie sie ohne die eigene Verschiebung läge.
      const mitteX = rect.left + rect.width / 2 - mx;
      const mitteY = rect.top + rect.height / 2 - my;

      const anteilX = klemme((event.clientX - mitteX) / (rect.width / 2), 1);
      const anteilY = klemme((event.clientY - mitteY) / (rect.height / 2), 1);

      mx = anteilX * MAGNET_MAX_X * pull;
      my = anteilY * MAGNET_MAX_Y * pull;

      el.style.setProperty('--mx', `${mx.toFixed(1)}px`);
      el.style.setProperty('--my', `${my.toFixed(1)}px`);

      // Der Lichtschein sitzt auf der Fläche selbst, deshalb hier die
      // tatsächliche Lage - und auf 0 bis 100 begrenzt, damit er nicht
      // halb daneben liegt.
      const px = klemme(((event.clientX - rect.left) / rect.width) * 100, 100);
      const py = klemme(((event.clientY - rect.top) / rect.height) * 100, 100);

      el.style.setProperty('--px', `${Math.max(0, px).toFixed(1)}%`);
      el.style.setProperty('--py', `${Math.max(0, py).toFixed(1)}%`);
      el.style.transition = 'none';
    });

    el.addEventListener('pointerleave', () => {
      mx = 0;
      my = 0;
      el.style.transition = '';
      el.style.setProperty('--mx', '0px');
      el.style.setProperty('--my', '0px');
    });
  });
}

/* ------------------------------------------------------------------ */
/* Eigener Mauszeiger                                                  */
/* ------------------------------------------------------------------ */

export function initCursor() {
  if (!env.hasPointer || env.reducedMotion) return;

  const cursor = document.createElement('div');
  cursor.className = 'wa-cursor';
  cursor.setAttribute('aria-hidden', 'true');
  document.body.append(cursor);

  let targetX = window.innerWidth / 2;
  let targetY = window.innerHeight / 2;
  let x = targetX;
  let y = targetY;

  window.addEventListener(
    'pointermove',
    (event) => {
      targetX = event.clientX;
      targetY = event.clientY;
      cursor.classList.add('is-visible');

      // Für Effekte, die der Maus folgen (Leuchten im Hintergrund)
      document.documentElement.style.setProperty(
        '--wa-pointer-x',
        (event.clientX / window.innerWidth).toFixed(3)
      );
      document.documentElement.style.setProperty(
        '--wa-pointer-y',
        (event.clientY / window.innerHeight).toFixed(3)
      );
    },
    { passive: true }
  );

  document.addEventListener('pointerleave', () => cursor.classList.remove('is-visible'));

  // Über anklickbaren Dingen wird der Ring grösser.
  const interactive = 'a, button, summary, input, textarea, select, [role="button"]';
  document.addEventListener('pointerover', (event) => {
    if (event.target instanceof Element && event.target.closest(interactive)) {
      cursor.classList.add('is-hovering');
    }
  });
  document.addEventListener('pointerout', (event) => {
    if (event.target instanceof Element && event.target.closest(interactive)) {
      cursor.classList.remove('is-hovering');
    }
  });

  onFrame(() => {
    x = lerp(x, targetX, 0.2);
    y = lerp(y, targetY, 0.2);
    cursor.style.transform = `translate3d(${x - 12}px, ${y - 12}px, 0)`;
  });
}

/* ------------------------------------------------------------------ */
/* Zahlen, die hochzählen                                              */
/* ------------------------------------------------------------------ */

export function initCounters() {
  const counters = document.querySelectorAll('[data-count-to]');
  if (counters.length === 0) return;

  const run = (el) => {
    const to = Number(el.dataset.countTo ?? 0);
    const suffix = el.dataset.countSuffix ?? '';
    const duration = Number(el.dataset.countDuration ?? 1600);

    if (env.reducedMotion) {
      el.textContent = `${to}${suffix}`;
      return;
    }

    const start = performance.now();

    const step = (now) => {
      const t = clamp((now - start) / duration, 0, 1);
      // Sanft auslaufen statt abrupt stoppen
      const eased = 1 - Math.pow(1 - t, 3);
      el.textContent = `${Math.round(to * eased)}${suffix}`;
      if (t < 1) requestAnimationFrame(step);
    };

    requestAnimationFrame(step);
  };

  if (!('IntersectionObserver' in window)) {
    counters.forEach(run);
    return;
  }

  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        run(entry.target);
        observer.unobserve(entry.target);
      }
    },
    { threshold: 0.4 }
  );

  counters.forEach((el) => observer.observe(el));
}

/* ------------------------------------------------------------------ */
/* Laufband endlos machen                                              */
/* ------------------------------------------------------------------ */

export function initMarquee() {
  document.querySelectorAll('.wa-marquee').forEach((marquee) => {
    const track = marquee.querySelector('.wa-marquee__track');
    if (!track || track.dataset.cloned === '1') return;

    // Eine Kopie dahinter, damit der Übergang nahtlos wirkt.
    const clone = track.cloneNode(true);
    clone.setAttribute('aria-hidden', 'true');
    track.dataset.cloned = '1';
    marquee.append(clone);
  });
}

/* ------------------------------------------------------------------ */
/* Miniaturen der Referenzen erst laden, wenn sie sichtbar werden      */
/* ------------------------------------------------------------------ */

export function initThumbnails() {
  const frames = document.querySelectorAll('iframe[data-src]');
  if (frames.length === 0) return;

  const load = (frame) => {
    const src = frame.dataset.src;
    if (!src) return;
    frame.src = src;
    delete frame.dataset.src;
  };

  if (!('IntersectionObserver' in window)) {
    frames.forEach(load);
    return;
  }

  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue;
        load(entry.target);
        observer.unobserve(entry.target);
      }
    },
    { rootMargin: '320px' }
  );

  frames.forEach((frame) => observer.observe(frame));
}
