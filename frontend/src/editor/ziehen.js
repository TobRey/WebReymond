/**
 * Die Zugmechanik.
 *
 * Ein Zug ist an der Maus und am Finger dasselbe – deshalb steht hier
 * nur ein Satz Ereignisse (Pointer) und nicht zwei. Der Unterschied
 * liegt woanders, und er ist wichtig genug für eine eigene Regel:
 *
 *   Mit der Maus beginnt der Zug, sobald sich der Zeiger ein paar Pixel
 *   bewegt hat. Es gibt nichts anderes, was eine gedrückte Maustaste
 *   sonst bedeuten könnte.
 *
 *   Mit dem Finger beginnt er erst nach 250 Millisekunden Halten. Ohne
 *   diese Wartezeit liesse sich die Seite nicht mehr scrollen – jede
 *   Wischbewegung wäre ein Zug, und der Editor wäre auf dem Telefon
 *   unbenutzbar.
 *
 * Wer nicht ziehen mag, muss nicht: Der Editor bietet zusätzlich einen
 * Weg über Antippen an (siehe editor.js). Auf einem kleinen Bildschirm
 * ist der oft schneller, und bei zittriger Hand ist er der einzige, der
 * verlässlich funktioniert.
 */

/** Wie lange der Finger stillhalten muss, bevor der Zug beginnt. */
const HALTEN_MS = 250;

/** Wie weit die Maus sich bewegen muss, bevor aus dem Klick ein Zug wird. */
const SCHWELLE_PX = 5;

/** Wie nah am Rand die Seite von selbst weiterrollt. */
const ROLLZONE_PX = 90;

/** Wie schnell sie das tut. */
const ROLLTEMPO = 14;

/**
 * Etwas ziehbar machen.
 *
 * @param {object} o
 * @param {Document} o.dokument      wo gezogen wird (das Dokument im Rahmen)
 * @param {string}   o.griff         Auswahl für das, was den Zug auslöst
 * @param {Function} o.ziel          (element) => das Element, das bewegt wird
 * @param {Function} o.plaetze       (element) => [{ vor: element|null, kasten }]
 * @param {Function} o.abgelegt      (element, platzIndex) => void
 * @param {Function} [o.beginnt]     (element) => void
 * @param {Function} [o.beendet]     () => void
 */
