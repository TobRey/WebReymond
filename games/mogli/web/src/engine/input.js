// Ein Knopf, drei Wege ihn zu drücken: Finger, Taste, Gamepad.
//
// LATENZ IST HIER DAS THEMA. Zwei Entscheidungen dazu:
//
// 1. Gehorcht wird `pointerdown`, nicht `click`. `click` feuert erst beim
//    Loslassen und auf Touchgeräten teils mit spürbarer Verzögerung.
// 2. Ein Druck wird GEZÄHLT, nicht nur als Zustand gemerkt. Die Logik läuft
//    mit 60 Schritten pro Sekunde, ein Finger kann aber zwischen zwei
//    Schritten tippen und wieder loslassen. Ohne den Zähler ginge genau
//    dieser Tipp verloren – und das wäre der Fehler, den man beim Spielen als
//    "reagiert nicht" empfindet.

/** event.code statt event.key: layoutunabhängig, damit auch AZERTY passt. */
const KEY_JUMP = new Set(['Space', 'ArrowUp', 'KeyW', 'Enter', 'NumpadEnter']);
/** Diese Tasten würden sonst die Seite scrollen. */
const SWALLOW = new Set(['Space', 'ArrowUp', 'ArrowDown']);

export function createInput() {
  /** Wie oft seit dem letzten Logikschritt gedrückt wurde. */
  let pressCount = 0;
  let keyHeld = false;
  let pointerHeld = false;
  let padHeld = false;
  let padPauseWasDown = false;
  let wasDown = false;

  const state = { jump: false, jumpPressed: false, jumpReleased: false };
  const commands = [];

  function press() {
    pressCount += 1;
  }

  function onKeyDown(event) {
    if (event.repeat) return;
    const code = event.code;
    const target = event.target;
    const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA');

    if (KEY_JUMP.has(code)) {
      if (typing) return;
      keyHeld = true;
      press();
    } else if (code === 'Escape' || code === 'KeyP') commands.push('pause');
    else if (code === 'KeyR') commands.push('restart');
    else if (code === 'KeyM') commands.push('mute');
    else if (code === 'KeyF') commands.push('fullscreen');
    else return;

    if (!typing && SWALLOW.has(code)) event.preventDefault();
  }

  function onKeyUp(event) {
    if (KEY_JUMP.has(event.code)) keyHeld = false;
  }

  function onBlur() {
    keyHeld = false;
    pointerHeld = false;
  }

  window.addEventListener('keydown', onKeyDown);
  window.addEventListener('keyup', onKeyUp);
  window.addEventListener('blur', onBlur);

  /**
   * Macht eine Fläche zum Sprungknopf. Es gibt keine Richtungstasten mehr –
   * die ganze Spielfläche ist der Knopf.
   *
   * `deadZone` ist ein Bereich innerhalb der Fläche, der nicht springt: die
   * Einblendung mit Knöpfen und Namensfeld. Ohne diese Ausnahme würde das
   * preventDefault unten das Antippen eines Knopfes und das Hineintippen ins
   * Namensfeld verschlucken – der Sprungknopf darf nicht die Bedienung fressen.
   */
  function bindTapArea(element, deadZone = null) {
    if (element === null) return;
    const inDeadZone = (target) =>
      deadZone !== null && target instanceof Node && deadZone.contains(target);

    element.addEventListener(
      'pointerdown',
      (event) => {
        // Nur die primäre Taste; ein Rechtsklick soll nicht springen.
        if (event.button !== 0) return;
        if (inDeadZone(event.target)) return;
        event.preventDefault();
        pointerHeld = true;
        press();
      },
      { passive: false },
    );
    const release = () => {
      pointerHeld = false;
    };
    element.addEventListener('pointerup', release);
    element.addEventListener('pointercancel', release);
    element.addEventListener('pointerleave', release);
    // Auf iOS öffnet ein langer Druck sonst das Kontextmenü.
    element.addEventListener('contextmenu', (event) => {
      if (inDeadZone(event.target)) return;
      event.preventDefault();
    });
  }

  function button(gp, index) {
    return gp.buttons.length > index && gp.buttons[index].pressed;
  }

  function pollGamepad() {
    const pads = window.navigator.getGamepads ? window.navigator.getGamepads() : [];
    let jump = false;
    let pause = false;
    for (const gp of pads) {
      if (!gp) continue;
      if (button(gp, 0) || button(gp, 1) || button(gp, 2) || button(gp, 3)) jump = true;
      if (button(gp, 9)) pause = true;
    }
    if (jump && !padHeld) press();
    padHeld = jump;
    if (pause && !padPauseWasDown) commands.push('pause');
    padPauseWasDown = pause;
  }

  return {
    bindTapArea,

    /** Einmal je Logikschritt aufrufen. */
    update() {
      pollGamepad();

      const down = keyHeld || pointerHeld || padHeld;
      // Ein zwischenzeitlicher Tipp zählt auch dann, wenn der Finger beim
      // Abfragen längst wieder weg ist.
      state.jumpPressed = pressCount > 0 || (down && !wasDown);
      state.jumpReleased = !down && wasDown;
      state.jump = down || pressCount > 0;
      pressCount = 0;
      wasDown = down;
      return state;
    },

    get state() {
      return state;
    },

    /** Holt die aufgelaufenen Einmalbefehle ab und leert die Liste. */
    takeCommands() {
      if (commands.length === 0) return [];
      return commands.splice(0, commands.length);
    },

    /** Alles loslassen – beim Pausieren und beim Szenenwechsel. */
    releaseAll() {
      onBlur();
      pressCount = 0;
      wasDown = false;
    },

    dispose() {
      window.removeEventListener('keydown', onKeyDown);
      window.removeEventListener('keyup', onKeyUp);
      window.removeEventListener('blur', onBlur);
    },
  };
}