export function ziehbar(o) {
  const dok = o.dokument;
  let zustand = null;

  dok.addEventListener('pointerdown', beginn, { passive: false });

  function beginn(e) {
    // Nur die linke Maustaste. Ein Rechtsklick öffnet ein Menü, und wer
    // mitten im Menü plötzlich einen Abschnitt verschiebt, hat sich das
    // nicht ausgesucht.
    if (e.button !== 0) return;

    const griff = e.target.closest?.(o.griff);
    if (!griff) return;

    const element = o.ziel(griff);
    if (!element) return;

    zustand = {
      element,
      griff,
      zeiger: e.pointerId,
      startX: e.clientX,
      startY: e.clientY,
      finger: e.pointerType !== 'mouse',
      laeuft: false,
      warten: null,
      plaetze: [],
      gewaehlt: -1,
      linie: null,
      geist: null,
      rollen: 0,
    };

    if (zustand.finger) {
      // Beim Finger darf der Browser vorerst weiter scrollen – erst wenn
      // der Zug wirklich beginnt, nehmen wir ihm das ab.
      zustand.warten = setTimeout(() => starten(e), HALTEN_MS);
    }

    dok.addEventListener('pointermove', bewegen, { passive: false });
    dok.addEventListener('pointerup', ende);
    dok.addEventListener('pointercancel', abbrechen);
  }

  function starten(e) {
    if (!zustand || zustand.laeuft) return;

    zustand.laeuft = true;
    zustand.plaetze = o.plaetze(zustand.element) || [];

    dok.documentElement.classList.add('wae-zieht');
    zustand.element.classList.add('wae-gezogen');

    zustand.linie = dok.createElement('div');
    zustand.linie.className = 'wae wae-linie';
    dok.body.appendChild(zustand.linie);

    // Ein Schatten am Zeiger, damit sichtbar bleibt, was gerade in der
    // Hand liegt – gerade auf dem Telefon, wo der Finger das Original
    // verdeckt.
    zustand.geist = dok.createElement('div');
    zustand.geist.className = 'wae wae-geist';
    zustand.geist.textContent = zustand.element.dataset.section
      || zustand.element.dataset.block
      || 'Element';
    dok.body.appendChild(zustand.geist);

    o.beginnt?.(zustand.element);

    try {
      zustand.griff.setPointerCapture?.(e.pointerId);
    } catch {
      /* Manche Browser verweigern das mitten im Zug – dann eben ohne. */
    }

    zeigen(e.clientX, e.clientY);
  }

  function bewegen(e) {
    if (!zustand || e.pointerId !== zustand.zeiger) return;

    const weit = Math.hypot(e.clientX - zustand.startX, e.clientY - zustand.startY);

    if (!zustand.laeuft) {
      if (zustand.finger) {
        // Vor Ablauf der Wartezeit ist eine Bewegung ein Wischen und
        // kein Zug. Dann wird der Zug verworfen, nicht gestartet.
        if (weit > 12) abbrechen();
        return;
      }

      if (weit < SCHWELLE_PX) return;

      starten(e);
    }

    e.preventDefault();
    zeigen(e.clientX, e.clientY);
    rollen(e.clientY);
  }

  /** Den nächstgelegenen Platz suchen und die Linie dorthin legen. */
  function zeigen(x, y) {
    if (!zustand?.laeuft) return;

    zustand.geist.style.transform = `translate(${x + 14}px, ${y + 14}px)`;

    let naechster = -1;
    let abstand = Infinity;

    zustand.plaetze.forEach((platz, i) => {
      const mitte = platz.kasten.top + platz.kasten.height / 2;
      const d = Math.abs(y - mitte);

      if (d < abstand) {
        abstand = d;
        naechster = i;
      }
    });

    zustand.gewaehlt = naechster;

    if (naechster < 0) {
      zustand.linie.style.display = 'none';
      return;
    }

    const k = zustand.plaetze[naechster].kasten;

    zustand.linie.style.display = 'block';
    zustand.linie.style.transform = `translate(${k.left}px, ${k.top}px)`;
    zustand.linie.style.width = `${k.width}px`;
  }

  /**
   * Am Rand von selbst weiterrollen.
   *
   * Ohne das lässt sich ein Abschnitt nur dorthin ziehen, was gerade
   * sichtbar ist – auf einer langen Seite also fast nirgendwohin.
   */
  function rollen(y) {
    const hoehe = dok.defaultView.innerHeight;

    let tempo = 0;

    if (y < ROLLZONE_PX) tempo = -ROLLTEMPO * (1 - y / ROLLZONE_PX);
    else if (y > hoehe - ROLLZONE_PX) tempo = ROLLTEMPO * (1 - (hoehe - y) / ROLLZONE_PX);

    if (tempo === 0) {
      if (zustand.rollen) {
        cancelAnimationFrame(zustand.rollen);
        zustand.rollen = 0;
      }
      return;
    }

    if (zustand.rollen) return;

    const schritt = () => {
      if (!zustand?.laeuft) return;

      dok.defaultView.scrollBy(0, tempo);

      // Die Kästen verschieben sich beim Rollen mit, also neu messen.
      zustand.plaetze = o.plaetze(zustand.element) || [];
      zustand.rollen = requestAnimationFrame(schritt);
    };

    zustand.rollen = requestAnimationFrame(schritt);
  }

  function ende() {
    if (!zustand) return;

    const lief = zustand.laeuft;
    const element = zustand.element;
    const platz = zustand.gewaehlt;

    aufraeumen();

    if (lief && platz >= 0) o.abgelegt(element, platz);
  }

  function abbrechen() {
    aufraeumen();
  }

  function aufraeumen() {
    if (!zustand) return;

    clearTimeout(zustand.warten);

    if (zustand.rollen) cancelAnimationFrame(zustand.rollen);

    zustand.linie?.remove();
    zustand.geist?.remove();
    zustand.element.classList.remove('wae-gezogen');
    dok.documentElement.classList.remove('wae-zieht');

    dok.removeEventListener('pointermove', bewegen);
    dok.removeEventListener('pointerup', ende);
    dok.removeEventListener('pointercancel', abbrechen);

    if (zustand.laeuft) o.beendet?.();

    zustand = null;
  }
}

/**
 * Die möglichen Plätze zwischen einer Reihe von Geschwistern.
 *
 * Zurück kommt für jeden Platz ein Kasten in Fensterkoordinaten – dort
 * wird die Linie gezeichnet, und dort wird gemessen, welcher Platz der
 * nächste ist.
 *
 * @param {Element[]} geschwister
 * @param {Element}   bewegt      was gerade gezogen wird
 * @param {Function}  [erlaubt]   (element, index) => darf hier abgelegt werden?
 */
export function plaetzeZwischen(geschwister, bewegt, erlaubt) {
  const plaetze = [];

  geschwister.forEach((el, i) => {
    if (erlaubt && !erlaubt(el, i)) return;

    const k = el.getBoundingClientRect();

    // Vor diesem Element.
    plaetze.push({
      vor: el,
      index: i,
      kasten: { top: k.top, left: k.left, width: k.width, height: 1 },
    });
  });

  // Und hinter dem letzten erlaubten.
  const letzte = geschwister.filter((el, i) => !erlaubt || erlaubt(el, i));

  if (letzte.length) {
    const k = letzte[letzte.length - 1].getBoundingClientRect();

    plaetze.push({
      vor: null,
      index: geschwister.indexOf(letzte[letzte.length - 1]) + 1,
      kasten: { top: k.bottom, left: k.left, width: k.width, height: 1 },
    });
  }

  return plaetze;
}
